<template>
  <div class="field space-y-4">
    <div class="flex items-center justify-between">
      <h3 v-text="t('Homework')" />
      <BaseButton
        v-if="isTeacher"
        icon="plus"
        type="primary"
        :label="t('Create homework assignment')"
        @click="goToCreate"
      />
    </div>

    <!-- Same EmptyState convention as LpList.vue/GlossaryList.vue/LinksList.vue
         (icon + summary + detail) instead of BaseTable's own bare
         text-for-empty string - no CTA button here, unlike those, since this
         module already has a persistent "Create homework assignment" button
         in the header above, so a second one here would just duplicate it. -->
    <EmptyState
      v-if="!isLoading && 0 === totalItems"
      :detail="t('Homework assignments created for this course will appear here.')"
      :summary="t('No homework assignments have been created for this course yet.')"
      icon="file-text"
    />

    <BaseTable
      v-else
      data-key="@id"
      :is-loading="isLoading"
      :total-items="totalItems"
      :values="assignments"
      v-model:multi-sort-meta="sortFields"
      v-model:rows="loadParams.itemsPerPage"
      lazy
      removable-sort
      sort-mode="multiple"
      @sort="onSort"
      @page="onPage"
    >
      <Column
        :header="t('Title')"
        :sortable="true"
        field="title"
      />

      <Column
        :header="t('Opens on')"
        :sortable="true"
        field="opensOn"
      >
        <template #body="slotProps">
          {{ formatDeadline(slotProps.data.opensOn) }}
        </template>
      </Column>

      <Column
        :header="t('Deadline')"
        :sortable="true"
        field="deadline"
      >
        <template #body="slotProps">
          {{ formatDeadline(slotProps.data.deadline) }}
        </template>
      </Column>

      <Column :header="t('Status')">
        <template #body="slotProps">
          <span v-if="isTeacher">{{ teacherStatusLabel(slotProps.data) }}</span>
          <span v-else>{{ studentStatusLabel(slotProps.data) }}</span>
        </template>
      </Column>

      <Column
        :header="t('Actions')"
        body-class="space-x-2"
      >
        <template #body="slotProps">
          <template v-if="isTeacher">
            <BaseButton
              icon="check"
              size="small"
              type="black"
              :label="t('Review')"
              @click="() => goToCorrect(slotProps.data)"
            />
            <BaseButton
              icon="edit"
              only-icon
              size="small"
              type="secondary"
              :label="t('Edit')"
              @click="() => goToEdit(slotProps.data)"
            />
            <span
              :title="
                canDeleteAssignment(slotProps.data) ? '' : t('This assignment has submitted work and cannot be deleted')
              "
            >
              <BaseButton
                :disabled="!canDeleteAssignment(slotProps.data)"
                icon="trash"
                only-icon
                size="small"
                type="danger"
                :label="t('Delete')"
                @click="() => onClickDelete(slotProps.data)"
              />
            </span>
          </template>
          <BaseButton
            v-else-if="hasBeenSubmitted(slotProps.data)"
            icon="eye-on"
            size="small"
            type="primary-text"
            :label="t('View submission')"
            @click="() => goToSubmit(slotProps.data)"
          />
          <BaseButton
            v-else
            icon="upload"
            size="small"
            type="primary-text"
            :label="t('Submit')"
            @click="() => goToSubmit(slotProps.data)"
          />
        </template>
      </Column>
    </BaseTable>
  </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from "vue"
import { useI18n } from "vue-i18n"
import { useRoute, useRouter } from "vue-router"
import Column from "primevue/column"
import homeworkAssignmentService from "../../services/chomeworkassignment"
import homeworkSubmissionService from "../../services/chomeworksubmission"
import homeworkAssignmentProgressService from "../../services/homeworkassignmentprogress"
import courseRelUserService from "../../services/courseRelUserService"
import sessionRelCourseRelUserService from "../../services/sessionRelCourseRelUserService"
import { useSecurityStore } from "../../store/securityStore"
import { usePlatformConfig } from "../../store/platformConfig"
import { useFormatDate } from "../../composables/formatDate"
import { useNotification } from "../../composables/notification"
import { useConfirmation } from "../../composables/useConfirmation"
import { getCourseContext } from "../../utils/courseContext"
import BaseButton from "../../components/basecomponents/BaseButton.vue"
import BaseTable from "../../components/basecomponents/BaseTable.vue"
import EmptyState from "../../components/EmptyState.vue"
import {
  HOMEWORK_SUBMISSION_STATUS_LATE,
  HOMEWORK_SUBMISSION_STATUS_SUBMITTED,
} from "../../constants/entity/chomeworksubmission"
import { SESSION_STUDENT } from "../../constants/entity/session"

// Chamilo's legacy course_rel_user.status convention (see CourseRelUserTest /
// AssignmentAddUser.vue for the same value used the same way): 5 = student.
const COURSE_REL_USER_STATUS_STUDENT = 5

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const securityStore = useSecurityStore()
const platformConfigStore = usePlatformConfig()
const { abbreviatedDatetime } = useFormatDate()
const notification = useNotification()
const { requireConfirmation } = useConfirmation()

// Same "is the current user a teacher in this course context" pattern used by
// assets/vue/views/assignments/AssignmentsList.vue: role checks combined with
// the student-view override.
const isTeacher = computed(
  () =>
    (securityStore.isCurrentTeacher ||
      securityStore.isCourseAdmin ||
      securityStore.isAdmin ||
      securityStore.isTeacher) &&
    !platformConfigStore.isStudentViewActive,
)

const isLoading = ref(true)
const assignments = ref([])
const totalItems = ref(0)

// Server-side pagination state (see loadAssignments()) - a course can have
// far more than API Platform's default page size worth of assignments, so
// the list must never be fetched/filtered as if it were complete without
// paging through it. Mirrors HomeworkCorrectAndRate.vue's loadParams.
const loadParams = reactive({
  page: 1,
  itemsPerPage: null,
})

// Default matches the new server-side default order (deadline DESC) - see
// CHomeworkAssignment.php's ApiResource order attribute. PrimeVue's
// multi-sort-meta convention: order -1 = desc, 1 = asc (mirrors
// assets/vue/components/assignments/TeacherAssignmentList.vue).
const sortFields = ref([{ field: "deadline", order: -1 }])

// Keyed by numeric assignment id, each value the full array of the current
// student's own CHomeworkSubmission rows for that assignment (there can be
// more than one for a multi-report assignment - one per sequenceNumber).
// Only ever populated for students - a teacher has no "own" submission to
// look up, and CHomeworkSubmissionExtension scopes GetCollection to the
// current user's own submissions anyway, so calling this as a teacher would
// be both wasted work and meaningless.
const mySubmissionByAssignmentId = ref({})

// Teacher-only counterparts: how many students are enrolled in the course/
// session currently being browsed, and how many submissions (per assignment)
// have actually reached SUBMITTED/LATE - CHomeworkSubmissionExtension grants
// course teachers unrestricted visibility, so listSubmissionsForAssignment()
// returns every student's submission here instead of just "my own".
const studentTotal = ref(0)
const submittedCountByAssignmentId = ref({})

// Multi-report assignments only: how many students already reached their
// requiredSubmissionCount (on-time reports only - same "Reached" definition
// already used on the per-assignment progress table in
// HomeworkCorrectAndRate.vue, kept consistent between both screens). Raw
// report-row counts (submittedCountByAssignmentId above) are meaningless as
// a "students done" indicator here, since one student can hold many of
// those rows - see teacherStatusLabel() below.
const completedCountByAssignmentId = ref({})

async function loadStudentTotal() {
  if (!isTeacher.value) return

  const { cid, sid } = getCourseContext()
  if (!cid) return

  try {
    if (sid) {
      // status=SESSION_STUDENT excludes session/course coaches from the
      // count - the entity's `status` field is now filterable server-side
      // (see SessionRelCourseRelUser's SearchFilter). AssignmentAddUser.vue
      // still uses the unfiltered call for its own, different purpose
      // (listing everyone to add, not counting students).
      const { totalItems: sessionTotal } = await sessionRelCourseRelUserService.findAll({
        session: sid,
        course: cid,
        status: SESSION_STUDENT,
      })
      studentTotal.value = sessionTotal || 0
    } else {
      const { totalItems: courseTotal } = await courseRelUserService.findAll({
        course: cid,
        status: COURSE_REL_USER_STATUS_STUDENT,
      })
      studentTotal.value = courseTotal || 0
    }
  } catch (error) {
    notification.showErrorNotification(error)
  }
}

async function loadMySubmissions() {
  if (isTeacher.value || !assignments.value.length) return

  const entries = await Promise.all(
    assignments.value.map(async (assignment) => {
      const id = getAssignmentId(assignment)
      try {
        const { items } = await homeworkSubmissionService.listSubmissionsForAssignment(id)
        return [id, items || []]
      } catch (error) {
        notification.showErrorNotification(error)

        return [id, []]
      }
    }),
  )

  mySubmissionByAssignmentId.value = Object.fromEntries(entries)
}

async function loadSubmittedCounts() {
  if (!isTeacher.value || !assignments.value.length) return

  const entries = await Promise.all(
    assignments.value.map(async (assignment) => {
      const id = getAssignmentId(assignment)
      try {
        const { items } = await homeworkSubmissionService.listSubmissionsForAssignment(id)
        const submittedCount = (items || []).filter((item) =>
          [HOMEWORK_SUBMISSION_STATUS_SUBMITTED, HOMEWORK_SUBMISSION_STATUS_LATE].includes(item.status),
        ).length

        return [id, submittedCount]
      } catch (error) {
        notification.showErrorNotification(error)

        return [id, 0]
      }
    }),
  )

  submittedCountByAssignmentId.value = Object.fromEntries(entries)
}

async function loadCompletedCounts() {
  if (!isTeacher.value || !assignments.value.length) return

  const multiReportAssignments = assignments.value.filter((assignment) => assignment.allowMultipleSubmissions)
  if (!multiReportAssignments.length) return

  const entries = await Promise.all(
    multiReportAssignments.map(async (assignment) => {
      const id = getAssignmentId(assignment)
      try {
        const { items } = await homeworkAssignmentProgressService.getProgress(id)
        const completedCount = (items || []).filter((row) => row.onTimeCount >= row.requiredCount).length

        return [id, completedCount]
      } catch (error) {
        notification.showErrorNotification(error)

        return [id, 0]
      }
    }),
  )

  completedCountByAssignmentId.value = Object.fromEntries(entries)
}

async function loadAssignments() {
  isLoading.value = true
  try {
    const orderParams = {}
    sortFields.value.forEach((sortItem) => {
      orderParams[`order[${sortItem.field}]`] = -1 === sortItem.order ? "desc" : "asc"
    })

    const result = await homeworkAssignmentService.listAssignments({
      page: loadParams.page,
      itemsPerPage: loadParams.itemsPerPage,
      ...orderParams,
    })
    assignments.value = result.items || []
    totalItems.value = result.totalItems || 0

    await Promise.all([loadMySubmissions(), loadStudentTotal(), loadSubmittedCounts()])
    // Depends on assignments.value (for the allowMultipleSubmissions filter)
    // only, not on the results of the calls above - runs after them simply
    // to keep this function's control flow linear, not because of a real
    // data dependency.
    await loadCompletedCounts()
  } catch (error) {
    notification.showErrorNotification(error)
  } finally {
    isLoading.value = false
  }
}

// BaseTable initializes `loadParams.itemsPerPage` itself (from platform
// settings) once mounted, which is what actually triggers the first load
// below - mirrors HomeworkCorrectAndRate.vue's lazy-loading pattern exactly,
// so the list is always fetched page-by-page from the server rather than
// once "in full".
watch(
  loadParams,
  () => {
    if (!loadParams.itemsPerPage) return
    loadAssignments()
  },
  { deep: true, immediate: true },
)

function onSort(event) {
  sortFields.value = event.multiSortMeta
  loadAssignments()
}

function onPage(event) {
  loadParams.page = event.page + 1
  loadParams.itemsPerPage = event.rows
}

function mySubmissions(assignment) {
  return mySubmissionByAssignmentId.value[getAssignmentId(assignment)] || []
}

// Single-report assignments only ever have one row (server always assigns
// sequenceNumber = 1) - multi-report callers must use mySubmissions() instead
// and reason about the whole array.
function mySubmission(assignment) {
  return mySubmissions(assignment)[0] || null
}

function hasBeenSubmitted(assignment) {
  return mySubmissions(assignment).some((item) =>
    [HOMEWORK_SUBMISSION_STATUS_SUBMITTED, HOMEWORK_SUBMISSION_STATUS_LATE].includes(item.status),
  )
}

function teacherStatusLabel(assignment) {
  if (assignment.allowMultipleSubmissions) {
    const completedCount = completedCountByAssignmentId.value[getAssignmentId(assignment)] ?? 0

    return t("{completed} / {total} completed", { completed: completedCount, total: studentTotal.value })
  }

  const submittedCount = submittedCountByAssignmentId.value[getAssignmentId(assignment)] ?? 0

  return t("{submitted} / {total} submitted", { submitted: submittedCount, total: studentTotal.value })
}

// Deletable only once no student has actually submitted (draft or no
// submission at all is fine) - matches the server-side guard in
// CHomeworkAssignmentDeleteProcessor, which rejects the DELETE request
// outright if this is stale/bypassed.
function canDeleteAssignment(assignment) {
  return 0 === (submittedCountByAssignmentId.value[getAssignmentId(assignment)] ?? 0)
}

function onClickDelete(assignment) {
  if (!canDeleteAssignment(assignment)) return

  requireConfirmation({
    message: t("Are you sure you want to delete this assignment?"),
    accept: async () => {
      try {
        await homeworkAssignmentService.del(assignment)
        assignments.value = assignments.value.filter((item) => item["@id"] !== assignment["@id"])
        totalItems.value = Math.max(0, totalItems.value - 1)
        notification.showSuccessNotification(t("Assignment deleted"))
      } catch (error) {
        notification.showErrorNotification(error)
      }
    },
  })
}

function studentStatusLabel(assignment) {
  if (assignment.allowMultipleSubmissions) {
    const reports = mySubmissions(assignment).filter((item) =>
      [HOMEWORK_SUBMISSION_STATUS_SUBMITTED, HOMEWORK_SUBMISSION_STATUS_LATE].includes(item.status),
    )
    if (!reports.length) return t("Not submitted")

    const lateCount = reports.filter((item) => item.status === HOMEWORK_SUBMISSION_STATUS_LATE).length
    const base = t("{submitted}/{required} reports submitted", {
      submitted: reports.length,
      required: assignment.requiredSubmissionCount,
    })

    return lateCount > 0 ? `${base} (${t("{count} late", { count: lateCount })})` : base
  }

  const submission = mySubmission(assignment)
  if (!submission) return t("Not submitted")

  if (submission.status === HOMEWORK_SUBMISSION_STATUS_LATE) {
    return submission.submittedAt
      ? t("Submitted (late) on {date}", { date: abbreviatedDatetime(submission.submittedAt) })
      : t("Submitted (late)")
  }

  if (submission.status === HOMEWORK_SUBMISSION_STATUS_SUBMITTED) {
    return submission.submittedAt
      ? t("Submitted on {date}", { date: abbreviatedDatetime(submission.submittedAt) })
      : t("Submitted")
  }

  return t("Draft")
}

function getAssignmentId(assignment) {
  return parseInt(assignment["@id"].split("/").pop(), 10)
}

function formatDeadline(deadline) {
  return deadline ? abbreviatedDatetime(deadline) : "—"
}

function goToCreate() {
  router.push({ name: "HomeworkAssignmentCreate", query: route.query })
}

function goToSubmit(assignment) {
  router.push({
    name: "HomeworkSubmit",
    params: { assignmentId: getAssignmentId(assignment) },
    query: route.query,
  })
}

function goToCorrect(assignment) {
  router.push({
    name: "HomeworkCorrectAndRate",
    params: { assignmentId: getAssignmentId(assignment) },
    query: route.query,
  })
}

function goToEdit(assignment) {
  router.push({
    name: "HomeworkAssignmentEdit",
    params: { assignmentId: getAssignmentId(assignment) },
    query: route.query,
  })
}
</script>
