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
      <section v-if="relationships.length > 0" class="governance-relations">
        <span class="governance-relations-title">{{ t('governance.relationships') }}</span>
        <button
          v-for="relationship in relationships"
          :key="relationship.key"
          class="governance-relation-link"
          type="button"
          @click="$emit('open-relation', relationship)"
        >
          <strong>+1</strong>
          <span>{{ relationshipLabel(relationship) }}</span>
          <em>{{ relationSummary(relationship) }}</em>
        </button>
      </section>
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

const props = defineProps({
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
  relationships: {
    type: Array,
    default: () => [],
  },
  relationSelections: {
    type: Object,
    default: () => ({}),
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

defineEmits(['close', 'submit', 'update:maximized', 'open-relation']);

function fieldLabel(field) {
  const key = String(field?.label_key || '').trim();
  return key !== '' ? t(key) : String(field?.key || '');
}

function optionLabel(option) {
  const key = String(option?.label_key || '').trim();
  return key !== '' ? t(key) : String(option?.label || option?.value || '');
}

function relationshipLabel(relationship) {
  const key = String(relationship?.label_key || '').trim();
  return key !== '' ? t(key) : String(relationship?.key || '');
}

function relationSummary(relationship) {
  const key = String(relationship?.key || '').trim();
  const selected = Array.isArray(props.relationSelections?.[key]) ? props.relationSelections[key] : [];
  if (selected.length === 0) return t('governance.relation_picker.none_selected');
  return t('governance.relation_picker.selected_count', { count: selected.length });
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

.governance-relations {
  display: grid;
  gap: 8px;
  margin-top: 12px;
}

.governance-relations-title {
  color: var(--text-secondary);
  font-size: 12px;
  font-weight: 700;
}

.governance-relation-link {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  gap: 8px;
  align-items: center;
  padding: 9px 10px;
  border: 1px solid var(--border-subtle);
  border-radius: 8px;
  background: var(--bg-soft);
  color: var(--text-main);
  cursor: pointer;
  text-align: start;
}

.governance-relation-link strong {
  color: var(--accent-cyan);
}

.governance-relation-link em {
  color: var(--text-muted);
  font-size: 12px;
  font-style: normal;
}

@media (max-width: 760px) {
  .governance-form {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
