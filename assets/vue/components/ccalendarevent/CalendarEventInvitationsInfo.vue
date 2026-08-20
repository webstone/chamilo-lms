<script setup>
import { useI18n } from "vue-i18n"
import ShowLinks from "../resource_links/ShowLinks.vue"

const { t } = useI18n()

defineProps({
  event: {
    type: Object,
    required: true,
  },
})
</script>

<template>
  <div
    v-if="event.resourceLinkListFromEntity.length"
    class="invitations-info"
  >
    <h6
      v-text="t('Event invitations')"
      class="invitations-info__title"
    />

    <div class="invitations-info__item">
      <p v-text="t('Invitees')" />
      <div>
        <ShowLinks
          :item="event"
          :show-status="false"
        />
      </div>
    </div>
  </div>
</template>
