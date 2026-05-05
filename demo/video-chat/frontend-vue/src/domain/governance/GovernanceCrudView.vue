<template>
  <section class="view-card governance-crud-view">
    <AppPageHeader class="section governance-crud-head" :title="title">
      <template #actions>
        <button class="btn btn-cyan" type="button" @click="openCreateModal">Create new</button>
      </template>
    </AppPageHeader>

    <section class="toolbar governance-crud-toolbar">
      <label class="search-field search-field-main" :aria-label="`Search ${pluralLabel}`">
        <input
          v-model.trim="query"
          class="input"
          type="search"
          :placeholder="`Search ${pluralLabel}`"
        />
      </label>
    </section>

    <section class="table-wrap governance-table-wrap">
      <table class="governance-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Key</th>
            <th>Status</th>
            <th>Beschreibung</th>
            <th>Updated</th>
            <th class="governance-actions-col">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in pagedRows" :key="row.id">
            <td data-label="Name">
              <div class="governance-name">{{ row.name }}</div>
              <div class="governance-subline">{{ singularLabel }}</div>
            </td>
            <td data-label="Key">{{ row.key || 'n/a' }}</td>
            <td data-label="Status">
              <span class="tag" :class="statusClass(row.status)">{{ row.status }}</span>
            </td>
            <td data-label="Beschreibung">{{ row.description || 'n/a' }}</td>
            <td data-label="Updated">{{ formatDate(row.updatedAt) }}</td>
            <td data-label="Actions">
              <span v-if="row.readonly" class="governance-readonly-label">System</span>
              <div v-else class="actions-inline">
                <AppIconButton
                  icon="/assets/orgas/kingrt/icons/gear.png"
                  :title="`Edit ${singularLabel}`"
                  @click="openEditModal(row)"
                />
                <AppIconButton
                  icon="/assets/orgas/kingrt/icons/remove_user.png"
                  :title="`Delete ${singularLabel}`"
                  danger
                  @click="deleteRow(row)"
                />
              </div>
            </td>
          </tr>
          <tr v-if="filteredRows.length === 0">
            <td colspan="6" class="governance-empty-cell">No entries match the current filter.</td>
          </tr>
        </tbody>
      </table>
    </section>

    <footer class="footer governance-crud-footer">
      <AppPagination
        :page="page"
        :page-count="pageCount"
        :total="filteredRows.length"
        :total-label="pluralLabel"
        :has-prev="page > 1"
        :has-next="page < pageCount"
        @page-change="goToPage"
      />
    </footer>

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
  </section>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import AppIconButton from '../../components/AppIconButton.vue';
import AppPageHeader from '../../components/AppPageHeader.vue';
import AppPagination from '../../components/AppPagination.vue';
import GovernanceCrudModal from './GovernanceCrudModal.vue';
import { buildGovernanceCatalogRows } from '../../modules/governanceCatalog.js';
import { workspaceModuleRegistry } from '../../modules/index.js';

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
const title = computed(() => routeLabel('pageTitle', 'Governance'));
const singularLabel = computed(() => routeLabel('entitySingular', title.value));
const pluralLabel = computed(() => routeLabel('entityPlural', title.value));
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
    ? `Edit ${singularLabel.value}`
    : `Create ${singularLabel.value}`
));
const modalSubmitLabel = computed(() => (modalMode.value === 'edit' ? 'Save changes' : 'Create'));

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

function routeLabel(key, fallback) {
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
    formError.value = 'Name is required.';
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

function formatDate(value) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return 'n/a';
  return new Intl.DateTimeFormat('de-DE', {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(date);
}
</script>

<style scoped>
.governance-crud-view {
  min-height: 100%;
  display: flex;
  flex-direction: column;
  gap: 0;
  background: transparent;
}

.governance-crud-head,
.governance-crud-toolbar,
.governance-crud-footer {
  background: var(--bg-ui-chrome);
}

.governance-crud-head,
.governance-crud-toolbar {
  display: flex;
  gap: 10px;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
}

.governance-crud-toolbar {
  padding-bottom: 25px;
}

.governance-table-wrap {
  flex: 1 1 auto;
  min-height: 0;
  margin-top: 0;
  padding-left: 10px;
  padding-right: 10px;
}

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

.governance-crud-footer {
  display: flex;
  justify-content: center;
  margin-top: auto;
  padding-left: 10px;
  padding-right: 10px;
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
