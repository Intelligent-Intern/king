<template>
  <AppModalShell
    :open="open"
    :title="t('appointment_settings.title')"
    :aria-label="t('appointment_settings.dialog_aria')"
    dialog-class="calls-modal-dialog appointment-settings-dialog"
    body-class="calls-modal-body appointment-settings-body"
    footer-class="calls-modal-footer appointment-settings-footer"
    @close="$emit('close')"
  >
    <template #body>
      <label class="field">
        <span>{{ t('appointment_settings.slot_size') }}</span>
        <select v-model.number="draft.slot_minutes" class="input">
          <option v-for="minutes in slotMinuteOptions" :key="minutes" :value="minutes">
            {{ t('appointment_settings.minutes', { minutes }) }}
          </option>
        </select>
      </label>

      <label class="field">
        <span>{{ t('appointment_settings.invitation_text') }}</span>
        <textarea
          v-model.trim="draft.invitation_text"
          class="calls-textarea appointment-settings-text"
          maxlength="1200"
          rows="6"
        ></textarea>
      </label>
    </template>

    <template #footer>
      <button class="btn" type="button" :disabled="saving" @click="$emit('close')">{{ t('common.cancel') }}</button>
      <button class="btn btn-cyan" type="button" :disabled="saving" @click="confirm">
        {{ saving ? t('common.saving') : t('common.save') }}
      </button>
    </template>
  </AppModalShell>
</template>

<script setup>
import { reactive, watch } from 'vue';
import AppModalShell from '../../../components/AppModalShell.vue';
import { t } from '../../../modules/localization/i18nRuntime.js';

const props = defineProps({
  open: {
    type: Boolean,
    default: false,
  },
  saving: {
    type: Boolean,
    default: false,
  },
  settings: {
    type: Object,
    default: () => ({}),
  },
});

const emit = defineEmits(['close', 'confirm']);
const slotMinuteOptions = [5, 10, 15, 20, 30, 45, 60];
const draft = reactive({ slot_minutes: 15, invitation_text: '' });

function syncDraft() {
  const minutes = Number.parseInt(String(props.settings?.slot_minutes || 15), 10);
  draft.slot_minutes = slotMinuteOptions.includes(minutes) ? minutes : 15;
  draft.invitation_text = String(props.settings?.invitation_text || '');
}

function confirm() {
  emit('confirm', {
    slot_minutes: draft.slot_minutes,
    invitation_text: draft.invitation_text,
  });
}

watch(() => props.open, (open) => {
  if (open) syncDraft();
});

watch(() => props.settings, syncDraft, { immediate: true, deep: true });
</script>

<style scoped>
:deep(.appointment-settings-dialog) {
  width: min(560px, calc(100vw - 24px));
  max-height: calc(100dvh - 24px);
}

:deep(.appointment-settings-body) {
  display: grid;
  gap: 12px;
}

.appointment-settings-text {
  min-height: 140px;
}

.calls-textarea {
  width: 100%;
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  background: var(--bg-input);
  color: var(--color-0a1322);
  padding: 8px 10px;
  resize: vertical;
}
</style>
