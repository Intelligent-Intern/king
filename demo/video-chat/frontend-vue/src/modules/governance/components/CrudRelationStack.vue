<template>
  <AppModalShell
    :open="open"
    :title="title"
    :aria-label="title"
    root-class-name="governance-modal crud-relation-modal"
    backdrop-class="governance-modal-backdrop"
    dialog-class="governance-modal-dialog crud-relation-dialog"
    header-class="governance-modal-head governance-modal-head-brand"
    header-left-class="governance-modal-head-left"
    logo-class="governance-modal-head-logo"
    body-class="governance-modal-body crud-relation-body"
    footer-class="governance-modal-footer crud-relation-footer"
    :close-label="t('governance.close_modal')"
    maximizable
    :maximized="maximized"
    @update:maximized="$emit('update:maximized', $event)"
    @close="$emit('close')"
  >
    <template #body>
      <nav v-if="navigator.stack.value.length > 1" class="crud-relation-breadcrumbs" :aria-label="t('governance.relation_picker.stack')">
        <button
          v-for="(frame, index) in navigator.stack.value"
          :key="`${frame.key}:${index}`"
          class="crud-relation-crumb"
          type="button"
          :disabled="index === navigator.stack.value.length - 1"
          @click="goBackTo(index)"
        >
          {{ frameLabel(frame) }}
        </button>
      </nav>

      <div class="crud-relation-toolbar">
        <label class="search-field search-field-main" :aria-label="t('governance.relation_picker.search')">
          <input
            v-model.trim="navigator.query.value"
            class="input"
            type="search"
            :placeholder="t('governance.relation_picker.search')"
          />
        </label>
        <span class="crud-relation-count">
          {{ t('governance.relation_picker.selected_count', { count: navigator.currentSelectionIds.value.length }) }}
        </span>
        <button v-if="canCreateDraft" class="btn" type="button" :disabled="draftSaving" @click="startCreateDraft">
          {{ t('governance.relation_picker.create') }}
        </button>
      </div>

      <form v-if="creatingDraft" class="crud-relation-create" autocomplete="off" @submit.prevent="submitCreateDraft">
        <label v-for="field in createFields" :key="field.key" :class="fieldClass(field)">
          <span>{{ fieldLabel(field) }}</span>
          <textarea
            v-if="field.type === 'textarea'"
            v-model.trim="draft[field.key]"
            class="input crud-relation-textarea"
            rows="3"
          ></textarea>
          <AppSelect v-else-if="field.type === 'enum'" v-model="draft[field.key]">
            <option v-for="option in field.options || []" :key="option.value" :value="option.value">
              {{ optionLabel(option) }}
            </option>
          </AppSelect>
          <input
            v-else
            v-model.trim="draft[field.key]"
            class="input"
            :type="field.input_type || 'text'"
            autocomplete="off"
          />
        </label>
        <p v-if="draftError" class="crud-relation-error">{{ draftError }}</p>
        <div class="crud-relation-create-actions">
          <button class="btn" type="button" @click="creatingDraft = false">{{ t('common.cancel') }}</button>
          <button class="btn btn-cyan" type="submit" :disabled="draftSaving">
            {{ draftSaving ? t('common.saving') : t('governance.relation_picker.save_draft') }}
          </button>
        </div>
      </form>

      <section class="crud-relation-content">
        <table class="crud-relation-table">
          <thead>
            <tr>
              <th>{{ t('governance.relation_picker.select') }}</th>
              <th>{{ t('governance.name') }}</th>
              <th>{{ t('governance.key') }}</th>
              <th>{{ t('governance.status') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in navigator.pagedRows.value" :key="navigator.rowId(row)">
              <td :data-label="t('governance.relation_picker.select')">
                <input
                  class="crud-relation-check"
                  :type="isMultiple ? 'checkbox' : 'radio'"
                  :checked="navigator.rowSelected(row)"
                  :name="`relation-${navigator.currentFrame.value?.key || 'root'}`"
                  @change="navigator.toggleRow(row)"
                />
              </td>
              <td :data-label="t('governance.name')">
                <div class="crud-relation-name">{{ rowLabel(row) }}</div>
                <div class="crud-relation-subline">{{ targetEntityLabel }}</div>
              </td>
              <td :data-label="t('governance.key')">{{ row.key || t('common.not_available') }}</td>
              <td :data-label="t('governance.status')">{{ row.status || t('common.not_available') }}</td>
            </tr>
            <tr v-if="navigator.filteredRows.value.length === 0">
              <td colspan="4" class="crud-relation-empty">{{ t('governance.relation_picker.empty') }}</td>
            </tr>
          </tbody>
        </table>

        <section v-if="nestedRelations.length > 0" class="crud-relation-nested">
          <button
            v-for="nestedRelation in nestedRelations"
            :key="nestedRelation.key"
            class="crud-relation-link"
            type="button"
            :disabled="!canOpenNestedRelation"
            @click="pushNestedRelation(nestedRelation)"
          >
            <strong>+1</strong>
            <span>{{ relationLabel(nestedRelation) }}</span>
          </button>
        </section>
      </section>

      <AppPagination
        :page="navigator.page.value"
        :page-count="navigator.pageCount.value"
        :total="navigator.filteredRows.value.length"
        :total-label="targetEntityLabel"
        :has-prev="navigator.page.value > 1"
        :has-next="navigator.page.value < navigator.pageCount.value"
        @page-change="navigator.goToPage"
      />
    </template>

    <template #footer>
      <button v-if="navigator.stack.value.length > 1" class="btn" type="button" @click="returnToParent">
        {{ t('governance.relation_picker.back') }}
      </button>
      <button class="btn" type="button" @click="$emit('close')">{{ t('common.cancel') }}</button>
      <button class="btn btn-cyan" type="button" @click="applySelection">
        {{ t('governance.relation_picker.apply') }}
      </button>
    </template>
  </AppModalShell>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue';
import AppModalShell from '../../../components/AppModalShell.vue';
import AppPagination from '../../../components/AppPagination.vue';
import AppSelect from '../../../components/AppSelect.vue';
import { t } from '../../localization/i18nRuntime.js';
import { descriptorAllowsAction } from '../crudDescriptors.js';
import { useCrudRelationNavigator } from '../useCrudRelationNavigator.js';

const props = defineProps({
  open: {
    type: Boolean,
    default: false,
  },
  relation: {
    type: Object,
    default: null,
  },
  selections: {
    type: Object,
    default: () => ({}),
  },
  rowProvider: {
    type: Function,
    default: () => [],
  },
  createDraft: {
    type: Function,
    default: null,
  },
  canCreateDraftForEntity: {
    type: Function,
    default: () => true,
  },
  showNestedRelations: {
    type: Boolean,
    default: true,
  },
  maximized: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(['close', 'apply', 'update:maximized']);

const navigator = useCrudRelationNavigator({
  rowProvider: (entityKey) => props.rowProvider(entityKey),
});
const creatingDraft = ref(false);
const draftSaving = ref(false);
const draftError = ref('');
const draft = reactive({});

const title = computed(() => t('governance.relation_picker.title', { relation: relationLabel(props.relation) }));
const isMultiple = computed(() => navigator.currentFrame.value?.selection_mode === 'multiple');
const nestedRelations = computed(() => (props.showNestedRelations ? navigator.currentDescriptor.value?.relationships || [] : []));
const canOpenNestedRelation = computed(() => navigator.currentSelectionIds.value.length > 0);
const createFields = computed(() => (navigator.currentDescriptor.value?.fields || []).filter((field) => (
  field && field.readonly !== true && field.type !== 'relation'
)));
const canCreateDraft = computed(() => (
  descriptorAllowsAction(navigator.currentDescriptor.value, 'create')
  && typeof props.createDraft === 'function'
  && props.canCreateDraftForEntity(navigator.currentDescriptor.value?.entity_key || '')
  && createFields.value.length > 0
));
const targetEntityLabel = computed(() => {
  const descriptor = navigator.currentDescriptor.value;
  if (!descriptor) return frameLabel(navigator.currentFrame.value);
  return t(`navigation.governance.${descriptor.entity_key.replace('-', '_')}`);
});

watch(
  () => [props.open, props.relation],
  () => {
    if (!props.open || !props.relation) return;
    navigator.reset(props.relation, selectedRowsForRelation(props.relation));
    creatingDraft.value = false;
    draftError.value = '';
  },
  { immediate: true },
);

function selectedRowsForRelation(relation) {
  const key = String(relation?.key || '').trim();
  return Array.isArray(props.selections?.[key]) ? props.selections[key] : [];
}

function relationLabel(relation) {
  const key = String(relation?.label_key || '').trim();
  return key !== '' ? t(key) : String(relation?.key || '');
}

function frameLabel(frame) {
  return relationLabel(frame);
}

function rowLabel(row) {
  return String(row?.name || row?.display_name || row?.email || row?.key || row?.id || '').trim() || t('common.not_available');
}

function fieldLabel(field) {
  const key = String(field?.label_key || '').trim();
  return key !== '' ? t(key) : String(field?.key || '');
}

function optionLabel(option) {
  const key = String(option?.label_key || '').trim();
  return key !== '' ? t(key) : String(option?.label || option?.value || '');
}

function fieldClass(field) {
  return {
    'crud-relation-field': true,
    'crud-relation-field-wide': field?.wide === true || field?.type === 'textarea',
  };
}

function fieldDefaultValue(field) {
  if (field.default !== undefined) return field.default;
  if (field.type === 'enum' && Array.isArray(field.options) && field.options.length > 0) {
    return field.options[0].value;
  }
  return '';
}

function startCreateDraft() {
  for (const key of Object.keys(draft)) {
    delete draft[key];
  }
  for (const field of createFields.value) {
    draft[field.key] = fieldDefaultValue(field);
  }
  draftError.value = '';
  creatingDraft.value = true;
}

async function submitCreateDraft() {
  if (draftSaving.value) return;
  const missingField = createFields.value.find((field) => (
    field.required === true && String(draft[field.key] || '').trim() === ''
  ));
  if (missingField) {
    draftError.value = t('governance.field_required', { field: fieldLabel(missingField) });
    return;
  }

  draftSaving.value = true;
  try {
    const row = await props.createDraft?.(
      navigator.currentDescriptor.value?.entity_key || '',
      Object.fromEntries(createFields.value.map((field) => [field.key, String(draft[field.key] || '').trim()])),
    );
    if (row) {
      navigator.toggleRow(row);
    }
    creatingDraft.value = false;
    draftError.value = '';
  } catch (error) {
    draftError.value = error instanceof Error ? error.message : t('governance.save_failed');
  } finally {
    draftSaving.value = false;
  }
}

function goBackTo(index) {
  while (navigator.stack.value.length > index + 1) {
    returnToParent();
  }
}

function pushNestedRelation(relation) {
  if (!canOpenNestedRelation.value) return;
  navigator.push(relation);
}

function returnToParent() {
  if (!navigator.applyCurrentSelectionToParent()) {
    navigator.back();
  }
}

function applySelection() {
  if (navigator.stack.value.length > 1) {
    returnToParent();
    return;
  }
  emit('apply', {
    relation: navigator.currentFrame.value,
    selectedRows: navigator.currentSelectedRows.value,
    selectedIds: navigator.currentSelectionIds.value,
    stack: navigator.stack.value,
  });
}
</script>

<style scoped>
:deep(.crud-relation-body) {
  display: grid;
  gap: 12px;
}

.crud-relation-breadcrumbs,
.crud-relation-toolbar,
.crud-relation-nested,
:deep(.crud-relation-footer) {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
}

.crud-relation-crumb,
.crud-relation-link {
  border: 1px solid var(--border-subtle);
  border-radius: 8px;
  background: var(--bg-soft);
  color: var(--text-main);
  cursor: pointer;
}

.crud-relation-crumb {
  padding: 6px 10px;
}

.crud-relation-link {
  display: inline-flex;
  gap: 8px;
  align-items: center;
  padding: 8px 10px;
}

.crud-relation-link strong {
  color: var(--accent-cyan);
}

.crud-relation-link:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}

.crud-relation-toolbar {
  justify-content: space-between;
}

.crud-relation-count {
  color: var(--text-muted);
  font-size: 12px;
  font-weight: 700;
}

.crud-relation-create {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
  padding: 10px;
  border: 1px solid var(--border-subtle);
  border-radius: 8px;
  background: var(--bg-soft);
}

.crud-relation-field {
  display: grid;
  gap: 6px;
  color: var(--text-secondary);
  font-size: 12px;
  font-weight: 700;
}

.crud-relation-field-wide,
.crud-relation-error,
.crud-relation-create-actions {
  grid-column: 1 / -1;
}

.crud-relation-textarea {
  min-height: 82px;
  resize: vertical;
}

.crud-relation-error {
  margin: 0;
  color: var(--color-ffb5b5);
}

.crud-relation-create-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}

.crud-relation-content {
  min-height: 0;
  overflow: auto;
}

.crud-relation-table {
  width: 100%;
  table-layout: fixed;
}

.crud-relation-table th:first-child,
.crud-relation-table td:first-child {
  width: 82px;
}

.crud-relation-check {
  width: 18px;
  height: 18px;
}

.crud-relation-name {
  overflow: hidden;
  color: var(--text-main);
  font-weight: 700;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.crud-relation-subline {
  display: block;
  margin-top: 3px;
  color: var(--text-muted);
  font-size: 11px;
}

.crud-relation-empty {
  padding: 18px 12px;
  color: var(--text-muted);
  text-align: center;
}

@media (max-width: 760px) {
  .crud-relation-create {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
