import fetch from "../utils/fetch"
import makeService from "./api"
import baseService from "./baseService"
import prettyBytes from "pretty-bytes"
import api from "../config/api"
import { useCidReqStore } from "../store/cidReq"

// Fallback URL parser (mirrors master's utils/courseContext.js, inlined for
// stable since the helper is not backported here).
const COURSE_HOME_PATH = /^\/course\/(\d+)\/home/

function getCourseContext() {
  const search = new URLSearchParams(window.location.search)
  const pathMatch = window.location.pathname.match(COURSE_HOME_PATH)
  const cid = pathMatch ? pathMatch[1] : search.get("cid")
  return {
    cid: parseInt(cid ?? 0) || 0,
    sid: parseInt(search.get("sid") ?? 0) || 0,
    gid: parseInt(search.get("gid") ?? 0) || 0,
  }
}

const oldService = makeService("documents")

function normalizeCode(code) {
  return String(code || "")
    .trim()
    .toLowerCase()
}

/**
 * Convert payload.searchFieldValues object into flat FormData keys:
 * - searchFieldValues[t] = "..."
 * - searchFieldValues[d] = "..."
 *
 * This prevents "[object Object]" from being sent to the backend when using FormData.
 */
function flattenSearchFieldValues(payload) {
  if (!payload || typeof payload !== "object") {
    return payload
  }

  const normalized = { ...payload }
  const sfv = normalized.searchFieldValues

  // Remove the original object to avoid FormData -> "[object Object]"
  if (sfv && typeof sfv === "object" && !Array.isArray(sfv)) {
    delete normalized.searchFieldValues

    Object.entries(sfv).forEach(([code, val]) => {
      const c = normalizeCode(code)
      if (!c) return
      normalized[`searchFieldValues[${c}]`] = String(val ?? "")
    })
  }

  return normalized
}

function buildFormData(payload) {
  const fd = new FormData()

  Object.entries(payload || {}).forEach(([key, val]) => {
    if (undefined === val || null === val) return
    fd.append(key, val)
  })

  return fd
}

// ----------------------------
// Quota helpers (shared)
// ----------------------------

// Default threshold so it is easy to see in UI.
const DEFAULT_QUOTA_WARNING_THRESHOLD_PERCENT = 2
const DEFAULT_QUOTA_STALE_MS = 30_000

// In-memory cache per (courseId, sid, gid)
const quotaCache = new Map()

function quotaCacheKey(courseId, sid, gid) {
  return `${Number(courseId) || 0}:${Number(sid) || 0}:${Number(gid) || 0}`
}

/**
 * Fetch quota usage for a course. Returns:
 * { availableBytes, availablePercent, fetchedAt } or null
 */
async function getQuotaUsage(courseId, { sid = 0, gid = 0, force = false, staleMs = DEFAULT_QUOTA_STALE_MS } = {}) {
  const cid = Number(courseId || 0)
  if (!cid) return null

  const s = Number(sid || 0)
  const g = Number(gid || 0)

  const key = quotaCacheKey(cid, s, g)
  const now = Date.now()

  const cached = quotaCache.get(key)
  if (!force && cached?.fetchedAt && now - cached.fetchedAt < staleMs) {
    return cached
  }

  try {
    const url = `/api/documents/${cid}/usage?sid=${s}&gid=${g}`
    const response = await window.fetch(url, {
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    })

    if (!response.ok) return null

    const json = await response.json()
    const quota = json?.quota || {}
    const availableBytes = Number(quota.availableBytes)
    const availablePercent = Number(quota.availablePercent)

    if (!Number.isFinite(availableBytes) || !Number.isFinite(availablePercent)) {
      return null
    }

    const info = { availableBytes, availablePercent, fetchedAt: now }
    quotaCache.set(key, info)
    return info
  } catch (e) {
    console.error("[DocumentsService] Failed to fetch quota usage:", e)
    return null
  }
}

/**
 * Build "Available space (%s)" message using i18n + prettyBytes.
 * Vue i18n does not format "%s", so we replace it manually.
 */
function formatAvailableSpaceMessage(t, availableBytes) {
  const template = String(t?.("Available space (%s)") ?? "Available space (%s)")
  const bytesLabel = prettyBytes(Math.max(Number(availableBytes || 0), 0))
  return template.includes("%s") ? template.replace("%s", bytesLabel) : `${template} (${bytesLabel})`
}

/**
 * Return warning message (string) if quota is below/equals threshold.
 * Otherwise returns "".
 */
function getQuotaWarningMessage(t, quotaInfo, { thresholdPercent = DEFAULT_QUOTA_WARNING_THRESHOLD_PERCENT } = {}) {
  const ap = Number(quotaInfo?.availablePercent)
  const ab = Number(quotaInfo?.availableBytes)

  if (!Number.isFinite(ap) || !Number.isFinite(ab)) return ""

  if (ap <= Number(thresholdPercent)) {
    return formatAvailableSpaceMessage(t, ab)
  }

  return ""
}

/**
 * Convenience: fetch usage + compute message.
 */
async function fetchQuotaWarningMessage(
  t,
  courseId,
  { sid = 0, gid = 0, force = false, thresholdPercent = DEFAULT_QUOTA_WARNING_THRESHOLD_PERCENT } = {},
) {
  const info = await getQuotaUsage(courseId, { sid, gid, force })
  return getQuotaWarningMessage(t, info, { thresholdPercent })
}

/**
 * Extract a meaningful error message from API responseText (Uppy or others).
 */
function extractApiErrorMessageFromText(responseText) {
  if (!responseText) return ""

  try {
    const json = JSON.parse(responseText)
    const msg =
      json?.error ||
      json?.message ||
      json?.detail ||
      json?.["hydra:description"] ||
      (Array.isArray(json?.violations) && json.violations.length ? json.violations[0].message : null)

    return String(msg || "")
  } catch {
    return String(responseText || "")
  }
}

/**
 * Detect quota errors by status + message.
 */
function isQuotaError(status, message) {
  const s = Number(status || 0)
  const m = String(message || "").toLowerCase()

  if ([507, 413, 422, 400].includes(s)) {
    if (m.includes("not enough space")) return true
    if (m.includes("there is not enough space")) return true
    if (m.includes("quota")) return true
    if (m.includes("disk") && m.includes("space")) return true
  }

  if (m.includes("there is not enough space")) return true
  if (m.includes("not enough space")) return true
  if (m.includes("quota")) return true

  return false
}

/**
 * Standard quota message used across UI.
 */
function getQuotaUploadErrorMessage(t) {
  return String(
    t?.("There is not enough space to upload this file.") ?? "There is not enough space to upload this file.",
  )
}

export default {
  ...oldService,

  // ----------------------------
  // Existing overrides
  // ----------------------------

  /**
   * Override createWithFormData only for documents to avoid breaking other modules.
   * Two reasons:
   * 1. Flattens searchFieldValues so FormData does not serialize them as "[object Object]".
   * 2. Forces the current course/session/group context (cid/sid/gid) onto the POST URL.
   *    The shared axios interceptor in config/api.js reads getRawCourseContext() from
   *    window.location.search, which is empty when the SPA navigates without preserving
   *    ?cid=. Without cid in the request, CidReqListener wipes the session course;
   *    CreateDocumentFileAction then builds a resource_link with no cid, and the new
   *    document hangs orphaned (not visible in the course documents list). Reading the
   *    Pinia cidReq store directly here is the canonical source maintained by the
   *    router guards and survives URL changes.
   */
  async createWithFormData(payload) {
    const prepared = flattenSearchFieldValues(payload)
    const formData = buildFormData(prepared)

    // Course context: Pinia store is authoritative; getCourseContext() (URL-based)
    // is the fallback for early init before the store is hydrated.
    let cid = 0
    let sid = 0
    let gid = 0
    try {
      const store = useCidReqStore()
      cid = Number(store.course?.id ?? 0) || 0
      sid = Number(store.session?.id ?? 0) || 0
      gid = Number(store.group?.id ?? 0) || 0
    } catch {
      // Pinia not active (test or pre-mount) — fall through to URL parse.
    }
    if (!cid) {
      const fromUrl = getCourseContext()
      cid = fromUrl.cid
      sid = fromUrl.sid
      gid = fromUrl.gid
    }

    const params = {}
    if (cid > 0) params.cid = cid
    if (sid > 0) params.sid = sid
    if (gid > 0) params.gid = gid

    // Use axios directly (config/api), then wrap the axios response in a minimal
    // Response-like shim. The legacy CRUD store expects .ok / .status / .json().
    const response = await api.post("/api/documents", formData, { params })
    return {
      ok: response.status >= 200 && response.status < 300,
      status: response.status,
      statusText: response.statusText,
      headers: response.headers,
      json: async () => response.data,
    }
  },

  /**
   * IMPORTANT:
   * PHP/Symfony does not parse multipart/form-data on PUT requests.
   * So for updates we send POST with a method override to PUT.
   */
  updateWithFormData(payload) {
    const prepared = flattenSearchFieldValues(payload)
    const iri = prepared?.["@id"] || payload?.["@id"]

    if (!iri) {
      throw new Error("[Documents] updateWithFormData: missing @id in payload.")
    }

    const bodyPayload = { ...prepared }
    delete bodyPayload["@id"]
    delete bodyPayload["@context"]
    delete bodyPayload["@type"]

    const fd = buildFormData(bodyPayload)
    fd.append("_method", "PUT")

    return fetch(iri, {
      method: "POST",
      body: fd,
      headers: {
        "X-HTTP-Method-Override": "PUT",
      },
    })
  },

  /**
   * Retrieves all document templates for a given course.
   */
  getTemplates: async (courseId) => {
    return baseService.get(`/template/all-templates/${courseId}`)
  },

  // ----------------------------
  // Quota API (shared)
  // ----------------------------
  getQuotaUsage,
  formatAvailableSpaceMessage,
  getQuotaWarningMessage,
  fetchQuotaWarningMessage,
  extractApiErrorMessageFromText,
  isQuotaError,
  getQuotaUploadErrorMessage,
}
