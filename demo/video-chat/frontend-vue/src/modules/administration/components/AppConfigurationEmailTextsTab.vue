<template>
  <section class="app-config-crud">
    <section class="app-config-crud-main">
      <section class="app-config-toolbar">
        <button class="btn btn-cyan" type="button" @click="openCreate">
          {{ t('administration.email_text_create') }}
        </button>
        <label class="search-field search-field-main" :aria-label="t('administration.email_text_search')">
          <input
            v-model.trim="query"
            class="input"
            type="search"
            :placeholder="t('administration.email_text_search')"
            @keydown.enter.prevent="applySearch"
          />
        </label>
        <AppIconButton
          icon="/assets/orgas/kingrt/icons/send.png"
          :title="t('administration.apply_search')"
          :aria-label="t('administration.apply_search')"
          @click="applySearch"
        />
      </section>

      <section v-if="state.loading" class="settings-upload-status">{{ t('common.loading') }}</section>
      <section v-if="state.error" class="settings-upload-status error">{{ state.error }}</section>

      <AdminTableFrame class="app-config-table-wrap">
        <table class="app-config-table">
          <thead>
            <tr>
              <th>{{ t('administration.email_text_label') }}</th>
              <th>{{ t('administration.email_text_key') }}</th>
              <th>{{ t('administration.subject_template') }}</th>
              <th>{{ t('administration.email_text_updated') }}</th>
              <th>{{ t('governance.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="row.id">
              <td :data-label="t('administration.email_text_label')">
                <strong>{{ row.label }}</strong>
                <span v-if="row.is_system" class="tag">{{ t('administration.email_text_system') }}</span>
              </td>
              <td :data-label="t('administration.email_text_key')">{{ row.template_key }}</td>
              <td :data-label="t('administration.subject_template')">{{ row.subject_template }}</td>
              <td :data-label="t('administration.email_text_updated')">{{ formatDate(row.updated_at) }}</td>
              <td :data-label="t('governance.actions')">
                <div class="actions-inline">
                  <AppIconButton
                    icon="/assets/orgas/kingrt/icons/gear.png"
                    :title="t('administration.email_text_edit')"
                    :aria-label="t('administration.email_text_edit')"
                    @click="openEdit(row)"
                  />
                  <AppIconButton
                    icon="/assets/orgas/kingrt/icons/remove_user.png"
                    :title="t('administration.email_text_delete')"
                    :aria-label="t('administration.email_text_delete')"
                    :disabled="row.is_system"
                    danger
                    @click="deleteRow(row)"
                  />
                </div>
              </td>
            </tr>
            <tr v-if="!state.loading && rows.length === 0" class="table-empty-row">
              <td colspan="5" class="app-config-empty">{{ t('administration.email_text_empty') }}</td>
            </tr>
          </tbody>
        </table>
      </AdminTableFrame>

      <footer class="app-config-pagination">
        <AppPagination
          :page="pagination.page"
          :page-count="pagination.page_count"
          :total="pagination.total"
          :total-label="t('administration.email_texts_total')"
          :has-prev="pagination.page > 1"
          :has-next="pagination.page < pagination.page_count"
          :disabled="state.loading"
          @page-change="goToPage"
        />
      </footer>
    </section>

    <aside v-if="editor.open" class="app-config-editor">
      <header class="app-config-editor-head">
        <strong>{{ editor.mode === 'create' ? t('administration.email_text_create') : t('administration.email_text_edit') }}</strong>
        <AppIconButton
          icon="/assets/orgas/kingrt/icons/cancel.png"
          :title="t('common.close_panel')"
          :aria-label="t('common.close_panel')"
          @click="closeEditor"
        />
      </header>
      <form class="app-config-editor-form" @submit.prevent="saveEditor">
        <label class="settings-field">
          <span>{{ t('administration.email_text_label') }}</span>
          <input v-model.trim="form.label" class="input" type="text" />
        </label>
        <label class="settings-field">
          <span>{{ t('administration.email_text_key') }}</span>
          <input v-model.trim="form.template_key" class="input" type="text" :disabled="form.is_system" />
        </label>
        <label class="settings-field">
          <span>{{ t('administration.subject_template') }}</span>
          <input v-model.trim="form.subject_template" class="input" type="text" />
        </label>
        <label class="settings-field app-config-editor-body">
          <span>{{ t('administration.body_template') }}</span>
          <textarea v-model="form.body_template" class="settings-textarea" rows="12"></textarea>
        </label>
        <label class="settings-field">
          <span>{{ t('administration.email_text_status') }}</span>
          <AppSelect v-model="form.status">
            <option value="active">{{ t('administration.email_text_status_active') }}</option>
            <option value="disabled">{{ t('administration.email_text_status_disabled') }}</option>
          </AppSelect>
        </label>
        <section v-if="editor.error" class="settings-upload-status error">{{ editor.error }}</section>
        <footer class="app-config-editor-actions">
          <button class="btn btn-cyan" type="submit" :disabled="editor.saving">
            {{ editor.saving ? t('settings.saving') : t('common.save') }}
          </button>
        </footer>
      </form>
    </aside>
  </section>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import AppIconButton from '../../../components/AppIconButton.vue';
import AppPagination from '../../../components/AppPagination.vue';
import AppSelect from '../../../components/AppSelect.vue';
import AdminTableFrame from '../../../components/admin/AdminTableFrame.vue';
import {
  createWorkspaceEmailText,
  deleteWorkspaceEmailText,
  listWorkspaceEmailTexts,
  updateWorkspaceEmailText,
} from '../../../domain/workspace/administrationApi';
import { formatLocalizedDateTimeDisplay } from '../../../support/dateTimeFormat';
import { t } from '../../localization/i18nRuntime.js';

const rows = ref([]);
const query = ref('');
const pagination = reactive({ page: 1, page_size: 10, total: 0, page_count: 1 });
const state = reactive({ loading: false, error: '' });
const editor = reactive({ open: false, mode: 'create', saving: false, error: '' });
const form = reactive({
  id: '',
  label: '',
  template_key: '',
  subject_template: '',
  body_template: '',
  status: 'active',
  is_system: false,
});

function formatDate(value) {
  return formatLocalizedDateTimeDisplay(value) || t('common.not_available');
}

function applyListing(result) {
  rows.value = Array.isArray(result?.rows) ? result.rows : [];
  const next = result?.pagination || {};
  pagination.page = Number(next.page || 1);
  pagination.page_size = Number(next.page_size || 10);
  pagination.total = Number(next.total || rows.value.length);
  pagination.page_count = Math.max(1, Number(next.page_count || 1));
}

async function loadRows() {
  state.loading = true;
  state.error = '';
  try {
    applyListing(await listWorkspaceEmailTexts({
      query: query.value,
      page: pagination.page,
      page_size: pagination.page_size,
    }));
  } catch (error) {
    state.error = error instanceof Error ? error.message : t('administration.email_text_load_failed');
  } finally {
    state.loading = false;
  }
}

function applySearch() {
  pagination.page = 1;
  void loadRows();
}

function goToPage(page) {
  pagination.page = Math.max(1, Number(page) || 1);
  void loadRows();
}

function resetForm(row = null) {
  form.id = String(row?.id || '');
  form.label = String(row?.label || '');
  form.template_key = String(row?.template_key || '');
  form.subject_template = String(row?.subject_template || '');
  form.body_template = String(row?.body_template || '');
  form.status = String(row?.status || 'active');
  form.is_system = Boolean(row?.is_system);
}

function openCreate() {
  resetForm();
  editor.mode = 'create';
  editor.error = '';
  editor.open = true;
}

function openEdit(row) {
  resetForm(row);
  editor.mode = 'edit';
  editor.error = '';
  editor.open = true;
}

function closeEditor() {
  editor.open = false;
  editor.error = '';
}

function payloadFromForm() {
  return {
    label: form.label,
    template_key: form.template_key,
    subject_template: form.subject_template,
    body_template: form.body_template,
    status: form.status,
  };
}

async function saveEditor() {
  if (editor.saving) return;
  editor.saving = true;
  editor.error = '';
  try {
    if (editor.mode === 'create') {
      await createWorkspaceEmailText(payloadFromForm());
    } else {
      await updateWorkspaceEmailText(form.id, payloadFromForm());
    }
    editor.open = false;
    await loadRows();
  } catch (error) {
    editor.error = error instanceof Error ? error.message : t('administration.email_text_save_failed');
  } finally {
    editor.saving = false;
  }
}

async function deleteRow(row) {
  if (!row || row.is_system) return;
  state.error = '';
  try {
    await deleteWorkspaceEmailText(row.id);
    await loadRows();
  } catch (error) {
    state.error = error instanceof Error ? error.message : t('administration.email_text_delete_failed');
  }
}

onMounted(() => {
  void loadRows();
});
</script>

<style scoped>
.app-config-crud {
  height: 100%;
  min-height: 0;
  display: flex;
  overflow: hidden;
}

.app-config-crud-main {
  flex: 1 1 auto;
  min-width: 0;
  min-height: 0;
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.app-config-toolbar {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: 20px;
}

.app-config-toolbar .btn {
  margin-inline-end: auto;
}

.app-config-toolbar .search-field {
  flex: 0 1 360px;
  width: min(360px, 100%);
}

.app-config-table-wrap {
  padding-inline: 0;
}

.app-config-table {
  width: 100%;
  border-collapse: collapse;
}

.app-config-table th,
.app-config-table td {
  border-bottom: 1px solid var(--color-border);
  padding: 12px 14px;
  text-align: left;
  vertical-align: middle;
}

.app-config-table th {
  color: var(--text-main);
  background: var(--color-surface-navy);
}

.app-config-table td {
  color: var(--text-primary);
}

.app-config-table td strong {
  display: block;
  margin-bottom: 4px;
}

.app-config-empty {
  min-height: 180px;
  text-align: center;
  color: var(--text-muted);
}

.app-config-pagination {
  display: flex;
  justify-content: center;
  margin-top: auto;
}

.app-config-editor {
  flex: 0 0 min(420px, 44vw);
  min-width: 320px;
  height: 100%;
  margin-inline-start: 20px;
  border-left: 1px solid var(--color-border);
  border-top: 0;
  border-bottom: 0;
  border-radius: 0;
  background: var(--color-surface-navy);
  display: flex;
  flex-direction: column;
  min-height: 0;
}

.app-config-editor-head {
  min-height: 54px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 0 14px;
  border-bottom: 1px solid var(--color-border);
}

.app-config-editor-form {
  min-height: 0;
  overflow: auto;
  display: grid;
  gap: 14px;
  padding: 14px;
}

.settings-textarea {
  width: 100%;
  min-height: 220px;
  border: 1px solid var(--border-subtle);
  border-radius: 0;
  background: var(--bg-input);
  color: var(--text-primary);
  padding: 8px 10px;
  resize: vertical;
}

.app-config-editor-actions {
  display: flex;
  justify-content: flex-end;
}

.settings-upload-status.error {
  color: var(--color-heading);
}

@media (max-width: 900px) {
  .app-config-crud {
    flex-direction: column;
  }

  .app-config-editor {
    flex: 1 1 auto;
    width: 100%;
    min-width: 0;
    margin: 20px 0 0;
    border-left: 0;
    border-top: 1px solid var(--color-border);
  }
}

@media (max-width: 760px) {
  .app-config-toolbar .btn,
  .app-config-toolbar .search-field,
  .app-config-toolbar .icon-mini-btn {
    flex: 1 1 100%;
    width: 100%;
    margin-inline-end: 0;
  }
}
</style>
