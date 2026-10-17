import { getCourseContext } from "./courseContext"

/**
 * Builds the download URL for GET /api/c_homework_submissions/{id}/export.
 *
 * Must carry cid/sid/gid explicitly, same as every other fetch in the
 * homework module (via services' buildCidParams()) - CidReqListener treats a
 * request with no cid at all as a signal to clear the whole PHP session's
 * stored course/session context (cid/sid/gid/course/session and more), not
 * just to scope this one request. A plain <a href> navigation with none of
 * these query params would silently break the teacher's course context for
 * the rest of the app.
 *
 * @param {{"@id": string}} submission
 * @returns {string}
 */
export function buildSubmissionExportUrl(submission) {
  const iid = parseInt(String(submission["@id"]).split("/").pop(), 10)
  const { cid, sid, gid } = getCourseContext()
  const params = new URLSearchParams({ cid })
  if (sid) params.set("sid", sid)
  if (gid) params.set("gid", gid)

  return `/api/c_homework_submissions/${iid}/export?${params.toString()}`
}
