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

    <label v-if="hasCallContext && availableApps.length > 0" class="call-apps-picker">
      <span>Call App</span>
      <AppSelect
        :model-value="selectedAppKey"
        aria-label="Select Call App"
        @update:model-value="selectAppByKey"
      >
        <option value="" disabled>Select Call App</option>
        <option
          v-for="app in availableApps"
          :key="`option:${app.app_key}`"
          :value="app.app_key"
        >
          {{ app.name }}
        </option>
      </AppSelect>
    </label>

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
        <span class="call-apps-item-side">
          <span class="call-apps-item-state">{{ app.app_key }}</span>
          <span class="call-apps-item-action">{{ selectedAppKey === app.app_key ? 'Selected' : 'Select' }}</span>
        </span>
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
      <fieldset class="call-apps-policy">
        <legend>Default participant access</legend>
        <div class="call-apps-policy-options">
          <label class="call-apps-policy-choice" :class="{ active: defaultPolicy === 'blocked_by_default' }">
            <input v-model="defaultPolicy" type="radio" value="blocked_by_default" />
            <span>Blocked</span>
            <small>Grant individually</small>
          </label>
          <label class="call-apps-policy-choice" :class="{ active: defaultPolicy === 'allowed_by_default' }">
            <input v-model="defaultPolicy" type="radio" value="allowed_by_default" />
            <span>Allowed</span>
            <small>Participants can open</small>
          </label>
        </div>
      </fieldset>
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

    <section v-if="activeSessionForAccess" class="call-apps-access" aria-label="Call App participant access">
      <div class="call-apps-access-head">
        <h2>Access</h2>
        <span>{{ activeSessionName }}</span>
        <span class="call-apps-access-default">{{ activeSessionDefaultAccessLabel }}</span>
      </div>
      <div v-if="callAppAccessParticipants.length > 0" class="call-apps-access-list">
        <div
          v-for="participant in callAppAccessParticipants"
          :key="participant.userId"
          class="call-apps-access-row"
        >
          <span class="call-apps-access-main">
            <span class="call-apps-access-name">{{ participant.displayName }}</span>
            <span class="call-apps-access-state" :class="grantStateClass(participant)">{{ grantStateLabel(participant) }}</span>
          </span>
          <CallAppParticipantGrantButton
            :session="activeSessionForAccess"
            :row="participant"
            :can-manage="canManage"
            :api-request="apiRequest"
            :send-socket-frame="sendSocketFrame"
            :request-room-snapshot="requestRoomSnapshot"
            variant="label"
            @grant-updated="applyLocalGrantUpdate"
          />
        </div>
      </div>
      <div v-else class="call-apps-empty">
        No call participants are available for this Call App session.
      </div>
    </section>
  </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import AppSelect from '../../../components/AppSelect.vue';
import CallAppParticipantGrantButton from './CallAppParticipantGrantButton.vue';
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
  activeSession: {
    type: Object,
    default: null,
  },
  participants: {
    type: Array,
    default: () => [],
  },
  sendSocketFrame: {
    type: Function,
    default: () => false,
  },
  requestRoomSnapshot: {
    type: Function,
    default: () => {},
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
const localGrantOverrides = ref({});

const normalizedCallId = computed(() => String(props.callId || '').trim());
const hasCallContext = computed(() => normalizedCallId.value !== '');
const pageCount = computed(() => Math.max(1, Number(pagination.value.page_count || 1)));
const selectedApp = computed(() => availableApps.value.find((app) => app.app_key === selectedAppKey.value) || null);
const activeSessionForAccess = computed(() => {
  const session = props.activeSession && typeof props.activeSession === 'object' ? props.activeSession : null;
  if (!session) return null;
  const sessionId = String(session.id || '').trim();
  const status = String(session.status || '').trim().toLowerCase();
  return sessionId !== '' && status === 'active' ? session : null;
});
const activeSessionName = computed(() => {
  const session = activeSessionForAccess.value;
  const app = session?.app && typeof session.app === 'object' ? session.app : {};
  return String(app.name || session?.app_key || 'Call App').trim() || 'Call App';
});
const callAppAccessParticipants = computed(() => {
  const seen = new Set();
  const rows = [];
  for (const rawRow of Array.isArray(props.participants) ? props.participants : []) {
    const row = rawRow && typeof rawRow === 'object' ? rawRow : {};
    const userId = Number(row.userId || row.user_id || 0);
    if (!Number.isInteger(userId) || userId <= 0 || seen.has(userId)) continue;
    seen.add(userId);
    rows.push({
      ...row,
      userId,
      displayName: String(row.displayName || row.display_name || `User ${userId}`).trim() || `User ${userId}`,
      isRoomMember: row.isRoomMember !== false && row.is_room_member !== false,
    });
  }
  return rows;
});

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

function normalizeGrantState(value) {
  const state = String(value || '').trim().toLowerCase();
  return state === 'allowed' || state === 'denied' ? state : '';
}

function defaultGrantState() {
  return String(activeSessionForAccess.value?.default_app_policy || '') === 'allowed_by_default' ? 'allowed' : 'denied';
}

const activeSessionDefaultAccessLabel = computed(() => (
  defaultGrantState() === 'allowed' ? 'Default: allowed' : 'Default: blocked'
));

function grantStateForParticipant(participant) {
  const userId = Number(participant?.userId || participant?.user_id || 0);
  const sessionId = String(activeSessionForAccess.value?.id || '').trim();
  const override = normalizeGrantState(localGrantOverrides.value[`${sessionId}:${userId}`]);
  if (override !== '') return override;

  const grants = Array.isArray(activeSessionForAccess.value?.grants) ? activeSessionForAccess.value.grants : [];
  const grant = grants.find((row) => (
    String(row?.subject_type || '') === 'user'
    && Number(row?.user_id || 0) === userId
  ));
  return normalizeGrantState(grant?.grant_state) || defaultGrantState();
}

function grantStateLabel(participant) {
  return grantStateForParticipant(participant) === 'allowed' ? 'Allowed' : 'Blocked';
}

function grantStateClass(participant) {
  return grantStateForParticipant(participant) === 'allowed' ? 'state-allowed' : 'state-denied';
}

function applyLocalGrantUpdate(event) {
  const sessionId = String(event?.sessionId || activeSessionForAccess.value?.id || '').trim();
  const userId = Number(event?.userId || 0);
  const grantState = normalizeGrantState(event?.grantState);
  if (sessionId === '' || !Number.isInteger(userId) || userId <= 0 || grantState === '') return;
  localGrantOverrides.value = {
    ...localGrantOverrides.value,
    [`${sessionId}:${userId}`]: grantState,
  };
}

function selectApp(app) {
  selectedAppKey.value = String(app?.app_key || '').trim();
  defaultPolicy.value = normalizeDefaultPolicy(app?.installation?.default_app_policy);
  actionError.value = '';
  notice.value = '';
}

function selectAppByKey(appKey) {
  const normalizedAppKey = String(appKey || '').trim();
  const app = availableApps.value.find((row) => row.app_key === normalizedAppKey) || null;
  if (!app) {
    selectedAppKey.value = '';
    actionError.value = '';
    notice.value = '';
    return;
  }
  selectApp(app);
}

function reconcileSelectedAppAfterLoad() {
  if (selectedAppKey.value !== '' && selectedApp.value) return;
  if (availableApps.value.length === 1) {
    selectApp(availableApps.value[0]);
    return;
  }
  selectedAppKey.value = '';
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

  reconcileSelectedAppAfterLoad();
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
    props.requestRoomSnapshot();
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

watch(
  () => String(activeSessionForAccess.value?.id || '').trim(),
  () => {
    localGrantOverrides.value = {};
  },
);
</script>

<style scoped src="./CallAppsSidebarPanel.css"></style>
