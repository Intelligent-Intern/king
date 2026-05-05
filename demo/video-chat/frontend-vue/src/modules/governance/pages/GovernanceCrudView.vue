<template>
  <AdminPageFrame class="governance-crud-view" :title="title">
    <template #actions>
      <button v-if="createAction" class="btn btn-cyan" type="button" @click="openCreateModal">{{ createButtonLabel }}</button>
    </template>

    <template #toolbar>
      <label class="search-field search-field-main" :aria-label="t('governance.search', { entity: pluralLabel })">
        <input
          v-model.trim="query"
          class="input"
          type="search"
          :placeholder="t('governance.search', { entity: pluralLabel })"
        />
      </label>
    </template>

    <AdminTableFrame class="governance-table-wrap">
      <table class="governance-table">
        <thead>
          <tr>
            <th v-for="column in tableColumns" :key="column.key" :style="columnStyle(column)">
              {{ columnLabel(column) }}
            </th>
            <th v-if="showActionsColumn" class="governance-actions-col">{{ t('governance.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in pagedRows" :key="row.id">
            <td
              v-for="column in tableColumns"
              :key="`${row.id}:${column.key}`"
              :data-label="columnLabel(column)"
              :style="columnStyle(column)"
            >
              <template v-if="column.cell === 'primary'">
                <div class="governance-name">{{ rowCellValue(row, column) }}</div>
                <div class="governance-subline">{{ singularLabel }}</div>
              </template>
              <span v-else-if="column.cell === 'status'" class="tag" :class="statusClass(row[column.key])">
                {{ statusLabel(row[column.key]) }}
              </span>
              <template v-else-if="column.cell === 'description'">{{ rowDescription(row) }}</template>
              <template v-else-if="column.cell === 'datetime'">{{ formatDate(row[column.key]) }}</template>
              <template v-else>{{ rowCellValue(row, column) }}</template>
            </td>
            <td v-if="showActionsColumn" :data-label="t('governance.actions')">
              <span v-if="isRowReadonly(row)" class="governance-readonly-label">{{ t('governance.system') }}</span>
              <div v-else class="actions-inline">
                <AppIconButton
                  v-for="action in rowActions"
                  :key="action.key"
                  :icon="rowActionIcon(action)"
                  :title="rowActionTitle(action)"
                  :danger="action.danger === true"
                  @click="handleRowAction(action, row)"
                />
              </div>
            </td>
          </tr>
          <tr v-if="filteredRows.length === 0">
            <td :colspan="emptyColspan" class="governance-empty-cell">{{ t('governance.empty_filter') }}</td>
          </tr>
        </tbody>
      </table>
    </AdminTableFrame>

    <template #footer>
      <AppPagination
        :page="page"
        :page-count="pageCount"
        :total="filteredRows.length"
        :total-label="pluralLabel"
        :has-prev="page > 1"
        :has-next="page < pageCount"
        @page-change="goToPage"
      />
    </template>

    <GovernanceCrudModal
      :open="modalOpen"
      :title="modalTitle"
      :submit-label="modalSubmitLabel"
      :form="form"
      :fields="modalFields"
      :relationships="modalRelationships"
      :relation-selections="relationSelections"
      :error="formError"
      :maximized="modalMaximized"
      @update:maximized="modalMaximized = $event"
      @close="closeModal"
      @open-relation="openRelationNavigator"
      @submit="submitModal"
    />

    <CrudRelationStack
      :open="relationNavigatorOpen"
      :relation="relationNavigatorRelation"
      :selections="relationSelections"
      :row-provider="relationRowsForEntity"
      :maximized="relationNavigatorMaximized"
      @update:maximized="relationNavigatorMaximized = $event"
      @close="closeRelationNavigator"
      @apply="applyRelationSelection"
    />
  </AdminPageFrame>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import AppIconButton from '../../../components/AppIconButton.vue';
import AppPagination from '../../../components/AppPagination.vue';
import AdminPageFrame from '../../../components/admin/AdminPageFrame.vue';
import AdminTableFrame from '../../../components/admin/AdminTableFrame.vue';
import { sessionState } from '../../../domain/auth/session';
import { moduleAccessContextFromSession } from '../../../http/routeAccess.js';
import { formatLocalizedDateTimeDisplay } from '../../../support/dateTimeFormat';
import CrudRelationStack from '../components/CrudRelationStack.vue';
import GovernanceCrudModal from './GovernanceCrudModal.vue';
import { buildGovernanceCatalogRows } from '../../governanceCatalog.js';
import { descriptorAllowsAction, governanceCrudDescriptorForRoute } from '../crudDescriptors.js';
import { createEntitySummaryCache, normalizeEntitySummary } from '../entitySummaryCache.js';
import { workspaceModuleRegistry } from '../../index.js';
import { t } from '../../localization/i18nRuntime.js';
import { entryAllowsAccess } from '../../navigationBuilder.js';
import { firstRouteActionByKind, routeActionLabel, routeActionsForContext } from '../../routeActions.js';

const route = useRoute();
const rowsByScope = reactive({});
const query = ref('');
const page = ref(1);
const pageSize = 8;
const modalOpen = ref(false);
const modalMode = ref('create');
const modalMaximized = ref(false);
const relationNavigatorOpen = ref(false);
const relationNavigatorRelation = ref(null);
const relationNavigatorMaximized = ref(false);
const formError = ref('');
const form = reactive({ id: '' });
const relationSelections = reactive({});
const entitySummaryCache = createEntitySummaryCache();

const scopeKey = computed(() => String(route.name || route.path));
const crudDescriptor = computed(() => governanceCrudDescriptorForRoute(route) || {});
const catalogRows = computed(() => buildGovernanceCatalogRows(workspaceModuleRegistry, scopeKey.value));
const rows = computed(() => {
  if (!Array.isArray(rowsByScope[scopeKey.value])) {
    rowsByScope[scopeKey.value] = [];
  }
  return rowsByScope[scopeKey.value];
});
const visibleRows = computed(() => [...catalogRows.value, ...rows.value]);
const title = computed(() => routeLabel('pageTitle', 'pageTitle_key', t('navigation.governance')));
const singularLabel = computed(() => routeLabel('entitySingular', 'entitySingular_key', title.value));
const pluralLabel = computed(() => routeLabel('entityPlural', 'entityPlural_key', title.value));
const routeActionContext = computed(() => moduleAccessContextFromSession(sessionState));
const availableRouteActions = computed(() => routeActionsForContext(route, routeActionContext.value));
const tableColumns = computed(() => crudDescriptor.value.table_columns || []);
const modalFields = computed(() => (crudDescriptor.value.fields || []).filter((field) => (
  field && field.readonly !== true && field.type !== 'relation'
)));
const modalRelationships = computed(() => crudDescriptor.value.relationships || []);
const rowActions = computed(() => (crudDescriptor.value.row_actions || []).filter((action) => (
  entryAllowsAccess(action, routeActionContext.value, action.required_permissions)
)));
const showActionsColumn = computed(() => rowActions.value.length > 0);
const emptyColspan = computed(() => tableColumns.value.length + (showActionsColumn.value ? 1 : 0));
const createAction = computed(() => (
  descriptorAllowsAction(crudDescriptor.value, 'create')
    ? firstRouteActionByKind(availableRouteActions.value, 'create')
    : null
));
const createButtonLabel = computed(() => routeActionLabel(createAction.value, t, t('governance.create')));
const filteredRows = computed(() => {
  const needle = query.value.trim().toLowerCase();
  if (needle === '') return visibleRows.value;
  return visibleRows.value.filter((row) => rowSearchText(row).includes(needle));
});
const pageCount = computed(() => Math.max(1, Math.ceil(filteredRows.value.length / pageSize)));
const pagedRows = computed(() => {
  const offset = (page.value - 1) * pageSize;
  return filteredRows.value.slice(offset, offset + pageSize);
});
const modalTitle = computed(() => (
  modalMode.value === 'edit'
    ? t('governance.modal.edit', { entity: singularLabel.value })
    : t('governance.modal.create', { entity: singularLabel.value })
));
const modalSubmitLabel = computed(() => (modalMode.value === 'edit' ? t('common.save_changes') : createButtonLabel.value));

watch(() => route.fullPath, () => {
  query.value = '';
  page.value = 1;
  closeRelationNavigator();
  closeModal();
});

watch(filteredRows, () => {
  if (page.value > pageCount.value) {
    page.value = pageCount.value;
  }
});

watch(visibleRows, () => {
  const entityKey = String(crudDescriptor.value.entity_key || '').trim();
  if (entityKey !== '') {
    entitySummaryCache.upsertRows(entityKey, visibleRows.value);
  }
}, { immediate: true });

function routeLabel(key, keyKey, fallback) {
  const translationKey = typeof route.meta?.[keyKey] === 'string' ? route.meta[keyKey].trim() : '';
  if (translationKey !== '') return t(translationKey);
  const value = typeof route.meta?.[key] === 'string' ? route.meta[key].trim() : '';
  return value || fallback;
}

function localizedDescriptionParams(params = {}) {
  const source = params && typeof params === 'object' ? params : {};
  const localized = {};
  for (const [key, value] of Object.entries(source)) {
    if (key.endsWith('_key')) {
      const targetKey = key.slice(0, -4);
      if (String(value || '').trim() !== '') {
        localized[targetKey] = t(String(value));
      }
      continue;
    }
    if (!Object.prototype.hasOwnProperty.call(localized, key)) {
      localized[key] = value;
    }
  }
  return localized;
}

function columnLabel(column) {
  const key = String(column?.label_key || '').trim();
  return key !== '' ? t(key) : String(column?.key || '');
}

function columnStyle(column) {
  const width = String(column?.width || '').trim();
  return width !== '' ? { width } : {};
}

function fieldLabel(field) {
  const key = String(field?.label_key || '').trim();
  return key !== '' ? t(key) : String(field?.key || '');
}

function rowDescription(row) {
  const descriptionKey = typeof row?.description_key === 'string' ? row.description_key.trim() : '';
  if (descriptionKey !== '') {
    return t(descriptionKey, localizedDescriptionParams(row?.description_params));
  }
  const description = typeof row?.description === 'string' ? row.description.trim() : '';
  return description || t('common.not_available');
}

function fieldForKey(key) {
  return modalFields.value.find((field) => field.key === key) || null;
}

function optionLabel(field, value) {
  if (!field || !Array.isArray(field.options)) return '';
  const option = field.options.find((candidate) => String(candidate.value) === String(value));
  if (!option) return '';
  const key = String(option.label_key || '').trim();
  return key !== '' ? t(key) : String(option.label || option.value || '');
}

function rawColumnValue(row, column) {
  if (column?.cell === 'description') return rowDescription(row);
  return row?.[column?.key] ?? '';
}

function rowCellValue(row, column) {
  const value = rawColumnValue(row, column);
  if (String(value || '').trim() === '') return t('common.not_available');
  const enumLabel = optionLabel(fieldForKey(column?.key), value);
  return enumLabel !== '' ? enumLabel : String(value);
}

function rowSearchText(row) {
  const keys = crudDescriptor.value.search_fields || tableColumns.value.map((column) => column.key);
  return keys
    .map((key) => {
      if (key === 'description') return rowDescription(row);
      return row?.[key] ?? '';
    })
    .join(' ')
    .toLowerCase();
}

function fieldDefaultValue(field, row = null) {
  if (row && row[field.key] !== undefined && row[field.key] !== null) return row[field.key];
  if (field.default !== undefined) return field.default;
  if (field.type === 'enum' && Array.isArray(field.options) && field.options.length > 0) {
    return field.options[0].value;
  }
  return '';
}

function resetForm(row = null) {
  for (const key of Object.keys(form)) {
    delete form[key];
  }
  form.id = row?.id || '';
  for (const field of modalFields.value) {
    form[field.key] = fieldDefaultValue(field, row);
  }
  resetRelationSelections(row);
  formError.value = '';
}

function openCreateModal() {
  if (!createAction.value) return;
  modalMode.value = 'create';
  modalMaximized.value = false;
  resetForm();
  modalOpen.value = true;
}

function openEditModal(row) {
  modalMode.value = 'edit';
  modalMaximized.value = false;
  resetForm(row);
  modalOpen.value = true;
}

function closeModal() {
  modalOpen.value = false;
  modalMaximized.value = false;
  closeRelationNavigator();
  formError.value = '';
}

function submitModal() {
  const missingField = modalFields.value.find((field) => (
    field.required === true && String(form[field.key] || '').trim() === ''
  ));
  if (missingField) {
    formError.value = t('governance.field_required', { field: fieldLabel(missingField) });
    return;
  }

  const now = new Date().toISOString();
  const payload = formPayload();
  if (modalMode.value === 'edit') {
    const existing = rows.value.find((row) => row.id === form.id);
    if (existing) {
      Object.assign(existing, rowFromPayload(payload, existing.id, now));
    }
  } else {
    rows.value.unshift(rowFromPayload(payload, `${scopeKey.value}-${Date.now()}`, now));
  }

  closeModal();
}

function deleteRow(row) {
  if (isRowReadonly(row)) return;
  const index = rows.value.findIndex((candidate) => candidate.id === row.id);
  if (index >= 0) {
    rows.value.splice(index, 1);
  }
}

function formPayload() {
  return Object.fromEntries(modalFields.value.map((field) => {
    const value = String(form[field.key] || '').trim();
    if (field.key === 'status') return [field.key, normalizeStatus(value)];
    return [field.key, value];
  }));
}

function rowFromPayload(payload, id, updatedAt) {
  const name = payload.name || payload.display_name || payload.email || payload.key || payload.job_type || singularLabel.value;
  return {
    id,
    ...payload,
    relationships: relationSelectionSnapshot(),
    name,
    key: payload.key || '',
    description: payload.description || '',
    status: payload.status ? normalizeStatus(payload.status) : 'active',
    updatedAt,
  };
}

function resetRelationSelections(row = null) {
  for (const key of Object.keys(relationSelections)) {
    delete relationSelections[key];
  }
  const source = row?.relationships && typeof row.relationships === 'object' ? row.relationships : {};
  for (const [key, value] of Object.entries(source)) {
    relationSelections[key] = Array.isArray(value) ? value : [];
  }
}

function relationSelectionSnapshot() {
  return Object.fromEntries(Object.entries(relationSelections).map(([key, rows]) => [
    key,
    Array.isArray(rows) ? rows.map((row) => rowSelectionSummary(row, row?.entity_key || '')) : [],
  ]));
}

function rowSelectionSummary(row, entityKey = '') {
  return normalizeEntitySummary(entityKey || crudDescriptor.value.entity_key || '', row);
}

function openRelationNavigator(relationship) {
  relationNavigatorRelation.value = relationship;
  relationNavigatorMaximized.value = false;
  relationNavigatorOpen.value = true;
}

function closeRelationNavigator() {
  relationNavigatorOpen.value = false;
  relationNavigatorRelation.value = null;
  relationNavigatorMaximized.value = false;
}

function applyRelationSelection(payload) {
  const key = String(payload?.relation?.key || '').trim();
  const entityKey = String(payload?.relation?.target_entity || '').trim();
  if (key !== '') {
    relationSelections[key] = Array.isArray(payload.selectedRows)
      ? payload.selectedRows.map((row) => rowSelectionSummary(row, entityKey))
      : [];
  }
  closeRelationNavigator();
}

function relationRowsForEntity(entityKey) {
  const key = String(entityKey || '').trim();
  if (key === 'subjects') {
    return [
      ...relationRowsForEntity('users'),
      ...relationRowsForEntity('groups'),
      ...relationRowsForEntity('organizations'),
    ];
  }
  if (key === 'resources') {
    return [
      ...relationRowsForEntity('modules'),
      ...relationRowsForEntity('permissions'),
      ...relationRowsForEntity('groups'),
      ...relationRowsForEntity('organizations'),
    ];
  }
  if (key === 'modules' || key === 'permissions') {
    const catalog = buildGovernanceCatalogRows(workspaceModuleRegistry, `admin-governance-${key}`);
    entitySummaryCache.upsertRows(key, catalog);
    return entitySummaryCache.rows(key);
  }
  const scopedRows = rowsByScope[`admin-governance-${key}`];
  if (Array.isArray(scopedRows)) {
    entitySummaryCache.upsertRows(key, scopedRows);
  }
  return entitySummaryCache.rows(key);
}

function isRowReadonly(row) {
  return row?.readonly === true || crudDescriptor.value.readonly === true;
}

function rowActionIcon(action) {
  return action.icon || '/assets/orgas/kingrt/icons/gear.png';
}

function rowActionTitle(action) {
  const key = String(action?.label_key || '').trim();
  return key !== '' ? t(key, { entity: singularLabel.value }) : String(action?.key || '');
}

function handleRowAction(action, row) {
  if (action.kind === 'edit') {
    openEditModal(row);
    return;
  }
  if (action.kind === 'delete') {
    deleteRow(row);
  }
}

function goToPage(nextPage) {
  const parsed = Number.parseInt(String(nextPage), 10);
  if (!Number.isInteger(parsed)) return;
  page.value = Math.min(pageCount.value, Math.max(1, parsed));
}

function normalizeStatus(value) {
  const normalized = String(value || '').trim().toLowerCase();
  return ['active', 'draft', 'disabled'].includes(normalized) ? normalized : 'active';
}

function statusClass(status) {
  if (status === 'active') return 'ok';
  if (status === 'disabled') return 'danger';
  return 'warn';
}

function statusLabel(status) {
  const normalized = normalizeStatus(status);
  if (normalized === 'active') return t('governance.status_active');
  if (normalized === 'draft') return t('governance.status_draft');
  if (normalized === 'disabled') return t('governance.status_disabled');
  return String(status || '');
}

function formatDate(value) {
  return formatLocalizedDateTimeDisplay(value, {
    locale: sessionState.locale,
    dateFormat: sessionState.dateFormat,
    timeFormat: sessionState.timeFormat,
    fallback: t('common.not_available'),
  });
}
</script>

<style scoped>
.governance-table {
  width: 100%;
  table-layout: fixed;
  margin-top: 10px;
}

.governance-actions-col {
  width: 120px;
}

.governance-name {
  overflow: hidden;
  color: var(--text-main);
  font-weight: 700;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.governance-subline {
  display: block;
  margin-top: 3px;
  color: var(--text-muted);
  font-size: 11px;
}

.governance-empty-cell {
  padding: 18px 12px;
  text-align: center;
  color: var(--text-muted);
}

.governance-readonly-label {
  color: var(--text-muted);
  font-size: 0.76rem;
  font-weight: 700;
  text-transform: uppercase;
}

@media (max-width: 760px) {
  .governance-table {
    table-layout: auto;
    border-collapse: separate;
    border-spacing: 0 8px;
  }

  .governance-table thead {
    position: absolute;
    width: 1px;
    height: 1px;
    margin: -1px;
    padding: 0;
    border: 0;
    overflow: hidden;
    clip: rect(0 0 0 0);
    clip-path: inset(50%);
    white-space: nowrap;
  }

  .governance-table tbody,
  .governance-table tr,
  .governance-table td {
    display: block;
    width: 100%;
  }

  .governance-table tbody tr {
    border: 1px solid var(--border-subtle);
    border-radius: 8px;
    overflow: hidden;
    background: var(--bg-row);
  }

  .governance-table td {
    display: grid;
    grid-template-columns: minmax(100px, 34%) minmax(0, 1fr);
    gap: 8px;
    align-items: start;
    padding: 8px 10px;
    border-bottom: 1px solid var(--border-subtle);
  }

  .governance-table td::before {
    content: attr(data-label);
    color: var(--text-muted);
    font-size: 11px;
    font-weight: 600;
  }

  .governance-table td:last-child {
    border-bottom: 0;
  }
}
</style>
