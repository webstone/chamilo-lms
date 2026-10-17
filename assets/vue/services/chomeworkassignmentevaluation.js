import makeService from "./api"
import baseService from "./baseService"
import { getCourseContext } from "../utils/courseContext"

function buildCidParams() {
  const { cid, sid, gid } = getCourseContext()

  return {
    cid,
    ...(sid ? { sid } : {}),
    ...(gid ? { gid } : {}),
  }
}

/** Lists final evaluations for a given assignment (collection endpoint). */
async function listEvaluationsForAssignment(assignmentId) {
  return baseService.getCollection(`/api/c_homework_assignment_evaluations`, {
    ...buildCidParams(),
    "assignment.iid": assignmentId,
  })
}

/**
 * Creates a final evaluation for one student (teacher-side). See
 * HomeworkSubmit.vue's loadOrCreateSubmission() for the resourceLinkList
 * shape this mirrors - `uid` must be the EVALUATED student's id, not the
 * current (teacher) user's id.
 */
async function createEvaluation(payload) {
  return baseService.post(`/api/c_homework_assignment_evaluations`, payload)
}

/** Updates a final evaluation's score/feedback (teacher-side). */
async function updateEvaluation(id, score, feedback) {
  return baseService.put(`/api/c_homework_assignment_evaluations/${id}`, { score, feedback }, { params: buildCidParams() })
}

export default {
  ...makeService("c_homework_assignment_evaluations"),
  listEvaluationsForAssignment,
  createEvaluation,
  updateEvaluation,
}
