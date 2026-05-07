<template>
  <section class="view-card calendar-view">
    <section class="calendar-main">
      <section class="calendar-toolbar" :aria-label="t('calendar.calendar')">
        <button class="btn btn-cyan" type="button" @click="openCreate">
          {{ t('calendar.create_calendar') }}
        </button>
        <label class="search-field search-field-main" :aria-label="t('calendar.search')">
          <input
            v-model.trim="query"
            class="input"
            type="search"
            :placeholder="t('calendar.search')"
            @keydown.enter.prevent="applySearch"
          />
        </label>
        <AppIconButton
          icon="/assets/orgas/kingrt/icons/send.png"
          :title="t('common.search')"
          :aria-label="t('common.search')"
          @click="applySearch"
        />
      </section>

      <section v-if="state.loading" class="calendar-state">{{ t('common.loading') }}</section>
      <section v-if="state.error" class="calendar-state error">{{ state.error }}</section>

      <AdminTableFrame class="calendar-table-wrap">
        <table class="calendar-table">
          <thead>
            <tr>
              <th>{{ t('calendar.name') }}</th>
              <th>{{ t('calendar.owner') }}</th>
              <th>{{ t('calendar.type') }}</th>
              <th>{{ t('calendar.members') }}</th>
              <th>{{ t('calendar.updated') }}</th>
              <th>{{ t('governance.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="row.id">
              <td :data-label="t('calendar.name')">
                <strong>{{ row.name }}</strong>
                <span v-if="row.description" class="calendar-description">{{ row.description }}</span>
              </td>
              <td :data-label="t('calendar.owner')">{{ row.owner_name || row.owner_email || t('common.not_available') }}</td>
              <td :data-label="t('calendar.type')">
                <span class="tag" :class="row.is_personal ? 'ok' : ''">
                  {{ row.is_personal ? t('calendar.personal') : t('calendar.shared') }}
                </span>
              </td>
              <td :data-label="t('calendar.members')">{{ memberSummary(row) }}</td>
              <td :data-label="t('calendar.updated')">{{ formatDate(row.updated_at) }}</td>
              <td :data-label="t('governance.actions')">
                <div class="actions-inline">
                  <AppIconButton
                    icon="/assets/orgas/kingrt/icons/gear.png"
                    :title="t('calendar.edit_calendar')"
                    :aria-label="t('calendar.edit_calendar')"
                    @click="openEdit(row)"
                  />
                  <AppIconButton
                    icon="/assets/orgas/kingrt/icons/remove_user.png"
                    :title="t('calendar.delete_calendar')"
                    :aria-label="t('calendar.delete_calendar')"
                    :disabled="row.is_personal"
                    danger
                    @click="deleteCalendar(row)"
                  />
                </div>
              </td>
            </tr>
            <tr v-if="!state.loading && rows.length === 0" class="table-empty-row">
              <td colspan="6" class="calendar-empty">{{ t('calendar.empty') }}</td>
            </tr>
          </tbody>
        </table>
      </AdminTableFrame>

      <footer class="calendar-pagination">
        <AppPagination
          :page="pagination.page"
          :page-count="pagination.page_count"
          :total="pagination.total"
          :total-label="t('calendar.calendars_total')"
          :has-prev="pagination.page > 1"
          :has-next="pagination.page < pagination.page_count"
          :disabled="state.loading"
          @page-change="goToPage"
        />
      </footer>
    </section>

    <aside v-if="editor.open" class="calendar-editor">
      <header class="calendar-editor-head">
        <strong>{{ editor.mode === 'create' ? t('calendar.create_calendar') : t('calendar.edit_calendar') }}</strong>
        <AppIconButton
          icon="/assets/orgas/kingrt/icons/cancel.png"
          :title="t('common.close_panel')"
          :aria-label="t('common.close_panel')"
          @click="closeEditor"
        />
      </header>

      <form class="calendar-editor-form" @submit.prevent="saveEditor">
        <label class="settings-field">
          <span>{{ t('calendar.name') }}</span>
          <input v-model.trim="form.name" class="input" type="text" :placeholder="t('calendar.name_placeholder')" />
        </label>
        <label class="settings-field">
          <span>{{ t('calendar.description') }}</span>
          <textarea
            v-model.trim="form.description"
            class="settings-textarea"
            rows="4"
            :placeholder="t('calendar.description_placeholder')"
          ></textarea>
        </label>

        <section class="calendar-access-block">
          <header>
            <strong>{{ t('calendar.access') }}</strong>
            <span>{{ editor.isPersonal ? t('calendar.personal_hint') : t('calendar.shared_hint') }}</span>
          </header>

          <section v-if="selectedMembers.length > 0" class="calendar-selected-members">
            <article v-for="member in selectedMembers" :key="member.user_id" class="calendar-member-chip">
              <span>{{ member.display_name || member.email }}</span>
              <AppIconButton
                icon="/assets/orgas/kingrt/icons/cancel.png"
                :title="t('calendar.remove_member')"
                :aria-label="t('calendar.remove_member')"
                @click="removeSelectedMember(member.user_id)"
              />
            </article>
          </section>

          <section class="calendar-directory-search">
            <label class="search-field search-field-main" :aria-label="t('calendar.search_users')">
              <input
                v-model.trim="directory.query"
                class="input"
                type="search"
                :placeholder="t('calendar.search_users')"
                @keydown.enter.prevent="applyDirectorySearch"
              />
            </label>
            <AppIconButton
              icon="/assets/orgas/kingrt/icons/send.png"
              :title="t('common.search')"
              :aria-label="t('common.search')"
              @click="applyDirectorySearch"
            />
          </section>

          <section v-if="directory.error" class="calendar-state error">{{ directory.error }}</section>
          <section class="calendar-directory-list" :class="{ loading: directory.loading }">
            <button
              v-for="user in directory.rows"
              :key="user.id"
              class="calendar-directory-row"
              type="button"
              :class="{ selected: isMemberSelected(user.id) }"
              @click="toggleDirectoryUser(user)"
            >
              <span>
                <strong>{{ user.display_name || user.email }}</strong>
                <small>{{ user.email }}</small>
              </span>
              <span>{{ isMemberSelected(user.id) ? t('common.remove') : t('calendar.select_user') }}</span>
            </button>
            <p v-if="!directory.loading && directory.rows.length === 0" class="calendar-empty-inline">
              {{ t('calendar.users_empty') }}
            </p>
          </section>

          <AppPagination
            :page="directory.page"
            :page-count="directory.page_count"
            :total="directory.total"
            :has-prev="directory.page > 1"
            :has-next="directory.page < directory.page_count"
            :disabled="directory.loading"
            @page-change="goToDirectoryPage"
          />
        </section>

        <section v-if="editor.error" class="calendar-state error">{{ editor.error }}</section>
        <footer class="calendar-editor-actions">
          <button class="btn btn-cyan" type="submit" :disabled="editor.saving">
            {{ editor.saving ? t('common.saving') : t('common.save') }}
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
import AdminTableFrame from '../../../components/admin/AdminTableFrame.vue';
import {
  createWorkspaceCalendar,
  deleteWorkspaceCalendar,
  listCalendarDirectoryUsers,
  listWorkspaceCalendars,
  updateWorkspaceCalendar,
} from '../../../domain/workspace/calendarApi';
import { formatLocalizedDateTimeDisplay } from '../../../support/dateTimeFormat';
import { t } from '../../localization/i18nRuntime.js';

const rows = ref([]);
const query = ref('');
const selectedMembers = ref([]);
const pagination = reactive({ page: 1, page_size: 10, total: 0, page_count: 1 });
const state = reactive({ loading: false, error: '' });
const editor = reactive({ open: false, mode: 'create', isPersonal: false, saving: false, error: '' });
const form = reactive({ id: '', name: '', description: '' });
const directory = reactive({
  query: '',
  page: 1,
  page_size: 8,
  total: 0,
  page_count: 1,
  loading: false,
  error: '',
  rows: [],
});

function formatDate(value) {
  return formatLocalizedDateTimeDisplay(value) || t('common.not_available');
}

function memberSummary(row) {
  const count = Number(row?.member_count || 0);
  return t('calendar.member_count', { count });
}

function applyListing(payload) {
  rows.value = Array.isArray(payload?.calendars) ? payload.calendars : [];
  const next = payload?.pagination || {};
  pagination.page = Number(next.page || 1);
  pagination.page_size = Number(next.page_size || 10);
  pagination.total = Number(next.total || rows.value.length);
  pagination.page_count = Math.max(1, Number(next.page_count || 1));
}

async function loadRows() {
  state.loading = true;
  state.error = '';
  try {
    applyListing(await listWorkspaceCalendars({
      query: query.value,
      page: pagination.page,
      page_size: pagination.page_size,
    }));
  } catch (error) {
    state.error = error instanceof Error ? error.message : t('calendar.load_failed');
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
  form.name = String(row?.name || '');
  form.description = String(row?.description || '');
  editor.isPersonal = Boolean(row?.is_personal);
  selectedMembers.value = Array.isArray(row?.members)
    ? row.members.filter((member) => String(member?.access_role || '') !== 'owner')
    : [];
}

function openCreate() {
  resetForm();
  editor.mode = 'create';
  editor.error = '';
  editor.open = true;
  void loadDirectory();
}

function openEdit(row) {
  resetForm(row);
  editor.mode = 'edit';
  editor.error = '';
  editor.open = true;
  void loadDirectory();
}

function closeEditor() {
  editor.open = false;
  editor.error = '';
}

function payloadFromForm() {
  return {
    name: form.name,
    description: form.description,
    member_user_ids: selectedMembers.value.map((member) => Number(member.user_id || member.id || 0)).filter((id) => id > 0),
  };
}

async function saveEditor() {
  if (editor.saving) return;
  editor.saving = true;
  editor.error = '';
  try {
    if (editor.mode === 'create') {
      await createWorkspaceCalendar(payloadFromForm());
    } else {
      await updateWorkspaceCalendar(form.id, payloadFromForm());
    }
    editor.open = false;
    await loadRows();
  } catch (error) {
    editor.error = error instanceof Error ? error.message : t('calendar.save_failed');
  } finally {
    editor.saving = false;
  }
}

async function deleteCalendar(row) {
  if (row?.is_personal) {
    state.error = t('calendar.delete_personal_rejected');
    return;
  }
  try {
    await deleteWorkspaceCalendar(row.id);
    await loadRows();
  } catch (error) {
    state.error = error instanceof Error ? error.message : t('calendar.save_failed');
  }
}

function applyDirectoryPayload(payload) {
  directory.rows = Array.isArray(payload?.users) ? payload.users : [];
  const next = payload?.pagination || {};
  directory.page = Number(next.page || 1);
  directory.total = Number(next.total || directory.rows.length);
  directory.page_count = Math.max(1, Number(next.page_count || 1));
}

async function loadDirectory() {
  directory.loading = true;
  directory.error = '';
  try {
    applyDirectoryPayload(await listCalendarDirectoryUsers({
      query: directory.query,
      page: directory.page,
      page_size: directory.page_size,
    }));
  } catch (error) {
    directory.error = error instanceof Error ? error.message : t('calendar.user_load_failed');
  } finally {
    directory.loading = false;
  }
}

function applyDirectorySearch() {
  directory.page = 1;
  void loadDirectory();
}

function goToDirectoryPage(page) {
  directory.page = Math.max(1, Number(page) || 1);
  void loadDirectory();
}

function normalizedUserId(value) {
  const id = Number(value);
  return Number.isInteger(id) && id > 0 ? id : 0;
}

function isMemberSelected(userId) {
  const id = normalizedUserId(userId);
  return selectedMembers.value.some((member) => normalizedUserId(member.user_id || member.id) === id);
}

function toggleDirectoryUser(user) {
  const id = normalizedUserId(user?.id);
  if (id <= 0) return;
  if (isMemberSelected(id)) {
    removeSelectedMember(id);
    return;
  }
  selectedMembers.value = [
    ...selectedMembers.value,
    {
      user_id: id,
      display_name: String(user?.display_name || ''),
      email: String(user?.email || ''),
      access_role: 'viewer',
    },
  ];
}

function removeSelectedMember(userId) {
  const id = normalizedUserId(userId);
  selectedMembers.value = selectedMembers.value.filter((member) => normalizedUserId(member.user_id || member.id) !== id);
}

onMounted(() => {
  void loadRows();
});
</script>

<style scoped>
.calendar-view {
  display: flex;
  min-height: 0;
  height: 100%;
  overflow: hidden;
  background: transparent;
}

.calendar-main {
  min-width: 0;
  flex: 1 1 auto;
  display: flex;
  flex-direction: column;
  gap: 20px;
  padding: 0 20px 20px;
  overflow: hidden;
}

.calendar-toolbar,
.calendar-directory-search {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 20px;
}

.calendar-toolbar .btn {
  margin-inline-end: auto;
}

.calendar-toolbar .search-field,
.calendar-directory-search .search-field {
  flex: 0 1 360px;
  max-width: 100%;
}

.calendar-state {
  color: var(--text-muted);
}

.calendar-state.error {
  color: var(--color-error);
}

.calendar-table-wrap {
  min-height: 0;
  flex: 1 1 auto;
}

.calendar-table strong,
.calendar-directory-row strong {
  display: block;
  color: var(--text-main);
}

.calendar-description,
.calendar-directory-row small {
  display: block;
  color: var(--text-muted);
  margin-top: 4px;
}

.calendar-empty,
.calendar-empty-inline {
  color: var(--text-muted);
  text-align: center;
}

.calendar-pagination {
  flex: 0 0 auto;
  display: flex;
  justify-content: center;
}

.calendar-editor {
  width: min(440px, 38vw);
  min-width: 360px;
  flex: 0 0 auto;
  display: flex;
  flex-direction: column;
  min-height: 0;
  border-left: 1px solid var(--color-border);
  border-radius: 0;
  background: var(--color-surface-navy);
}

.calendar-editor-head,
.calendar-editor-actions {
  flex: 0 0 auto;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
  padding: 20px;
}

.calendar-editor-form {
  min-height: 0;
  flex: 1 1 auto;
  display: flex;
  flex-direction: column;
  gap: 18px;
  padding: 0 20px 20px;
  overflow: auto;
}

.calendar-access-block {
  display: flex;
  flex-direction: column;
  gap: 16px;
  min-height: 0;
}

.calendar-access-block header {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.calendar-access-block header span {
  color: var(--text-muted);
}

.calendar-selected-members {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.calendar-member-chip,
.calendar-directory-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
  border: 1px solid var(--color-border);
  border-radius: 0;
  background: var(--color-border);
  color: var(--text-main);
}

.calendar-member-chip {
  min-height: 40px;
  padding: 8px 8px 8px 12px;
}

.calendar-directory-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
  min-height: 160px;
}

.calendar-directory-row {
  width: 100%;
  min-height: 54px;
  padding: 10px 12px;
  text-align: left;
  font: inherit;
  cursor: pointer;
}

.calendar-directory-row.selected {
  border-color: var(--brand-cyan);
}

.calendar-editor-actions {
  margin-top: auto;
  padding: 0 0 0;
  justify-content: flex-end;
}

@media (max-width: 980px) {
  .calendar-view {
    flex-direction: column;
    overflow: auto;
  }

  .calendar-main {
    overflow: visible;
    padding-inline: 10px;
  }

  .calendar-toolbar,
  .calendar-directory-search {
    align-items: stretch;
    flex-wrap: wrap;
  }

  .calendar-toolbar .btn,
  .calendar-toolbar .search-field,
  .calendar-directory-search .search-field {
    flex: 1 1 100%;
    width: 100%;
    margin-inline-end: 0;
  }

  .calendar-editor {
    width: 100%;
    min-width: 0;
    border-left: 0;
    border-top: 1px solid var(--color-border);
  }
}
</style>
