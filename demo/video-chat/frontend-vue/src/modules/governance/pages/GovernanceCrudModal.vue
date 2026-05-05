<template>
  <AppModalShell
    :open="open"
    :title="title"
    :aria-label="title"
    root-class-name="governance-modal"
    backdrop-class="governance-modal-backdrop"
    dialog-class="governance-modal-dialog"
    header-class="governance-modal-head governance-modal-head-brand"
    header-left-class="governance-modal-head-left"
    logo-class="governance-modal-head-logo"
    body-class="governance-modal-body"
    footer-class="governance-modal-footer"
    :close-label="t('governance.close_modal')"
    maximizable
    :maximized="maximized"
    @update:maximized="$emit('update:maximized', $event)"
    @close="$emit('close')"
  >
    <template #body>
      <form id="governanceCrudForm" class="governance-form" autocomplete="off" @submit.prevent="$emit('submit')">
        <label class="governance-field">
          <span>{{ t('governance.name') }}</span>
          <input v-model.trim="form.name" class="input" type="text" autocomplete="off" />
        </label>
        <label class="governance-field">
          <span>{{ t('governance.key') }}</span>
          <input v-model.trim="form.key" class="input" type="text" autocomplete="off" />
        </label>
        <label class="governance-field governance-field-wide">
          <span>{{ t('governance.description') }}</span>
          <textarea v-model.trim="form.description" class="input governance-textarea" rows="4"></textarea>
        </label>
        <label class="governance-field">
          <span>{{ t('governance.status') }}</span>
          <AppSelect v-model="form.status">
            <option value="active">active</option>
            <option value="draft">draft</option>
            <option value="disabled">disabled</option>
          </AppSelect>
        </label>
      </form>
      <p v-if="error" class="governance-form-error">{{ error }}</p>
    </template>

    <template #footer>
      <button class="btn" type="button" :disabled="saving" @click="$emit('close')">{{ t('common.cancel') }}</button>
      <button class="btn btn-cyan" type="submit" form="governanceCrudForm" :disabled="saving">
        {{ saving ? t('settings.saving') : submitLabel }}
      </button>
    </template>
  </AppModalShell>
</template>

<script setup>
import AppModalShell from '../../../components/AppModalShell.vue';
import AppSelect from '../../../components/AppSelect.vue';
import { t } from '../../localization/i18nRuntime.js';

defineProps({
  open: {
    type: Boolean,
    default: false,
  },
  title: {
    type: String,
    required: true,
  },
  submitLabel: {
    type: String,
    default: '',
  },
  form: {
    type: Object,
    required: true,
  },
  saving: {
    type: Boolean,
    default: false,
  },
  error: {
    type: String,
    default: '',
  },
  maximized: {
    type: Boolean,
    default: false,
  },
});

defineEmits(['close', 'submit', 'update:maximized']);
</script>

<style scoped>
.governance-form {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.governance-field {
  display: grid;
  gap: 6px;
  color: var(--text-secondary);
  font-size: 12px;
  font-weight: 700;
}

.governance-field-wide {
  grid-column: 1 / -1;
}

.governance-textarea {
  min-height: 96px;
  resize: vertical;
}

.governance-form-error {
  margin: 0;
  color: var(--color-ffb5b5);
}

@media (max-width: 760px) {
  .governance-form {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
