<script setup>
import { computed, onMounted, ref, watch } from "vue"
import DatePicker from "primevue/datepicker"
import FloatLabel from "primevue/floatlabel"
import Message from "primevue/message"
import { usePlatformConfig } from "../../store/platformConfig"
import { calendarLocales } from "../../utils/calendarLocales"
import { useLocale } from "../../composables/locale"
import { usePrimeVue } from "primevue/config"
import { useI18n } from "vue-i18n"
import BaseButton from "./BaseButton.vue"

const { t } = useI18n()
const platformConfigStore = usePlatformConfig()
/**
 * @type {Number}
 */
const timepicketIncrement = platformConfigStore.getSetting("platform.timepicker_increment")

const modelValue = defineModel({
  type: [Date, Array, String, null],
  required: false,
  default: null,
})

// Internal value used by the DatePicker
const internalValue = ref(modelValue.value)

// Sync internal value when the external model changes (e.g. reset from parent)
watch(
  () => modelValue.value,
  (newValue) => {
    internalValue.value = newValue
  },
)

const datepickerRef = ref(null)

const { appLocale } = useLocale()
const localePrefix = ref(getLocalePrefix(appLocale.value))

const props = defineProps({
  label: {
    type: String,
    required: true,
  },
  id: {
    type: String,
    required: true,
    default: "",
  },
  type: {
    type: String,
    required: false,
    default: "single",
    validator: (value) => ["single", "range"].includes(value),
  },
  showTime: {
    type: Boolean,
    required: false,
    default: false,
  },
  isInvalid: {
    type: Boolean,
    required: false,
    default: false,
  },
  errorText: {
    type: String,
    required: false,
    default: null,
  },
  showInline: {
    type: Boolean,
    required: false,
    default: false,
  },
  // The float-label CSS (_float_label.scss) floats the label whenever the
  // input has a value, is focused, OR has a `placeholder` attribute present.
  // Without a placeholder, an empty BaseCalendar's label sits inline until
  // the user interacts with it, which looks inconsistent next to a field
  // that already has a value. Pass a non-empty placeholder (e.g. " ") to
  // keep the label floated at all times - same trigger BaseInputText/BaseSelect
  // rely on via FloatLabel's default "on" variant.
  placeholder: {
    type: String,
    required: false,
    default: null,
  },
  disabled: {
    type: Boolean,
    required: false,
    default: false,
  },
})

function getLocalePrefix(locale) {
  const defaultLang = "en"
  return typeof locale === "string" ? locale.split("_")[0] : defaultLang
}

const dateFormat = computed(() => {
  switch (localePrefix.value) {
    case "en":
      return "mm/dd/yy"
    case "fr":
      return "dd/mm/yy"
    case "de":
      return "dd.mm.yy"
    case "es":
      return "dd/mm/yy"
    default:
      return "dd/mm/yy"
  }
})

const selectedLocale = computed(() => calendarLocales[localePrefix.value] || calendarLocales.en)

const primevue = usePrimeVue()
onMounted(() => {
  if (selectedLocale.value) {
    primevue.config.locale = selectedLocale.value
  }
})

// When showTime is enabled, do NOT allow manual input.
// Manual typing can produce ambiguous strings like "09/01/2025" which might be sent to backend.
const allowManualInput = computed(() => {
  if (props.type === "range") {
    return false
  }
  return !props.showTime
})

// When showTime is false, we keep the old behavior: update parent immediately
watch(
  () => internalValue.value,
  (newValue) => {
    if (!props.showTime) {
      modelValue.value = newValue
    }
  },
)

function isSameCalendarDay(a, b) {
  return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate()
}

// Workaround for a PrimeVue DatePicker limitation (selectionMode "range" +
// showTime): its range+time click handling tracks a single shared
// currentHour/currentMinute state instead of one per endpoint (see
// onDateSelect/updateModelTime in primevue/datepicker). Picking the SAME
// calendar day twice to build a same-day time range (e.g. 10:00 -> 17:00)
// can silently discard the first click instead of completing the range,
// whenever the shared time state at the moment of the second click compares
// "earlier" than the time already baked into the first click - PrimeVue then
// takes its "start a new selection" branch instead of its "set the end
// date" branch (onDateSelect's `else` branch, which also leaves its internal
// focusedDateIndex at 0/start). We can't intercept that internal comparison
// (it runs before v-model ever sees the result), so instead we detect the
// resulting signature - the model going from an incomplete [date, null]
// straight to another incomplete [date, null] on the SAME day - and
// reconstruct the range ourselves from the two clicked times rather than
// accepting the reset. A genuine second click on a different day, or a
// click that starts a brand new range after a complete one, are both left
// untouched.
//
// Fixing the visible value alone isn't enough: PrimeVue's own internal
// `rawValue` resyncs from our corrected v-model automatically (see its
// `modelValue` watcher), but `focusedDateIndex` does not - it stays stuck
// at 0 from the reset branch above, so every subsequent time-picker tick
// would keep editing the START time instead of the END time we just fixed
// up. `focusedDateIndex` is plain internal component data (same class of
// property as `overlayVisible`, which this file already reaches into via
// datepickerRef for hideOverlay()), so we correct it the same way.
if ("range" === props.type) {
  watch(internalValue, (newValue, oldValue) => {
    if (!Array.isArray(newValue) || !Array.isArray(oldValue)) return

    const [oldStart, oldEnd] = oldValue
    const [newStart, newEnd] = newValue

    if (!(oldStart instanceof Date) || null !== oldEnd) return
    if (!(newStart instanceof Date) || null !== newEnd) return
    if (oldStart.getTime() === newStart.getTime()) return
    if (!isSameCalendarDay(oldStart, newStart)) return

    const [earlier, later] = oldStart.getTime() <= newStart.getTime() ? [oldStart, newStart] : [newStart, oldStart]
    internalValue.value = [earlier, later]

    const instance = datepickerRef.value
    if (instance && "focusedDateIndex" in instance) {
      instance.focusedDateIndex = 1
    }
  })
}

// Safely hide the calendar overlay (PrimeVue internal API)
const hideOverlay = () => {
  const instance = datepickerRef.value
  if (!instance) {
    return
  }

  // PrimeVue DatePicker exposes overlayVisible / hideOverlay in runtime instance
  if (typeof instance.hideOverlay === "function") {
    instance.hideOverlay()
    return
  }

  if ("overlayVisible" in instance) {
    instance.overlayVisible = false
  }
}

// User confirms the current selection
const onApplyClick = () => {
  modelValue.value = internalValue.value
  hideOverlay()
}

// User cancels the selection and restores external value
const onCancelClick = () => {
  internalValue.value = modelValue.value
  hideOverlay()
}
</script>

<template>
  <div class="field">
    <FloatLabel variant="on">
      <DatePicker
        ref="datepickerRef"
        v-model="internalValue"
        :date-format="dateFormat"
        :disabled="disabled"
        :inline="showInline"
        :input-id="id"
        :invalid="isInvalid"
        :manual-input="allowManualInput"
        :placeholder="placeholder"
        :selection-mode="type"
        :show-time="showTime"
        :step-minute="timepicketIncrement"
        fluid
        icon-display="input"
        show-icon
      >
        <!-- Custom footer only when using time selection -->
        <template
          v-if="showTime"
          #footer
        >
          <div class="base-calendar-footer">
            <BaseButton
              :label="t('Cancel')"
              icon="close"
              size="small"
              type="black"
              @click="onCancelClick"
            />
            <BaseButton
              :label="t('Select')"
              icon="confirm"
              size="small"
              type="secondary"
              @click="onApplyClick"
            />
          </div>
        </template>
      </DatePicker>
      <label
        :for="id"
        v-text="label"
      />
    </FloatLabel>
    <Message
      v-if="isInvalid"
      size="small"
      severity="seconday"
      variant="simple"
    >
      {{ errorText }}
    </Message>
  </div>
</template>
