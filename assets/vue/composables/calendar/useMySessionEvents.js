import { ref } from "vue"
import { useI18n } from "vue-i18n"
import axios from "axios"

/**
 * Fetches the read-only session-derived calendar events for every session
 * the given user is enrolled in (across all their courses), for the
 * personal/global Agenda.
 *
 * Returns objects shaped like FullCalendar events with extendedProps carrying:
 *   sessionId, sessionStart, sessionEnd, isPast, isViewerEnrolled (always true here).
 * Sibling of useCourseSessionEvents.js rather than an extension of it - that
 * composable's prop typing is course-id-specific.
 *
 * Does NOT auto-fetch on mount and does NOT watch `userId`. Callers must
 * invoke `refetch()` when ready (typically inside `onMounted`) and set up
 * their own `watch(...)` if the id is reactive.
 *
 * @param {number|import("vue").Ref<number>} userId
 * @returns {{ events: import("vue").Ref<Object[]>, isLoading: import("vue").Ref<boolean>, errorMessage: import("vue").Ref<string>, refetch: () => Promise<void> }}
 */
export function useMySessionEvents(userId) {
  const { t } = useI18n()
  const events = ref([])
  const isLoading = ref(false)
  const errorMessage = ref("")

  async function refetch() {
    const id = typeof userId === "object" && userId !== null && "value" in userId ? userId.value : userId

    if (!id) {
      events.value = []
      errorMessage.value = ""
      return
    }

    isLoading.value = true
    errorMessage.value = ""
    try {
      const { data } = await axios.get(`/api/users/${id}/session_events`, {
        headers: { Accept: "application/json" },
      })
      events.value = Array.isArray(data) ? data : []
    } catch (e) {
      errorMessage.value =
        e?.response?.status === 403
          ? t("You are not allowed to view this user's sessions.")
          : t("Could not load session markers.")
      events.value = []
    } finally {
      isLoading.value = false
    }
  }

  return { events, isLoading, errorMessage, refetch }
}
