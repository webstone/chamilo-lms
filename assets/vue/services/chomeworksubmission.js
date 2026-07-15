import makeService from "./api"
import baseService from "./baseService"
import { getCourseContext } from "../utils/courseContext"
import { HOMEWORK_SUBMISSION_STATUS_SUBMITTED } from "../constants/entity/chomeworksubmission"

function buildCidParams() {
  const { cid, sid, gid } = getCourseContext()

  return {
    cid,
    ...(sid ? { sid } : {}),
    ...(gid ? { gid } : {}),
  }
}

/**
 * Lists submissions for a given assignment (collection endpoint), unwrapped to
 * {totalItems, items, nextPageParams}.
 *
 * Filtered server-side via the "assignment.iid" SearchFilter registered on
 * CHomeworkSubmission (see src/CourseBundle/Entity/CHomeworkSubmission.php),
 * mirroring the "publication.iid" filter CStudentPublicationComment uses for
 * the analogous relation in cstudentpublication.js's loadComments().
 *
 * `page`/`itemsPerPage`/`status` are optional and all server-side: without
 * them, API Platform's default collection page size (30) silently truncates
 * the result, which HomeworkCorrectAndRate.vue's teacher-facing submission
 * list must not do for a course with more than 30 submissions - see that
 * component for the real (paginated) caller. HomeworkSubmit.vue's own
 * find-or-create-my-draft call deliberately omits these (a student's own
 * filtered collection is always 0-1 items, so default pagination is a no-op
 * there).
 */
async function listSubmissionsForAssignment(assignmentId, { page, itemsPerPage, status } = {}) {
  return baseService.getCollection(`/api/c_homework_submissions`, {
    ...buildCidParams(),
    "assignment.iid": assignmentId,
    ...(page ? { page } : {}),
    ...(itemsPerPage ? { itemsPerPage } : {}),
    ...(status !== undefined && status !== null && "" !== status ? { status } : {}),
  })
}

/**
 * Creates a submission (student-side "find or create my draft" flow - see
 * HomeworkSubmit.vue). CHomeworkSubmission has a UNIQUE(assignment_id, user_id)
 * constraint, so callers must first check listSubmissionsForAssignment() for
 * an existing row before calling this.
 */
async function createSubmission(payload) {
  return baseService.post(`/api/c_homework_submissions`, payload)
}

/** Saves a submission as a draft (student-side autosave/manual save). */
async function saveDraft(id, payload) {
  return baseService.put(`/api/c_homework_submissions/${id}`, payload, { params: buildCidParams() })
}

/** Marks a submission as submitted. */
async function submit(id, payload) {
  return baseService.put(
    `/api/c_homework_submissions/${id}`,
    { ...payload, status: HOMEWORK_SUBMISSION_STATUS_SUBMITTED },
    { params: buildCidParams() },
  )
}

/** Grades a submission (teacher-side). */
async function grade(id, score, feedback) {
  return baseService.put(`/api/c_homework_submissions/${id}`, { score, feedback }, { params: buildCidParams() })
}

export default {
  ...makeService("c_homework_submissions"),
  listSubmissionsForAssignment,
  createSubmission,
  saveDraft,
  submit,
  grade,
}
