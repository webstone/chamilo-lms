<template>
  <div class="flex items-center gap-2">
    <BaseButton
      :label="label"
      :size="size"
      icon="attachment"
      type="primary"
      @click="showFileDialog"
    />
    <p class="text-gray-90">
      {{ fileName }}
    </p>
    <input
      ref="inputFile"
      :accept="accept"
      :id="inputId"
      :name="inputName"
      class="hidden"
      type="file"
    />
  </div>
</template>

<script setup>
import BaseButton from "./BaseButton.vue"
import { onMounted, ref } from "vue"
import { sizeValidator } from "./validators"

defineProps({
  label: {
    type: String,
    required: true,
  },
  accept: {
    type: String,
    default: undefined,
  },
  size: {
    type: String,
    default: "normal",
    validator: sizeValidator,
  },
  // Optional id/name for the underlying (visually hidden) <input type="file">.
  // Not set by any caller before the Homework module: the element has no id,
  // name, or wrapping <label>, so it cannot be targeted by Mink/Behat's
  // standard "field" locator (id|name|label|placeholder) - see
  // tests/behat/features/toolHomework.feature's file-upload steps, which rely
  // on these to address the field via the existing, generic
  // "I attach the file ... to ..." step instead of a new one-off step
  // definition. Left undefined by default so every pre-existing caller keeps
  // rendering exactly as before.
  inputId: {
    type: String,
    default: undefined,
  },
  inputName: {
    type: String,
    default: undefined,
  },
})

const emit = defineEmits(["fileSelected"])

const inputFile = ref(null)
const fileName = ref("")

onMounted(() => {
  inputFile.value.addEventListener("change", fileSelected)
})

const fileSelected = () => {
  let file = inputFile.value.files[0]
  fileName.value = file.name
  emit("fileSelected", file)
}

const showFileDialog = () => {
  inputFile.value.click()
}
</script>
