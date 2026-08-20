import { ref } from "vue"
import { useI18n } from "vue-i18n"
import axios from "axios"

/**
 * Fetches the read-only session-derived calendar events for a course.
 *
 * Returns objects shaped like FullCalendar events with extendedProps carrying:
 *   sessionId, sessionStart, sessionEnd, isPast, isViewerEnrolled.
 * Past sessions are styled muted and 50% opacity by the consuming view;
 * viewer-enrolled sessions get a left-border accent.
 *
 * Does NOT auto-fetch on mount and does NOT watch `courseId`/`sessionId`. Callers must
 * invoke `refetch()` when ready (typically inside `onMounted`) and set up
 * their own `watch(...)` if either id is reactive.
 *
 * @param {number|import("vue").Ref<number>} courseId
 * @param {number|import("vue").Ref<number>} [sessionId] - When set (non-zero), restricts
 *   the returned markers to this single session instead of every session on the course.
 * @returns {{ events: import("vue").Ref<Object[]>, isLoading: import("vue").Ref<boolean>, errorMessage: import("vue").Ref<string>, refetch: () => Promise<void> }}
 */
export function useCourseSessionEvents(courseId, sessionId) {
  const { t } = useI18n()
  const events = ref([])
  const isLoading = ref(false)
  const errorMessage = ref("")

  async function refetch() {
    const id = typeof courseId === "object" && courseId !== null && "value" in courseId ? courseId.value : courseId
    const sid = typeof sessionId === "object" && sessionId !== null && "value" in sessionId ? sessionId.value : sessionId

    if (!id) {
      events.value = []
      errorMessage.value = ""
      return
    }

    isLoading.value = true
    errorMessage.value = ""
    // No cancellation guard: rapid successive refetch() calls can produce a
    // slow-first/fast-second race (last response wins by definition; if the
    // older one completes later it overwrites). Not a problem for the current
    // caller, but worth adding an AbortController if polling is introduced.
    try {
      const { data } = await axios.get(`/api/courses/${id}/session_events`, {
        headers: { Accept: "application/json" },
        params: sid ? { sid } : {},
      })
      events.value = Array.isArray(data) ? data : []
    } catch (e) {
      errorMessage.value =
        e?.response?.status === 403
          ? t("You are not allowed to view this course's session plan.")
          : t("Could not load session markers.")
      events.value = []
    } finally {
      isLoading.value = false
    }
  }

  return { events, isLoading, errorMessage, refetch }
}
