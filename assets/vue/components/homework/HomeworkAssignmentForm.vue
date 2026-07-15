<template>
  <form @submit.prevent="onSubmit">
    <BaseInputText
      v-model="v$.title.$model"
      :error-text="v$.title.$errors.map((e) => e.$message).join('<br>')"
      :form-submitted="formSubmitted"
      :is-invalid="v$.title.$error"
      :label="t('Title')"
      id="title"
    />

    <BaseTinyEditor
      v-model="assignment.description"
      :label="t('Description')"
      editor-id=""
    />

    <div class="field flex items-start gap-4">
      <div class="shrink-0">
        <BaseRadioButtons
          v-model="assignment.submissionType"
          :options="submissionTypeOptions"
          :title="t('Submission type')"
          name="submission_type"
        />
      </div>

      <!-- mt-8 offsets past BaseRadioButtons' own title label (line height +
           mb-2) so this column's content starts level with the actual radio
           buttons, not with the "Submission type" title above them. -->
      <div class="flex-1 mt-8">
        <div v-if="assignment.submissionType === HOMEWORK_ASSIGNMENT_TYPE_FILE">
          <div class="flex items-center gap-2">
            <BaseFileUpload
              :label="t('Attach a template document')"
              input-id="homework-template-document"
              input-name="homework-template-document"
              @file-selected="onTemplateFileSelected"
            />
            <span
              v-if="isUploadingTemplate"
              class="text-gray-500"
              v-text="t('Uploading...')"
            />
            <span
              v-else-if="templateDocumentName"
              class="text-gray-90"
              v-text="templateDocumentName"
            />
            <span
              v-else-if="assignment.templateDocument"
              class="text-gray-90"
              v-text="t('A template document is already attached. Upload a new file to replace it.')"
            />
          </div>
        </div>

        <div v-else>
          <div class="flex items-center gap-2">
            <div class="flex-1">
              <BaseSelect
                v-model="assignment.form"
                :is-loading="isFormsLoading"
                :label="t('Form')"
                :options="forms"
                allow-clear
                id="homework-form-id"
                name="form"
                option-label="title"
                option-value="@id"
              />
            </div>

            <BaseButton
              v-if="selectedFormId"
              :label="t('Edit form')"
              icon="edit"
              type="black"
              @click="goToEditForm"
            />
            <BaseButton
              :label="t('Create new form')"
              icon="plus"
              type="black"
              @click="goToFormBuilder"
            />
          </div>
        </div>
      </div>
    </div>

    <!-- max-w-xs constrains only the date pickers (and thus their popups) -
         the rest of the form stays full width. mb-5 here reproduces .field's
         own mb-5 spacing (assets/css/scss/atoms/_form.scss), which is lost
         because BaseCalendar's inner .field becomes this wrapper's only
         child and matches .field's own "last:mb-0" rule. -->
    <div class="max-w-xs mb-5">
      <BaseCalendar
        v-model="assignment.opensOn"
        :error-text="v$.opensOn.$errors.map((e) => e.$message).join('<br>')"
        :is-invalid="v$.opensOn.$error"
        :label="t('Opens on')"
        :placeholder="' '"
        id="opens-on"
        show-time
      />
    </div>

    <div class="max-w-xs mb-5">
      <BaseCalendar
        v-model="assignment.deadline"
        :error-text="v$.deadline.$errors.map((e) => e.$message).join('<br>')"
        :is-invalid="v$.deadline.$error"
        :label="t('Deadline')"
        :placeholder="' '"
        id="deadline"
        show-time
      />
    </div>

    <BaseCheckbox
      id="allow-late-submission"
      v-model="assignment.allowLateSubmission"
      :label="t('Allow late submission')"
      name="allow_late_submission"
    />

    <BaseSelect
      v-model="assignment.evaluationMode"
      :is-invalid="v$.evaluationMode.$error"
      :label="t('Evaluation mode')"
      :message-text="v$.evaluationMode.$errors.map((e) => e.$message).join('<br>')"
      :options="evaluationModeOptions"
      id="evaluation-mode"
      name="evaluation_mode"
      option-label="label"
      option-value="value"
    />

    <BaseCheckbox
      id="add-to-gradebook"
      v-model="chkAddToGradebook"
      :label="t('Add to gradebook')"
      name="add_to_gradebook"
    />

    <div v-if="chkAddToGradebook">
      <BaseSelect
        v-model="assignment.gradebookCategoryId"
        :is-invalid="v$.gradebookCategoryId.$error"
        :label="t('Select assessment')"
        :message-text="v$.gradebookCategoryId.$errors.map((e) => e.$message).join('<br>')"
        :options="gradebookCategories"
        id="gradebook-category-id"
        name="gradebook_category_id"
        option-label="name"
        option-value="id"
      />
    </div>

    <BaseCheckbox
      id="add-to-calendar"
      v-model="assignment.addToCalendar"
      :label="t('Add to calendar')"
      name="add_to_calendar"
    />

    <div class="flex justify-end space-x-2 mt-2">
      <BaseButton
        :disabled="isFormLoading || isUploadingTemplate"
        :label="isEditMode ? t('Save changes') : t('Save')"
        icon="save"
        is-submit
        type="secondary"
      />
    </div>
  </form>
</template>

<script setup>
import BaseCalendar from "../basecomponents/BaseCalendar.vue"
import BaseInputText from "../basecomponents/BaseInputText.vue"
import BaseButton from "../basecomponents/BaseButton.vue"
import BaseCheckbox from "../basecomponents/BaseCheckbox.vue"
import BaseSelect from "../basecomponents/BaseSelect.vue"
import BaseRadioButtons from "../basecomponents/BaseRadioButtons.vue"
import BaseFileUpload from "../basecomponents/BaseFileUpload.vue"
import BaseTinyEditor from "../basecomponents/BaseTinyEditor.vue"
import useVuelidate from "@vuelidate/core"
import { computed, onMounted, reactive, ref, watchEffect } from "vue"
import { maxValue, required, requiredIf } from "@vuelidate/validators"
import { useI18n } from "vue-i18n"
import { useRoute, useRouter } from "vue-router"
import { getCourseContext } from "../../utils/courseContext"
import { useNotification } from "../../composables/notification"
import { RESOURCE_LINK_PUBLISHED } from "../../constants/entity/resourcelink"
import documentsService from "../../services/documents"
import homeworkFormService from "../../services/chomeworkform"
import {
  HOMEWORK_ASSIGNMENT_EVALUATION_NONE,
  HOMEWORK_ASSIGNMENT_EVALUATION_SCORE,
  HOMEWORK_ASSIGNMENT_EVALUATION_STATUS_ONLY,
  HOMEWORK_ASSIGNMENT_TYPE_FILE,
  HOMEWORK_ASSIGNMENT_TYPE_FORM,
} from "../../constants/entity/chomeworkassignment"

const props = defineProps({
  isFormLoading: {
    type: Boolean,
    default: false,
  },
  // When set, the form pre-fills from this existing assignment (as returned
  // by chomeworkassignment.js's getAssignment()) and onSubmit() builds a
  // payload for HomeworkAssignmentEdit.vue's updateAssignment() call instead
  // of HomeworkAssignmentCreate.vue's createAssignment(). Mirrors the
  // defaultAssignment prop name/shape already used by the reference
  // AssignmentsForm.vue (assets/vue/components/assignments/AssignmentsForm.vue).
  defaultAssignment: {
    type: Object,
    default: null,
  },
})

const emit = defineEmits(["submit"])

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const { cid, sid, gid } = getCourseContext()
const { showErrorNotification } = useNotification()

// Whole-course vs. session-scoped assignments are NOT chosen by the teacher in
// this form: like AssignmentsForm.vue (assets/vue/components/assignments/AssignmentsForm.vue),
// the course/session is derived server-side from the current navigation context
// (cid/sid already present in the URL and resolved into the Symfony session by
// CidReqListener). ResourceListener::normalizeSingleLinkContextFromSession()
// reads that session context to build the resourceLinkList, and
// CHomeworkAssignmentPostStateProcessor reads the session back off that same
// ResourceLink - so a teacher browsing a specific session's homework tool
// automatically creates a session-scoped assignment, and a teacher browsing the
// course-wide homework tool creates a course-wide one. No explicit picker needed.

const chkAddToGradebook = ref(false)
const formSubmitted = ref(false)
const isUploadingTemplate = ref(false)
const templateDocumentName = ref("")
const isFormsLoading = ref(false)

const forms = ref([])

// TODO: no dedicated gradebook-category picker component/endpoint exists yet
// anywhere in the codebase - AssignmentsForm.vue (the closest reference form)
// itself only stubs this with a single hardcoded "Default" option. Mirroring
// that exact stub here rather than inventing a new pattern; replace both once
// a real gradebook category listing endpoint exists.
const gradebookCategories = ref([{ name: "Default", id: 1 }])

const submissionTypeOptions = ref([
  { label: t("File"), value: HOMEWORK_ASSIGNMENT_TYPE_FILE },
  { label: t("Form"), value: HOMEWORK_ASSIGNMENT_TYPE_FORM },
])

const evaluationModeOptions = ref([
  { label: t("None"), value: HOMEWORK_ASSIGNMENT_EVALUATION_NONE },
  { label: t("Status only"), value: HOMEWORK_ASSIGNMENT_EVALUATION_STATUS_ONLY },
  { label: t("Score"), value: HOMEWORK_ASSIGNMENT_EVALUATION_SCORE },
])

const assignment = reactive({
  title: "",
  description: "",
  submissionType: HOMEWORK_ASSIGNMENT_TYPE_FILE,
  opensOn: null,
  deadline: new Date(),
  allowLateSubmission: false,
  templateDocument: null,
  form: null,
  evaluationMode: HOMEWORK_ASSIGNMENT_EVALUATION_NONE,
  gradebookCategoryId: Number(gradebookCategories.value?.[0]?.id ?? 1),
  addToCalendar: false,
})

// Whether this instance is editing an existing assignment (HomeworkAssignmentEdit.vue)
// rather than creating a new one (HomeworkAssignmentCreate.vue). Drives both
// the prefill below and onSubmit()'s payload shape.
const isEditMode = computed(() => !!props.defaultAssignment)

onMounted(async () => {
  isFormsLoading.value = true
  try {
    const { items } = await homeworkFormService.listForms()
    forms.value = items || []
  } catch (error) {
    showErrorNotification(error)
  } finally {
    isFormsLoading.value = false
  }
})

// Some backends serialize datetimes as "Y-m-d H:i:s" (no "T" separator),
// which `new Date(...)` cannot parse reliably in every browser. Mirrors the
// fromApiLocal() helper in assets/vue/views/assignments/AssignmentDetail.vue.
function fromApiLocal(str) {
  if (!str) return null
  const normalized = String(str).includes("T") ? String(str) : String(str).replace(" ", "T")
  return new Date(normalized)
}

// Pre-fills the form from the existing assignment when defaultAssignment is
// set (edit mode). All fields used here (title, description, submissionType,
// opensOn, deadline, allowLateSubmission, templateDocument, form,
// evaluationMode, addToGradebook, addToCalendar) are exposed under the
// homework_assignment:read serialization group - see
// src/CourseBundle/Entity/CHomeworkAssignment.php. gradebookCategoryId is
// deliberately NOT read here: it is write-only (homework_assignment:write
// only, no :read group), same limitation already documented above for
// gradebookCategories itself - only the stub "Default" (id 1) option exists,
// so re-selecting it on save is a no-op either way.
watchEffect(() => {
  const def = props.defaultAssignment
  if (!def) return

  assignment.title = def.title
  assignment.description = def.description
  assignment.submissionType = def.submissionType
  assignment.opensOn = fromApiLocal(def.opensOn)
  assignment.deadline = fromApiLocal(def.deadline) ?? new Date()
  assignment.allowLateSubmission = !!def.allowLateSubmission
  assignment.templateDocument = def.templateDocument ?? null
  assignment.form = def.form ?? null
  assignment.evaluationMode = def.evaluationMode
  assignment.addToCalendar = !!def.addToCalendar

  chkAddToGradebook.value = !!def.addToGradebook
})

async function onTemplateFileSelected(file) {
  isUploadingTemplate.value = true
  assignment.templateDocument = null
  templateDocumentName.value = ""

  try {
    const response = await documentsService.createWithFormData({
      uploadFile: file,
      title: file.name,
      filetype: "file",
      // Same resource node the assignment itself is created under (see
      // parentResourceNode in onSubmit below) - mirrors the precedent in
      // assets/vue/views/documents/DocumentForHtmlEditor.vue's handleUploadSelected()
      // (and assets/vue/views/documents/Create.vue), which post this exact
      // title/filetype/uploadFile/parentResourceNodeId/resourceLinkList shape
      // through the same createWithFormData action.
      parentResourceNodeId: Number(route.params.node),
      resourceLinkList: JSON.stringify([{ visibility: RESOURCE_LINK_PUBLISHED }]),
    })
    const document = await response.json()

    assignment.templateDocument = document["@id"]
    templateDocumentName.value = file.name
  } catch (error) {
    showErrorNotification(error)
  } finally {
    isUploadingTemplate.value = false
  }
}

function goToFormBuilder() {
  // returnTo tells HomeworkFormBuilder.vue where to navigate back to after
  // saving - the same convention used by the file-manager/document-picker
  // flows (see assets/vue/views/documents/DocumentsUpload.vue,
  // assets/vue/views/filemanager/Upload.vue).
  router.push({ name: "HomeworkFormBuilder", query: { cid, sid, gid, returnTo: "HomeworkAssignmentCreate" } })
}

// assignment.form holds the selected form's IRI (e.g. "/api/c_homework_forms/5"),
// same extraction convention used elsewhere for IRI -> numeric id (see
// assets/vue/components/assignments/TeacherAssignmentList.vue).
const selectedFormId = computed(() => {
  const iri = assignment.form
  if (!iri) return null
  const id = parseInt(String(iri).split("/").pop(), 10)
  return Number.isNaN(id) ? null : id
})

function goToEditForm() {
  if (!selectedFormId.value) return

  router.push({
    name: "HomeworkFormBuilder",
    params: { formId: selectedFormId.value },
    query: { cid, sid, gid, returnTo: "HomeworkAssignmentCreate" },
  })
}

const rules = computed(() => ({
  title: { required, $autoDirty: true },
  // maxValue's own required() check makes it a no-op while opensOn is unset
  // (optional field) - it only kicks in once the teacher picks a value,
  // mirroring AssignmentsForm.vue's reciprocal expiresOn/endsOn maxValue/minValue pattern.
  opensOn: {
    maxValue: maxValue(assignment.deadline),
    $autoDirty: true,
  },
  deadline: { required, $autoDirty: true },
  evaluationMode: { required },
  gradebookCategoryId: {
    requiredIf: requiredIf(() => chkAddToGradebook.value),
    $autoDirty: true,
  },
}))

const v$ = useVuelidate(rules, assignment)

async function onSubmit() {
  formSubmitted.value = true

  const valid = await v$.value.$validate()
  if (!valid) return

  const payload = {
    title: assignment.title,
    description: assignment.description,
    // parentResourceNodeId (not parentResourceNode): AbstractResource::$parentResourceNode
    // is only #[Groups]-exposed for a fixed set of write groups that does NOT
    // include homework_assignment:write, so the serializer silently drops that
    // key on denormalization. ResourceListener::prePersist() has a raw-request
    // fallback that reads "parentResourceNodeId" directly from the JSON body
    // instead - the same key AttendanceForm.vue and this file's own
    // createWithFormData template-document upload (onTemplateFileSelected
    // above) already use.
    parentResourceNodeId: Number(route.params.node),
    // Course/session context derived server-side from the gated session course
    // (see the comment above the "Whole-course vs. session-scoped" note).
    resourceLinkList: [{ visibility: RESOURCE_LINK_PUBLISHED }],
    submissionType: assignment.submissionType,
    deadline: assignment.deadline.toISOString(),
    allowLateSubmission: assignment.allowLateSubmission,
    evaluationMode: assignment.evaluationMode,
    addToGradebook: chkAddToGradebook.value,
    addToCalendar: assignment.addToCalendar,
  }

  if (assignment.opensOn) {
    payload.opensOn = assignment.opensOn.toISOString()
  }

  if (assignment.submissionType === HOMEWORK_ASSIGNMENT_TYPE_FILE) {
    if (assignment.templateDocument) {
      payload.templateDocument = assignment.templateDocument
    }
  } else if (assignment.form) {
    payload.form = assignment.form
  }

  if (chkAddToGradebook.value) {
    payload.gradebookCategoryId = Number(assignment.gradebookCategoryId)
  }

  emit("submit", payload)
}
</script>
