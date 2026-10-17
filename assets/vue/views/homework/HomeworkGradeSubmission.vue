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

      <div class="flex gap-2">
        <BaseButton
          :disabled="!previousSubmissionId"
          icon="arrow-left"
          :label="t('Previous')"
          size="small"
          type="black"
          @click="goToNeighbor(previousSubmissionId)"
        />
        <BaseButton
          :disabled="!nextSubmissionId"
          icon="arrow-right"
          :label="t('Next')"
          size="small"
          type="black"
          @click="goToNeighbor(nextSubmissionId)"
        />
      </div>
    </div>
    <hr />

    <div
      v-if="isLoading"
      v-text="t('Loading...')"
    />

    <template v-else-if="assignment && submission">
      <div class="flex items-start justify-between flex-wrap gap-2">
        <div>
          <h1 class="text-2xl font-bold">{{ t("Grading {0}", [studentDisplayName]) }}</h1>
          <p class="text-gray-60">{{ assignment.title }} — {{ statusLabel(submission.status) }}</p>
        </div>

        <BaseButton
          icon="download"
          :label="t('Download as PDF')"
          type="black"
          @click="downloadSubmission"
        />
      </div>

      <!-- TYPE_FILE: single document download link. -->
      <div v-if="assignment.submissionType === HOMEWORK_ASSIGNMENT_TYPE_FILE">
        <a
          v-if="documentMeta?.downloadUrl"
          :href="documentMeta.downloadUrl"
          class="text-primary underline"
          rel="noopener"
          target="_blank"
        >
          {{ t("Download submitted file") }}: {{ documentMeta.title }}
        </a>
        <p
          v-else-if="!submissionDocumentIri"
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
          v-for="answer in submission.answers || []"
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

      <div class="border border-gray-25 bg-gray-15 rounded-lg p-4 space-y-4">
        <BaseInputNumber
          v-if="assignment.evaluationMode === HOMEWORK_ASSIGNMENT_EVALUATION_SCORE"
          id="homework-score"
          v-model="scoreInput"
          :label="t('Score')"
          :min="0"
        />

        <BaseTinyEditor
          v-if="assignment.evaluationMode !== HOMEWORK_ASSIGNMENT_EVALUATION_NONE"
          v-model="feedbackInput"
          editor-id="homework-feedback"
          :editor-config="feedbackEditorConfig"
          :full-page="false"
          :title="t('Feedback')"
          :use-file-manager="false"
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
import homeworkAssignmentService from "../../services/chomeworkassignment"
import homeworkSubmissionService from "../../services/chomeworksubmission"
import homeworkFormService from "../../services/chomeworkform"
import documentsService from "../../services/documents"
import userService from "../../services/userService"
import { useNotification } from "../../composables/notification"
import { useFormatDate } from "../../composables/formatDate"
import { buildSubmissionExportUrl } from "../../utils/homeworkSubmissionExport"
import BaseButton from "../../components/basecomponents/BaseButton.vue"
import BaseInputNumber from "../../components/basecomponents/BaseInputNumber.vue"
import BaseTinyEditor from "../../components/basecomponents/BaseTinyEditor.vue"
import {
  HOMEWORK_ASSIGNMENT_EVALUATION_NONE,
  HOMEWORK_ASSIGNMENT_EVALUATION_SCORE,
  HOMEWORK_ASSIGNMENT_TYPE_FILE,
  HOMEWORK_ASSIGNMENT_TYPE_FORM,
} from "../../constants/entity/chomeworkassignment"
import {
  HOMEWORK_SUBMISSION_STATUS_SUBMITTED,
  HOMEWORK_SUBMISSION_STATUS_LATE,
} from "../../constants/entity/chomeworksubmission"
import {
  HOMEWORK_FORM_FIELD_TYPE_CHECKBOX,
  HOMEWORK_FORM_FIELD_TYPE_DATE,
  HOMEWORK_FORM_FIELD_TYPE_FILE,
} from "../../constants/entity/chomeworkformfield"

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const { showSuccessNotification, showErrorNotification } = useNotification()
const { shortDate } = useFormatDate()

const assignmentId = Number(route.params.assignmentId)
// Reactive, NOT a plain const: Previous/Next navigate to this SAME route
// (name: "HomeworkGradeSubmission") with only the :submissionId param
// changed. Vue Router reuses the existing component instance for a
// same-route-record navigation - it does NOT remount it - so onMounted()
// only ever runs once. Everything that depends on "which submission is this"
// must be reactive to route.params.submissionId (see the watch() below that
// re-runs the load), not computed once at setup time.
const submissionId = computed(() => Number(route.params.submissionId))

const isLoading = ref(true)
const isFormLoading = ref(false)
const isSaving = ref(false)

const assignment = ref(null)
const submission = ref(null)
const form = ref(null)
const studentDisplayName = ref("…")

const scoreInput = ref(null)
const feedbackInput = ref("")

// Compact Tiny config for a short feedback field (no file manager, no
// full-page toolbar needed) - mirrors DropboxFeedbackDialog.vue's tinyConfig.
const feedbackEditorConfig = {
  height: 200,
  menubar: false,
  statusbar: false,
  toolbar: "bold italic underline | bullist numlist | link unlink | removeformat",
}

// fieldIri -> field object, documentIri -> {title, downloadUrl}. Same
// per-page caches HomeworkCorrectAndRate.vue used to keep for its (now
// removed) inline panel.
const fieldsByIri = reactive({})
const docMeta = reactive({})

// The assignment's full submission list, filtered/sorted exactly like the
// list page's own active view (status/user/sortOrder forwarded via
// route.query when navigating here) - fetched unpaginated purely to compute
// Previous/Next, same accepted tradeoff as HomeworkCorrectAndRate.vue's own
// student-filter-options fetch (see docs/superpowers/specs/2026-09-28-homework-round4-design.md,
// section 5). Works even on a direct/bookmarked link: an absent status/user
// behaves as "no filter" and an absent sortOrder defaults to desc, matching
// the list's own defaults.
const orderedSubmissionIds = ref([])

const currentIndex = computed(() => orderedSubmissionIds.value.indexOf(submissionId.value))
const previousSubmissionId = computed(() =>
  currentIndex.value > 0 ? orderedSubmissionIds.value[currentIndex.value - 1] : null,
)
const nextSubmissionId = computed(() =>
  currentIndex.value !== -1 && currentIndex.value < orderedSubmissionIds.value.length - 1
    ? orderedSubmissionIds.value[currentIndex.value + 1]
    : null,
)

const submissionDocumentIri = computed(() => resolveIri(submission.value?.document))
const documentMeta = computed(() => (submissionDocumentIri.value ? docMeta[submissionDocumentIri.value] : null))

async function loadPage() {
  isLoading.value = true
  try {
    const [assignmentResult, submissionResult] = await Promise.all([
      homeworkAssignmentService.getAssignment(assignmentId),
      homeworkSubmissionService.getSubmission(submissionId.value),
    ])
    assignment.value = assignmentResult
    submission.value = submissionResult
    scoreInput.value = submissionResult.score ?? null
    feedbackInput.value = submissionResult.feedback ?? ""

    await Promise.all([loadOrderedSubmissionIds(), loadStudentDisplayName(), loadReportContent()])
  } catch (error) {
    showErrorNotification(error)
  } finally {
    isLoading.value = false
  }
}

onMounted(loadPage)

// Previous/Next push a new submissionId onto this SAME route (name:
// "HomeworkGradeSubmission") - Vue Router reuses this component instance for
// a same-route-record navigation rather than remounting it, so onMounted()
// alone would never fire again for the new submission (confirmed live: the
// URL changed but the page kept showing the original submission, and
// Previous/Next appeared to do nothing). Explicitly re-run the whole load
// whenever the route's submissionId actually changes.
watch(
  () => route.params.submissionId,
  () => loadPage(),
)

async function loadOrderedSubmissionIds() {
  const { items } = await homeworkSubmissionService.listSubmissionsForAssignment(assignmentId, {
    itemsPerPage: 9999,
    status: route.query.status ? Number(route.query.status) : "",
    user: "string" === typeof route.query.user ? route.query.user : "",
    sortOrder: "asc" === route.query.sortOrder ? "asc" : "desc",
  })
  orderedSubmissionIds.value = items.map((item) => extractIidFromIri(item["@id"]))
}

async function loadStudentDisplayName() {
  const iri = resolveIri(submission.value?.user)
  if (!iri) return
  try {
    const user = await userService.find(iri)
    studentDisplayName.value = user?.fullName || user?.username || iri
  } catch (error) {
    console.error("[HomeworkGradeSubmission] Failed to resolve student name", error)
    studentDisplayName.value = iri
  }
}

async function loadReportContent() {
  if (assignment.value?.submissionType === HOMEWORK_ASSIGNMENT_TYPE_FILE) {
    const docIri = resolveIri(submission.value.document)
    if (docIri) await loadDocMeta(docIri)
    return
  }

  await loadForm()
  await Promise.all(
    (submission.value.answers || [])
      .filter((answer) => answer.fileDocument)
      .map((answer) => loadDocMeta(resolveIri(answer.fileDocument))),
  )
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
    console.error("[HomeworkGradeSubmission] Failed to load document metadata", error)
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

function isDateField(fieldIri) {
  return fieldsByIri[fieldIri]?.type === HOMEWORK_FORM_FIELD_TYPE_DATE
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
  if (isDateField(answer.field)) {
    return answer.value ? shortDate(answer.value) || answer.value : "—"
  }
  return answer.value || "—"
}

async function saveGrade() {
  if (!submission.value || isSaving.value) return

  isSaving.value = true
  try {
    const result = await homeworkSubmissionService.grade(submissionId.value, scoreInput.value, feedbackInput.value)
    submission.value = result
    showSuccessNotification(t("Grade saved"))
  } catch (error) {
    showErrorNotification(error)
  } finally {
    isSaving.value = false
  }
}

// A plain click handler via window.open(), NOT BaseButton's :to-url prop -
// see HomeworkCorrectAndRate.vue's downloadSubmission() for why: PrimeVue's
// <Button as-child> silently drops target/rel/download, confirmed live.
function downloadSubmission() {
  if (!submission.value) return
  window.open(buildSubmissionExportUrl(submission.value), "_blank", "noopener")
}

function goToNeighbor(neighborId) {
  if (!neighborId) return
  router.push({
    name: "HomeworkGradeSubmission",
    params: { node: route.params.node, assignmentId, submissionId: neighborId },
    query: route.query,
  })
}

function goBack() {
  router.push({
    name: "HomeworkCorrectAndRate",
    params: { node: route.params.node, assignmentId },
    query: route.query,
  })
}
</script>
