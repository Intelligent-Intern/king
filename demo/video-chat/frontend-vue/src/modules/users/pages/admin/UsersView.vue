<template>
  <AdminPageFrame class="admin-users-view" :title="t('users.title')">
    <template #actions>
      <button class="btn btn-cyan" type="button" @click="openCreateUser">{{ t('users.new_user') }}</button>
    </template>

    <template #toolbar>
      <label class="search-field search-field-main" :aria-label="t('users.search')">
        <input
          v-model.trim="queryDraft"
          class="input"
          type="search"
          :placeholder="t('users.search')"
        />
      </label>

      <AppIconButton
        class="users-toolbar-search-btn"
        icon="/assets/orgas/kingrt/icons/send.png"
        :title="t('users.search')"
        :aria-label="t('users.search')"
        @click="applySearchNow"
      />
    </template>

    <section v-if="notice" class="section users-banner ok">{{ notice }}</section>
    <section v-if="error" class="section users-banner error">{{ error }}</section>
    <section v-if="loading && rows.length === 0" class="section users-empty">{{ t('users.loading') }}</section>

    <AdminUsersTable
      v-else
      :rows="rows"
      :mutating-user-id="mutatingUserId"
      :can-toggle-status="canToggleStatus"
      :can-delete-user="canDeleteUser"
      @edit-user="openEditUser"
      @toggle-user-status="toggleUserStatus"
      @delete-user="deleteUser"
    />

    <template #footer>
      <AppPagination
        :page="page"
        :page-count="pageCount"
        :total="pagination.total"
        :total-label="t('users.total_label')"
        :has-prev="pagination.hasPrev"
        :has-next="pagination.hasNext"
        :disabled="loading"
        @page-change="goToPage"
      />
    </template>

    <AdminUserEditorModal
      :open="dialogOpen"
      :dialog-title="dialogTitle"
      :dialog-submit-label="dialogSubmitLabel"
      :form="form"
      :form-saving="formSaving"
      :form-error="formError"
      :avatar-editor-open="avatarEditorOpen"
      :avatar-preview-src="avatarPreviewSrc"
      :avatar-editor-preview-src="avatarEditorPreviewSrc"
      :default-avatar-options="defaultAvatarOptions"
      :user-email-rows="userEmailRows"
      v-model:user-email-draft="userEmailDraft"
      :user-email-loading="userEmailLoading"
      :user-email-submitting="userEmailSubmitting"
      :user-email-mutating-id="userEmailMutatingId"
      :can-edit-role="canEditRole"
      :can-edit-governance-roles="canEditGovernanceRoles"
      :can-edit-governance-groups="canEditGovernanceGroups"
      :can-edit-status="canEditStatus"
      :can-edit-theme-editor="canEditThemeEditor"
      :theme-options="workspaceThemeOptions"
      :governance-role-options="governanceRoleOptions"
      :governance-group-options="governanceGroupOptions"
      @close="closeDialog"
      @delete-pending-email="deletePendingEmail"
      @create-pending-email="createPendingEmail"
      @open-avatar-editor="openAvatarEditor"
      @avatar-file-select="handleAvatarFileSelect"
      @set-default-avatar="setDefaultAvatar"
      @submit-form="submitForm"
      @save-avatar-changes="saveAvatarChanges"
    />
  </AdminPageFrame>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AppIconButton from '../../../../components/AppIconButton.vue';
import AppPagination from '../../../../components/AppPagination.vue';
import AdminPageFrame from '../../../../components/admin/AdminPageFrame.vue';
import AdminUserEditorModal from '../components/UserEditorModal.vue';
import AdminUsersTable from '../components/UsersTable.vue';
import { createAdminSyncReloadController } from './syncReload';
import { createAdminUsersApi, normalizeAdminAvatarSrc } from './api';
import { isAllowedAvatarMimeType, readAvatarFileAsDataUrl } from './avatarInput';
import { governanceGroupRelationshipPayload, governanceRoleRelationshipPayload, loadGovernanceGroupOptions, loadGovernanceRoleOptions } from './governanceRoles';
import { populateUserEditorForm, resetUserEditorForm } from './userEditorFormState';
import { appearanceState, loadWorkspaceAppearance } from '../../../../domain/workspace/appearance';
import { t } from '../../../localization/i18nRuntime.js';
import {
  applyAdminUserPermissions,
  canDeleteAdminUser,
  canToggleAdminUserStatus,
  resetAdminUserPermissions,
} from './permissions';
import { sessionState } from '../../../../domain/auth/session';

const router = useRouter();
const route = useRoute();
const apiRequest = createAdminUsersApi({ router });
const avatarPlaceholder = '/assets/orgas/kingrt/avatar-placeholder.svg';
const defaultAvatarOptions = computed(() => [
  { label: t('users.avatar_kingrt_default'), path: '/assets/orgas/kingrt/avatar-placeholder.svg' },
  { label: t('users.avatar_legacy_default'), path: '/assets/orgas/intelligent-intern/avatar-placeholder.svg' },
]);
const queryDraft = ref('');
const queryApplied = ref('');
const page = ref(1);
const rows = ref([]);
const loading = ref(false);
const error = ref('');
const notice = ref('');
const pagination = reactive({
  total: 0,
  pageCount: 1,
  hasPrev: false,
  hasNext: false,
});
const dialogOpen = ref(false);
const formSaving = ref(false);
const formError = ref('');
const mutatingUserId = ref(0);
const avatarEditorOpen = ref(false);
const avatarUploadDataUrl = ref('');
const avatarDefaultSelection = ref('');
const form = reactive({
  mode: 'create',
  id: 0,
  email: '',
  display_name: '',
  password: '',
  password_repeat: '',
  role: 'user',
  status: 'active',
  time_format: '24h',
  theme: 'dark',
  theme_editor_enabled: false,
  avatar_path: '',
  governance_groups: [],
  governance_roles: [],
});
const userEmailRows = ref([]);
const governanceGroupOptions = ref([]);
const governanceRoleOptions = ref([]);
const userEmailDraft = ref('');
const userEmailLoading = ref(false);
const userEmailSubmitting = ref(false);
const userEmailMutatingId = ref(0);
const selectedUserPermissions = reactive({
  isSelf: false,
  isPrimaryAdmin: false,
  canChangeRole: true,
  canChangeStatus: true,
  canChangeThemeEditor: true,
  canToggleStatus: true,
  canDelete: true,
});

let loadToken = 0;
let searchTimer = 0;
let routeEditRequestToken = 0;

const adminSyncReload = createAdminSyncReloadController({
  getSessionToken: () => sessionState.sessionToken,
  getOwnSessionId: () => sessionState.sessionId || sessionState.sessionToken,
  onReload: () => loadUsers(),
});

function applySelectedUserPermissions(user) {
  applyAdminUserPermissions(selectedUserPermissions, user, Number(sessionState.userId || 0));
}

function resetSelectedUserPermissions() {
  resetAdminUserPermissions(selectedUserPermissions);
}

function canToggleStatus(user) {
  return canToggleAdminUserStatus(user, Number(sessionState.userId || 0));
}

function canDeleteUser(user) {
  return canDeleteAdminUser(user, Number(sessionState.userId || 0));
}

async function loadUsers() {
  const token = ++loadToken;
  loading.value = true;
  error.value = '';

  try {
    const payload = await apiRequest('/api/admin/users', {
      query: {
        query: queryApplied.value,
        page: page.value,
        page_size: 10,
        order: 'role_then_name_asc',
      },
    });

    if (token !== loadToken) return;

    rows.value = Array.isArray(payload.users) ? payload.users : [];
    const paging = payload.pagination || {};
    const nextPageCount = Number.isInteger(paging.page_count) && paging.page_count > 0 ? paging.page_count : 1;
    pagination.total = Number.isInteger(paging.total) ? paging.total : rows.value.length;
    pagination.pageCount = nextPageCount;
    pagination.hasPrev = Boolean(paging.has_prev);
    pagination.hasNext = Boolean(paging.has_next);
    if (page.value > pagination.pageCount) {
      page.value = pagination.pageCount;
      if (token === loadToken) {
        await loadUsers();
      }
      return;
    }
  } catch (err) {
    if (token !== loadToken) return;
    rows.value = [];
    pagination.total = 0;
    pagination.pageCount = 1;
    pagination.hasPrev = false;
    pagination.hasNext = false;
    error.value = err instanceof Error ? err.message : t('users.load_failed');
  } finally {
    if (token === loadToken) loading.value = false;
  }
}

async function loadGovernanceRoles() {
  try {
    governanceRoleOptions.value = await loadGovernanceRoleOptions(apiRequest);
  } catch {
    governanceRoleOptions.value = [];
  }
}

async function loadGovernanceGroups() {
  try {
    governanceGroupOptions.value = await loadGovernanceGroupOptions(apiRequest);
  } catch {
    governanceGroupOptions.value = [];
  }
}

function loadGovernanceRelationOptions() {
  if (governanceGroupOptions.value.length === 0) void loadGovernanceGroups();
  if (governanceRoleOptions.value.length === 0) void loadGovernanceRoles();
}

function applySearchNow() {
  queryApplied.value = queryDraft.value.trim();
  page.value = 1;
  void loadUsers();
}

watch(queryDraft, () => {
  window.clearTimeout(searchTimer);
  searchTimer = window.setTimeout(() => {
    queryApplied.value = queryDraft.value.trim();
    page.value = 1;
    void loadUsers();
  }, 250);
});

function goToPage(nextPage) {
  if (!Number.isInteger(nextPage) || nextPage < 1 || nextPage === page.value) return;
  page.value = nextPage;
  void loadUsers();
}

function resetForm(mode = 'create') {
  resetUserEditorForm(form, mode);
  avatarEditorOpen.value = false;
  avatarUploadDataUrl.value = '';
  avatarDefaultSelection.value = '';
  userEmailRows.value = [];
  userEmailDraft.value = '';
  userEmailLoading.value = false;
  userEmailSubmitting.value = false;
  userEmailMutatingId.value = 0;
  resetSelectedUserPermissions();
  formError.value = '';
}

function openCreateUser() {
  resetForm('create');
  loadGovernanceRelationOptions();
  dialogOpen.value = true;
}

function clearEditUserQueryFromRoute() {
  const query = { ...route.query };
  let changed = false;
  if (Object.prototype.hasOwnProperty.call(query, 'edit_user_id')) {
    delete query.edit_user_id;
    changed = true;
  }
  if (Object.prototype.hasOwnProperty.call(query, 'email_verified')) {
    delete query.email_verified;
    changed = true;
  }
  if (!changed) return;
  void router.replace({ query }).catch(() => {});
}

function routeEditUserId() {
  const raw = typeof route.query.edit_user_id === 'string'
    ? route.query.edit_user_id.trim()
    : '';
  const userId = Number(raw);
  return Number.isInteger(userId) && userId > 0 ? userId : 0;
}

async function loadUserEmails(userId) {
  const normalizedUserId = Number(userId || 0);
  if (normalizedUserId <= 0) {
    userEmailRows.value = [];
    return;
  }

  userEmailLoading.value = true;
  try {
    const payload = await apiRequest(`/api/admin/users/${encodeURIComponent(String(normalizedUserId))}/emails`);
    const result = payload?.result && typeof payload.result === 'object' ? payload.result : {};
    const emails = Array.isArray(result.emails) ? result.emails : [];
    userEmailRows.value = emails;
  } catch (err) {
    userEmailRows.value = [];
    formError.value = err instanceof Error ? err.message : t('users.load_emails_failed');
  } finally {
    userEmailLoading.value = false;
  }
}

async function openEditUser(user) {
  resetForm('edit');
  populateUserEditorForm(form, user);
  applySelectedUserPermissions(user);
  loadGovernanceRelationOptions();
  dialogOpen.value = true;
  await loadUserEmails(form.id);
}

function closeDialog() {
  if (form.mode === 'edit') {
    clearEditUserQueryFromRoute();
  }
  dialogOpen.value = false;
  formSaving.value = false;
  avatarEditorOpen.value = false;
  avatarUploadDataUrl.value = '';
  avatarDefaultSelection.value = '';
  userEmailRows.value = [];
  userEmailDraft.value = '';
  userEmailLoading.value = false;
  userEmailSubmitting.value = false;
  userEmailMutatingId.value = 0;
  resetSelectedUserPermissions();
  formError.value = '';
}

const dialogTitle = computed(() => (form.mode === 'create' ? t('users.create_user') : t('users.edit_user')));
const dialogSubmitLabel = computed(() => (form.mode === 'create' ? t('users.create_user') : t('common.save_changes')));
const pageCount = computed(() => Math.max(1, pagination.pageCount));
const canEditRole = computed(() => (form.mode === 'create' ? true : selectedUserPermissions.canChangeRole));
const canEditGovernanceGroups = computed(() => (form.mode === 'create' ? true : !selectedUserPermissions.isSelf));
const canEditGovernanceRoles = canEditGovernanceGroups;
const canEditStatus = computed(() => (form.mode === 'create' ? true : selectedUserPermissions.canChangeStatus));
const canEditThemeEditor = computed(() => (form.mode === 'create' ? true : selectedUserPermissions.canChangeThemeEditor));
const workspaceThemeOptions = computed(() => (
  appearanceState.themes.length > 0
    ? appearanceState.themes
    : [
      { id: 'dark', label: t('theme_settings.system_dark') },
      { id: 'light', label: t('theme_settings.system_light') },
    ]
));
const avatarPreviewSrc = computed(() => normalizeAdminAvatarSrc(form.avatar_path, avatarPlaceholder));
const avatarEditorPreviewSrc = computed(() => {
  if (avatarUploadDataUrl.value !== '') return avatarUploadDataUrl.value;
  if (avatarDefaultSelection.value !== '') {
    return normalizeAdminAvatarSrc(avatarDefaultSelection.value, avatarPlaceholder);
  }
  return avatarPreviewSrc.value;
});

function openAvatarEditor() {
  if (form.mode !== 'edit') return;
  avatarEditorOpen.value = true;
  avatarUploadDataUrl.value = '';
  avatarDefaultSelection.value = '';
  formError.value = '';
}

function closeAvatarEditor() {
  avatarEditorOpen.value = false;
  avatarUploadDataUrl.value = '';
  avatarDefaultSelection.value = '';
  formError.value = '';
}

async function handleAvatarFileSelect(event) {
  const file = event?.target?.files?.[0] || null;
  if (!file) return;

  if (!isAllowedAvatarMimeType(file.type)) {
    formError.value = t('users.avatar_type_invalid');
    return;
  }

  try {
    avatarUploadDataUrl.value = await readAvatarFileAsDataUrl(file);
    avatarDefaultSelection.value = '';
    formError.value = '';
  } catch (err) {
    formError.value = err instanceof Error ? err.message : t('settings.avatar_prepare_failed');
  }
}


function setDefaultAvatar(path) {
  avatarDefaultSelection.value = String(path || '').trim();
  avatarUploadDataUrl.value = '';
  formError.value = '';
}

async function createPendingEmail() {
  if (form.mode !== 'edit' || form.id <= 0 || userEmailSubmitting.value) return;

  const nextEmail = String(userEmailDraft.value || '').trim().toLowerCase();
  if (nextEmail === '') {
    formError.value = t('users.email_required');
    return;
  }
  if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(nextEmail)) {
    formError.value = t('users.email_invalid');
    return;
  }

  userEmailSubmitting.value = true;
  formError.value = '';
  try {
    const payload = await apiRequest(`/api/admin/users/${encodeURIComponent(String(form.id))}/emails`, {
      method: 'POST',
      body: {
        email: nextEmail,
      },
    });
    const result = payload?.result && typeof payload.result === 'object' ? payload.result : {};
    const delivery = result.delivery && typeof result.delivery === 'object' ? result.delivery : {};
    const sent = Boolean(delivery.sent);
    const channel = String(delivery.channel || '').trim();
    if (sent) {
      notice.value = t('users.confirmation_email_sent', { email: nextEmail });
    } else if (channel !== '') {
      notice.value = t('users.confirmation_queued_channel', { email: nextEmail, channel });
    } else {
      notice.value = t('users.confirmation_queued', { email: nextEmail });
    }
    adminSyncReload.publish('users', 'user_email_added');
    userEmailDraft.value = '';
    await loadUserEmails(form.id);
  } catch (err) {
    formError.value = err instanceof Error ? err.message : t('users.email_confirmation_create_failed');
  } finally {
    userEmailSubmitting.value = false;
  }
}

async function deletePendingEmail(emailRow) {
  if (form.mode !== 'edit' || form.id <= 0) return;

  const emailId = Number(emailRow?.id || 0);
  const emailValue = String(emailRow?.email || '').trim();
  if (emailId <= 0) return;
  if (Boolean(emailRow?.is_verified)) {
    formError.value = t('users.confirmed_email_delete_blocked');
    return;
  }

  const confirmed = window.confirm(t('users.confirm_delete_unconfirmed_email', { email: emailValue || `#${emailId}` }));
  if (!confirmed) return;

  userEmailMutatingId.value = emailId;
  formError.value = '';
  try {
    await apiRequest(`/api/admin/users/${encodeURIComponent(String(form.id))}/emails/${encodeURIComponent(String(emailId))}`, {
      method: 'DELETE',
    });
    notice.value = t('users.removed_unconfirmed_email', { email: emailValue || `#${emailId}` });
    adminSyncReload.publish('users', 'user_email_removed');
    await loadUserEmails(form.id);
  } catch (err) {
    formError.value = err instanceof Error ? err.message : t('users.delete_email_failed');
  } finally {
    userEmailMutatingId.value = 0;
  }
}

async function submitForm() {
  if (formSaving.value) return;

  const email = String(form.email || '').trim();
  const displayName = String(form.display_name || '').trim();
  const role = String(form.role || 'user').trim();

  if (displayName === '') {
    formError.value = t('settings.display_name_required');
    return;
  }

  if (form.mode === 'create' && email === '') {
    formError.value = t('users.email_required');
    return;
  }

  if (form.mode === 'create' && String(form.password || '').trim() === '') {
    formError.value = t('users.password_required_new');
    return;
  }

  if (form.mode === 'create' && String(form.password_repeat || '') !== String(form.password || '')) {
    formError.value = t('users.passwords_mismatch');
    return;
  }

  formSaving.value = true;
  formError.value = '';

  try {
    if (form.mode === 'create') {
      await apiRequest('/api/admin/users', {
        method: 'POST',
        body: {
          email,
          display_name: displayName,
          password: form.password,
          password_repeat: form.password_repeat,
          role,
          theme_editor_enabled: Boolean(form.theme_editor_enabled),
          relationships: {
            groups: governanceGroupRelationshipPayload(form.governance_groups),
            roles: governanceRoleRelationshipPayload(form.governance_roles),
          },
        },
      });
      notice.value = t('users.created_notice', { name: displayName });
      adminSyncReload.publish('users', 'user_created');
      page.value = 1;
    } else {
      const patchBody = {
        display_name: displayName,
        time_format: String(form.time_format || '24h'),
        theme: String(form.theme || 'dark'),
        avatar_path: String(form.avatar_path || '').trim() === '' ? null : String(form.avatar_path || '').trim(),
      };
      if (canEditThemeEditor.value) {
        patchBody.theme_editor_enabled = Boolean(form.theme_editor_enabled);
      }
      if (canEditRole.value) {
        patchBody.role = role;
      }
      if (canEditStatus.value) {
        patchBody.status = String(form.status || 'active');
      }
      if (canEditGovernanceGroups.value || canEditGovernanceRoles.value) {
        patchBody.relationships = {};
        if (canEditGovernanceGroups.value) {
          patchBody.relationships.groups = governanceGroupRelationshipPayload(form.governance_groups);
        }
        if (canEditGovernanceRoles.value) {
          patchBody.relationships.roles = governanceRoleRelationshipPayload(form.governance_roles);
        }
      }

      await apiRequest(`/api/admin/users/${encodeURIComponent(String(form.id))}`, {
        method: 'PATCH',
        body: patchBody,
      });
      notice.value = t('users.updated_notice', { name: displayName });
      adminSyncReload.publish('users', 'user_updated');
    }

    dialogOpen.value = false;
    await loadUsers();
  } catch (err) {
    formError.value = err instanceof Error ? err.message : t('users.save_failed');
  } finally {
    formSaving.value = false;
  }
}

async function saveAvatarChanges() {
  if (formSaving.value || form.mode !== 'edit' || form.id <= 0) return;

  const userId = Number(form.id || 0);
  if (userId <= 0) return;

  if (avatarUploadDataUrl.value === '' && avatarDefaultSelection.value === '') {
    formError.value = t('users.avatar_select_required');
    return;
  }

  formSaving.value = true;
  formError.value = '';
  error.value = '';
  notice.value = '';

  try {
    if (avatarUploadDataUrl.value !== '') {
      const uploadPayload = await apiRequest(`/api/admin/users/${encodeURIComponent(String(userId))}/avatar`, {
        method: 'POST',
        body: {
          data_url: avatarUploadDataUrl.value,
        },
      });
      const avatarPath = String(uploadPayload?.result?.avatar_path || '').trim();
      form.avatar_path = avatarPath;
      notice.value = t('users.avatar_uploaded');
    } else {
      const defaultPath = String(avatarDefaultSelection.value || '').trim();
      await apiRequest(`/api/admin/users/${encodeURIComponent(String(userId))}`, {
        method: 'PATCH',
        body: {
          avatar_path: defaultPath === '' ? null : defaultPath,
        },
      });
      form.avatar_path = defaultPath;
      notice.value = t('users.default_avatar_applied');
    }

    adminSyncReload.publish('users', 'user_avatar_updated');
    closeAvatarEditor();
    await loadUsers();
  } catch (err) {
    formError.value = err instanceof Error ? err.message : t('users.avatar_save_failed');
  } finally {
    formSaving.value = false;
  }
}

async function deleteUser(user) {
  const userId = Number(user?.id || 0);
  if (userId <= 0) return;
  if (!canDeleteUser(user)) return;
  if (mutatingUserId.value === userId) return;

  const label = String(user?.display_name || user?.email || `#${userId}`);
  const confirmed = window.confirm(t('users.confirm_delete_user', { name: label }));
  if (!confirmed) return;

  mutatingUserId.value = userId;
  error.value = '';
  notice.value = '';
  formError.value = '';

  try {
    const payload = await apiRequest(`/api/admin/users/${encodeURIComponent(String(userId))}`, {
      method: 'DELETE',
    });
    const deletedCalls = Number(payload?.result?.deleted_calls || 0);
    notice.value = t('users.deleted_notice', { name: label, count: deletedCalls });
    adminSyncReload.publish('users', 'user_deleted');

    if (dialogOpen.value && form.mode === 'edit' && Number(form.id || 0) === userId) {
      closeDialog();
    }

    if (rows.value.length === 1 && page.value > 1) {
      page.value -= 1;
    }
    await loadUsers();
  } catch (err) {
    error.value = err instanceof Error ? err.message : t('users.delete_failed');
  } finally {
    mutatingUserId.value = 0;
  }
}

async function toggleUserStatus(user) {
  const userId = Number(user.id || 0);
  if (userId <= 0) return;
  if (!canToggleStatus(user)) return;
  mutatingUserId.value = userId;
  error.value = '';
  notice.value = '';

  try {
    const status = String(user.status || '').toLowerCase();
    if (status !== 'disabled' && userId === Number(sessionState.userId || 0)) {
      error.value = t('users.cannot_deactivate_self');
      return;
    }
    await apiRequest(`/api/admin/users/${encodeURIComponent(String(userId))}/${status === 'disabled' ? 'reactivate' : 'deactivate'}`, {
      method: 'POST',
    });
    notice.value = t('users.status_changed_notice', {
      action: status === 'disabled' ? t('users.reactivated') : t('users.deactivated'),
      name: String(user.display_name || user.email || userId),
    });
    adminSyncReload.publish('users', 'user_status_updated');
    await loadUsers();
  } catch (err) {
    error.value = err instanceof Error ? err.message : t('users.status_update_failed');
  } finally {
    mutatingUserId.value = 0;
  }
}

async function fetchUserById(userId) {
  const normalizedUserId = Number(userId || 0);
  if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) {
    return null;
  }
  const payload = await apiRequest(`/api/admin/users/${encodeURIComponent(String(normalizedUserId))}`);
  const result = payload?.result && typeof payload.result === 'object' ? payload.result : {};
  const user = result.user && typeof result.user === 'object' ? result.user : null;
  return user;
}

async function openEditUserFromRouteQuery() {
  const userId = routeEditUserId();
  if (userId <= 0) return;

  const alreadyOpen = dialogOpen.value
    && form.mode === 'edit'
    && Number(form.id || 0) === userId;
  if (alreadyOpen) return;

  const requestToken = ++routeEditRequestToken;
  try {
    const user = await fetchUserById(userId);
    if (requestToken !== routeEditRequestToken) return;
    if (!user) return;
    await openEditUser(user);
    if (String(route.query.email_verified || '').trim() === '1') {
      notice.value = t('users.email_change_confirmed');
    }
  } catch (err) {
    if (requestToken !== routeEditRequestToken) return;
    error.value = err instanceof Error ? err.message : t('users.open_editor_failed');
  }
}

watch(
  () => [route.query.edit_user_id, route.query.email_verified],
  () => {
    void openEditUserFromRouteQuery();
  }
);

onMounted(() => {
  adminSyncReload.start();
  void (async () => {
    await loadUsers();
    await Promise.all([
      loadWorkspaceAppearance({ force: true }),
      loadGovernanceRoles(),
    ]);
    await openEditUserFromRouteQuery();
  })();
});

onBeforeUnmount(() => {
  window.clearTimeout(searchTimer);
  searchTimer = 0;
  adminSyncReload.dispose();
});

watch(
  () => sessionState.sessionToken,
  (nextValue, previousValue) => {
    const nextToken = String(nextValue || '').trim();
    const previousToken = String(previousValue || '').trim();
    if (nextToken === previousToken) return;
    adminSyncReload.reconnect();
  }
);
</script>

<style scoped src="./UsersView.css"></style>
