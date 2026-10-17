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

      <div
        v-if="assignment.allowMultipleSubmissions"
        class="space-y-3"
      >
        <h2 class="text-lg font-semibold">{{ t("Progress overview") }}</h2>

        <BaseTable
          :values="progressRows"
          data-key="userId"
        >
          <Column :header="t('Student')">
            <template #body="{ data }">
              {{ data.userFullName }}
            </template>
          </Column>

          <Column :header="t('Progress')">
            <template #body="{ data }">
              {{ data.onTimeCount }}/{{ data.requiredCount }}
              <span v-if="data.totalCount > data.onTimeCount">
                ({{ data.totalCount }} {{ t("total, incl. late") }})
              </span>
            </template>
          </Column>

          <Column :header="t('Status')">
            <template #body="{ data }">
              <span :class="data.onTimeCount >= data.requiredCount ? 'text-primary' : 'text-warning'">
                {{ data.onTimeCount >= data.requiredCount ? t("Reached") : t("Not reached") }}
              </span>
            </template>
          </Column>

          <Column :header="t('Final evaluation')">
            <template #body="{ data }">
              <BaseButton
                :label="evaluationForUserId(data.userId) ? t('Edit final evaluation') : t('Add final evaluation')"
                icon="check"
                size="small"
                type="primary-text"
                @click="selectProgressRow(data)"
              />
            </template>
          </Column>
        </BaseTable>

        <div
          v-if="null !== selectedProgressUserId"
          class="border border-gray-25 bg-gray-15 rounded-lg p-4 space-y-4"
        >
          <h3 class="text-lg font-semibold">{{ t("Final evaluation") }}</h3>

          <BaseInputNumber
            v-if="assignment.evaluationMode === HOMEWORK_ASSIGNMENT_EVALUATION_SCORE"
            id="final-evaluation-score"
            v-model="evaluationScoreInput"
            :label="t('Score')"
            :min="0"
          />

          <BaseTinyEditor
            v-if="assignment.evaluationMode !== HOMEWORK_ASSIGNMENT_EVALUATION_NONE"
            v-model="evaluationFeedbackInput"
            editor-id="final-evaluation-feedback"
            :editor-config="feedbackEditorConfig"
            :full-page="false"
            :title="t('Feedback')"
            :use-file-manager="false"
          />

          <div class="flex justify-end">
            <BaseButton
              :is-loading="isSavingEvaluation"
              :label="t('Save')"
              icon="save"
              type="primary"
              @click="saveFinalEvaluation"
            />
          </div>
        </div>

        <hr />
        <h2 class="text-lg font-semibold">{{ t("Individual reports") }}</h2>
      </div>

      <div class="flex items-center gap-2">
        <BaseSelect
          id="homework-status-filter"
          v-model="statusFilter"
          :label="t('Status')"
          :options="statusFilterOptions"
          style="min-width: 200px"
        />

        <BaseSelect
          id="homework-student-filter"
          v-model="studentFilter"
          :label="t('Student')"
          :options="studentFilterOptions"
          style="min-width: 200px"
        />
      </div>

      <BaseTable
        v-model:rows="loadParams.itemsPerPage"
        v-model:sort-order="sortOrderValue"
        :is-loading="isLoadingSubmissions"
        :total-items="totalRecords"
        :values="submissions"
        data-key="@id"
        lazy
        sort-field="submittedAt"
        sort-mode="single"
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

        <Column
          :header="t('Submitted')"
          :sortable="true"
          field="submittedAt"
        >
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
            <div class="flex gap-2">
              <BaseButton
                icon="check"
                size="small"
                type="black"
                :label="t('Review and rate')"
                @click="goToGradeSubmission(data)"
              />
              <BaseButton
                v-if="canDownload(data)"
                icon="download"
                size="small"
                type="black"
                :label="t('Download')"
                @click="downloadSubmission(data)"
              />
            </div>
          </template>
        </Column>
      </BaseTable>

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
import homeworkAssignmentProgressService from "../../services/homeworkassignmentprogress"
import homeworkAssignmentEvaluationService from "../../services/chomeworkassignmentevaluation"
import userService from "../../services/userService"
import { useFormatDate } from "../../composables/formatDate"
import { useNotification } from "../../composables/notification"
import { getCourseContext } from "../../utils/courseContext"
import { buildSubmissionExportUrl } from "../../utils/homeworkSubmissionExport"
import { RESOURCE_LINK_PUBLISHED } from "../../constants/entity/resourcelink"
import BaseButton from "../../components/basecomponents/BaseButton.vue"
import BaseTable from "../../components/basecomponents/BaseTable.vue"
import BaseSelect from "../../components/basecomponents/BaseSelect.vue"
import BaseInputNumber from "../../components/basecomponents/BaseInputNumber.vue"
import BaseTinyEditor from "../../components/basecomponents/BaseTinyEditor.vue"
import {
  HOMEWORK_ASSIGNMENT_EVALUATION_NONE,
  HOMEWORK_ASSIGNMENT_EVALUATION_SCORE,
} from "../../constants/entity/chomeworkassignment"
import {
  HOMEWORK_SUBMISSION_STATUS_DRAFT,
  HOMEWORK_SUBMISSION_STATUS_SUBMITTED,
  HOMEWORK_SUBMISSION_STATUS_LATE,
} from "../../constants/entity/chomeworksubmission"

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const { abbreviatedDatetime } = useFormatDate()
const { showSuccessNotification, showErrorNotification } = useNotification()

const assignmentId = Number(route.params.assignmentId)

const isLoading = ref(true)
const isLoadingSubmissions = ref(false)

const assignment = ref(null)
const submissions = ref([])
const totalRecords = ref(0)

// Server-side pagination state (see loadSubmissions()) - a course can have
// far more than API Platform's default 30-item page size worth of
// submissions, so the list must never be fetched/filtered as if it were
// complete without paging through it. Initialized from the URL (see
// syncFiltersToUrl() below) so returning from HomeworkGradeSubmission via the
// browser Back button restores exactly where the teacher left off, instead
// of resetting to page 1 - BaseTable only applies its own platform-default
// itemsPerPage when this starts out null (see BaseTable.vue's
// `if (rows.value == null) rows.value = initialRows()`), so a real value
// from the URL is respected, not overridden.
const loadParams = reactive({
  page: route.query.page ? Number(route.query.page) : 1,
  itemsPerPage: route.query.itemsPerPage ? Number(route.query.itemsPerPage) : null,
})

// "" = all statuses; otherwise one of HOMEWORK_SUBMISSION_STATUS_*. Applied
// server-side (CHomeworkSubmission's "status" SearchFilter), not client-side,
// so it always reflects the full result set rather than just the current page.
// Initialized from the URL for the same reason as loadParams above.
const statusFilter = ref(route.query.status ? Number(route.query.status) : "")

// PrimeVue numeric convention: -1 = desc (default: most recent first), 1 = asc.
const sortOrderValue = ref("asc" === route.query.sortOrder ? 1 : -1)

// "" = all students; otherwise a user IRI. Applied server-side (the new
// "user" SearchFilter). Options are sourced from a dedicated unpaginated
// fetch (studentFilterOptions/loadStudentFilterOptions below), NOT from the
// paginated `submissions` used to drive the table - that list only ever
// holds one page's worth of rows, which would silently omit students on
// other pages.
const studentFilter = ref("string" === typeof route.query.user ? route.query.user : "")
const studentFilterOptions = ref([{ label: t("All"), value: "" }])

// Multi-report assignments only (see the "Progress overview" block).
const progressRows = ref([])
// userIri -> CHomeworkAssignmentEvaluation, populated by loadProgress().
const evaluationsByUserIri = reactive({})
const selectedProgressUserId = ref(null)
const evaluationScoreInput = ref(null)
const evaluationFeedbackInput = ref("")

// Compact Tiny config for a short feedback field (no file manager, no
// full-page toolbar needed) - mirrors DropboxFeedbackDialog.vue's tinyConfig.
const feedbackEditorConfig = {
  height: 200,
  menubar: false,
  statusbar: false,
  toolbar: "bold italic underline | bullist numlist | link unlink | removeformat",
}
const isSavingEvaluation = ref(false)

// userIri -> display name. Plain reactive cache, same pattern as
// HomeworkSubmit.vue's docMeta/answersByFieldIri.
const userNames = reactive({})

const statusFilterOptions = computed(() => [
  { label: t("All"), value: "" },
  { label: t("Draft"), value: HOMEWORK_SUBMISSION_STATUS_DRAFT },
  { label: t("Submitted"), value: HOMEWORK_SUBMISSION_STATUS_SUBMITTED },
  { label: t("Late"), value: HOMEWORK_SUBMISSION_STATUS_LATE },
])

// Downloading a draft has little value - it may have no (complete) content
// yet, same reasoning HomeworkGradeSubmission.vue's own content rendering
// already relies on submission status for.
function canDownload(submission) {
  return HOMEWORK_SUBMISSION_STATUS_SUBMITTED === submission.status || HOMEWORK_SUBMISSION_STATUS_LATE === submission.status
}

// A plain click handler via window.open(), NOT BaseButton's :to-url prop:
// :to-url renders through PrimeVue's <Button as-child>, whose "asChild"
// template branch is a bare `<slot :class="..." :a11yAttrs="..." />` with no
// `v-bind="$attrs"` at all (see node_modules/primevue/button/Button.vue) -
// any extra attribute placed on BaseButton (target/rel/download) is silently
// dropped before it ever reaches the real <a> BaseAppLink renders. Confirmed
// live: none of target="_blank", rel="noopener", or the download attribute
// had any effect through that path. window.open() sidesteps the whole chain.
function downloadSubmission(submission) {
  window.open(buildSubmissionExportUrl(submission), "_blank", "noopener")
}

// Keeps status/user/sortOrder/page/itemsPerPage in this page's own URL, so:
// (1) the browser Back button from HomeworkGradeSubmission restores exactly
// this filtered/sorted/paginated view instead of resetting it (there was no
// real navigation away before this page existed, so this never mattered
// until now), and (2) HomeworkGradeSubmission's own Previous/Next can be fed
// the exact same filter/sort via route.query, forwarded unchanged.
function syncFiltersToUrl() {
  router.replace({
    query: {
      ...route.query,
      status: statusFilter.value || undefined,
      user: studentFilter.value || undefined,
      sortOrder: -1 === sortOrderValue.value ? undefined : "asc",
      page: 1 === loadParams.page ? undefined : loadParams.page,
      itemsPerPage: loadParams.itemsPerPage || undefined,
    },
  })
}

function goToGradeSubmission(submission) {
  router.push({
    name: "HomeworkGradeSubmission",
    params: {
      node: route.params.node,
      assignmentId,
      submissionId: extractIidFromIri(submission["@id"]),
    },
    query: route.query,
  })
}

onMounted(async () => {
  try {
    assignment.value = await homeworkAssignmentService.getAssignment(assignmentId)
    await loadStudentFilterOptions()

    if (assignment.value.allowMultipleSubmissions) {
      await loadProgress()
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
    syncFiltersToUrl()
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

// A sort change must also restart pagination from page 1, for the same
// reason as the status filter above.
watch(sortOrderValue, () => {
  loadParams.page = 1
  if (loadParams.itemsPerPage) loadSubmissions()
})

// Same reasoning as the statusFilter watcher above: reusing the current page
// against a differently-filtered result set would show the wrong slice.
watch(studentFilter, () => {
  loadParams.page = 1
  if (loadParams.itemsPerPage) loadSubmissions()
})

async function loadSubmissions() {
  isLoadingSubmissions.value = true
  try {
    // Server-side pagination + server-side status/user filters
    // (CHomeworkSubmission's "status"/"user" SearchFilters) -
    // CHomeworkSubmissionExtension (backend) already scopes the underlying
    // query to "course-wide teacher" via HomeworkCourseTeacherChecker, so no
    // client-side session filtering is added here (it would defeat the
    // module's cross-session grading requirement); only pagination/status/user
    // are handled server-side.
    const { items, totalItems } = await homeworkSubmissionService.listSubmissionsForAssignment(assignmentId, {
      page: loadParams.page,
      itemsPerPage: loadParams.itemsPerPage,
      status: statusFilter.value,
      sortOrder: -1 === sortOrderValue.value ? "desc" : "asc",
      user: studentFilter.value,
    })
    submissions.value = items
    totalRecords.value = totalItems
    await Promise.all(items.map((item) => loadUserName(item.user)))
  } finally {
    isLoadingSubmissions.value = false
  }
}

// Dedicated, unpaginated (itemsPerPage: 9999) fetch used ONLY to populate the
// student filter dropdown's options - kept fully separate from
// submissions/loadSubmissions() (the paginated data driving the table) so
// neither interferes with the other: this fetch must see every student
// across every page, while the table must stay paginated.
// Known scalability tradeoff, accepted deliberately (see
// docs/superpowers/specs/2026-09-28-homework-round4-design.md, section 5):
// fetches the assignment's FULL unpaginated submission collection just to
// build the distinct-student dropdown, then resolves each distinct student's
// name individually (no batch-by-IRI endpoint exists in userService.js).
// For an allowMultipleSubmissions assignment with hundreds of rows across
// many students, this is a real network cost on every mount of this view -
// revisit with a dedicated backend "distinct students for this assignment"
// endpoint (or partial serialization) if this becomes slow in practice.
async function loadStudentFilterOptions() {
  try {
    const { items } = await homeworkSubmissionService.listSubmissionsForAssignment(assignmentId, { itemsPerPage: 9999 })
    await Promise.all(items.map((item) => loadUserName(item.user)))

    const seen = new Set()
    const options = [{ label: t("All"), value: "" }]
    items.forEach((item) => {
      const iri = resolveIri(item.user)
      if (!iri || seen.has(iri)) return
      seen.add(iri)
      options.push({ label: userNames[iri] || iri, value: iri })
    })
    studentFilterOptions.value = options
  } catch (error) {
    showErrorNotification(error)
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

// Multi-report assignments: per-student on-time/total report counts (the
// teacher-facing progress table) plus the existing final evaluations, so the
// "Edit"/"Add final evaluation" button label reflects whether one already
// exists.
async function loadProgress() {
  const { items: progress } = await homeworkAssignmentProgressService.getProgress(assignmentId)
  progressRows.value = progress

  const { items: evaluations } = await homeworkAssignmentEvaluationService.listEvaluationsForAssignment(assignmentId)
  Object.keys(evaluationsByUserIri).forEach((key) => delete evaluationsByUserIri[key])
  evaluations.forEach((evaluation) => {
    evaluationsByUserIri[evaluation.user] = evaluation
  })
}

function evaluationForUserId(userId) {
  return evaluationsByUserIri[`/api/users/${userId}`] || null
}

function selectProgressRow(row) {
  selectedProgressUserId.value = row.userId
  const existing = evaluationForUserId(row.userId)
  evaluationScoreInput.value = existing?.score ?? null
  evaluationFeedbackInput.value = existing?.feedback ?? ""
}

async function saveFinalEvaluation() {
  if (null === selectedProgressUserId.value || isSavingEvaluation.value) return

  isSavingEvaluation.value = true
  try {
    const userIri = `/api/users/${selectedProgressUserId.value}`
    const existing = evaluationsByUserIri[userIri]
    let result

    if (existing) {
      result = await homeworkAssignmentEvaluationService.updateEvaluation(
        extractIidFromIri(existing["@id"]),
        evaluationScoreInput.value,
        evaluationFeedbackInput.value,
      )
    } else {
      const { cid, sid } = getCourseContext()
      // Deliberately a USER-scoped link (uid = the EVALUATED student, not the
      // teacher saving this) - see chomeworkassignmentevaluation.js's
      // createEvaluation() docblock and HomeworkSubmit.vue's
      // loadOrCreateSubmission() for why this exact resourceLinkList shape
      // is required for privacy scoping.
      result = await homeworkAssignmentEvaluationService.createEvaluation({
        assignment: assignment.value["@id"],
        user: userIri,
        score: evaluationScoreInput.value,
        feedback: evaluationFeedbackInput.value,
        parentResourceNodeId: Number(route.params.node),
        resourceLinkList: [
          {
            cid,
            ...(sid ? { sid } : {}),
            visibility: RESOURCE_LINK_PUBLISHED,
            uid: selectedProgressUserId.value,
          },
        ],
      })
    }

    evaluationsByUserIri[userIri] = result
    showSuccessNotification(t("Final evaluation saved"))
  } catch (error) {
    showErrorNotification(error)
  } finally {
    isSavingEvaluation.value = false
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
