<template>
  <section
    v-if="visible"
    class="workspace-owner-absence-banner"
    :class="{ ended: endedVisible }"
    role="status"
    aria-live="polite"
    data-testid="owner-absence-countdown"
  >
    <span class="workspace-owner-absence-text">{{ message }}</span>
    <span
      v-if="countdownVisible"
      class="workspace-owner-absence-time"
      data-testid="owner-absence-remaining"
    >
      {{ countdownLabel }}
    </span>
  </section>
</template>

<script setup>
import { computed } from 'vue';
import { t } from '../../modules/localization/i18nRuntime.js';
import {
  formatOwnerAbsenceCountdown,
  normalizeOwnerAbsencePayload,
  shouldShowOwnerAbsenceCountdown,
  shouldShowOwnerAbsenceEnded,
} from './workspace/callWorkspace/ownerAbsenceState.js';

const props = defineProps({
  ownerAbsence: {
    type: Object,
    default: null,
  },
});

const state = computed(() => normalizeOwnerAbsencePayload(props.ownerAbsence));
const countdownVisible = computed(() => shouldShowOwnerAbsenceCountdown(state.value));
const endedVisible = computed(() => shouldShowOwnerAbsenceEnded(state.value));
const visible = computed(() => countdownVisible.value || endedVisible.value);
const countdownLabel = computed(() => formatOwnerAbsenceCountdown(state.value?.countdownRemainingMs || 0));
const message = computed(() => (
  endedVisible.value
    ? t('calls.workspace.owner_absence_ended')
    : t('calls.workspace.owner_absence_countdown', { countdown: countdownLabel.value })
));
</script>
