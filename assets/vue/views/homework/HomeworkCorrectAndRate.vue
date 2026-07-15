<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <BaseButton
        :label="t('Back')"
        icon="back"
        only-icon
        size="small"
        type="black"
        @click="goBack"
      />
    </div>
    <hr />

    <div
      v-if="isLoading"
      v-text="t('Loading...')"
    />

    <template v-else-if="assignment">
      <h1 class="text-2xl font-bold">{{ assignment.title }}</h1>
      <p
        v-if="assignment.description"
        class="text-gray-90"
        v-html="assignment.description"
      />
      <p class="text-gray-60">{{ t("Deadline") }}: {{ abbreviatedDatetime(assignment.deadline) }}</p>

      <div class="max-w-xs">
        <BaseSelect
          id="homework-status-filter"
          v-model="statusFilter"
          :label="t('Status')"
          :options="statusFilterOptions"
        />
      </div>

      <BaseTable
        v-model:rows="loadParams.itemsPerPage"
        :is-loading="isLoadingSubmissions"
        :total-items="totalRecords"
        :values="submissions"
        data-key="@id"
        lazy
        @page="onPage"
      >
        <Column :header="t('Student')">
          <template #body="{ data }">
            {{ studentName(data) }}
          </template>
        </Column>

        <Column :header="t('Status')">
          <template #body="{ data }">
            {{ statusLabel(data.status) }}
          </template>
        </Column>

        <Column :header="t('Submitted')">
          <template #body="{ data }">
            {{ data.submittedAt ? abbreviatedDatetime(data.submittedAt) : "—" }}
          </template>
        </Column>

        <Column :header="t('Score')">
          <template #body="{ data }">
            {{ data.score !== null && data.score !== undefined ? data.score : t("Not graded yet") }}
          </template>
        </Column>

        <Column :header="t('Actions')">
          <template #body="{ data }">
            <BaseButton
              icon="check"
              size="small"
              type="primary-text"
              :label="t('Review and rate')"
              @click="selectSubmission(data)"
            />
          </template>
        </Column>
      </BaseTable>

      <div
        v-if="selectedSubmission"
        class="border rounded-lg p-4 space-y-4"
      >
        <h3 class="text-lg font-semibold">
          {{ t("Grading {0}", [studentName(selectedSubmission)]) }}
        </h3>

        <!-- TYPE_FILE: single document download link. -->
        <div v-if="assignment.submissionType === HOMEWORK_ASSIGNMENT_TYPE_FILE">
          <a
            v-if="selectedDocumentMeta?.downloadUrl"
            :href="selectedDocumentMeta.downloadUrl"
            class="text-primary underline"
            rel="noopener"
            target="_blank"
          >
            {{ t("Download submitted file") }}: {{ selectedDocumentMeta.title }}
          </a>
          <p
            v-else-if="!selectedSubmissionDocumentIri"
            v-text="t('No file was submitted.')"
          />
        </div>

        <!-- TYPE_FORM: read-only rendering of each answer. -->
        <div
          v-else-if="assignment.submissionType === HOMEWORK_ASSIGNMENT_TYPE_FORM"
          class="space-y-3"
        >
          <div
            v-if="isFormLoading"
            v-text="t('Loading...')"
          />

          <div
            v-for="answer in selectedSubmission.answers || []"
            v-else
            :key="answer.field"
            class="space-y-1"
          >
            <p class="font-medium text-gray-90">{{ fieldLabel(answer.field) }}</p>

            <p v-if="isFileField(answer.field)">
              <a
                v-if="answerFileMeta(answer)?.downloadUrl"
                :href="answerFileMeta(answer).downloadUrl"
                class="text-primary underline"
                rel="noopener"
                target="_blank"
              >
                {{ t("Download file") }}: {{ answerFileMeta(answer).title }}
              </a>
              <span
                v-else
                class="text-gray-50"
                v-text="t('No file attached')"
              />
            </p>

            <p
              v-else
              class="text-gray-90 whitespace-pre-wrap"
              v-text="formatAnswerValue(answer)"
            />
          </div>
        </div>

        <BaseInputNumber
          id="homework-score"
          v-model="scoreInput"
          :label="t('Score')"
          :min="0"
        />

        <BaseTextArea
          id="homework-feedback"
          v-model="feedbackInput"
          :label="t('Feedback')"
        />

        <div class="flex justify-end">
          <BaseButton
            :is-loading="isSaving"
            :label="t('Save')"
            icon="save"
            type="primary"
            @click="saveGrade"
          />
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from "vue"
import { useRoute, useRouter } from "vue-router"
import { useI18n } from "vue-i18n"
import Column from "primevue/column"
import homeworkAssignmentService from "../../services/chomeworkassignment"
import homeworkSubmissionService from "../../services/chomeworksubmission"
import homeworkFormService from "../../services/chomeworkform"
import documentsService from "../../services/documents"
import userService from "../../services/userService"
import { useFormatDate } from "../../composables/formatDate"
import { useNotification } from "../../composables/notification"
import BaseButton from "../../components/basecomponents/BaseButton.vue"
import BaseTable from "../../components/basecomponents/BaseTable.vue"
import BaseSelect from "../../components/basecomponents/BaseSelect.vue"
import BaseInputNumber from "../../components/basecomponents/BaseInputNumber.vue"
import BaseTextArea from "../../components/basecomponents/BaseTextArea.vue"
import {
  HOMEWORK_ASSIGNMENT_TYPE_FILE,
  HOMEWORK_ASSIGNMENT_TYPE_FORM,
} from "../../constants/entity/chomeworkassignment"
import {
  HOMEWORK_SUBMISSION_STATUS_DRAFT,
  HOMEWORK_SUBMISSION_STATUS_SUBMITTED,
  HOMEWORK_SUBMISSION_STATUS_LATE,
} from "../../constants/entity/chomeworksubmission"
import {
  HOMEWORK_FORM_FIELD_TYPE_CHECKBOX,
  HOMEWORK_FORM_FIELD_TYPE_FILE,
} from "../../constants/entity/chomeworkformfield"

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const { abbreviatedDatetime } = useFormatDate()
const { showSuccessNotification, showErrorNotification } = useNotification()

const assignmentId = Number(route.params.assignmentId)

const isLoading = ref(true)
const isLoadingSubmissions = ref(false)
const isFormLoading = ref(false)
const isSaving = ref(false)

const assignment = ref(null)
const form = ref(null)
const submissions = ref([])
const totalRecords = ref(0)

// Server-side pagination state (see loadSubmissions()) - a course can have
// far more than API Platform's default 30-item page size worth of
// submissions, so the list must never be fetched/filtered as if it were
// complete without paging through it.
const loadParams = reactive({
  page: 1,
  itemsPerPage: null,
})

// "" = all statuses; otherwise one of HOMEWORK_SUBMISSION_STATUS_*. Applied
// server-side (CHomeworkSubmission's "status" SearchFilter), not client-side,
// so it always reflects the full result set rather than just the current page.
const statusFilter = ref("")

const selectedSubmission = ref(null)
const scoreInput = ref(null)
const feedbackInput = ref("")

// userIri -> display name, documentIri -> {title, downloadUrl}, fieldIri -> field object.
// Plain reactive caches, same pattern as HomeworkSubmit.vue's docMeta/answersByFieldIri.
const userNames = reactive({})
const docMeta = reactive({})
const fieldsByIri = reactive({})

const statusFilterOptions = computed(() => [
  { label: t("All"), value: "" },
  { label: t("Draft"), value: HOMEWORK_SUBMISSION_STATUS_DRAFT },
  { label: t("Submitted"), value: HOMEWORK_SUBMISSION_STATUS_SUBMITTED },
  { label: t("Late"), value: HOMEWORK_SUBMISSION_STATUS_LATE },
])

const selectedSubmissionDocumentIri = computed(() => resolveIri(selectedSubmission.value?.document))
const selectedDocumentMeta = computed(() =>
  selectedSubmissionDocumentIri.value ? docMeta[selectedSubmissionDocumentIri.value] : null,
)

onMounted(async () => {
  try {
    assignment.value = await homeworkAssignmentService.getAssignment(assignmentId)

    if (assignment.value.submissionType === HOMEWORK_ASSIGNMENT_TYPE_FORM) {
      await loadForm()
    }
  } catch (error) {
    showErrorNotification(error)
  } finally {
    isLoading.value = false
  }
})

// BaseTable initializes `loadParams.itemsPerPage` itself (from platform
// settings) once mounted, which is what actually triggers the first load
// below - mirrors TeacherSubmissionList.vue's (the Work module's reference
// implementation) lazy-loading pattern exactly, so the list is always
// fetched page-by-page from the server rather than once "in full" (which
// would silently truncate at API Platform's default 30-item page size for
// any course with more submissions than that).
watch(
  loadParams,
  () => {
    if (!loadParams.itemsPerPage) return
    loadSubmissions()
  },
  { deep: true, immediate: true },
)

// A status filter change must restart pagination from page 1 - reusing the
// current page against a differently-filtered result set would show the
// wrong slice (or an out-of-range empty page).
watch(statusFilter, () => {
  loadParams.page = 1
  if (loadParams.itemsPerPage) loadSubmissions()
})

async function loadSubmissions() {
  isLoadingSubmissions.value = true
  try {
    // Server-side pagination + server-side status filter (CHomeworkSubmission's
    // "status" SearchFilter) - CHomeworkSubmissionExtension (backend) already
    // scopes the underlying query to "course-wide teacher" via
    // HomeworkCourseTeacherChecker, so no client-side session filtering is
    // added here (it would defeat the module's cross-session grading
    // requirement); only pagination/status are handled server-side.
    const { items, totalItems } = await homeworkSubmissionService.listSubmissionsForAssignment(assignmentId, {
      page: loadParams.page,
      itemsPerPage: loadParams.itemsPerPage,
      status: statusFilter.value,
    })
    submissions.value = items
    totalRecords.value = totalItems
    await Promise.all(items.map((item) => loadUserName(item.user)))
  } finally {
    isLoadingSubmissions.value = false
  }
}

function onPage(event) {
  loadParams.page = event.page + 1
  loadParams.itemsPerPage = event.rows
}

async function loadUserName(userIri) {
  if (!userIri || userNames[userIri]) return
  try {
    const user = await userService.find(userIri)
    userNames[userIri] = user?.fullName || user?.username || userIri
  } catch (error) {
    console.error("[HomeworkCorrectAndRate] Failed to resolve student name", error)
    userNames[userIri] = userIri
  }
}

function studentName(submission) {
  const iri = resolveIri(submission?.user)
  return (iri ? userNames[iri] : null) || "…"
}

async function loadForm() {
  const formIri = resolveIri(assignment.value?.form)
  if (!formIri) return

  isFormLoading.value = true
  try {
    form.value = await homeworkFormService.getForm(extractIidFromIri(formIri))
    // CHomeworkFormField has no direct Get endpoint (NotFoundAction), so
    // labels/types can only be resolved via the parent form's embedded
    // pages/fields - same reasoning as HomeworkSubmit.vue's `allFields`.
    ;(form.value.pages || []).forEach((page) => {
      ;(page.fields || []).forEach((field) => {
        fieldsByIri[field["@id"]] = field
      })
    })
  } finally {
    isFormLoading.value = false
  }
}

async function loadDocMeta(iri) {
  if (!iri || docMeta[iri]) return
  try {
    docMeta[iri] = await documentsService.getDocumentByIri(iri)
  } catch (error) {
    console.error("[HomeworkCorrectAndRate] Failed to load document metadata", error)
  }
}

function resolveIri(value) {
  if (!value) return null
  return "string" === typeof value ? value : value["@id"] || null
}

function extractIidFromIri(iri) {
  return parseInt(String(iri).split("/").pop(), 10)
}

function statusLabel(status) {
  if (status === HOMEWORK_SUBMISSION_STATUS_LATE) return t("Submitted (late)")
  if (status === HOMEWORK_SUBMISSION_STATUS_SUBMITTED) return t("Submitted")
  return t("Draft")
}

function fieldLabel(fieldIri) {
  return fieldsByIri[fieldIri]?.label || fieldIri
}

function isFileField(fieldIri) {
  return fieldsByIri[fieldIri]?.type === HOMEWORK_FORM_FIELD_TYPE_FILE
}

function isCheckboxField(fieldIri) {
  return fieldsByIri[fieldIri]?.type === HOMEWORK_FORM_FIELD_TYPE_CHECKBOX
}

function answerFileMeta(answer) {
  const iri = resolveIri(answer.fileDocument)
  return iri ? docMeta[iri] : null
}

function formatAnswerValue(answer) {
  if (isCheckboxField(answer.field)) {
    try {
      const parsed = JSON.parse(answer.value || "[]")
      return Array.isArray(parsed) ? parsed.join(", ") : answer.value || "—"
    } catch {
      return answer.value || "—"
    }
  }
  return answer.value || "—"
}

async function selectSubmission(submission) {
  selectedSubmission.value = submission
  scoreInput.value = submission.score ?? null
  feedbackInput.value = submission.feedback ?? ""

  if (assignment.value?.submissionType === HOMEWORK_ASSIGNMENT_TYPE_FILE) {
    const docIri = resolveIri(submission.document)
    if (docIri) await loadDocMeta(docIri)
  } else {
    await Promise.all(
      (submission.answers || [])
        .filter((answer) => answer.fileDocument)
        .map((answer) => loadDocMeta(resolveIri(answer.fileDocument))),
    )
  }
}

async function saveGrade() {
  if (!selectedSubmission.value || isSaving.value) return

  isSaving.value = true
  try {
    const id = extractIidFromIri(selectedSubmission.value["@id"])
    const result = await homeworkSubmissionService.grade(id, scoreInput.value, feedbackInput.value)

    const index = submissions.value.findIndex((item) => item["@id"] === selectedSubmission.value["@id"])
    if (index !== -1) submissions.value[index] = result

    selectedSubmission.value = result
    showSuccessNotification(t("Grade saved"))
  } catch (error) {
    showErrorNotification(error)
  } finally {
    isSaving.value = false
  }
}

function goBack() {
  router.push({
    name: "HomeworkList",
    params: { node: route.params.node },
    query: route.query,
  })
}
</script>
