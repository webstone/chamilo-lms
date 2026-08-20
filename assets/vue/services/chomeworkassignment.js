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

/** Lists homework assignments (collection endpoint), unwrapped to {totalItems, items, nextPageParams}. */
async function listAssignments(params = {}) {
  return baseService.getCollection(`/api/c_homework_assignments`, { ...buildCidParams(), ...params })
}

/** Fetches a single homework assignment by id. */
async function getAssignment(id) {
  return baseService.get(`/api/c_homework_assignments/${id}`, buildCidParams())
}

/** Creates a homework assignment. */
async function createAssignment(payload) {
  return baseService.post(`/api/c_homework_assignments`, payload)
}

/** Updates a homework assignment. */
async function updateAssignment(id, payload) {
  return baseService.put(`/api/c_homework_assignments/${id}`, payload, { params: buildCidParams() })
}

export default {
  ...makeService("c_homework_assignments"),
  listAssignments,
  getAssignment,
  createAssignment,
  updateAssignment,
}
