<script setup>
import { computed } from "vue"
import { useI18n } from "vue-i18n"
import Dialog from "primevue/dialog"

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  title: { type: String, required: true },
  sessionTitle: { type: String, required: true },
  courseId: { type: Number, required: true },
  sessionId: { type: Number, required: true },
  sessionStart: { type: String, required: true },
  sessionEnd: { type: String, default: null },
  isPast: { type: Boolean, default: false },
  isViewerEnrolled: { type: Boolean, default: false },
  isAdminViewer: { type: Boolean, default: false },
})

const emit = defineEmits(["update:modelValue"])

const { t, locale } = useI18n()

const isVisible = computed({
  get: () => props.modelValue,
  set: (v) => emit("update:modelValue", v),
})

const statusLabel = computed(() => (props.isPast ? t("Past session") : t("Upcoming session")))

const statusDotClass = computed(() => (props.isPast ? "bg-gray-400" : "bg-primary"))

const sessionHref = computed(() => {
  // Both admin and enrolled-student land on the same canonical Vue course-home
  // route, scoped to the session via the ?sid query — the same convention used
  // by every other dynamic course-context navigation in assets/vue/router/index.js.
  // A non-enrolled non-admin sees a metadata-only popover with no link.
  if (!props.isAdminViewer && !props.isViewerEnrolled) {
    return null
  }
  return `/course/${props.courseId}/home?sid=${props.sessionId}`
})

function formatDate(iso) {
  if (!iso) return ""
  return new Date(iso).toLocaleString(locale.value, {
    year: "numeric",
    month: "long",
    day: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  })
}
</script>

<template>
  <Dialog
    v-model:visible="isVisible"
    :modal="true"
    class="p-fluid"
  >
    <template #header>
      <div class="flex flex-col">
        <h3 class="text-lg font-semibold">{{ title }}</h3>
        <p class="text-sm text-gray-500 mt-0.5">{{ sessionTitle }}</p>
      </div>
    </template>

    <div class="flex flex-col gap-3 text-sm">
      <div class="flex items-center gap-2">
        <span
          class="inline-block w-3 h-3 rounded-full"
          :class="statusDotClass"
          :aria-hidden="true"
        />
        <span>{{ statusLabel }}</span>
        <span
          v-if="isViewerEnrolled"
          class="ml-auto inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-amber-100 text-amber-800"
        >
          {{ t("Your session") }}
        </span>
      </div>

      <div>
        <div class="font-semibold">{{ t("Starts on") }}</div>
        <div>{{ formatDate(sessionStart) }}</div>
      </div>

      <div v-if="sessionEnd">
        <div class="font-semibold">{{ t("Ends on") }}</div>
        <div>{{ formatDate(sessionEnd) }}</div>
      </div>

      <a
        v-if="sessionHref"
        :href="sessionHref"
        :aria-label="`${t('Go to session')}: ${title}`"
        class="inline-flex items-center justify-center px-3 py-2 rounded bg-primary text-white text-sm font-medium hover:bg-primary-dark"
      >
        {{ t("Go to session") }}
      </a>
    </div>
  </Dialog>
</template>
