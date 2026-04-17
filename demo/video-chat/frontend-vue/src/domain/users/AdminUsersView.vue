<template>
  <section class="view-card admin-users-view">
    <header class="section admin-users-head">
      <div>
        <h1>User Management</h1>
      </div>
      <div class="actions">
        <button class="btn btn-cyan" type="button" @click="openCreateUser">New user</button>
      </div>
    </header>

    <section class="toolbar admin-users-toolbar">
      <label class="search-field search-field-main" aria-label="Search users">
        <input
          v-model.trim="queryDraft"
          class="input"
          type="search"
          placeholder="Search by name, email, or role"
        />
      </label>

      <AppSelect v-model.number="pageSize" @change="resetAndReload">
        <option :value="10">10 / page</option>
        <option :value="20">20 / page</option>
        <option :value="50">50 / page</option>
      </AppSelect>

      <AppSelect v-model="order" @change="resetAndReload">
        <option value="role_then_name_asc">Role + name A-Z</option>
        <option value="role_then_name_desc">Role + name Z-A</option>
      </AppSelect>

      <button
        class="icon-mini-btn users-toolbar-search-btn"
        type="button"
        title="Search users"
        aria-label="Search users"
        @click="applySearchNow"
      >
        <img src="/assets/orgas/kingrt/icons/send.png" alt="" />
      </button>
    </section>

    <section v-if="notice" class="section users-banner ok">{{ notice }}</section>
    <section v-if="error" class="section users-banner error">{{ error }}</section>
    <section v-if="loading && rows.length === 0" class="section users-empty">Loading users...</section>

    <section v-else class="table-wrap users-table-wrap">
      <table class="users-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Email</th>
            <th>Role</th>
            <th>Status</th>
            <th>Updated</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="user in rows" :key="user.id">
            <td data-label="Name">
              <div class="users-name">{{ user.display_name }}</div>
              <div class="users-subline code">#{{ user.id }}</div>
            </td>
            <td data-label="Email">
              <div>{{ user.email }}</div>
              <div class="users-subline">{{ user.time_format }} · {{ user.theme }}</div>
            </td>
            <td data-label="Role"><span class="tag" :class="roleTagClass(user.role)">{{ user.role }}</span></td>
            <td data-label="Status"><span class="tag" :class="statusTagClass(user.status)">{{ user.status }}</span></td>
            <td data-label="Updated">{{ formatDateTime(user.updated_at) }}</td>
            <td data-label="Actions">
              <div class="actions-inline">
                <button class="icon-mini-btn" type="button" title="Edit user" @click="openEditUser(user)">
                  <img src="/assets/orgas/kingrt/icons/gear.png" alt="" />
                </button>
                <button
                  v-if="canToggleStatus(user)"
                  class="icon-mini-btn"
                  type="button"
                  :disabled="mutatingUserId === user.id"
                  @click="toggleUserStatus(user)"
                >
                  <img
                    :src="String(user.status || '').toLowerCase() === 'disabled'
                      ? '/assets/orgas/kingrt/icons/adminon.png'
                      : '/assets/orgas/kingrt/icons/adminoff.png'"
                    alt=""
                  />
                </button>
                <button
                  v-if="canDeleteUser(user)"
                  class="icon-mini-btn danger"
                  type="button"
                  title="Delete user"
                  :disabled="mutatingUserId === user.id"
                  @click="deleteUser(user)"
                >
                  <img src="/assets/orgas/kingrt/icons/remove_user.png" alt="" />
                </button>
              </div>
            </td>
          </tr>
          <tr v-if="rows.length === 0">
            <td colspan="6" class="users-empty-cell">No users match the current filter.</td>
          </tr>
        </tbody>
      </table>
    </section>

    <footer class="footer users-footer">
      <div class="pagination">
        <button class="pager-btn pager-icon-btn" type="button" :disabled="!pagination.hasPrev || loading" @click="goToPage(page - 1)">
          <img class="pager-icon-img" src="/assets/orgas/kingrt/icons/backward.png" alt="" />
        </button>
        <div class="page-info">Page {{ page }} / {{ pageCount }} · {{ pagination.total }} users</div>
        <button class="pager-btn pager-icon-btn" type="button" :disabled="!pagination.hasNext || loading" @click="goToPage(page + 1)">
          <img class="pager-icon-img" src="/assets/orgas/kingrt/icons/forward.png" alt="" />
        </button>
      </div>
    </footer>

    <div v-if="dialogOpen" class="users-modal" role="dialog" aria-modal="true" :aria-label="dialogTitle">
      <div class="users-modal-backdrop" @click="closeDialog"></div>
      <div class="users-modal-dialog">
        <header class="users-modal-head users-modal-head-brand">
          <div class="users-modal-head-left">
            <img class="users-modal-head-logo" src="/assets/orgas/kingrt/logo.svg" alt="" />
            <h4>{{ dialogTitle }}</h4>
          </div>
          <button class="icon-mini-btn" type="button" @click="closeDialog">
            <img src="/assets/orgas/kingrt/icons/cancel.png" alt="" />
          </button>
        </header>

        <div v-if="!avatarEditorOpen" class="users-modal-body">
          <label v-if="form.mode === 'create'" class="users-field">
            <span>Email</span>
            <input v-model.trim="form.email" class="input" type="email" autocomplete="email" />
          </label>

          <section v-else class="users-field users-field-wide">
            <span>Emails</span>
            <div class="users-email-list">
              <article
                v-for="emailRow in userEmailRows"
                :key="emailRow.id"
                class="users-email-row"
              >
                <div class="users-email-main">
                  <div class="users-email-value">{{ emailRow.email }}</div>
                  <div class="users-email-meta">
                    <span class="tag" :class="emailRow.is_verified ? 'ok' : 'warn'">
                      {{ emailRow.is_verified ? 'confirmed' : 'unconfirmed' }}
                    </span>
                    <span v-if="emailRow.is_primary" class="tag ok">primary</span>
                  </div>
                </div>
                <button
                  v-if="!emailRow.is_verified"
                  class="icon-mini-btn danger"
                  type="button"
                  :disabled="formSaving || userEmailMutatingId === emailRow.id"
                  @click="deletePendingEmail(emailRow)"
                >
                  <img src="/assets/orgas/kingrt/icons/remove_user.png" alt="" />
                </button>
              </article>
              <p v-if="userEmailRows.length === 0" class="users-email-empty">No emails configured.</p>
            </div>
            <div class="users-email-create">
              <input
                v-model.trim="userEmailDraft"
                class="input"
                type="email"
                autocomplete="email"
                placeholder="Add new email"
                :disabled="formSaving || userEmailSubmitting || userEmailLoading"
              />
              <button
                class="btn"
                type="button"
                :disabled="formSaving || userEmailSubmitting || userEmailLoading"
                @click="createPendingEmail"
              >
                {{ userEmailSubmitting ? 'Sending…' : 'Send confirmation' }}
              </button>
            </div>
          </section>

          <label class="users-field">
            <span>Display name</span>
            <input v-model.trim="form.display_name" class="input" type="text" />
          </label>

          <label v-if="form.mode === 'create'" class="users-field">
            <span>Password</span>
            <input v-model="form.password" class="input" type="password" autocomplete="new-password" />
          </label>

          <label v-if="form.mode === 'create'" class="users-field">
            <span>Repeat password</span>
            <input v-model="form.password_repeat" class="input" type="password" autocomplete="new-password" />
          </label>

          <label class="users-field">
            <span>Role</span>
            <AppSelect v-model="form.role" :disabled="!canEditRole">
              <option value="user">user</option>
              <option value="admin">admin</option>
            </AppSelect>
          </label>

          <label v-if="form.mode === 'edit'" class="users-field">
            <span>Status</span>
            <AppSelect v-model="form.status" :disabled="!canEditStatus">
              <option value="active">active</option>
              <option value="disabled">disabled</option>
            </AppSelect>
          </label>

          <label v-if="form.mode === 'edit'" class="users-field">
            <span>Time format</span>
            <AppSelect v-model="form.time_format">
              <option value="24h">24h</option>
              <option value="12h">12h</option>
            </AppSelect>
          </label>

          <label v-if="form.mode === 'edit'" class="users-field">
            <span>Theme</span>
            <AppSelect v-model="form.theme">
              <option value="dark">dark</option>
              <option value="light">light</option>
            </AppSelect>
          </label>

          <section v-if="form.mode === 'edit'" class="users-field users-field-wide users-avatar-edit-row">
            <div class="users-avatar-preview-wrap">
              <img class="users-avatar-preview" :src="avatarPreviewSrc" alt="User avatar preview" />
            </div>
            <div class="users-avatar-edit-actions">
              <button class="btn btn-cyan" type="button" :disabled="formSaving" @click="openAvatarEditor">Change avatar</button>
            </div>
          </section>
        </div>

        <div v-else class="users-avatar-modal-body">
          <div class="users-avatar-preview-wrap">
            <img class="users-avatar-preview users-avatar-preview-large" :src="avatarEditorPreviewSrc" alt="Avatar preview" />
          </div>
          <label class="users-avatar-file">
            <span>Upload avatar</span>
            <input class="input" type="file" accept="image/png,image/jpeg,image/webp" @change="handleAvatarFileSelect" />
          </label>
          <section class="users-avatar-defaults">
            <span>Set default</span>
            <div class="users-avatar-defaults-actions">
              <button
                v-for="option in defaultAvatarOptions"
                :key="option.path"
                class="btn"
                type="button"
                :disabled="formSaving"
                @click="setDefaultAvatar(option.path)"
              >
                {{ option.label }}
              </button>
            </div>
          </section>
          <p class="users-avatar-hint">Upload a file or pick one default avatar.</p>
        </div>

        <p v-if="formError" class="users-form-error">{{ formError }}</p>

        <footer class="users-modal-footer">
          <button
            class="btn btn-cyan"
            type="button"
            :disabled="formSaving"
            @click="avatarEditorOpen ? saveAvatarChanges() : submitForm()"
          >
            {{ formSaving ? 'Saving...' : (avatarEditorOpen ? 'Save avatar' : dialogSubmitLabel) }}
          </button>
        </footer>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AppSelect from '../../components/AppSelect.vue';
import { currentBackendOrigin, fetchBackend } from '../../support/backendFetch';
import { logoutSession, refreshSession, sessionState } from '../auth/session';

const router = useRouter();
const route = useRoute();
const avatarPlaceholder = '/assets/orgas/kingrt/avatar-placeholder.svg';
const defaultAvatarOptions = [
  { label: 'KingRT default', path: '/assets/orgas/kingrt/avatar-placeholder.svg' },
  { label: 'Legacy default', path: '/assets/orgas/intelligent-intern/avatar-placeholder.svg' },
];
const queryDraft = ref('');
const queryApplied = ref('');
const page = ref(1);
const pageSize = ref(10);
const order = ref('role_then_name_asc');
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
  avatar_path: '',
});
const userEmailRows = ref([]);
const userEmailDraft = ref('');
const userEmailLoading = ref(false);
const userEmailSubmitting = ref(false);
const userEmailMutatingId = ref(0);
const selectedUserPermissions = reactive({
  isSelf: false,
  isPrimaryAdmin: false,
  canChangeRole: true,
  canChangeStatus: true,
  canToggleStatus: true,
  canDelete: true,
});

let loadToken = 0;
let searchTimer = 0;
let routeEditRequestToken = 0;

function normalizeAvatarSrc(rawPath) {
  const value = String(rawPath || '').trim();
  if (value === '') return avatarPlaceholder;
  if (value.startsWith('data:')) return value;
  if (value.startsWith('http://') || value.startsWith('https://')) return value;
  if (value.startsWith('/api/')) return `${currentBackendOrigin()}${value}`;
  return value;
}

function requestHeaders(includeBody) {
  const token = String(sessionState.sessionToken || '').trim();
  const headers = { accept: 'application/json' };
  if (includeBody) headers['content-type'] = 'application/json';
  if (token !== '') {
    headers.authorization = `Bearer ${token}`;
  }
  return headers;
}

function extractErrorMessage(payload, fallback) {
  const message = payload && typeof payload === 'object' ? payload?.error?.message : '';
  if (typeof message === 'string' && message.trim() !== '') return message.trim();
  return fallback;
}

async function apiRequest(path, { method = 'GET', query = null, body = null } = {}, allowRefreshRetry = true) {
  let response = null;
  try {
    const result = await fetchBackend(path, {
      method,
      query,
      headers: requestHeaders(body !== null),
      body: body === null ? undefined : JSON.stringify(body),
    });
    response = result.response;
  } catch (error) {
    const message = error instanceof Error ? error.message.trim() : '';
    if (message === '' || /failed to fetch|socket|connection/i.test(message)) {
      throw new Error(`Could not reach backend (${currentBackendOrigin()}).`);
    }
    throw new Error(message);
  }

  let payload = null;
  try {
    payload = await response.json();
  } catch {
    payload = null;
  }

  if (!response.ok) {
    if ((response.status === 401 || response.status === 403) && allowRefreshRetry) {
      const refreshResult = await refreshSession();
      if (refreshResult?.ok) {
        return apiRequest(path, { method, query, body }, false);
      }
      await logoutSession();
      await router.push('/login');
      throw new Error('Session expired. Please sign in again.');
    }
    throw new Error(extractErrorMessage(payload, `Request failed (${response.status}).`));
  }

  if (!payload || payload.status !== 'ok') {
    throw new Error('Backend returned an invalid payload.');
  }

  return payload;
}

function formatDateTime(value) {
  const text = typeof value === 'string' ? value.trim() : '';
  if (text === '') return 'n/a';
  const date = new Date(text);
  if (Number.isNaN(date.getTime())) return text;
  return new Intl.DateTimeFormat('en-GB', {
    year: 'numeric',
    month: 'short',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  }).format(date);
}

function roleTagClass(role) {
  const normalized = String(role || '').toLowerCase();
  if (normalized === 'admin') return 'ok';
  return 'warn';
}

function statusTagClass(status) {
  const normalized = String(status || '').toLowerCase();
  if (normalized === 'active') return 'ok';
  if (normalized === 'disabled') return 'warn';
  return 'warn';
}

function normalizeBoolean(value, fallback = false) {
  if (typeof value === 'boolean') return value;
  return fallback;
}

function deriveUserPermissions(user) {
  if (!user || typeof user !== 'object') {
    return {
      isSelf: false,
      isPrimaryAdmin: false,
      canChangeRole: true,
      canChangeStatus: true,
      canToggleStatus: true,
      canDelete: true,
    };
  }

  const userPermissions = user.permissions && typeof user.permissions === 'object'
    ? user.permissions
    : {};
  const userId = Number(user.id || 0);
  const fallbackIsSelf = userId > 0 && userId === Number(sessionState.userId || 0);
  const isSelf = normalizeBoolean(user.is_self, fallbackIsSelf);
  const isPrimaryAdmin = normalizeBoolean(user.is_primary_admin, false);
  const fallbackAllowed = !isSelf && !isPrimaryAdmin;

  return {
    isSelf,
    isPrimaryAdmin,
    canChangeRole: normalizeBoolean(userPermissions.can_change_role, fallbackAllowed),
    canChangeStatus: normalizeBoolean(userPermissions.can_change_status, fallbackAllowed),
    canToggleStatus: normalizeBoolean(userPermissions.can_toggle_status, fallbackAllowed),
    canDelete: normalizeBoolean(userPermissions.can_delete, fallbackAllowed),
  };
}

function applySelectedUserPermissions(user) {
  const permissions = deriveUserPermissions(user);
  selectedUserPermissions.isSelf = permissions.isSelf;
  selectedUserPermissions.isPrimaryAdmin = permissions.isPrimaryAdmin;
  selectedUserPermissions.canChangeRole = permissions.canChangeRole;
  selectedUserPermissions.canChangeStatus = permissions.canChangeStatus;
  selectedUserPermissions.canToggleStatus = permissions.canToggleStatus;
  selectedUserPermissions.canDelete = permissions.canDelete;
}

function resetSelectedUserPermissions() {
  selectedUserPermissions.isSelf = false;
  selectedUserPermissions.isPrimaryAdmin = false;
  selectedUserPermissions.canChangeRole = true;
  selectedUserPermissions.canChangeStatus = true;
  selectedUserPermissions.canToggleStatus = true;
  selectedUserPermissions.canDelete = true;
}

function canToggleStatus(user) {
  return deriveUserPermissions(user).canToggleStatus;
}

function canDeleteUser(user) {
  return deriveUserPermissions(user).canDelete;
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
        page_size: pageSize.value,
        order: order.value,
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
    error.value = err instanceof Error ? err.message : 'Could not load users.';
  } finally {
    if (token === loadToken) loading.value = false;
  }
}

function resetAndReload() {
  page.value = 1;
  void loadUsers();
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
  form.mode = mode;
  form.id = 0;
  form.email = '';
  form.display_name = '';
  form.password = '';
  form.password_repeat = '';
  form.role = 'user';
  form.status = 'active';
  form.time_format = '24h';
  form.theme = 'dark';
  form.avatar_path = '';
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
    formError.value = err instanceof Error ? err.message : 'Could not load user emails.';
  } finally {
    userEmailLoading.value = false;
  }
}

async function openEditUser(user) {
  resetForm('edit');
  form.id = Number(user.id || 0);
  form.email = String(user.email || '');
  form.display_name = String(user.display_name || '');
  form.role = String(user.role || 'user');
  form.status = String(user.status || 'active');
  form.time_format = String(user.time_format || '24h');
  form.theme = String(user.theme || 'dark');
  form.avatar_path = String(user.avatar_path || '');
  applySelectedUserPermissions(user);
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

const dialogTitle = computed(() => (form.mode === 'create' ? 'Create user' : 'Edit user'));
const dialogSubmitLabel = computed(() => (form.mode === 'create' ? 'Create user' : 'Save changes'));
const pageCount = computed(() => Math.max(1, pagination.pageCount));
const canEditRole = computed(() => (form.mode === 'create' ? true : selectedUserPermissions.canChangeRole));
const canEditStatus = computed(() => (form.mode === 'create' ? true : selectedUserPermissions.canChangeStatus));
const avatarPreviewSrc = computed(() => normalizeAvatarSrc(form.avatar_path));
const avatarEditorPreviewSrc = computed(() => {
  if (avatarUploadDataUrl.value !== '') return avatarUploadDataUrl.value;
  if (avatarDefaultSelection.value !== '') return normalizeAvatarSrc(avatarDefaultSelection.value);
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

function readFileAsDataUrl(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(typeof reader.result === 'string' ? reader.result : '');
    reader.onerror = () => reject(new Error('Could not read avatar file.'));
    reader.readAsDataURL(file);
  });
}

async function handleAvatarFileSelect(event) {
  const file = event?.target?.files?.[0] || null;
  if (!file) return;

  if (!['image/png', 'image/jpeg', 'image/webp'].includes(String(file.type || ''))) {
    formError.value = 'Avatar must be PNG, JPEG, or WEBP.';
    return;
  }

  try {
    avatarUploadDataUrl.value = await readFileAsDataUrl(file);
    avatarDefaultSelection.value = '';
    formError.value = '';
  } catch (err) {
    formError.value = err instanceof Error ? err.message : 'Could not prepare avatar upload.';
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
    formError.value = 'Email is required.';
    return;
  }
  if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(nextEmail)) {
    formError.value = 'Email is invalid.';
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
      notice.value = `Confirmation email sent to ${nextEmail}.`;
    } else if (channel !== '') {
      notice.value = `Confirmation for ${nextEmail} queued via ${channel}.`;
    } else {
      notice.value = `Confirmation for ${nextEmail} has been queued.`;
    }
    userEmailDraft.value = '';
    await loadUserEmails(form.id);
  } catch (err) {
    formError.value = err instanceof Error ? err.message : 'Could not create email confirmation.';
  } finally {
    userEmailSubmitting.value = false;
  }
}

async function deletePendingEmail(emailRow) {
  if (form.mode !== 'edit' || form.id <= 0) return;

  const emailId = Number(emailRow?.id || 0);
  const emailValue = String(emailRow?.email || '').trim();
  if (emailId <= 0) return;
  if (normalizeBoolean(emailRow?.is_verified, false)) {
    formError.value = 'Confirmed emails cannot be deleted.';
    return;
  }

  const confirmed = window.confirm(`Delete unconfirmed email ${emailValue || `#${emailId}`}?`);
  if (!confirmed) return;

  userEmailMutatingId.value = emailId;
  formError.value = '';
  try {
    await apiRequest(`/api/admin/users/${encodeURIComponent(String(form.id))}/emails/${encodeURIComponent(String(emailId))}`, {
      method: 'DELETE',
    });
    notice.value = `Removed unconfirmed email ${emailValue || `#${emailId}`}.`;
    await loadUserEmails(form.id);
  } catch (err) {
    formError.value = err instanceof Error ? err.message : 'Could not delete email.';
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
    formError.value = 'Display name is required.';
    return;
  }

  if (form.mode === 'create' && email === '') {
    formError.value = 'Email is required.';
    return;
  }

  if (form.mode === 'create' && String(form.password || '').trim() === '') {
    formError.value = 'Password is required for new users.';
    return;
  }

  if (form.mode === 'create' && String(form.password_repeat || '') !== String(form.password || '')) {
    formError.value = 'Passwords do not match.';
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
        },
      });
      notice.value = `Created ${displayName}.`;
      page.value = 1;
    } else {
      const patchBody = {
        display_name: displayName,
        time_format: String(form.time_format || '24h'),
        theme: String(form.theme || 'dark'),
        avatar_path: String(form.avatar_path || '').trim() === '' ? null : String(form.avatar_path || '').trim(),
      };
      if (canEditRole.value) {
        patchBody.role = role;
      }
      if (canEditStatus.value) {
        patchBody.status = String(form.status || 'active');
      }

      await apiRequest(`/api/admin/users/${encodeURIComponent(String(form.id))}`, {
        method: 'PATCH',
        body: patchBody,
      });
      notice.value = `Updated ${displayName}.`;
    }

    dialogOpen.value = false;
    await loadUsers();
  } catch (err) {
    formError.value = err instanceof Error ? err.message : 'Could not save user.';
  } finally {
    formSaving.value = false;
  }
}

async function saveAvatarChanges() {
  if (formSaving.value || form.mode !== 'edit' || form.id <= 0) return;

  const userId = Number(form.id || 0);
  if (userId <= 0) return;

  if (avatarUploadDataUrl.value === '' && avatarDefaultSelection.value === '') {
    formError.value = 'Select an avatar file or pick a default avatar.';
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
      notice.value = 'Avatar uploaded.';
    } else {
      const defaultPath = String(avatarDefaultSelection.value || '').trim();
      await apiRequest(`/api/admin/users/${encodeURIComponent(String(userId))}`, {
        method: 'PATCH',
        body: {
          avatar_path: defaultPath === '' ? null : defaultPath,
        },
      });
      form.avatar_path = defaultPath;
      notice.value = 'Default avatar applied.';
    }

    closeAvatarEditor();
    await loadUsers();
  } catch (err) {
    formError.value = err instanceof Error ? err.message : 'Could not save avatar changes.';
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
  const confirmed = window.confirm(`Delete ${label}? This also deletes all video calls owned by this user.`);
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
    notice.value = `Deleted ${label}. Removed ${deletedCalls} owned video calls.`;

    if (dialogOpen.value && form.mode === 'edit' && Number(form.id || 0) === userId) {
      closeDialog();
    }

    if (rows.value.length === 1 && page.value > 1) {
      page.value -= 1;
    }
    await loadUsers();
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Could not delete user.';
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
      error.value = 'You cannot deactivate your own account.';
      return;
    }
    await apiRequest(`/api/admin/users/${encodeURIComponent(String(userId))}/${status === 'disabled' ? 'reactivate' : 'deactivate'}`, {
      method: 'POST',
    });
    notice.value = `${status === 'disabled' ? 'Reactivated' : 'Deactivated'} ${String(user.display_name || user.email || userId)}.`;
    await loadUsers();
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Could not update user status.';
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
      notice.value = 'Email change confirmed.';
    }
  } catch (err) {
    if (requestToken !== routeEditRequestToken) return;
    error.value = err instanceof Error ? err.message : 'Could not open user editor.';
  }
}

watch(
  () => [route.query.edit_user_id, route.query.email_verified],
  () => {
    void openEditUserFromRouteQuery();
  }
);

onMounted(() => {
  void (async () => {
    await loadUsers();
    await openEditUserFromRouteQuery();
  })();
});
</script>

<style scoped>
.admin-users-view {
  min-height: 100%;
  display: flex;
  flex-direction: column;
  gap: 0;
  background: transparent;
}

.admin-users-view > :first-child {
  border-top-left-radius: 0;
  border-top-right-radius: 5px;
}

.admin-users-head,
.admin-users-toolbar {
  display: flex;
  gap: 10px;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
}

.admin-users-toolbar {
  margin-bottom: 15px;
}

.admin-users-head,
.admin-users-toolbar,
.users-footer {
  background: var(--bg-ui-chrome);
}

.admin-users-head h1 {
  margin: 0;
  font-size: 18px;
}

.admin-users-head p {
  margin: 4px 0 0;
  color: var(--text-muted);
}

.search-field {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 8px;
  flex: 1 1 320px;
}

.users-toolbar-search-btn {
  width: 40px;
  height: 40px;
}

.users-toolbar-search-btn img {
  width: 18px;
  height: 18px;
}

.users-banner {
  padding: 12px;
  border-radius: 6px;
}

.users-banner.ok {
  border: 1px solid var(--border-subtle);
  background: #152a49;
}

.users-banner.error {
  border: 1px solid #8f4a58;
  background: #311922;
  color: #ffd7db;
}

.users-empty {
  padding: 12px;
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  background: #152a49;
  color: var(--text-main);
}

.users-table {
  table-layout: fixed;
  margin-top: 10px;
}

.users-table th:nth-child(1),
.users-table td:nth-child(1) {
  width: 19%;
}

.users-table th:nth-child(2),
.users-table td:nth-child(2) {
  width: 27%;
}

.users-table th:nth-child(3),
.users-table td:nth-child(3) {
  width: 11%;
}

.users-table th:nth-child(4),
.users-table td:nth-child(4) {
  width: 11%;
}

.users-table th:nth-child(5),
.users-table td:nth-child(5) {
  width: 14%;
}

.users-table th:nth-child(6),
.users-table td:nth-child(6) {
  width: 18%;
}

.users-name {
  font-weight: 700;
  color: var(--text-main);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.users-subline {
  margin-top: 3px;
  font-size: 11px;
  color: var(--text-muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.users-empty-cell {
  padding: 18px 12px;
  text-align: center;
  color: var(--text-muted);
}

.users-footer {
  display: flex;
  justify-content: center;
  margin-top: auto;
  padding-left: 10px;
  padding-right: 10px;
}

.users-table-wrap {
  flex: 1 1 auto;
  min-height: 0;
  margin-top: 0;
  padding-left: 10px;
  padding-right: 10px;
}

.users-modal {
  position: fixed;
  inset: 0;
  z-index: 30;
  display: grid;
  place-items: center;
}

.users-modal-backdrop {
  position: absolute;
  inset: 0;
  background: rgba(5, 12, 23, 0.72);
}

.users-modal-dialog {
  --users-modal-padding: 16px;
  position: relative;
  z-index: 1;
  width: min(980px, calc(100vw - 24px));
  max-height: min(94vh, 980px);
  overflow: auto;
  border-radius: 10px;
  border: 1px solid var(--border-subtle);
  background: #10203b;
  padding: var(--users-modal-padding);
  display: grid;
  gap: 14px;
}

.users-modal-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.users-modal-head h4 {
  margin: 0;
}

.users-modal-head-brand {
  margin: calc(var(--users-modal-padding) * -1) calc(var(--users-modal-padding) * -1) 0;
  padding: 10px;
  background: var(--brand-bg);
}

.users-modal-head-left {
  min-width: 0;
  display: inline-flex;
  align-items: center;
  gap: 10px;
}

.users-modal-head-logo {
  width: auto;
  height: 24px;
  display: block;
}

.users-modal-body {
  display: grid;
  gap: 12px;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.users-field {
  display: grid;
  gap: 6px;
}

.users-field span {
  font-size: 11px;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.users-field-wide {
  grid-column: 1 / -1;
}

.users-email-list {
  display: grid;
  gap: 6px;
  max-height: 220px;
  overflow: auto;
  padding: 8px;
  border: 1px solid var(--border-subtle);
  border-radius: 8px;
  background: #0f1d34;
}

.users-email-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 8px 10px;
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  background: rgba(5, 12, 23, 0.35);
}

.users-email-main {
  min-width: 0;
  display: grid;
  gap: 6px;
}

.users-email-value {
  font-size: 13px;
  color: var(--text-main);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.users-email-meta {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.users-email-empty {
  margin: 0;
  font-size: 12px;
  color: var(--text-muted);
}

.users-email-create {
  margin-top: 8px;
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 8px;
}

.users-avatar-edit-row {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 12px;
  align-items: center;
}

.users-avatar-modal-body {
  display: grid;
  gap: 12px;
}

.users-avatar-preview-wrap {
  width: 72px;
  height: 72px;
  border-radius: 50%;
  border: 1px solid var(--border-subtle);
  overflow: hidden;
  background: #0b1324;
}

.users-avatar-preview {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.users-avatar-preview-large {
  width: 100%;
  height: 100%;
}

.users-avatar-edit-actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.users-avatar-file {
  display: grid;
  gap: 6px;
}

.users-avatar-file span,
.users-avatar-defaults > span {
  font-size: 11px;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.users-avatar-defaults {
  display: grid;
  gap: 8px;
}

.users-avatar-defaults-actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.users-avatar-hint {
  margin: 0;
  font-size: 12px;
  color: var(--text-muted);
}

.users-form-error {
  margin: 0;
  color: #ffd7db;
}

.users-modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}

@media (max-width: 1180px) {
  .users-modal-body {
    grid-template-columns: 1fr;
  }

  .users-table th:nth-child(5),
  .users-table td:nth-child(5) {
    width: 18%;
  }
}

@media (max-width: 760px) {
  .users-email-create {
    grid-template-columns: 1fr;
  }

  .users-table {
    width: 100%;
    table-layout: auto;
    border-collapse: separate;
    border-spacing: 0 8px;
  }

  .users-table thead {
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

  .users-table th:nth-child(1),
  .users-table td:nth-child(1),
  .users-table th:nth-child(2),
  .users-table td:nth-child(2),
  .users-table th:nth-child(3),
  .users-table td:nth-child(3),
  .users-table th:nth-child(4),
  .users-table td:nth-child(4),
  .users-table th:nth-child(5),
  .users-table td:nth-child(5),
  .users-table th:nth-child(6),
  .users-table td:nth-child(6) {
    width: auto;
  }

  .users-table tbody,
  .users-table tr,
  .users-table td {
    display: block;
    width: 100%;
  }

  .users-table tbody tr {
    border: 1px solid var(--border-subtle);
    border-radius: 8px;
    overflow: hidden;
    background: var(--bg-row);
  }

  .users-table td {
    display: grid;
    grid-template-columns: minmax(90px, 34%) minmax(0, 1fr);
    gap: 8px;
    align-items: start;
    padding: 8px 10px;
    border-bottom: 1px solid var(--border-subtle);
  }

  .users-table td::before {
    content: attr(data-label);
    color: var(--text-muted);
    font-size: 11px;
    font-weight: 600;
  }

  .users-table td:last-child {
    border-bottom: 0;
  }

  .users-table td:last-child .actions-inline {
    justify-content: flex-start;
    flex-wrap: wrap;
  }

  .users-table .users-subline.code {
    word-break: break-all;
    overflow-wrap: anywhere;
  }

  .users-table .users-empty-cell {
    display: block;
    padding: 12px 10px;
    border-bottom: 0;
  }

  .users-table .users-empty-cell::before {
    content: none;
  }
}
</style>
