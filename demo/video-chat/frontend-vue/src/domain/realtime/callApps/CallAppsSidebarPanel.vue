<template>
  <section class="call-apps-sidebar" aria-label="Call Apps">
    <form class="call-apps-search" role="search" @submit.prevent="submitSearch">
      <input
        v-model.trim="searchDraft"
        class="input call-apps-search-input"
        type="search"
        placeholder="Search Call Apps"
        aria-label="Search Call Apps"
      />
      <button
        class="icon-mini-btn call-apps-search-submit"
        type="submit"
        :disabled="loading"
        aria-label="Search Call Apps"
        title="Search Call Apps"
      >
        <img src="/assets/orgas/kingrt/icons/send.png" alt="" />
      </button>
    </form>

    <div v-if="!hasCallContext" class="call-apps-empty">
      Call context is still loading.
    </div>
    <div v-else-if="error" class="call-apps-error">
      {{ error }}
    </div>

    <div v-if="hasCallContext" class="call-apps-list" :class="{ loading }">
      <button
        v-for="app in availableApps"
        :key="app.app_key"
        class="call-apps-list-item"
        :class="{ active: selectedAppKey === app.app_key }"
        type="button"
        @click="selectApp(app)"
      >
        <span class="call-apps-item-main">
          <span class="call-apps-item-name">{{ app.name }}</span>
          <span class="call-apps-item-meta">{{ app.category }} - {{ app.version || 'unversioned' }}</span>
          <span class="call-apps-item-badges" aria-label="Call App availability">
            <span class="call-apps-status-badge state-installed">{{ installationStateLabel(app) }}</span>
            <span class="call-apps-status-badge" :class="installationStatusClass(app)">{{ installationStatusLabel(app) }}</span>
            <span class="call-apps-status-badge" :class="healthStatusClass(app)">{{ healthStatusLabel(app) }}</span>
          </span>
        </span>
        <span class="call-apps-item-state">{{ app.app_key }}</span>
      </button>

      <div v-if="!loading && availableApps.length === 0" class="call-apps-empty">
        No installed Call Apps available.
      </div>
    </div>

    <div v-if="hasCallContext" class="call-apps-pagination">
      <button
        class="pager-btn pager-icon-btn"
        type="button"
        :disabled="!pagination.has_prev || loading"
        aria-label="Previous Call Apps page"
        @click="loadPage(pagination.page - 1)"
      >
        <img class="pager-icon-img" src="/assets/orgas/kingrt/icons/backward.png" alt="" />
      </button>
      <div class="page-info">Page {{ pagination.page }} / {{ pageCount }}</div>
      <button
        class="pager-btn pager-icon-btn"
        type="button"
        :disabled="!pagination.has_next || loading"
        aria-label="Next Call Apps page"
        @click="loadPage(pagination.page + 1)"
      >
        <img class="pager-icon-img" src="/assets/orgas/kingrt/icons/forward.png" alt="" />
      </button>
    </div>

    <section v-if="selectedApp" class="call-apps-detail" aria-label="Selected Call App" data-call-app-attach-flow="inline">
      <div class="call-apps-detail-head">
        <h2>{{ selectedApp.name }}</h2>
        <span>{{ selectedApp.category }} - {{ selectedApp.app_key }}</span>
      </div>
      <dl class="call-apps-detail-grid">
        <div>
          <dt>Version</dt>
          <dd>{{ selectedApp.version || 'unversioned' }}</dd>
        </div>
        <div>
          <dt>Runtime</dt>
          <dd>{{ selectedApp.runtime || 'iframe' }}</dd>
        </div>
        <div>
          <dt>Installation</dt>
          <dd>{{ installationStateLabel(selectedApp) }} / {{ installationStatusLabel(selectedApp) }}</dd>
        </div>
        <div>
          <dt>Health</dt>
          <dd>{{ healthStatusLabel(selectedApp) }}</dd>
        </div>
      </dl>
      <label class="call-apps-policy">
        <span>Default participant access</span>
        <AppSelect v-model="defaultPolicy" aria-label="Default participant access">
          <option value="blocked_by_default">Blocked by default</option>
          <option value="allowed_by_default">Allowed by default</option>
        </AppSelect>
      </label>
      <button
        class="btn btn-cyan full"
        type="button"
        :disabled="!canManage || submitting"
        @click="attachSelectedApp"
      >
        {{ submitting ? 'Adding...' : 'Add to call' }}
      </button>
      <p v-if="!canManage" class="call-apps-hint">
        Only the call owner or a moderator can attach Call Apps.
      </p>
      <p v-if="actionError" class="call-apps-error">{{ actionError }}</p>
      <p v-if="notice" class="call-apps-notice">{{ notice }}</p>
    </section>
  </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import AppSelect from '../../../components/AppSelect.vue';
import { useCallAppsCatalog } from './useCallAppsCatalog.js';

const props = defineProps({
  callId: {
    type: String,
    default: '',
  },
  canManage: {
    type: Boolean,
    default: false,
  },
  apiRequest: {
    type: Function,
    required: true,
  },
});

const emit = defineEmits(['session-created']);

const {
  availableApps,
  pagination,
  loading,
  error,
  loadAvailableApps,
  resetCallAppsCatalog,
} = useCallAppsCatalog();

const searchDraft = ref('');
const selectedAppKey = ref('');
const defaultPolicy = ref('blocked_by_default');
const submitting = ref(false);
const actionError = ref('');
const notice = ref('');

const normalizedCallId = computed(() => String(props.callId || '').trim());
const hasCallContext = computed(() => normalizedCallId.value !== '');
const pageCount = computed(() => Math.max(1, Number(pagination.value.page_count || 1)));
const selectedApp = computed(() => availableApps.value.find((app) => app.app_key === selectedAppKey.value) || null);

function normalizeDefaultPolicy(value) {
  return value === 'allowed_by_default' ? 'allowed_by_default' : 'blocked_by_default';
}

function availabilityFlag(app, key) {
  return app?.availability && app.availability[key] === true;
}

function installationStateLabel(app) {
  return availabilityFlag(app, 'installed') ? 'Installed' : 'Not installed';
}

function installationStatusLabel(app) {
  const status = String(app?.installation?.status || '').trim().toLowerCase();
  return status === 'enabled' ? 'Enabled' : (status || 'Disabled');
}

function healthStatusLabel(app) {
  const status = String(app?.health_status || '').trim().toLowerCase();
  return status === 'healthy' || availabilityFlag(app, 'healthy') ? 'Healthy' : (status || 'Unknown');
}

function installationStatusClass(app) {
  return installationStatusLabel(app).toLowerCase() === 'enabled' ? 'state-enabled' : 'state-disabled';
}

function healthStatusClass(app) {
  return healthStatusLabel(app).toLowerCase() === 'healthy' ? 'state-healthy' : 'state-unhealthy';
}

function selectApp(app) {
  selectedAppKey.value = String(app?.app_key || '').trim();
  defaultPolicy.value = normalizeDefaultPolicy(app?.installation?.default_app_policy);
  actionError.value = '';
  notice.value = '';
}

async function loadPage(page = 1) {
  if (!hasCallContext.value) {
    resetCallAppsCatalog();
    selectedAppKey.value = '';
    return;
  }

  await loadAvailableApps({
    callId: normalizedCallId.value,
    query: searchDraft.value,
    page,
    pageSize: 8,
  });

  if (selectedAppKey.value !== '' && !selectedApp.value) {
    selectedAppKey.value = '';
  }
}

async function submitSearch() {
  actionError.value = '';
  notice.value = '';
  await loadPage(1);
}

async function attachSelectedApp() {
  const appKey = String(selectedApp.value?.app_key || '').trim();
  if (!hasCallContext.value || appKey === '' || submitting.value) return;

  actionError.value = '';
  notice.value = '';
  submitting.value = true;
  try {
    const payload = await props.apiRequest(`/api/calls/${encodeURIComponent(normalizedCallId.value)}/call-app-sessions`, {
      method: 'POST',
      body: {
        app_key: appKey,
        default_app_policy: normalizeDefaultPolicy(defaultPolicy.value),
      },
    });
    notice.value = 'Call App added.';
    emit('session-created', payload?.result || {});
  } catch (attachError) {
    actionError.value = attachError instanceof Error ? attachError.message : 'Could not add Call App.';
  } finally {
    submitting.value = false;
  }
}

watch(
  normalizedCallId,
  () => {
    selectedAppKey.value = '';
    actionError.value = '';
    notice.value = '';
    void loadPage(1);
  },
  { immediate: true },
);
</script>

<style scoped>
.call-apps-sidebar {
  min-height: 0;
  overflow-y: auto;
  overflow-x: hidden;
  padding: 0 0 56px;
  display: grid;
  gap: 1px;
  align-content: start;
  direction: rtl;
}

.call-apps-sidebar > * {
  direction: var(--app-content-direction);
}

.call-apps-search {
  display: flex;
  flex-direction: row-reverse;
  align-items: center;
  justify-content: flex-start;
  gap: 20px;
  padding: 20px;
  background: var(--bg-surface-strong);
}

.call-apps-search-input {
  height: 34px;
  min-width: 0;
  flex: 1 1 auto;
  background: var(--border-subtle);
}

.call-apps-search-submit {
  width: 34px;
  height: 34px;
  flex: 0 0 34px;
}

.call-apps-search-submit img {
  width: 16px;
  height: 16px;
  filter: var(--action-icon-filter);
}

.call-apps-list {
  display: grid;
  gap: 1px;
  min-height: 0;
}

.call-apps-list.loading {
  opacity: 0.7;
}

.call-apps-list-item {
  width: 100%;
  border: 0;
  background: var(--bg-surface-strong);
  color: var(--text-primary);
  min-height: 82px;
  padding: 12px 20px;
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: 10px;
  text-align: left;
  cursor: pointer;
}

.call-apps-list-item:hover,
.call-apps-list-item.active {
  background: var(--color-border);
}

.call-apps-item-main {
  min-width: 0;
  display: grid;
  gap: 6px;
}

.call-apps-item-name {
  font-size: 13px;
  font-weight: 800;
  color: var(--text-primary);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.call-apps-item-meta,
.call-apps-item-state,
.call-apps-hint {
  font-size: 11px;
  color: var(--text-muted);
}

.call-apps-item-state {
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.call-apps-item-badges {
  min-width: 0;
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.call-apps-status-badge {
  min-height: 20px;
  padding: 3px 7px;
  border: 1px solid var(--color-border);
  background: var(--color-primary-navy);
  color: var(--color-heading);
  font-size: 10px;
  font-weight: 900;
  line-height: 12px;
  text-transform: uppercase;
}

.call-apps-status-badge.state-installed,
.call-apps-status-badge.state-enabled,
.call-apps-status-badge.state-healthy {
  color: var(--color-success);
}

.call-apps-status-badge.state-disabled,
.call-apps-status-badge.state-unhealthy {
  color: var(--color-error);
}

.call-apps-pagination {
  display: flex;
  flex-direction: row;
  justify-content: flex-end;
  align-items: center;
  gap: 20px;
  background: var(--bg-surface-strong);
  padding: 20px;
}

.call-apps-detail {
  background: var(--bg-surface-strong);
  padding: 20px;
  display: grid;
  gap: 20px;
}

.call-apps-detail-head {
  display: grid;
  gap: 4px;
}

.call-apps-detail-head h2 {
  margin: 0;
  font-size: 14px;
  line-height: 18px;
  color: var(--text-primary);
}

.call-apps-detail-head span {
  font-size: 12px;
  color: var(--text-muted);
}

.call-apps-detail-grid {
  margin: 0;
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  gap: 8px;
}

.call-apps-detail-grid div,
.call-apps-policy {
  display: grid;
  gap: 4px;
}

.call-apps-detail-grid dt,
.call-apps-policy span {
  font-size: 11px;
  color: var(--text-muted);
}

.call-apps-detail-grid dd {
  margin: 0;
  font-size: 12px;
  color: var(--text-primary);
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
}

.call-apps-empty,
.call-apps-error,
.call-apps-notice {
  padding: 12px 10px;
  font-size: 12px;
  background: var(--bg-surface-strong);
}

.call-apps-empty {
  color: var(--text-muted);
}

.call-apps-error {
  color: var(--color-error);
}

.call-apps-notice {
  color: var(--color-success);
}
</style>
