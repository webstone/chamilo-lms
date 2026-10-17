import baseService from "./baseService"
import { getCourseContext } from "../utils/courseContext"

/** Per-student on-time/total report counts for a multi-report assignment (teacher-side). */
async function getProgress(assignmentId) {
  const { cid, sid, gid } = getCourseContext()

  return baseService.getCollection(`/api/homework_assignments_progress`, {
    cid,
    ...(sid ? { sid } : {}),
    ...(gid ? { gid } : {}),
    assignmentId,
  })
}

export default {
  getProgress,
}
