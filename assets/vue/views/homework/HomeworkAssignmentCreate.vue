<template>
  <div class="field space-y-2">
    <BaseButton
      :label="t('Back')"
      icon="back"
      only-icon
      size="small"
      type="black"
      @click="goBack"
    />
    <div class="field">
      <h3 v-text="t('Create assignment')" />
    </div>

    <HomeworkAssignmentForm
      :is-form-loading="isFormLoading"
      @submit="onSubmit"
    />
  </div>
</template>

<script setup>
import HomeworkAssignmentForm from "../../components/homework/HomeworkAssignmentForm.vue"
import { useI18n } from "vue-i18n"
import { ref } from "vue"
import homeworkAssignmentService from "../../services/chomeworkassignment"
import { getCourseContext } from "../../utils/courseContext"
import { useNotification } from "../../composables/notification"
import { useRouter } from "vue-router"
import BaseButton from "../../components/basecomponents/BaseButton.vue"

const { t } = useI18n()
const { cid, sid, gid } = getCourseContext()
const router = useRouter()

const { showSuccessNotification, showErrorNotification } = useNotification()

const isFormLoading = ref(false)

function onSubmit(assignment) {
  isFormLoading.value = true

  homeworkAssignmentService
    .createAssignment(assignment)
    .then(() => {
      showSuccessNotification(t("Assignment created"))

      goBack()
    })
    .catch((error) => showErrorNotification(error))
    .finally(() => (isFormLoading.value = false))
}

function goBack() {
  router.push({
    name: "HomeworkList",
    query: { cid, sid, gid },
  })
}
</script>
