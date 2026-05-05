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
            <th>{{ t('governance.name') }}</th>
            <th>{{ t('governance.key') }}</th>
            <th>{{ t('governance.status') }}</th>
            <th>{{ t('governance.description') }}</th>
            <th>{{ t('governance.updated') }}</th>
            <th class="governance-actions-col">{{ t('governance.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in pagedRows" :key="row.id">
            <td :data-label="t('governance.name')">
              <div class="governance-name">{{ row.name }}</div>
              <div class="governance-subline">{{ singularLabel }}</div>
            </td>
            <td :data-label="t('governance.key')">{{ row.key || t('common.not_available') }}</td>
            <td :data-label="t('governance.status')">
              <span class="tag" :class="statusClass(row.status)">{{ statusLabel(row.status) }}</span>
            </td>
            <td :data-label="t('governance.description')">{{ row.description || t('common.not_available') }}</td>
            <td :data-label="t('governance.updated')">{{ formatDate(row.updatedAt) }}</td>
            <td :data-label="t('governance.actions')">
              <span v-if="row.readonly" class="governance-readonly-label">{{ t('governance.system') }}</span>
              <div v-else class="actions-inline">
                <AppIconButton
                  icon="/assets/orgas/kingrt/icons/gear.png"
                  :title="t('governance.edit_entity', { entity: singularLabel })"
                  @click="openEditModal(row)"
                />
                <AppIconButton
                  icon="/assets/orgas/kingrt/icons/remove_user.png"
                  :title="t('governance.delete_entity', { entity: singularLabel })"
                  danger
                  @click="deleteRow(row)"
                />
              </div>
            </td>
          </tr>
          <tr v-if="filteredRows.length === 0">
            <td colspan="6" class="governance-empty-cell">{{ t('governance.empty_filter') }}</td>
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
      :error="formError"
      :maximized="modalMaximized"
      @update:maximized="modalMaximized = $event"
      @close="closeModal"
      @submit="submitModal"
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
import GovernanceCrudModal from './GovernanceCrudModal.vue';
import { buildGovernanceCatalogRows } from '../../governanceCatalog.js';
import { workspaceModuleRegistry } from '../../index.js';
import { t } from '../../localization/i18nRuntime.js';
import { firstRouteActionByKind, routeActionLabel, routeActionsForContext } from '../../routeActions.js';

const route = useRoute();
const rowsByScope = reactive({});
const query = ref('');
const page = ref(1);
const pageSize = 8;
const modalOpen = ref(false);
const modalMode = ref('create');
const modalMaximized = ref(false);
const formError = ref('');
const form = reactive({
  id: '',
  name: '',
  key: '',
  description: '',
  status: 'active',
});

const scopeKey = computed(() => String(route.name || route.path));
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
const createAction = computed(() => firstRouteActionByKind(availableRouteActions.value, 'create'));
const createButtonLabel = computed(() => routeActionLabel(createAction.value, t, t('governance.create')));
const filteredRows = computed(() => {
  const needle = query.value.trim().toLowerCase();
  if (needle === '') return visibleRows.value;
  return visibleRows.value.filter((row) => (
    row.name.toLowerCase().includes(needle)
    || row.key.toLowerCase().includes(needle)
    || row.description.toLowerCase().includes(needle)
    || row.status.toLowerCase().includes(needle)
  ));
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
  closeModal();
});

watch(filteredRows, () => {
  if (page.value > pageCount.value) {
    page.value = pageCount.value;
  }
});

function routeLabel(key, keyKey, fallback) {
  const translationKey = typeof route.meta?.[keyKey] === 'string' ? route.meta[keyKey].trim() : '';
  if (translationKey !== '') return t(translationKey);
  const value = typeof route.meta?.[key] === 'string' ? route.meta[key].trim() : '';
  return value || fallback;
}

function resetForm(row = null) {
  form.id = row?.id || '';
  form.name = row?.name || '';
  form.key = row?.key || '';
  form.description = row?.description || '';
  form.status = row?.status || 'active';
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
  formError.value = '';
}

function submitModal() {
  const name = form.name.trim();
  if (name === '') {
    formError.value = t('governance.name_required');
    return;
  }

  const now = new Date().toISOString();
  if (modalMode.value === 'edit') {
    const existing = rows.value.find((row) => row.id === form.id);
    if (existing) {
      existing.name = name;
      existing.key = form.key.trim();
      existing.description = form.description.trim();
      existing.status = normalizeStatus(form.status);
      existing.updatedAt = now;
    }
  } else {
    rows.value.unshift({
      id: `${scopeKey.value}-${Date.now()}`,
      name,
      key: form.key.trim(),
      description: form.description.trim(),
      status: normalizeStatus(form.status),
      updatedAt: now,
    });
  }

  closeModal();
}

function deleteRow(row) {
  if (row.readonly) return;
  const index = rows.value.findIndex((candidate) => candidate.id === row.id);
  if (index >= 0) {
    rows.value.splice(index, 1);
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

.governance-table th:nth-child(1),
.governance-table td:nth-child(1) {
  width: 21%;
}

.governance-table th:nth-child(2),
.governance-table td:nth-child(2) {
  width: 17%;
}

.governance-table th:nth-child(3),
.governance-table td:nth-child(3) {
  width: 12%;
}

.governance-table th:nth-child(4),
.governance-table td:nth-child(4) {
  width: 25%;
}

.governance-table th:nth-child(5),
.governance-table td:nth-child(5) {
  width: 14%;
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
