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
      </div>

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
                <div class="governance-name">{{ rowLabel(row) }}</div>
                <div class="governance-subline">{{ targetEntityLabel }}</div>
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
            @click="navigator.push(nestedRelation)"
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
      <button v-if="navigator.stack.value.length > 1" class="btn" type="button" @click="navigator.back">
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
import { computed, watch } from 'vue';
import AppModalShell from '../../../components/AppModalShell.vue';
import AppPagination from '../../../components/AppPagination.vue';
import { t } from '../../localization/i18nRuntime.js';
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
  maximized: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(['close', 'apply', 'update:maximized']);

const navigator = useCrudRelationNavigator({
  rowProvider: (entityKey) => props.rowProvider(entityKey),
});

const title = computed(() => t('governance.relation_picker.title', { relation: relationLabel(props.relation) }));
const isMultiple = computed(() => navigator.currentFrame.value?.selection_mode === 'multiple');
const nestedRelations = computed(() => navigator.currentDescriptor.value?.relationships || []);
const targetEntityLabel = computed(() => {
  const descriptor = navigator.currentDescriptor.value;
  if (!descriptor) return t('common.not_available');
  return t(`navigation.governance.${descriptor.entity_key.replace('-', '_')}`);
});

watch(
  () => [props.open, props.relation],
  () => {
    if (!props.open || !props.relation) return;
    navigator.reset(props.relation, selectedRowsForRelation(props.relation));
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

function goBackTo(index) {
  while (navigator.stack.value.length > index + 1) {
    navigator.back();
  }
}

function applySelection() {
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

.crud-relation-toolbar {
  justify-content: space-between;
}

.crud-relation-count {
  color: var(--text-muted);
  font-size: 12px;
  font-weight: 700;
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

.crud-relation-empty {
  padding: 18px 12px;
  color: var(--text-muted);
  text-align: center;
}
</style>
