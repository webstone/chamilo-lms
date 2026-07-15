<template>
  <div class="field space-y-4">
    <BaseButton
      :label="t('Back')"
      icon="back"
      only-icon
      size="small"
      type="black"
      @click="goBack"
    />

    <h3 v-text="isEditMode ? t('Edit form') : t('New form')" />

    <BaseInputText
      id="homework-form-title"
      v-model="title"
      :error-text="t('Title is required')"
      :form-submitted="formSubmitted"
      :is-invalid="formSubmitted && !title.trim()"
      :label="t('Form title')"
    />

    <div
      v-if="isEditMode"
      class="border-2 border-danger bg-support-6 text-danger rounded-lg p-3 space-y-2"
    >
      <p
        class="font-semibold"
        v-text="t('Warning: editing this form is destructive')"
      />
      <p
        v-text="
          t(
            'Saving replaces ALL pages and fields on this form. This screen cannot check whether the form has already been used for submissions - if it has, the answers tied to its old fields will be permanently deleted. Only continue if you are certain no submissions depend on this form.',
          )
        "
      />
      <BaseCheckbox
        id="acknowledge-data-loss"
        v-model="acknowledgedDataLoss"
        :label="t('I understand and want to save anyway')"
        name="acknowledge_data_loss"
      />
    </div>

    <div
      v-if="isLoading"
      v-text="t('Loading...')"
    />

    <div
      v-else
      class="grid grid-cols-1 lg:grid-cols-3 gap-4"
    >
      <div class="lg:col-span-1 space-y-2">
        <h4 v-text="t('Pages')" />

        <Draggable
          v-model="pages"
          :animation="150"
          chosen-class="chosen"
          class="space-y-2"
          drag-class="dragging"
          ghost-class="ghosting"
          handle=".drag-handle"
          item-key="_key"
          tag="div"
        >
          <template #item="{ element: page }">
            <div
              :class="page._key === activePageKey ? 'border-primary bg-primary-10' : 'border-gray-25'"
              class="flex items-center gap-2 p-2 rounded-lg border"
            >
              <button
                :aria-label="t('Drag to reorder')"
                :title="t('Drag to reorder')"
                class="drag-handle cursor-move shrink-0"
                type="button"
              >
                <BaseIcon
                  icon="cursor-move"
                  size="small"
                />
              </button>

              <button
                class="flex-1 text-left truncate"
                type="button"
                @click="activePageKey = page._key"
              >
                {{ page.title.trim() || t("Untitled page") }}
              </button>

              <span
                class="text-tiny text-gray-50 shrink-0"
                v-text="page.fields.length"
              />

              <button
                :aria-label="t('Remove page')"
                :title="t('Remove page')"
                class="shrink-0"
                type="button"
                @click="removePage(page._key)"
              >
                <BaseIcon
                  icon="trash"
                  size="small"
                />
              </button>
            </div>
          </template>
        </Draggable>

        <BaseButton
          :label="t('Add page')"
          icon="plus"
          type="black"
          @click="addPage()"
        />
      </div>

      <div class="lg:col-span-2 space-y-4">
        <template v-if="activePage">
          <BaseInputText
            :id="`page-title-${activePage._key}`"
            v-model="activePage.title"
            :error-text="t('Page title is required')"
            :form-submitted="formSubmitted"
            :is-invalid="formSubmitted && !activePage.title.trim()"
            :label="t('Page title')"
          />

          <div>
            <h4 v-text="t('Add a field')" />
            <div class="flex flex-wrap gap-2 mt-2">
              <button
                v-for="fieldType in fieldTypes"
                :key="fieldType.type"
                class="flex items-center gap-1 px-3 py-2 rounded-lg border border-gray-25 hover:bg-gray-15"
                type="button"
                @click="addField(fieldType.type)"
              >
                <BaseIcon
                  :icon="fieldType.icon"
                  size="small"
                />
                <span v-text="fieldType.label" />
              </button>
            </div>
          </div>

          <div>
            <h4 v-text="t('Fields')" />

            <p
              v-if="!activePage.fields.length"
              class="text-gray-50"
              v-text="t('No fields yet. Add one from the palette above.')"
            />

            <Draggable
              v-model="activePage.fields"
              :animation="150"
              chosen-class="chosen"
              class="space-y-3 mt-2"
              drag-class="dragging"
              ghost-class="ghosting"
              handle=".drag-handle"
              item-key="_key"
              tag="div"
            >
              <template #item="{ element: field }">
                <BaseCard>
                  <div class="flex items-start gap-2">
                    <button
                      :aria-label="t('Drag to reorder')"
                      :title="t('Drag to reorder')"
                      class="drag-handle cursor-move shrink-0 mt-2"
                      type="button"
                    >
                      <BaseIcon
                        icon="cursor-move"
                        size="small"
                      />
                    </button>

                    <div class="flex-1 space-y-2">
                      <div class="flex items-center justify-between gap-2">
                        <span
                          class="text-tiny text-gray-50"
                          v-text="fieldTypeLabel(field.type)"
                        />
                        <button
                          :aria-label="t('Remove field')"
                          :title="t('Remove field')"
                          type="button"
                          @click="removeField(field._key)"
                        >
                          <BaseIcon
                            icon="trash"
                            size="small"
                          />
                        </button>
                      </div>

                      <BaseInputText
                        :id="`field-label-${field._key}`"
                        v-model="field.label"
                        :error-text="t('Field label is required')"
                        :form-submitted="formSubmitted"
                        :is-invalid="formSubmitted && !field.label.trim()"
                        :label="t('Label')"
                      />

                      <BaseTextArea
                        :id="`field-help-${field._key}`"
                        v-model="field.helpText"
                        :label="t('Help text')"
                      />

                      <BaseCheckbox
                        :id="`field-required-${field._key}`"
                        v-model="field.required"
                        :label="t('Required')"
                        :name="`field-required-${field._key}`"
                      />

                      <BaseInputNumber
                        v-if="field.type === HOMEWORK_FORM_FIELD_TYPE_TEXTAREA"
                        :id="`field-rows-${field._key}`"
                        v-model="field.rows"
                        :label="t('Visible rows')"
                        :max="30"
                        :min="2"
                      />

                      <div
                        v-if="needsOptions(field.type)"
                        class="space-y-1"
                      >
                        <span
                          class="text-tiny text-gray-50"
                          v-text="t('Options')"
                        />

                        <div
                          v-for="(option, optionIndex) in field.options"
                          :key="optionIndex"
                          class="flex items-center gap-2"
                        >
                          <BaseInputText
                            :id="`field-option-${field._key}-${optionIndex}`"
                            v-model="field.options[optionIndex]"
                            :label="t('Option {0}', [optionIndex + 1])"
                          />
                          <button
                            :aria-label="t('Remove option')"
                            :title="t('Remove option')"
                            type="button"
                            @click="removeOption(field, optionIndex)"
                          >
                            <BaseIcon
                              icon="trash"
                              size="small"
                            />
                          </button>
                        </div>

                        <p
                          v-if="formSubmitted && !hasValidOptions(field)"
                          class="text-danger text-tiny"
                          v-text="t('Add at least one option')"
                        />

                        <BaseButton
                          :label="t('Add option')"
                          icon="plus"
                          size="small"
                          type="black"
                          @click="addOption(field)"
                        />
                      </div>
                    </div>
                  </div>
                </BaseCard>
              </template>
            </Draggable>
          </div>
        </template>

        <p
          v-else
          class="text-gray-50"
          v-text="t('Add a page to start building the form.')"
        />
      </div>
    </div>

    <div class="flex justify-end gap-2">
      <BaseButton
        :label="t('Cancel')"
        icon="close"
        type="black"
        @click="goBack"
      />
      <BaseButton
        :disabled="isSaving || isLoading || (isEditMode && !acknowledgedDataLoss)"
        :is-loading="isSaving"
        :label="t('Save')"
        icon="save"
        type="secondary"
        @click="onSubmit"
      />
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from "vue"
import { useRoute, useRouter } from "vue-router"
import { useI18n } from "vue-i18n"
import Draggable from "vuedraggable"
import BaseButton from "../../components/basecomponents/BaseButton.vue"
import BaseCard from "../../components/basecomponents/BaseCard.vue"
import BaseCheckbox from "../../components/basecomponents/BaseCheckbox.vue"
import BaseIcon from "../../components/basecomponents/BaseIcon.vue"
import BaseInputNumber from "../../components/basecomponents/BaseInputNumber.vue"
import BaseInputText from "../../components/basecomponents/BaseInputText.vue"
import BaseTextArea from "../../components/basecomponents/BaseTextArea.vue"
import homeworkFormService from "../../services/chomeworkform"
import { useNotification } from "../../composables/notification"
import { getCourseContext } from "../../utils/courseContext"
import { RESOURCE_LINK_PUBLISHED } from "../../constants/entity/resourcelink"
import {
  HOMEWORK_FORM_FIELD_TYPE_CHECKBOX,
  HOMEWORK_FORM_FIELD_TYPE_DATE,
  HOMEWORK_FORM_FIELD_TYPE_FILE,
  HOMEWORK_FORM_FIELD_TYPE_NUMBER,
  HOMEWORK_FORM_FIELD_TYPE_SELECT,
  HOMEWORK_FORM_FIELD_TYPE_TEXT,
  HOMEWORK_FORM_FIELD_TYPE_TEXTAREA,
} from "../../constants/entity/chomeworkformfield"

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const { cid, sid, gid } = getCourseContext()
const { showSuccessNotification, showErrorNotification } = useNotification()

// The builder is reached from HomeworkAssignmentForm.vue's "Create new form"
// button (assets/vue/components/homework/HomeworkAssignmentForm.vue,
// goToFormBuilder()), which passes back a `returnTo` route name - the same
// convention already used by the file-manager/document-picker flows (see
// assets/vue/views/documents/DocumentsUpload.vue, assets/vue/views/filemanager/Upload.vue).
// Falling back to HomeworkAssignmentCreate keeps the round-trip coherent even
// if the builder is ever reached from a route that forgets to set it.
//
// Scoped to an explicit allow-list (not router.hasRoute() against the whole
// route table): navigation always goes through router.push({name}) so this
// was never an open redirect, but there's no reason for this homework-module
// screen to be able to bounce to an arbitrary named route elsewhere in the app.
const RETURN_ROUTE_ALLOW_LIST = ["HomeworkAssignmentCreate", "HomeworkList"]

const returnRouteName = computed(() => {
  const candidate = route.query.returnTo
  return RETURN_ROUTE_ALLOW_LIST.includes(candidate) ? candidate : "HomeworkAssignmentCreate"
})

const formId = computed(() => (route.params.formId ? Number(route.params.formId) : null))
const isEditMode = computed(() => formId.value !== null)

const fieldTypes = ref([
  { type: HOMEWORK_FORM_FIELD_TYPE_TEXT, label: t("Text"), icon: "file-text" },
  { type: HOMEWORK_FORM_FIELD_TYPE_TEXTAREA, label: t("Textarea"), icon: "comment" },
  { type: HOMEWORK_FORM_FIELD_TYPE_NUMBER, label: t("Number"), icon: "table" },
  { type: HOMEWORK_FORM_FIELD_TYPE_DATE, label: t("Date"), icon: "calendar-plus" },
  { type: HOMEWORK_FORM_FIELD_TYPE_SELECT, label: t("Select"), icon: "list" },
  { type: HOMEWORK_FORM_FIELD_TYPE_CHECKBOX, label: t("Checkbox"), icon: "multiple-marked" },
  { type: HOMEWORK_FORM_FIELD_TYPE_FILE, label: t("File"), icon: "file-upload" },
])

const OPTION_FIELD_TYPES = [HOMEWORK_FORM_FIELD_TYPE_SELECT, HOMEWORK_FORM_FIELD_TYPE_CHECKBOX]

// Matches HomeworkSubmit.vue's own DEFAULT_TEXTAREA_ROWS fallback (used when
// an older field has no configured rows value yet).
const DEFAULT_TEXTAREA_ROWS = 4

function needsOptions(type) {
  return OPTION_FIELD_TYPES.includes(type)
}

function needsRows(type) {
  return HOMEWORK_FORM_FIELD_TYPE_TEXTAREA === type
}

function fieldTypeLabel(type) {
  return fieldTypes.value.find((fieldType) => fieldType.type === type)?.label ?? ""
}

let uidCounter = 0
function nextUid() {
  uidCounter += 1
  return `local-${uidCounter}`
}

function createField(type) {
  return {
    _key: nextUid(),
    type,
    label: "",
    helpText: "",
    required: false,
    options: needsOptions(type) ? [""] : [],
    rows: needsRows(type) ? DEFAULT_TEXTAREA_ROWS : null,
  }
}

function createPage() {
  return {
    _key: nextUid(),
    title: t("Page {0}", [pages.value.length + 1]),
    fields: [],
  }
}

function hasValidOptions(field) {
  return field.options.some((option) => option.trim())
}

const title = ref("")
const pages = ref([])
const activePageKey = ref(null)
const activePage = computed(() => pages.value.find((page) => page._key === activePageKey.value) ?? null)

// Editing an existing form always replaces its whole pages/fields tree on save
// (see buildPayload()/onSubmit() below): CHomeworkForm::$pages and
// CHomeworkFormPage::$fields are both `cascade: ['persist','remove'],
// orphanRemoval: true` with no id-based merge anywhere in the app, and
// CHomeworkSubmissionAnswer::$field is `onDelete: 'CASCADE'` - so a save here
// orphan-removes the old page/field rows and cascade-deletes any submission
// answers tied to them. There is no cheap existence-check available from the
// current API surface (CHomeworkAssignment has no filter on its `form`
// relation, and CHomeworkSubmission only filters by `assignment.iid`, so
// reliably answering "does this form have submissions" would mean fetching
// every course-wide assignment and cross-checking each one - not a real
// check, and worth a dedicated backend endpoint rather than a frontend
// workaround). Blocking edits outright (option a) isn't possible without that
// same backend work, so this requires an explicit, unmissable acknowledgement
// before every edit-save instead (option b - see the warning banner in the
// template and the guard in onSubmit()).
const acknowledgedDataLoss = ref(false)

const isLoading = ref(false)
const isSaving = ref(false)
const formSubmitted = ref(false)

function addPage() {
  const page = createPage()
  pages.value.push(page)
  activePageKey.value = page._key
}

function removePage(pageKey) {
  const label = pages.value.find((page) => page._key === pageKey)?.title.trim() || t("this page")
  if (!confirm(t("Are you sure you want to remove {0}?", [label]))) {
    return
  }

  pages.value = pages.value.filter((page) => page._key !== pageKey)

  if (activePageKey.value === pageKey) {
    activePageKey.value = pages.value[0]?._key ?? null
  }
}

function addField(type) {
  if (!activePage.value) {
    return
  }

  activePage.value.fields.push(createField(type))
}

function removeField(fieldKey) {
  if (!activePage.value) {
    return
  }

  activePage.value.fields = activePage.value.fields.filter((field) => field._key !== fieldKey)
}

function addOption(field) {
  field.options.push("")
}

function removeOption(field, optionIndex) {
  // Always actually remove the row (the button is labelled "Remove option",
  // so it must remove rather than silently blank the last one). An empty
  // options list is caught by validate()/hasValidOptions() before save.
  field.options.splice(optionIndex, 1)
}

onMounted(async () => {
  if (!isEditMode.value) {
    addPage()
    return
  }

  isLoading.value = true
  try {
    const data = await homeworkFormService.getForm(formId.value)
    title.value = data.title || ""
    pages.value = (data.pages || []).map((page) => ({
      _key: nextUid(),
      title: page.title || "",
      fields: (page.fields || []).map((field) => ({
        _key: nextUid(),
        type: field.type,
        label: field.label || "",
        helpText: field.helpText || "",
        required: !!field.required,
        options:
          Array.isArray(field.options) && field.options.length
            ? [...field.options]
            : needsOptions(field.type)
              ? [""]
              : [],
        rows: needsRows(field.type) ? (field.rows ?? DEFAULT_TEXTAREA_ROWS) : null,
      })),
    }))
    activePageKey.value = pages.value[0]?._key ?? null
  } catch (error) {
    showErrorNotification(error)
  } finally {
    isLoading.value = false
  }
})

function validate() {
  if (!title.value.trim()) {
    return false
  }

  if (!pages.value.length) {
    return false
  }

  return pages.value.every((page) => {
    if (!page.title.trim() || !page.fields.length) {
      return false
    }

    return page.fields.every((field) => {
      if (!field.label.trim()) {
        return false
      }

      return !needsOptions(field.type) || hasValidOptions(field)
    })
  })
}

function buildPayload() {
  const payload = {
    title: title.value.trim(),
    pages: pages.value.map((page, pageIndex) => ({
      title: page.title.trim(),
      sortOrder: pageIndex,
      fields: page.fields.map((field, fieldIndex) => ({
        type: field.type,
        label: field.label.trim(),
        helpText: field.helpText.trim() ? field.helpText.trim() : null,
        required: field.required,
        options: needsOptions(field.type) ? field.options.map((option) => option.trim()).filter(Boolean) : null,
        rows: needsRows(field.type) ? field.rows : null,
        sortOrder: fieldIndex,
      })),
    })),
  }

  if (!isEditMode.value) {
    // Only sent on create: PUT must not re-parent or reset the resource links
    // of an existing form (mirrors assets/vue/components/attendance/AttendanceForm.vue's
    // "Only send these on create" guard).
    //
    // parentResourceNodeId (not parentResourceNode): AbstractResource::$parentResourceNode
    // is only #[Groups]-exposed for a fixed set of write groups that does NOT
    // include homework_form:write, so the serializer silently drops that key
    // on denormalization. ResourceListener::prePersist() has a raw-request
    // fallback that reads "parentResourceNodeId" directly from the JSON body
    // instead - the same key AttendanceForm.vue and the createWithFormData
    // template-document upload elsewhere in this module both already use.
    payload.parentResourceNodeId = Number(route.params.node)
    payload.resourceLinkList = [{ visibility: RESOURCE_LINK_PUBLISHED }]
  }

  return payload
}

async function onSubmit() {
  formSubmitted.value = true

  if (!validate()) {
    showErrorNotification(t("Please complete all required fields before saving."))
    return
  }

  // Defense in depth: the Save button is already disabled until this is
  // checked, but guard here too in case that ever changes.
  if (isEditMode.value && !acknowledgedDataLoss.value) {
    showErrorNotification(t("Please confirm the data-loss warning before saving."))
    return
  }

  const payload = buildPayload()

  isSaving.value = true
  try {
    if (isEditMode.value) {
      await homeworkFormService.updateForm(formId.value, payload)
    } else {
      await homeworkFormService.createForm(payload)
    }

    showSuccessNotification(t("Form saved"))
    goBack()
  } catch (error) {
    showErrorNotification(error)
  } finally {
    isSaving.value = false
  }
}

function goBack() {
  router.push({
    name: returnRouteName.value,
    params: { node: route.params.node },
    query: { cid, sid, gid },
  })
}
</script>
