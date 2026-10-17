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

      <!-- Blocking notice: deadline passed and late submissions are not
           allowed for this assignment - submitting new work is genuinely no
           longer possible, but this is an informational state (the final
           evaluation right below may well be exactly why it's closed), not
           an error - warning styling (matches the "deadline passed, late
           still allowed" notice further down), not the red/danger style. -->
      <div
        v-if="isBlocked"
        class="border-2 border-warning bg-support-6 rounded-lg p-3"
      >
        <p v-text="t('The deadline for this assignment has passed. Late submissions are not allowed.')" />
      </div>

      <!-- Rendered independently of isBlocked above: a student who can no
           longer submit new work must still be able to see their final
           evaluation once the deadline makes it visible (isVisibleToStudent()
           on the backend already gates this - an empty result here just
           means "not visible yet" or "not graded yet", not an error). -->
      <div
        v-if="assignment.allowMultipleSubmissions && finalEvaluation"
        class="border border-gray-25 bg-gray-15 rounded-lg p-3 space-y-2"
      >
        <p
          class="font-semibold"
          v-text="t('Final evaluation')"
        />
        <p v-if="assignment.evaluationMode === HOMEWORK_ASSIGNMENT_EVALUATION_SCORE && null !== finalEvaluation.score">
          {{ t("Score") }}: <span class="font-bold">{{ finalEvaluation.score }}</span>
        </p>
        <div v-if="assignment.evaluationMode !== HOMEWORK_ASSIGNMENT_EVALUATION_NONE && finalEvaluation.feedback">
          <p
            class="font-medium"
            v-text="t('Feedback')"
          />
          <div
            class="text-gray-90"
            v-html="finalEvaluation.feedback"
          />
        </div>
      </div>

      <template v-else>
        <div
          v-if="assignment.allowMultipleSubmissions"
          class="border border-gray-30 rounded-lg p-3 space-y-2"
        >
          <p class="font-medium">
            {{ t("{submitted}/{required} reports submitted", { submitted: submittedCount, required: assignment.requiredSubmissionCount }) }}
            <span
              v-if="lateCount > 0"
              class="text-warning"
            >
              ({{ t("{count} late", { count: lateCount }) }})
            </span>
          </p>

          <ul
            v-if="submittedReports.length > 0"
            class="space-y-1"
          >
            <li
              v-for="report in submittedReports"
              :key="report['@id']"
              class="text-gray-90"
            >
              {{ t("Report {n}", { n: report.sequenceNumber }) }}<span v-if="report.label">: {{ report.label }}</span>
              — {{ shortDate(report.submittedAt) }} {{ shortTime(report.submittedAt) }}
              <span
                v-if="report.status === HOMEWORK_SUBMISSION_STATUS_LATE"
                class="text-warning"
                v-text="t('(late)')"
              />
              <span v-if="report.score !== null && report.score !== undefined">
                — {{ t("Score") }}: {{ report.score }}
              </span>
            </li>
          </ul>

          <BaseButton
            v-if="!submission"
            :label="t('Start new report')"
            icon="plus"
            type="black"
            @click="startNewReport()"
          />
        </div>

        <template v-if="submission">
        <div
          v-if="isBeforeOpensOn && isDraft"
          class="border-2 border-primary bg-primary/10 text-primary rounded-lg p-3"
        >
          <p v-text="t('This report is not available yet. Available from {date}.', { date: abbreviatedDatetime(assignment.opensOn) })" />
        </div>

        <div
          v-if="isPastDeadline && isDraft"
          class="border-2 border-warning bg-support-6 rounded-lg p-3"
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
          <p
            v-if="
              assignment.evaluationMode === HOMEWORK_ASSIGNMENT_EVALUATION_SCORE &&
              null !== submission.score &&
              undefined !== submission.score
            "
          >
            {{ t("Score") }}: <span class="font-bold">{{ submission.score }}</span>
          </p>
          <div v-if="assignment.evaluationMode !== HOMEWORK_ASSIGNMENT_EVALUATION_NONE && submission.feedback">
            {{ t("Feedback") }}:
            <div
              class="italic"
              v-html="submission.feedback"
            />
          </div>
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
          v-if="assignment.allowMultipleSubmissions && isDraft"
          class="max-w-xs"
        >
          <BaseInputText
            v-model="reportLabel"
            :label="t('Report title')"
            :placeholder="t('Optional')"
            id="report-label"
          />
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
import homeworkAssignmentEvaluationService from "../../services/chomeworkassignmentevaluation"
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
  HOMEWORK_ASSIGNMENT_EVALUATION_NONE,
  HOMEWORK_ASSIGNMENT_EVALUATION_SCORE,
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
// The report currently being edited (a draft) - unchanged meaning from before
// multi-report support: null means "no active draft right now" (only
// possible in multi-report mode, between reports).
const submission = ref(null)
const form = ref(null)

// All of this student's reports for this assignment, sorted by
// sequenceNumber (multi-report assignments only - always empty otherwise).
const myReports = ref([])
const reportLabel = ref("")

// This student's own CHomeworkAssignmentEvaluation for this assignment, null
// until the backend actually makes it visible (see loadFinalEvaluation()).
const finalEvaluation = ref(null)

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
// Unlike isBlocked (deadline passed), this does NOT hide the whole page -
// a draft may still be created/edited before opensOn (matches the backend:
// CHomeworkSubmissionStatusResolver only gates the actual submit
// transition). Only the Submit button is disabled, with an info banner
// instead of the red "blocked" one.
const isBeforeOpensOn = computed(
  () => !!assignment.value?.opensOn && new Date() < new Date(assignment.value.opensOn),
)
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

const submittedReports = computed(() => myReports.value.filter((r) => r.status !== HOMEWORK_SUBMISSION_STATUS_DRAFT))
const submittedCount = computed(() => submittedReports.value.length)
const lateCount = computed(
  () => submittedReports.value.filter((r) => r.status === HOMEWORK_SUBMISSION_STATUS_LATE).length,
)

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
  if (!submission.value || !isDraft.value || isBeforeOpensOn.value) return false
  if (assignment.value?.submissionType === HOMEWORK_ASSIGNMENT_TYPE_FILE) {
    return !!documentIri.value
  }
  return allRequiredFieldsFilled.value
})

onMounted(async () => {
  try {
    assignment.value = await homeworkAssignmentService.getAssignment(assignmentId)

    // Deliberately outside the isBlocked guard below - see the template's
    // own comment on the final-evaluation block.
    if (assignment.value.allowMultipleSubmissions) {
      await loadFinalEvaluation()
    }

    if (!isBlocked.value) {
      if (assignment.value.allowMultipleSubmissions) {
        await loadMyReports()
      } else {
        await loadOrCreateSubmission()
      }

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

// GetCollection's own row-level scoping (CHomeworkAssignmentEvaluationExtension)
// already restricts this to the current student's own evaluation AND only
// once the assignment's deadline has passed - an empty result here is the
// normal "not visible yet" case, not an error, so finalEvaluation simply
// stays null rather than needing a separate "not visible" state.
async function loadFinalEvaluation() {
  const { items } = await homeworkAssignmentEvaluationService.listEvaluationsForAssignment(assignmentId)
  finalEvaluation.value = items[0] || null
}

// Multi-report counterpart of loadOrCreateSubmission(): loads ALL of the
// student's reports (for the progress header/list), and sets `submission`
// to whichever one is still a draft, if any - null when every report so far
// has been submitted (the student must click "Start new report" to get a
// new active draft, except on a true first visit with zero reports, where
// one is created automatically to match the single-report UX of "always
// have a draft ready").
async function loadMyReports() {
  const { items } = await homeworkSubmissionService.listSubmissionsForAssignment(assignmentId)
  const myIri = securityStore.user?.["@id"]
  myReports.value = items
    .filter((item) => item.user === myIri)
    .sort((a, b) => a.sequenceNumber - b.sequenceNumber)

  const draft = myReports.value.find((item) => item.status === HOMEWORK_SUBMISSION_STATUS_DRAFT)
  if (draft) {
    submission.value = draft
    populateLocalStateFromSubmission(draft)
    reportLabel.value = draft.label || ""
  } else if (0 === myReports.value.length) {
    await startNewReport()
  } else {
    submission.value = null
  }
}

async function startNewReport(label = null) {
  try {
    const { cid, sid } = getCourseContext()
    const created = await homeworkSubmissionService.createSubmission({
      assignment: assignment.value["@id"],
      ...(label ? { label } : {}),
      // See loadOrCreateSubmission() above for why this exact
      // parentResourceNodeId/resourceLinkList shape (user-scoped `uid` link)
      // is required.
      parentResourceNodeId: nodeId,
      resourceLinkList: [
        {
          cid,
          ...(sid ? { sid } : {}),
          visibility: RESOURCE_LINK_PUBLISHED,
          uid: securityStore.user?.id,
        },
      ],
    })

    myReports.value.push(created)
    submission.value = created
    populateLocalStateFromSubmission(created)
    reportLabel.value = created.label || ""
    startAutosave()
  } catch (error) {
    // The most common rejection here is a 422 from
    // CHomeworkTooManyActiveDraftsException (the previous report is still a
    // draft) - surfaced the same way every other failed request in this
    // component already is.
    showErrorNotification(error)
  }
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
  const payload =
    assignment.value?.submissionType === HOMEWORK_ASSIGNMENT_TYPE_FILE
      ? documentIri.value
        ? { document: documentIri.value }
        : {}
      : { answers: buildAnswersPayload() }

  if (assignment.value?.allowMultipleSubmissions) {
    payload.label = reportLabel.value || null
  }

  return payload
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

    if (assignment.value?.allowMultipleSubmissions) {
      const idx = myReports.value.findIndex((r) => r["@id"] === result["@id"])
      if (idx >= 0) {
        myReports.value[idx] = result
      } else {
        myReports.value.push(result)
      }
      // No active draft until the student clicks "Start new report" -
      // matches loadMyReports()'s own "null when nothing is a draft" rule.
      submission.value = null
      reportLabel.value = ""
    }
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
