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

      <!-- Blocking state: deadline passed and late submissions are not allowed for this
           assignment - no submission UI is shown at all, matching the spec's hard cutoff. -->
      <div
        v-if="isBlocked"
        class="border-2 border-danger bg-support-6 text-danger rounded-lg p-3"
      >
        <p v-text="t('The deadline for this assignment has passed. Late submissions are not allowed.')" />
      </div>

      <template v-else-if="submission">
        <div
          v-if="isPastDeadline && isDraft"
          class="border-2 border-warning bg-support-5 rounded-lg p-3"
        >
          <p
            v-text="
              t(
                'The deadline has passed, but late submissions are still allowed. Submitting now will mark this submission as late.',
              )
            "
          />
        </div>

        <div
          v-if="!isDraft"
          class="border-2 border-primary bg-primary/10 text-primary rounded-lg p-3 space-y-1"
        >
          <p v-text="submittedOnLabel" />
          <p v-if="submission.score !== null && submission.score !== undefined">
            {{ t("Score") }}: <span class="font-bold">{{ submission.score }}</span>
          </p>
          <p v-if="submission.feedback">
            {{ t("Feedback") }}:<br />
            <span class="italic">{{ submission.feedback }}</span>
          </p>
        </div>

        <!-- TYPE_FILE: single document upload for the whole submission. -->
        <div
          v-if="assignment.submissionType === HOMEWORK_ASSIGNMENT_TYPE_FILE"
          class="space-y-2"
        >
          <div v-if="templateDocumentIri">
            <a
              v-if="templateDocMeta?.downloadUrl"
              :href="templateDocMeta.downloadUrl"
              class="text-primary underline"
              rel="noopener"
              target="_blank"
            >
              {{ t("Download template") }}: {{ templateDocMeta.title }}
            </a>
          </div>

          <div
            v-if="isDraft"
            class="flex items-center gap-2"
          >
            <BaseFileUpload
              :label="t('Upload your file')"
              input-id="homework-submission-file"
              input-name="homework-submission-file"
              @file-selected="onDocumentFileSelected"
            />
            <span
              v-if="isUploadingDocument"
              class="text-gray-50"
              v-text="t('Uploading...')"
            />
            <span
              v-else-if="documentMeta"
              class="text-gray-90"
              v-text="documentMeta.title"
            />
          </div>
          <p
            v-else-if="documentMeta"
            v-text="t('Submitted file: {0}', [documentMeta.title])"
          />
        </div>

        <!-- TYPE_FORM: paginated dynamic form rendering. -->
        <div
          v-else-if="assignment.submissionType === HOMEWORK_ASSIGNMENT_TYPE_FORM"
          class="space-y-4"
        >
          <div
            v-if="isFormLoading"
            v-text="t('Loading...')"
          />

          <template v-else-if="currentPage">
            <p
              v-if="pages.length > 1"
              class="text-tiny text-gray-50"
              v-text="t('Page {0} of {1}', [currentPageIndex + 1, pages.length])"
            />
            <h4 v-text="currentPage.title" />

            <div
              v-for="field in currentPage.fields"
              :key="field['@id']"
              class="space-y-1"
            >
              <BaseInputText
                v-if="field.type === HOMEWORK_FORM_FIELD_TYPE_TEXT"
                :disabled="!isDraft"
                :id="fieldDomId(field)"
                :label="fieldLabel(field)"
                :model-value="getAnswerValue(field)"
                @update:model-value="(value) => setAnswerValue(field, value)"
              />

              <BaseTextArea
                v-else-if="field.type === HOMEWORK_FORM_FIELD_TYPE_TEXTAREA"
                :disabled="!isDraft"
                :id="fieldDomId(field)"
                :label="fieldLabel(field)"
                :model-value="getAnswerValue(field)"
                :rows="field.rows || DEFAULT_TEXTAREA_ROWS"
                @update:model-value="(value) => setAnswerValue(field, value)"
              />

              <BaseInputNumber
                v-else-if="field.type === HOMEWORK_FORM_FIELD_TYPE_NUMBER"
                :disabled="!isDraft"
                :id="fieldDomId(field)"
                :label="fieldLabel(field)"
                :model-value="numberOrNull(getAnswerValue(field))"
                @update:model-value="
                  (value) => setAnswerValue(field, value === null || undefined === value ? '' : String(value))
                "
              />

              <BaseCalendar
                v-else-if="field.type === HOMEWORK_FORM_FIELD_TYPE_DATE"
                :disabled="!isDraft"
                :id="fieldDomId(field)"
                :label="fieldLabel(field)"
                :model-value="dateOrNull(getAnswerValue(field))"
                @update:model-value="(value) => setAnswerValue(field, value ? value.toISOString() : '')"
              />

              <BaseSelect
                v-else-if="field.type === HOMEWORK_FORM_FIELD_TYPE_SELECT"
                :disabled="!isDraft"
                :id="fieldDomId(field)"
                :label="fieldLabel(field)"
                :model-value="getAnswerValue(field)"
                :options="fieldSelectOptions(field)"
                allow-clear
                option-label="label"
                option-value="value"
                @update:model-value="(value) => setAnswerValue(field, value)"
              />

              <BaseMultiSelect
                v-else-if="field.type === HOMEWORK_FORM_FIELD_TYPE_CHECKBOX"
                :disabled="!isDraft"
                :input-id="fieldDomId(field)"
                :label="fieldLabel(field)"
                :model-value="getCheckboxValue(field)"
                :options="fieldSelectOptions(field)"
                option-label="label"
                option-value="value"
                @update:model-value="(value) => setCheckboxValue(field, value)"
              />

              <div
                v-else-if="field.type === HOMEWORK_FORM_FIELD_TYPE_FILE"
                class="flex items-center gap-2"
              >
                <BaseFileUpload
                  v-if="isDraft"
                  :input-id="fieldDomId(field)"
                  :input-name="fieldDomId(field)"
                  :label="fieldLabel(field)"
                  @file-selected="(file) => onAnswerFileSelected(field, file)"
                />
                <span
                  v-else
                  v-text="fieldLabel(field)"
                />
                <span
                  v-if="getAnswerState(field).fileDocument"
                  class="text-gray-90"
                  v-text="t('File attached')"
                />
              </div>

              <p
                v-if="field.helpText"
                class="text-tiny text-gray-50"
                v-text="field.helpText"
              />
            </div>

            <div class="flex justify-between mt-2">
              <BaseButton
                v-if="currentPageIndex > 0"
                :label="t('Previous')"
                type="black"
                @click="goPreviousPage"
              />
              <span v-else />
              <BaseButton
                v-if="currentPageIndex < pages.length - 1"
                :label="t('Next')"
                type="black"
                @click="goNextPage"
              />
            </div>
          </template>
        </div>

        <div
          v-if="isDraft"
          class="flex justify-end gap-2 mt-4"
        >
          <BaseButton
            :is-loading="isSavingDraft"
            :label="t('Save draft')"
            icon="save"
            type="black"
            @click="saveDraftNow"
          />
          <BaseButton
            :disabled="!canSubmit"
            :is-loading="isSubmitting"
            :label="t('Submit')"
            icon="send"
            type="primary"
            @click="onSubmit"
          />
        </div>
      </template>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from "vue"
import { useRoute, useRouter } from "vue-router"
import { useI18n } from "vue-i18n"
import { getCourseContext } from "../../utils/courseContext"
import { useNotification } from "../../composables/notification"
import { useFormatDate } from "../../composables/formatDate"
import { useSecurityStore } from "../../store/securityStore"
import { RESOURCE_LINK_PUBLISHED } from "../../constants/entity/resourcelink"
import homeworkAssignmentService from "../../services/chomeworkassignment"
import homeworkSubmissionService from "../../services/chomeworksubmission"
import homeworkFormService from "../../services/chomeworkform"
import documentsService from "../../services/documents"
import BaseButton from "../../components/basecomponents/BaseButton.vue"
import BaseInputText from "../../components/basecomponents/BaseInputText.vue"
import BaseTextArea from "../../components/basecomponents/BaseTextArea.vue"
import BaseInputNumber from "../../components/basecomponents/BaseInputNumber.vue"
import BaseCalendar from "../../components/basecomponents/BaseCalendar.vue"
import BaseSelect from "../../components/basecomponents/BaseSelect.vue"
import BaseMultiSelect from "../../components/basecomponents/BaseMultiSelect.vue"
import BaseFileUpload from "../../components/basecomponents/BaseFileUpload.vue"
import {
  HOMEWORK_ASSIGNMENT_TYPE_FILE,
  HOMEWORK_ASSIGNMENT_TYPE_FORM,
} from "../../constants/entity/chomeworkassignment"
import {
  HOMEWORK_SUBMISSION_STATUS_DRAFT,
  HOMEWORK_SUBMISSION_STATUS_LATE,
  HOMEWORK_SUBMISSION_STATUS_SUBMITTED,
} from "../../constants/entity/chomeworksubmission"
import {
  HOMEWORK_FORM_FIELD_TYPE_CHECKBOX,
  HOMEWORK_FORM_FIELD_TYPE_DATE,
  HOMEWORK_FORM_FIELD_TYPE_FILE,
  HOMEWORK_FORM_FIELD_TYPE_NUMBER,
  HOMEWORK_FORM_FIELD_TYPE_SELECT,
  HOMEWORK_FORM_FIELD_TYPE_TEXT,
  HOMEWORK_FORM_FIELD_TYPE_TEXTAREA,
} from "../../constants/entity/chomeworkformfield"

// Autosave cadence for draft answers - matches common UX for long forms
// (e.g. Google Forms/Docs-style periodic background saves).
const AUTOSAVE_INTERVAL_MS = 30000

// Fallback height for textarea fields that predate the per-field "rows"
// config (HomeworkFormBuilder.vue) - taller than PrimeVue Textarea's own
// 2-row default, which read as cramped for anything longer than one line.
const DEFAULT_TEXTAREA_ROWS = 4

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const securityStore = useSecurityStore()
const { showSuccessNotification, showErrorNotification } = useNotification()
const { abbreviatedDatetime, shortDate, shortTime } = useFormatDate()

const assignmentId = Number(route.params.assignmentId)
// :node is defined on the PARENT "homework" route (assets/vue/router/homework.js)
// and inherited here by Vue Router's default param-merging - same pattern
// HomeworkAssignmentForm.vue/HomeworkFormBuilder.vue already rely on.
const nodeId = Number(route.params.node)

const isLoading = ref(true)
const isFormLoading = ref(false)
const isSavingDraft = ref(false)
const isSubmitting = ref(false)
const isUploadingDocument = ref(false)

const assignment = ref(null)
const submission = ref(null)
const form = ref(null)

// fieldIri -> { value: string, fileDocument: string|null }. Populated from the
// loaded submission's answers and mutated as the student edits the form.
const answersByFieldIri = reactive({})

// documentIri -> { title, downloadUrl } cache, shared by the assignment's
// templateDocument link and the student's own uploaded document.
const docMeta = reactive({})

const documentIri = ref(null)
const currentPageIndex = ref(0)

let autosaveTimer = null

// Breadcrumb.vue reads its label off the matched route record's static
// meta.breadcrumb - "Submit" is right while filling in a draft, but reads
// wrong once the report is actually submitted. Mutate the record in place
// (Breadcrumb.vue's watchEffect tracks route.matched reactively) rather than
// duplicating a second breadcrumb source of truth.
watch(
  submission,
  (sub) => {
    const matchedRecord = route.matched.find((record) => "HomeworkSubmit" === record.name)
    if (!matchedRecord) return

    matchedRecord.meta.breadcrumb = sub && sub.status !== HOMEWORK_SUBMISSION_STATUS_DRAFT ? "Submitted" : "Submit"
  },
  { immediate: true },
)

const isPastDeadline = computed(() => !!assignment.value && new Date() > new Date(assignment.value.deadline))
const isBlocked = computed(() => isPastDeadline.value && assignment.value && !assignment.value.allowLateSubmission)
const isDraft = computed(() => submission.value?.status === HOMEWORK_SUBMISSION_STATUS_DRAFT)

const statusLabel = computed(() => {
  if (!submission.value) return ""
  if (submission.value.status === HOMEWORK_SUBMISSION_STATUS_LATE) {
    return t("Submitted (late)")
  }
  if (submission.value.status === HOMEWORK_SUBMISSION_STATUS_SUBMITTED) {
    return t("Submitted")
  }
  return t("Draft")
})

// Confirmation box title: "Submitted on {date} at {time}" using the actual
// submittedAt timestamp - statusLabel above stays a plain "Submitted"/
// "Submitted (late)"/"Draft" for other uses (e.g. HomeworkList.vue's own
// per-status text already builds its own date-qualified label separately).
const submittedOnLabel = computed(() => {
  if (!submission.value?.submittedAt) return statusLabel.value

  const date = shortDate(submission.value.submittedAt)
  const time = shortTime(submission.value.submittedAt)

  if (submission.value.status === HOMEWORK_SUBMISSION_STATUS_LATE) {
    return t("Submitted late on {date} at {time}", { date, time })
  }

  return t("Submitted on {date} at {time}", { date, time })
})

const pages = computed(() => form.value?.pages || [])
const currentPage = computed(() => pages.value[currentPageIndex.value] || null)
const allFields = computed(() => pages.value.flatMap((page) => page.fields || []))

const templateDocumentIri = computed(() => resolveIri(assignment.value?.templateDocument))
const templateDocMeta = computed(() => (templateDocumentIri.value ? docMeta[templateDocumentIri.value] : null))
const documentMeta = computed(() => (documentIri.value ? docMeta[documentIri.value] : null))

const allRequiredFieldsFilled = computed(() =>
  allFields.value
    .filter((field) => field.required)
    .every((field) => {
      if (field.type === HOMEWORK_FORM_FIELD_TYPE_FILE) {
        return !!getAnswerState(field).fileDocument
      }
      if (field.type === HOMEWORK_FORM_FIELD_TYPE_CHECKBOX) {
        return getCheckboxValue(field).length > 0
      }
      return "" !== String(getAnswerValue(field) ?? "").trim()
    }),
)

const canSubmit = computed(() => {
  if (!submission.value || !isDraft.value) return false
  if (assignment.value?.submissionType === HOMEWORK_ASSIGNMENT_TYPE_FILE) {
    return !!documentIri.value
  }
  return allRequiredFieldsFilled.value
})

onMounted(async () => {
  try {
    assignment.value = await homeworkAssignmentService.getAssignment(assignmentId)

    if (!isBlocked.value) {
      await loadOrCreateSubmission()

      if (assignment.value.submissionType === HOMEWORK_ASSIGNMENT_TYPE_FORM) {
        await loadForm()
      }

      if (templateDocumentIri.value) {
        await loadDocMeta(templateDocumentIri.value)
      }

      startAutosave()
    }
  } catch (error) {
    showErrorNotification(error)
  } finally {
    isLoading.value = false
  }
})

onUnmounted(stopAutosave)

async function loadOrCreateSubmission() {
  const { items } = await homeworkSubmissionService.listSubmissionsForAssignment(assignmentId)
  const myIri = securityStore.user?.["@id"]
  let mine = items.find((item) => item.user === myIri)

  if (!mine) {
    const { cid, sid } = getCourseContext()
    mine = await homeworkSubmissionService.createSubmission({
      assignment: assignment.value["@id"],
      // parentResourceNodeId (not parentResourceNode): see
      // HomeworkAssignmentForm.vue's onSubmit() for why - same
      // AbstractResource::$parentResourceNode Groups gap, same raw-request
      // fallback in ResourceListener::prePersist(). Parented under the
      // Homework tool's own node (the course/session tree location doesn't
      // drive privacy here - the resourceLinkList below does).
      parentResourceNodeId: nodeId,
      // Deliberately a USER-scoped link (uid), not the course-wide
      // {visibility} link the assignment/form use: CHomeworkSubmission has no
      // ResourceNodeVoter special case for "own submission" the way
      // c_student_publication does, so a user-scoped ResourceLink is the only
      // mechanism that keeps one student's submission private from another
      // (see tests/CoreBundle/Api/HomeworkPermissionMatrixTest.php's class
      // docblock - this fixture-shape requirement is exactly what this POST
      // must reproduce for real submissions). cid/sid are still needed
      // alongside uid because a uid-only link carries no course at all -
      // ResourceListener::normalizeSingleLinkContextFromSession() explicitly
      // skips its own course-context auto-fill whenever uid/ugid is present.
      resourceLinkList: [
        {
          cid,
          ...(sid ? { sid } : {}),
          visibility: RESOURCE_LINK_PUBLISHED,
          uid: securityStore.user?.id,
        },
      ],
    })
  }

  submission.value = mine
  populateLocalStateFromSubmission(mine)
}

function populateLocalStateFromSubmission(sub) {
  documentIri.value = resolveIri(sub.document)
  if (documentIri.value) {
    loadDocMeta(documentIri.value)
  }

  Object.keys(answersByFieldIri).forEach((key) => delete answersByFieldIri[key])
  ;(sub.answers || []).forEach((answer) => {
    answersByFieldIri[answer.field] = {
      value: answer.value ?? "",
      fileDocument: answer.fileDocument || null,
    }
  })
}

async function loadForm() {
  const formIri = resolveIri(assignment.value?.form)
  if (!formIri) return

  isFormLoading.value = true
  try {
    form.value = await homeworkFormService.getForm(extractIidFromIri(formIri))
  } finally {
    isFormLoading.value = false
  }
}

async function loadDocMeta(iri) {
  if (!iri || docMeta[iri]) return
  try {
    docMeta[iri] = await documentsService.getDocumentByIri(iri)
  } catch (error) {
    // Non-fatal: the submission flow itself does not depend on the document's
    // display metadata (title/downloadUrl) resolving successfully.
    console.error("[HomeworkSubmit] Failed to load document metadata", error)
  }
}

function resolveIri(value) {
  if (!value) return null
  return "string" === typeof value ? value : value["@id"] || null
}

function extractIidFromIri(iri) {
  return parseInt(String(iri).split("/").pop(), 10)
}

function fieldDomId(field) {
  return `homework-field-${extractIidFromIri(field["@id"])}`
}

function fieldLabel(field) {
  return field.required ? `${field.label} *` : field.label
}

function fieldSelectOptions(field) {
  return (field.options || []).map((option) => ({ label: option, value: option }))
}

function getAnswerState(field) {
  const iri = field["@id"]
  if (!answersByFieldIri[iri]) {
    answersByFieldIri[iri] = { value: "", fileDocument: null }
  }
  return answersByFieldIri[iri]
}

function getAnswerValue(field) {
  return getAnswerState(field).value
}

function setAnswerValue(field, value) {
  getAnswerState(field).value = value
}

function getCheckboxValue(field) {
  try {
    const parsed = JSON.parse(getAnswerState(field).value || "[]")
    return Array.isArray(parsed) ? parsed : []
  } catch {
    return []
  }
}

function setCheckboxValue(field, values) {
  getAnswerState(field).value = JSON.stringify(values || [])
}

function numberOrNull(value) {
  return "" === value || null === value || undefined === value ? null : Number(value)
}

function dateOrNull(value) {
  return value ? new Date(value) : null
}

async function onDocumentFileSelected(file) {
  isUploadingDocument.value = true
  try {
    // /api/documents (the general document-upload endpoint used elsewhere in
    // the app) is gated by "ROLE_CURRENT_COURSE_TEACHER or
    // ROLE_CURRENT_COURSE_SESSION_TEACHER" - a plain student would get a 403
    // there. /api/documents/homework-submission-upload
    // (CreateHomeworkSubmissionFileAction) is the student-facing counterpart
    // - it forces the ResourceLink's `uid` to the authenticated user
    // server-side (ignoring whatever this request sends), so the
    // user-scoped-link privacy is enforced regardless of the resourceLinkList
    // passed here.
    const response = await documentsService.createWithFormData(
      {
        uploadFile: file,
        title: file.name,
        filetype: "file",
        parentResourceNodeId: nodeId,
        resourceLinkList: JSON.stringify([{ visibility: RESOURCE_LINK_PUBLISHED }]),
      },
      "/api/documents/homework-submission-upload",
    )
    const document = await response.json()
    documentIri.value = document["@id"]
    docMeta[document["@id"]] = document
  } catch (error) {
    showErrorNotification(error)
  } finally {
    isUploadingDocument.value = false
  }
}

async function onAnswerFileSelected(field, file) {
  try {
    const response = await documentsService.createWithFormData(
      {
        uploadFile: file,
        title: file.name,
        filetype: "file",
        parentResourceNodeId: nodeId,
        resourceLinkList: JSON.stringify([{ visibility: RESOURCE_LINK_PUBLISHED }]),
      },
      "/api/documents/homework-submission-upload",
    )
    const document = await response.json()
    getAnswerState(field).fileDocument = document["@id"]
    docMeta[document["@id"]] = document
  } catch (error) {
    showErrorNotification(error)
  }
}

function goPreviousPage() {
  if (currentPageIndex.value > 0) currentPageIndex.value -= 1
}

function goNextPage() {
  if (currentPageIndex.value < pages.value.length - 1) currentPageIndex.value += 1
}

function buildAnswersPayload() {
  const answers = []

  allFields.value.forEach((field) => {
    const state = answersByFieldIri[field["@id"]]
    if (!state) return

    const hasValue = "" !== String(state.value ?? "").trim()
    const hasFile = !!state.fileDocument
    if (!hasValue && !hasFile) return

    const answer = { field: field["@id"] }
    if (hasValue) answer.value = state.value
    if (hasFile) answer.fileDocument = state.fileDocument
    answers.push(answer)
  })

  return answers
}

function buildSavePayload() {
  if (assignment.value?.submissionType === HOMEWORK_ASSIGNMENT_TYPE_FILE) {
    return documentIri.value ? { document: documentIri.value } : {}
  }

  return { answers: buildAnswersPayload() }
}

async function saveDraftNow() {
  if (!submission.value || !isDraft.value || isSavingDraft.value) return

  isSavingDraft.value = true
  try {
    const result = await homeworkSubmissionService.saveDraft(
      extractIidFromIri(submission.value["@id"]),
      buildSavePayload(),
    )
    submission.value = result
  } catch (error) {
    // Autosave failures should not interrupt the student's work with a
    // blocking error toast; log for diagnostics and let the next periodic
    // tick (or the explicit "Save draft"/"Submit" click) retry.
    console.error("[HomeworkSubmit] Autosave failed", error)
  } finally {
    isSavingDraft.value = false
  }
}

function startAutosave() {
  stopAutosave()
  autosaveTimer = setInterval(() => {
    if (isDraft.value) saveDraftNow()
  }, AUTOSAVE_INTERVAL_MS)
}

function stopAutosave() {
  if (autosaveTimer) {
    clearInterval(autosaveTimer)
    autosaveTimer = null
  }
}

async function onSubmit() {
  if (!canSubmit.value || isSubmitting.value) return

  isSubmitting.value = true
  try {
    const payload = buildSavePayload()
    let result

    if (isPastDeadline.value) {
      // Late-but-allowed submission: chomeworksubmission.js's submit() helper
      // always forces STATUS_SUBMITTED, so the LATE status has to go through
      // saveDraft() directly instead. Client-computed rather than
      // backend-computed: no server-side logic exists anywhere in this module
      // that derives DRAFT/SUBMITTED/LATE from deadline + allowLateSubmission
      // (confirmed by reading CHomeworkSubmissionPostStateProcessor/
      // PutStateProcessor and grepping the codebase) - flagged as a residual
      // concern in the Task 15 report; a malicious client could in principle
      // send STATUS_SUBMITTED past the deadline instead of STATUS_LATE. That
      // is a lower-stakes spoof than fabricating a submission for someone
      // else (blocked server-side) and is left as-is for now.
      result = await homeworkSubmissionService.saveDraft(extractIidFromIri(submission.value["@id"]), {
        ...payload,
        status: HOMEWORK_SUBMISSION_STATUS_LATE,
      })
    } else {
      result = await homeworkSubmissionService.submit(extractIidFromIri(submission.value["@id"]), payload)
    }

    submission.value = result
    populateLocalStateFromSubmission(result)
    stopAutosave()
    showSuccessNotification(t("Submission sent"))
  } catch (error) {
    showErrorNotification(error)
  } finally {
    isSubmitting.value = false
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
