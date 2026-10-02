<script setup lang="ts">
/**
 * ADR-052 — the ONE place the "saved" dialog is rendered. Mounted once in
 * App.vue; every view raises it through useSaveFeedback's notifySaved() /
 * confirmSaved() instead of carrying a <SuccessDialog> of its own.
 */
import SuccessDialog from '@/design-system/components/SuccessDialog.vue'
import { dismissSaved, saveFeedbackState } from '@/composables/useSaveFeedback'
</script>

<template>
  <SuccessDialog
    :show="saveFeedbackState.show"
    :title="saveFeedbackState.title"
    :body="saveFeedbackState.body"
    data-test="save-feedback"
    @update:show="(v: boolean) => { if (!v) dismissSaved() }"
  />
</template>
