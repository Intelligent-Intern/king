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
        <label v-for="field in fields" :key="field.key" :class="fieldClass(field)">
          <span>{{ fieldLabel(field) }}</span>
          <textarea
            v-if="field.type === 'textarea'"
            v-model.trim="form[field.key]"
            class="input governance-textarea"
            rows="4"
          ></textarea>
          <AppSelect v-else-if="field.type === 'enum'" v-model="form[field.key]">
            <option v-for="option in field.options || []" :key="option.value" :value="option.value">
              {{ optionLabel(option) }}
            </option>
          </AppSelect>
          <input
            v-else
            v-model.trim="form[field.key]"
            class="input"
            :type="inputType(field)"
            :autocomplete="field.autocomplete || 'off'"
          />
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
  fields: {
    type: Array,
    default: () => [],
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

function fieldLabel(field) {
  const key = String(field?.label_key || '').trim();
  return key !== '' ? t(key) : String(field?.key || '');
}

function optionLabel(option) {
  const key = String(option?.label_key || '').trim();
  return key !== '' ? t(key) : String(option?.label || option?.value || '');
}

function inputType(field) {
  return String(field?.input_type || 'text').trim() || 'text';
}

function fieldClass(field) {
  return {
    'governance-field': true,
    'governance-field-wide': field?.wide === true || field?.type === 'textarea',
  };
}
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
