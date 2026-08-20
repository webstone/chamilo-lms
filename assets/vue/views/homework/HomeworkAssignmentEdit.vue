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
      <h3 v-text="t('Edit homework assignment')" />
    </div>

    <div
      v-if="isLoading"
      v-text="t('Loading...')"
    />

    <HomeworkAssignmentForm
      v-else-if="assignment"
      :default-assignment="assignment"
      :is-form-loading="isFormLoading"
      @submit="onSubmit"
    />
  </div>
</template>

<script setup>
import HomeworkAssignmentForm from "../../components/homework/HomeworkAssignmentForm.vue"
import { useI18n } from "vue-i18n"
import { onMounted, ref } from "vue"
import { useRoute, useRouter } from "vue-router"
import homeworkAssignmentService from "../../services/chomeworkassignment"
import { getCourseContext } from "../../utils/courseContext"
import { useNotification } from "../../composables/notification"
import BaseButton from "../../components/basecomponents/BaseButton.vue"

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const { cid, sid, gid } = getCourseContext()

const { showSuccessNotification, showErrorNotification } = useNotification()

// Route is registered as ":assignmentId(\\d+)/edit" (see
// assets/vue/router/homework.js), same param name convention already used by
// HomeworkSubmit.vue and HomeworkCorrectAndRate.vue.
const assignmentId = Number(route.params.assignmentId)

const assignment = ref(null)
const isLoading = ref(true)
const isFormLoading = ref(false)

onMounted(async () => {
  try {
    // getAssignment() is scoped server-side by HomeworkVoter::VIEW - any
    // teacher of the underlying course can view/edit the assignment
    // regardless of which session the request is currently scoped to (this
    // is intentional cross-session teacher access, not a bug - see
    // HomeworkVoter's docblock in
    // src/CoreBundle/Security/Authorization/Voter/HomeworkVoter.php).
    assignment.value = await homeworkAssignmentService.getAssignment(assignmentId)
  } catch (error) {
    showErrorNotification(error)
  } finally {
    isLoading.value = false
  }
})

function onSubmit(payload) {
  isFormLoading.value = true

  homeworkAssignmentService
    .updateAssignment(assignmentId, payload)
    .then(() => {
      showSuccessNotification(t("Homework assignment updated"))

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
