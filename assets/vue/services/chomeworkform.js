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

/** Lists homework forms (collection endpoint), unwrapped to {totalItems, items, nextPageParams}. */
async function listForms() {
  return baseService.getCollection(`/api/c_homework_forms`, buildCidParams())
}

/** Fetches a single homework form by id. */
async function getForm(id) {
  return baseService.get(`/api/c_homework_forms/${id}`, buildCidParams())
}

/** Creates a homework form. */
async function createForm(payload) {
  return baseService.post(`/api/c_homework_forms`, payload)
}

/** Updates a homework form. */
async function updateForm(id, payload) {
  return baseService.put(`/api/c_homework_forms/${id}`, payload, { params: buildCidParams() })
}

export default {
  ...makeService("c_homework_forms"),
  listForms,
  getForm,
  createForm,
  updateForm,
}
