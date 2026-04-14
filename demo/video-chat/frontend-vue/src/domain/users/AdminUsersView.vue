<template>
  <section class="view-card admin-users-view">
    <header class="section admin-users-head">
      <div>
        <h3>User Management</h3>
      </div>
      <div class="actions">
        <button class="btn" type="button" @click="openCreateUser">New user</button>
      </div>
    </header>

    <section class="toolbar admin-users-toolbar">
      <label class="search-field" aria-label="Search users">
        <input
          v-model.trim="queryDraft"
          class="input"
          type="search"
          placeholder="Search by name, email, or role"
        />
        <button class="btn" type="button" @click="applySearchNow">Search</button>
      </label>

      <select v-model.number="pageSize" class="select" @change="resetAndReload">
        <option :value="10">10 / page</option>
        <option :value="20">20 / page</option>
        <option :value="50">50 / page</option>
      </select>

      <select v-model="order" class="select" @change="resetAndReload">
        <option value="role_then_name_asc">Role + name A-Z</option>
        <option value="role_then_name_desc">Role + name Z-A</option>
      </select>
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
            <td>
              <div class="users-name">{{ user.display_name }}</div>
              <div class="users-subline code">#{{ user.id }}</div>
            </td>
            <td>
              <div>{{ user.email }}</div>
              <div class="users-subline">{{ user.time_format }} · {{ user.theme }}</div>
            </td>
            <td><span class="tag" :class="roleTagClass(user.role)">{{ user.role }}</span></td>
            <td><span class="tag" :class="statusTagClass(user.status)">{{ user.status }}</span></td>
            <td>{{ formatDateTime(user.updated_at) }}</td>
            <td>
              <div class="actions-inline">
                <button class="icon-mini-btn" type="button" title="Edit user" @click="openEditUser(user)">
                  <img src="/assets/orgas/kingrt/icons/gear.png" alt="" />
                </button>
                <button
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
        <header class="users-modal-head">
          <div>
            <h4>{{ dialogTitle }}</h4>
          </div>
          <button class="icon-mini-btn" type="button" @click="closeDialog">
            <img src="/assets/orgas/kingrt/icons/remove_user.png" alt="" />
          </button>
        </header>

        <div v-if="!avatarEditorOpen" class="users-modal-body">
          <label class="users-field">
            <span>Email</span>
            <input v-model.trim="form.email" class="input" type="email" autocomplete="email" />
          </label>

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
            <select v-model="form.role" class="select">
              <option value="user">user</option>
              <option value="admin">admin</option>
            </select>
          </label>

          <label v-if="form.mode === 'edit'" class="users-field">
            <span>Status</span>
            <select v-model="form.status" class="select">
              <option value="active">active</option>
              <option value="disabled">disabled</option>
            </select>
          </label>

          <label v-if="form.mode === 'edit'" class="users-field">
            <span>Time format</span>
            <select v-model="form.time_format" class="select">
              <option value="24h">24h</option>
              <option value="12h">12h</option>
            </select>
          </label>

          <label v-if="form.mode === 'edit'" class="users-field">
            <span>Theme</span>
            <select v-model="form.theme" class="select">
              <option value="dark">dark</option>
              <option value="light">light</option>
            </select>
          </label>

          <section v-if="form.mode === 'edit'" class="users-field users-field-wide users-avatar-edit-row">
            <div class="users-avatar-preview-wrap">
              <img class="users-avatar-preview" :src="avatarPreviewSrc" alt="User avatar preview" />
            </div>
            <div class="users-avatar-edit-actions">
              <button class="btn" type="button" :disabled="formSaving" @click="openAvatarEditor">Change avatar</button>
              <button class="btn" type="button" :disabled="formSaving" @click="deleteAvatar">Delete avatar</button>
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
            class="btn"
            type="button"
            :disabled="formSaving"
            @click="avatarEditorOpen ? closeAvatarEditor() : closeDialog()"
          >
            {{ avatarEditorOpen ? 'Back' : 'Cancel' }}
          </button>
          <button
            class="btn"
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
import { resolveBackendOrigin } from '../../support/backendOrigin';
import { sessionState } from '../auth/session';

const backendOrigin = resolveBackendOrigin();
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

let loadToken = 0;
let searchTimer = 0;

function normalizeAvatarSrc(rawPath) {
  const value = String(rawPath || '').trim();
  if (value === '') return avatarPlaceholder;
  if (value.startsWith('data:')) return value;
  if (value.startsWith('http://') || value.startsWith('https://')) return value;
  if (value.startsWith('/api/')) return `${backendOrigin}${value}`;
  return value;
}

function buildQueryString(params) {
  const query = new URLSearchParams();
  for (const [key, value] of Object.entries(params || {})) {
    if (value === undefined || value === null) continue;
    const text = String(value).trim();
    if (text === '') continue;
    query.set(key, text);
  }
  const encoded = query.toString();
  return encoded === '' ? '' : `?${encoded}`;
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

async function apiRequest(path, { method = 'GET', query = null, body = null } = {}) {
  const endpoint = `${backendOrigin}${path}${buildQueryString(query || {})}`;
  let response = null;
  try {
    response = await fetch(endpoint, {
      method,
      headers: requestHeaders(body !== null),
      body: body === null ? undefined : JSON.stringify(body),
    });
  } catch (error) {
    const message = error instanceof Error ? error.message.trim() : '';
    if (message === '' || /failed to fetch/i.test(message)) {
      throw new Error(`Could not reach backend (${backendOrigin}).`);
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
  formError.value = '';
}

function openCreateUser() {
  resetForm('create');
  dialogOpen.value = true;
}

function openEditUser(user) {
  resetForm('edit');
  form.id = Number(user.id || 0);
  form.email = String(user.email || '');
  form.display_name = String(user.display_name || '');
  form.role = String(user.role || 'user');
  form.status = String(user.status || 'active');
  form.time_format = String(user.time_format || '24h');
  form.theme = String(user.theme || 'dark');
  form.avatar_path = String(user.avatar_path || '');
  dialogOpen.value = true;
}

function closeDialog() {
  dialogOpen.value = false;
  formSaving.value = false;
  avatarEditorOpen.value = false;
  avatarUploadDataUrl.value = '';
  avatarDefaultSelection.value = '';
  formError.value = '';
}

const dialogTitle = computed(() => (form.mode === 'create' ? 'Create user' : 'Edit user'));
const dialogSubmitLabel = computed(() => (form.mode === 'create' ? 'Create user' : 'Save changes'));
const pageCount = computed(() => Math.max(1, pagination.pageCount));
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

async function submitForm() {
  if (formSaving.value) return;

  const email = String(form.email || '').trim();
  const displayName = String(form.display_name || '').trim();
  const role = String(form.role || 'user').trim();

  if (email === '' || displayName === '') {
    formError.value = 'Email and display name are required.';
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
      await apiRequest(`/api/admin/users/${encodeURIComponent(String(form.id))}`, {
        method: 'PATCH',
        body: {
          email,
          display_name: displayName,
          role,
          status: String(form.status || 'active'),
          time_format: String(form.time_format || '24h'),
          theme: String(form.theme || 'dark'),
          avatar_path: String(form.avatar_path || '').trim() === '' ? null : String(form.avatar_path || '').trim(),
        },
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

async function deleteAvatar() {
  if (formSaving.value || form.mode !== 'edit' || form.id <= 0) return;

  const userId = Number(form.id || 0);
  if (userId <= 0) return;

  formSaving.value = true;
  formError.value = '';
  error.value = '';
  notice.value = '';

  try {
    await apiRequest(`/api/admin/users/${encodeURIComponent(String(userId))}/avatar`, {
      method: 'DELETE',
    });
    form.avatar_path = '';
    avatarUploadDataUrl.value = '';
    avatarDefaultSelection.value = '';
    notice.value = 'Avatar removed.';
    await loadUsers();
  } catch (err) {
    formError.value = err instanceof Error ? err.message : 'Could not delete avatar.';
  } finally {
    formSaving.value = false;
  }
}

async function deleteUser(user) {
  const userId = Number(user?.id || 0);
  if (userId <= 0) return;
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
  mutatingUserId.value = userId;
  error.value = '';
  notice.value = '';

  try {
    const status = String(user.status || '').toLowerCase();
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

onMounted(() => {
  void loadUsers();
});
</script>

<style scoped>
.admin-users-view {
  min-height: 100%;
  display: flex;
  flex-direction: column;
  gap: 1px;
  background: var(--border-subtle);
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

.admin-users-head,
.admin-users-toolbar,
.users-footer {
  background: var(--bg-pane);
}

.admin-users-head h3 {
  margin: 0;
}

.admin-users-head p {
  margin: 4px 0 0;
  color: var(--text-muted);
}

.search-field {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 8px;
  flex: 1 1 320px;
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
}

.users-table-wrap {
  flex: 1 1 auto;
  min-height: 0;
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
  position: relative;
  z-index: 1;
  width: min(860px, calc(100vw - 24px));
  max-height: min(90vh, 900px);
  overflow: auto;
  border-radius: 10px;
  border: 1px solid var(--border-subtle);
  background: #10203b;
  padding: 16px;
  display: grid;
  gap: 14px;
}

.users-modal-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.users-modal-head h4,
.users-modal-head p {
  margin: 0;
}

.users-modal-head p {
  margin-top: 4px;
  color: var(--text-muted);
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
</style>
