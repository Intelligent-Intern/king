<template>
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
      <template v-for="section in sections" :key="section.key">
        <tr v-if="section.label" class="crud-relation-group-row">
          <td colspan="4">{{ section.label }}</td>
        </tr>
        <tr
          v-for="(row, rowIndex) in section.rows"
          :key="displayRowId(row) || `${section.key}:${rowIndex}`"
        >
          <td :data-label="t('governance.relation_picker.select')">
            <input
              class="crud-relation-check"
              :type="multiple ? 'checkbox' : 'radio'"
              :checked="isSelected(row)"
              :name="`relation-${frameKey || 'root'}`"
              @change="$emit('toggle-row', row)"
            />
          </td>
          <td :data-label="t('governance.name')">
            <div class="crud-relation-name">{{ displayRowLabel(row) }}</div>
            <div class="crud-relation-subline">{{ displayRowSubline(row) }}</div>
          </td>
          <td :data-label="t('governance.key')">{{ row.key || t('common.not_available') }}</td>
          <td :data-label="t('governance.status')">{{ row.status || t('common.not_available') }}</td>
        </tr>
      </template>
      <tr v-if="isEmpty">
        <td colspan="4" class="crud-relation-empty">{{ t('governance.relation_picker.empty') }}</td>
      </tr>
    </tbody>
  </table>
</template>

<script setup>
import { computed } from 'vue';
import { t } from '../../localization/i18nRuntime.js';

const props = defineProps({
  sections: {
    type: Array,
    default: () => [],
  },
  selectedIds: {
    type: Array,
    default: () => [],
  },
  multiple: {
    type: Boolean,
    default: false,
  },
  frameKey: {
    type: String,
    default: '',
  },
  rowId: {
    type: Function,
    default: null,
  },
  rowLabel: {
    type: Function,
    default: null,
  },
  rowSubline: {
    type: Function,
    default: null,
  },
});

defineEmits(['toggle-row']);

const isEmpty = computed(() => props.sections.every((section) => (
  !Array.isArray(section?.rows) || section.rows.length === 0
)));

function normalizeString(value) {
  return String(value || '').trim();
}

function defaultRowId(row) {
  return normalizeString(row?.id || row?.key || row?.name);
}

function defaultRowLabel(row) {
  return normalizeString(row?.name || row?.display_name || row?.email || row?.key || row?.id) || t('common.not_available');
}

function displayRowId(row) {
  const resolver = typeof props.rowId === 'function' ? props.rowId : defaultRowId;
  return normalizeString(resolver(row));
}

function displayRowLabel(row) {
  const resolver = typeof props.rowLabel === 'function' ? props.rowLabel : defaultRowLabel;
  return normalizeString(resolver(row)) || defaultRowLabel(row);
}

function displayRowSubline(row) {
  const resolver = typeof props.rowSubline === 'function' ? props.rowSubline : () => '';
  return normalizeString(resolver(row));
}

function isSelected(row) {
  const id = displayRowId(row);
  return id !== '' && props.selectedIds.includes(id);
}
</script>

<style scoped>
.crud-relation-table {
  width: 100%;
  table-layout: fixed;
}

.crud-relation-table th:first-child,
.crud-relation-table td:first-child {
  width: 82px;
}

.crud-relation-group-row td {
  padding: 8px 12px;
  background: var(--bg-soft);
  color: var(--text-main);
  font-size: 12px;
  font-weight: 800;
  text-transform: uppercase;
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
</style>
