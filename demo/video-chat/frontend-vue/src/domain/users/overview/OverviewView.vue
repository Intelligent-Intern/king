<template>
  <section class="view-card admin-overview-view">
    <section class="overview-toolbar">
      <div class="overview-view-tabs" role="tablist" aria-label="Overview views">
        <button
          class="tab"
          :class="{ active: activeOverviewView === 'dashboard' }"
          type="button"
          role="tab"
          data-view="dashboard"
          :aria-selected="activeOverviewView === 'dashboard'"
          @click="setActiveOverviewView('dashboard')"
        >
          Dashboard
        </button>
        <button
          class="tab"
          :class="{ active: activeOverviewView === 'calendar' }"
          type="button"
          role="tab"
          data-view="calendar"
          :aria-selected="activeOverviewView === 'calendar'"
          @click="setActiveOverviewView('calendar')"
        >
          Calender
        </button>
      </div>
    </section>

    <section class="view-panel dashboard-panel" :class="{ active: activeOverviewView === 'dashboard' }" data-panel="dashboard">
      <section class="metrics">
        <article class="metric">
          <div class="metric-label">Live Calls</div>
          <div class="metric-value">{{ liveCallsMetric }}</div>
        </article>
        <article class="metric">
          <div class="metric-label">Concurrent Participants</div>
          <div class="metric-value">{{ participantsMetric }}</div>
        </article>
        <article class="metric">
          <div class="metric-label">Healthy Cluster Nodes</div>
          <div class="metric-value">{{ healthyNodesMetric }}</div>
        </article>
        <article class="metric">
          <div class="metric-label">Nodes Under Load</div>
          <div class="metric-value">{{ nodesUnderLoadMetric }}</div>
        </article>
      </section>

      <section class="infra-overview-card">
        <header class="infra-overview-head">
          <div>
            <h2>Infrastructure Inventory</h2>
            <p>{{ infrastructureSubtitle }}</p>
          </div>
          <span class="tag" :class="infrastructureStatusTagClass">{{ infrastructureStatusLabel }}</span>
        </header>
        <p v-if="infrastructureState.loading" class="infra-inline-state">Loading infrastructure inventory…</p>
        <p v-else-if="infrastructureState.error" class="infra-inline-state error">{{ infrastructureState.error }}</p>
        <section v-else class="infra-provider-grid">
          <article v-for="provider in providerRows" :key="provider.id" class="infra-provider-card">
            <span class="infra-provider-label">{{ provider.label }}</span>
            <strong>{{ provider.statusLabel }}</strong>
            <span>{{ provider.capabilityLabel }}</span>
          </article>
        </section>
      </section>

      <section class="panel-grid grid-2">
        <article class="card">
          <h2 class="table-title">Running Calls</h2>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Call</th>
                  <th>Host</th>
                  <th>Live Users</th>
                  <th>Uptime</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="operationsState.loading">
                  <td colspan="5">Loading live call metrics…</td>
                </tr>
                <tr v-else-if="operationsState.error">
                  <td colspan="5">{{ operationsState.error }}</td>
                </tr>
                <tr v-else-if="runningCallsRows.length === 0">
                  <td colspan="5">No live calls right now.</td>
                </tr>
                <template v-else>
                  <tr v-for="row in runningCallsRows" :key="row.id">
                    <td>{{ row.call }}</td>
                    <td>{{ row.host }}</td>
                    <td>{{ row.users }}</td>
                    <td>{{ row.uptime }}</td>
                    <td><span class="tag" :class="row.statusTagClass">{{ row.statusLabel }}</span></td>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>
        </article>

        <article class="card">
          <h2 class="table-title">Infrastructure Nodes</h2>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Node</th>
                  <th>Provider</th>
                  <th>Region</th>
                  <th>Roles</th>
                  <th>Services</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in clusterHealthRows" :key="row.id">
                  <td>{{ row.node }}</td>
                  <td>{{ row.provider }}</td>
                  <td>{{ row.region }}</td>
                  <td>{{ row.roles }}</td>
                  <td>{{ row.services }}</td>
                  <td><span class="tag" :class="row.statusTagClass">{{ row.status }}</span></td>
                </tr>
                <tr v-if="clusterHealthRows.length === 0">
                  <td colspan="6">No infrastructure nodes reported.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </article>

        <article class="card grid-full">
          <h2 class="table-title">Telemetry & Scaling</h2>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Topic</th>
                  <th>Policy</th>
                  <th>Current Runtime</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in routingPolicyRows" :key="row.topic">
                  <td>{{ row.topic }}</td>
                  <td>{{ row.policy }}</td>
                  <td>
                    <span v-if="row.code" class="code">{{ row.runtime }}</span>
                    <template v-else>{{ row.runtime }}</template>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </article>
      </section>
    </section>

    <section class="view-panel calendar-panel" :class="{ active: activeOverviewView === 'calendar' }" data-panel="calendar">
      <div id="overviewCalendar" ref="overviewCalendarEl"></div>
      <p class="calendar-help">Double-click a date/slot to schedule. In day view, drag a time range to prefill the modal.</p>
    </section>

    <div class="calls-modal" :hidden="!composeState.open" role="dialog" aria-modal="true" aria-label="Call compose modal">
      <div class="calls-modal-backdrop" @click="closeCompose"></div>
      <div class="calls-modal-dialog">
        <header class="calls-modal-header calls-modal-header-enter">
          <div class="calls-modal-header-enter-left">
            <img class="calls-modal-header-enter-logo" src="/assets/orgas/kingrt/logo.svg" alt="" />
            <h4 class="calls-enter-title">{{ composeHeadline }}</h4>
          </div>
          <button class="icon-mini-btn" type="button" aria-label="Close" @click="closeCompose">
            <img src="/assets/orgas/kingrt/icons/cancel.png" alt="" />
          </button>
        </header>

        <div class="calls-modal-body">
          <section class="calls-modal-grid">
            <label class="field calls-field-wide">
              <span>Title</span>
              <input v-model="composeState.title" class="input" type="text" placeholder="Weekly Product Sync" />
            </label>
            <label class="field">
              <span>Starts at</span>
              <input
                v-model="composeState.startsLocal"
                class="input"
                type="datetime-local"
                aria-label="Call starts at"
              />
            </label>
            <label class="field">
              <span>Ends at</span>
              <input
                v-model="composeState.endsLocal"
                class="input"
                type="datetime-local"
                aria-label="Call ends at"
              />
            </label>
          </section>

          <section class="calls-participants-grid">
            <article class="calls-participants-panel">
              <header class="calls-participants-head">
                <h5>Registered users</h5>
                <label class="calls-search small" aria-label="Participant search">
                  <input
                    v-model="composeParticipants.query"
                    class="input"
                    type="search"
                    placeholder="Search users"
                    @keydown.enter.prevent
                  />
                </label>
              </header>

              <section class="calls-participants-list">
                <label
                  v-for="user in filteredRegisteredUsers"
                  :key="user.id"
                  class="calls-participant-row"
                >
                  <input
                    type="checkbox"
                    :checked="isUserSelected(user.id)"
                    @change="toggleUserSelection(user.id)"
                  />
                  <span class="calls-participant-main">{{ user.display_name || user.email }}</span>
                  <span class="calls-participant-meta">{{ user.email }} · {{ user.role }}</span>
                </label>
                <p v-if="filteredRegisteredUsers.length === 0" class="calls-empty-inline">
                  No users match the current filter.
                </p>
              </section>
            </article>

            <article class="calls-participants-panel">
              <header class="calls-participants-head">
                <h5>External participants</h5>
                <button class="btn btn-cyan" type="button" @click="addExternalRow">Add row</button>
              </header>

              <section class="calls-external-list">
                <div v-for="(row, index) in composeExternalRows" :key="row.id" class="calls-external-row">
                  <input
                    v-model="row.display_name"
                    class="input"
                    type="text"
                    placeholder="Display name"
                    :aria-label="`External participant ${index + 1} display name`"
                  />
                  <input
                    v-model="row.email"
                    class="input"
                    type="email"
                    placeholder="guest@example.com"
                    :aria-label="`External participant ${index + 1} email`"
                  />
                  <button
                    class="icon-mini-btn danger"
                    type="button"
                    title="Remove external participant"
                    :aria-label="`Remove external participant row ${index + 1}`"
                    @click="removeExternalRow(index)"
                  >
                    <img src="/assets/orgas/kingrt/icons/remove_user.png" alt="" />
                  </button>
                </div>
              </section>
            </article>
          </section>

          <section v-if="composeState.error" class="calls-inline-error">
            {{ composeState.error }}
          </section>
        </div>

        <footer class="calls-modal-footer">
          <button
            v-if="composeCanDelete"
            class="btn calls-btn-danger"
            type="button"
            :disabled="composeState.submitting"
            @click="deleteComposeEvent"
          >
            Delete
          </button>
          <button class="btn btn-cyan" type="button" :disabled="composeState.submitting" @click="submitCompose">
            {{ composeState.submitting ? 'Saving…' : composeSubmitLabel }}
          </button>
        </footer>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { Calendar } from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import interactionPlugin from '@fullcalendar/interaction';
import { currentBackendOrigin, fetchBackend } from '../../../support/backendFetch';
import { logoutSession, refreshSession, sessionState } from '../../auth/session';
import { formatDateRangeDisplay, fullCalendarEventTimeFormat } from '../../../support/dateTimeFormat';

const router = useRouter();
const activeOverviewView = ref('dashboard');
const overviewCalendarEl = ref(null);
let calendarInstance = null;
let lastDateKey = '';
let lastDateClickAt = 0;
let nextCalendarEventId = 1000;
let infrastructureLoadSeq = 0;
let operationsLoadSeq = 0;
let operationsRefreshTimer = null;

const infrastructureState = reactive({
  loading: false,
  error: '',
  lastLoadedAt: '',
  deployment: {},
  providers: [],
  nodes: [],
  services: [],
  telemetry: {},
  scaling: {},
});

const operationsState = reactive({
  loading: false,
  error: '',
  lastLoadedAt: '',
  metrics: {
    live_calls: 0,
    concurrent_participants: 0,
  },
  runningCalls: [],
});

const myCallsRows = ref([]);

const registeredUsers = [
  { id: 1, display_name: 'Jochen', email: 'jochen@kingrt.com', role: 'admin' },
  { id: 2, display_name: 'Anna Meyer', email: 'anna@kingrt.com', role: 'user' },
  { id: 3, display_name: 'Luca Klein', email: 'luca@kingrt.com', role: 'user' },
  { id: 4, display_name: 'Sara Hoffmann', email: 'sara@kingrt.com', role: 'user' },
  { id: 5, display_name: 'Lea Bauer', email: 'lea@kingrt.com', role: 'user' },
  { id: 6, display_name: 'Jonas Brandt', email: 'jonas@kingrt.com', role: 'user' },
];

const composeState = reactive({
  open: false,
  mode: 'schedule',
  calendarEventId: '',
  title: '',
  roomUuid: '',
  startsLocal: '',
  endsLocal: '',
  submitting: false,
  error: '',
});

const composeParticipants = reactive({
  query: '',
});

const composeSelectedUserIds = ref([]);
const composeExternalRows = ref([]);
let composeExternalRowId = 0;

const composeHeadline = computed(() => (
  composeState.mode === 'edit' ? 'Edit video call' : 'Schedule video call'
));

const composeSubmitLabel = computed(() => (
  composeState.mode === 'edit' ? 'Save changes' : 'Schedule call'
));

const composeCanDelete = computed(() => (
  composeState.mode === 'edit' && String(composeState.calendarEventId || '').trim() !== ''
));

const filteredRegisteredUsers = computed(() => {
  const query = String(composeParticipants.query || '').trim().toLowerCase();
  if (query === '') return registeredUsers;
  return registeredUsers.filter((user) => {
    const name = String(user.display_name || '').toLowerCase();
    const mail = String(user.email || '').toLowerCase();
    const role = String(user.role || '').toLowerCase();
    return name.includes(query) || mail.includes(query) || role.includes(query);
  });
});

function requestHeaders(includeBody = false) {
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

function normalizeArray(value) {
  return Array.isArray(value) ? value : [];
}

function tagClassForStatus(status) {
  const normalized = String(status || '').trim().toLowerCase();
  if (['ok', 'healthy', 'live', 'running', 'connected', 'configured', 'detected'].includes(normalized)) return 'ok';
  if (['warning', 'warn', 'degraded', 'high load', 'error'].includes(normalized)) return 'warn';
  return 'warn';
}

function applyInfrastructurePayload(payload) {
  infrastructureState.deployment = payload?.deployment && typeof payload.deployment === 'object' ? payload.deployment : {};
  infrastructureState.providers = normalizeArray(payload?.providers);
  infrastructureState.nodes = normalizeArray(payload?.nodes);
  infrastructureState.services = normalizeArray(payload?.services);
  infrastructureState.telemetry = payload?.telemetry && typeof payload.telemetry === 'object' ? payload.telemetry : {};
  infrastructureState.scaling = payload?.scaling && typeof payload.scaling === 'object' ? payload.scaling : {};
  infrastructureState.lastLoadedAt = String(payload?.time || new Date().toISOString());
}

async function loadInfrastructure() {
  const seq = ++infrastructureLoadSeq;
  infrastructureState.loading = true;
  infrastructureState.error = '';
  try {
    const payload = await apiRequest('/api/admin/infrastructure');
    if (seq !== infrastructureLoadSeq) return;
    applyInfrastructurePayload(payload);
  } catch (error) {
    if (seq !== infrastructureLoadSeq) return;
    infrastructureState.error = error instanceof Error ? error.message : 'Could not load infrastructure inventory.';
  } finally {
    if (seq === infrastructureLoadSeq) {
      infrastructureState.loading = false;
    }
  }
}

function normalizeNonNegativeInteger(value) {
  if (Number.isInteger(value)) return Math.max(0, value);
  const parsed = Number.parseInt(String(value ?? '').trim(), 10);
  return Number.isFinite(parsed) ? Math.max(0, parsed) : 0;
}

function formatUptimeSeconds(value) {
  const seconds = normalizeNonNegativeInteger(value);
  const hours = Math.floor(seconds / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  const remainingSeconds = seconds % 60;
  return [
    String(hours).padStart(2, '0'),
    String(minutes).padStart(2, '0'),
    String(remainingSeconds).padStart(2, '0'),
  ].join(':');
}

function normalizeOwnerHost(call) {
  const host = String(call?.host || '').trim();
  if (host !== '') return host;
  const displayName = String(call?.owner?.display_name || '').trim();
  if (displayName !== '') return displayName;
  const email = String(call?.owner?.email || '').trim();
  return email !== '' ? email : 'unknown';
}

function applyVideoOperationsPayload(payload) {
  const metrics = payload?.metrics && typeof payload.metrics === 'object' ? payload.metrics : {};
  operationsState.metrics = {
    live_calls: normalizeNonNegativeInteger(metrics.live_calls),
    concurrent_participants: normalizeNonNegativeInteger(metrics.concurrent_participants),
  };
  operationsState.runningCalls = normalizeArray(payload?.running_calls);
  operationsState.lastLoadedAt = String(payload?.time || new Date().toISOString());
}

async function loadVideoOperations({ background = false } = {}) {
  const seq = ++operationsLoadSeq;
  if (!background) {
    operationsState.loading = true;
  }
  operationsState.error = '';
  try {
    const payload = await apiRequest('/api/admin/video-operations');
    if (seq !== operationsLoadSeq) return;
    applyVideoOperationsPayload(payload);
  } catch (error) {
    if (seq !== operationsLoadSeq) return;
    operationsState.error = error instanceof Error ? error.message : 'Could not load live video operations.';
  } finally {
    if (seq === operationsLoadSeq) {
      operationsState.loading = false;
    }
  }
}

function startVideoOperationsRefreshLoop() {
  if (operationsRefreshTimer !== null) return;
  operationsRefreshTimer = window.setInterval(() => {
    if (activeOverviewView.value !== 'dashboard') return;
    void loadVideoOperations({ background: true });
  }, 15000);
}

function stopVideoOperationsRefreshLoop() {
  if (operationsRefreshTimer === null) return;
  window.clearInterval(operationsRefreshTimer);
  operationsRefreshTimer = null;
}

const runningCallsRows = computed(() => (
  operationsState.runningCalls.map((call, index) => {
    const id = String(call?.id || call?.room_id || `running-call-${index}`).trim();
    const liveParticipants = call?.live_participants && typeof call.live_participants === 'object'
      ? call.live_participants
      : {};
    const statusLabel = String(call?.status || 'live').trim() || 'live';

    return {
      id,
      call: String(call?.title || 'Untitled call'),
      host: normalizeOwnerHost(call),
      users: normalizeNonNegativeInteger(liveParticipants.total),
      uptime: formatUptimeSeconds(call?.uptime_seconds),
      statusLabel,
      statusTagClass: tagClassForStatus(statusLabel),
    };
  })
));

const liveCallsMetric = computed(() => String(normalizeNonNegativeInteger(
  operationsState.metrics.live_calls,
)));

const participantsMetric = computed(() => String(normalizeNonNegativeInteger(
  operationsState.metrics.concurrent_participants,
)));

const healthyNodesMetric = computed(() => {
  const total = clusterHealthRows.value.length;
  const healthy = clusterHealthRows.value.filter((row) => String(row.health).toLowerCase() === 'healthy').length;
  return `${healthy} / ${total}`;
});

const nodesUnderLoadMetric = computed(() => String(
  clusterHealthRows.value.filter((row) => String(row.health).toLowerCase() !== 'healthy').length,
));

const servicesByNodeId = computed(() => {
  const map = new Map();
  for (const service of infrastructureState.services) {
    const nodeId = String(service?.node_id || '').trim();
    if (nodeId === '') continue;
    if (!map.has(nodeId)) map.set(nodeId, []);
    map.get(nodeId).push(service);
  }
  return map;
});

const clusterHealthRows = computed(() => (
  infrastructureState.nodes.map((node) => {
    const nodeId = String(node?.id || '').trim();
    const services = servicesByNodeId.value.get(nodeId) || [];
    const roles = normalizeArray(node?.roles).map((role) => String(role || '').trim()).filter(Boolean);
    const health = String(node?.health || node?.status || 'unknown').trim().toLowerCase();
    return {
      id: nodeId || String(node?.name || 'unknown-node'),
      node: String(node?.name || nodeId || 'unknown-node'),
      provider: String(node?.provider || 'unknown'),
      region: String(node?.region || 'n/a'),
      roles: roles.length > 0 ? roles.join(', ') : 'n/a',
      services: services.length > 0 ? services.map((service) => String(service?.kind || service?.label || 'service')).join(', ') : 'n/a',
      status: String(node?.status || 'unknown'),
      health,
      statusTagClass: tagClassForStatus(health),
    };
  })
));

const providerRows = computed(() => (
  infrastructureState.providers.map((provider) => {
    const capabilities = provider?.capabilities && typeof provider.capabilities === 'object' ? provider.capabilities : {};
    const activeCapabilities = Object.entries(capabilities)
      .filter(([, value]) => Boolean(value))
      .map(([key]) => key.replaceAll('_', ' '));
    return {
      id: String(provider?.id || provider?.label || 'provider'),
      label: String(provider?.label || provider?.id || 'Provider'),
      statusLabel: String(provider?.status || 'unknown'),
      capabilityLabel: activeCapabilities.length > 0 ? activeCapabilities.join(' / ') : 'inventory only',
    };
  })
));

const infrastructureSubtitle = computed(() => {
  const deployment = infrastructureState.deployment || {};
  const name = String(deployment.name || deployment.id || 'deployment');
  const publicDomain = String(deployment.public_domain || 'local');
  const mode = String(deployment.inventory_mode || 'auto');
  return `${name} · ${publicDomain} · inventory ${mode}`;
});

const infrastructureStatusLabel = computed(() => {
  if (infrastructureState.loading) return 'loading';
  if (infrastructureState.error) return 'error';
  return clusterHealthRows.value.length > 0 ? 'inventory online' : 'no nodes';
});

const infrastructureStatusTagClass = computed(() => (
  infrastructureState.error ? 'warn' : 'ok'
));

const telemetrySummary = computed(() => {
  const openTelemetry = infrastructureState.telemetry?.open_telemetry || {};
  if (!openTelemetry.enabled) return 'OpenTelemetry not enabled';
  const metrics = openTelemetry.metrics_enabled ? 'metrics' : '';
  const logs = openTelemetry.logs_enabled ? 'logs' : '';
  const signals = [metrics, logs].filter(Boolean).join(' + ') || 'exporter configured';
  return `${signals} via ${openTelemetry.protocol || 'grpc'}`;
});

const scalingModesSummary = computed(() => {
  const modes = normalizeArray(infrastructureState.scaling?.modes);
  const available = modes.filter((mode) => Boolean(mode?.available)).map((mode) => String(mode?.label || mode?.id || '').trim()).filter(Boolean);
  return available.length > 0 ? available.join(' / ') : 'No scaling mode available';
});

const routingPolicyRows = computed(() => [
  {
    topic: 'Deployment routing',
    policy: 'Domain + API/WS/SFU/CDN subdomains',
    runtime: [
      infrastructureState.deployment?.public_domain,
      infrastructureState.deployment?.api_domain,
      infrastructureState.deployment?.ws_domain,
      infrastructureState.deployment?.sfu_domain,
      infrastructureState.deployment?.cdn_domain,
    ].filter(Boolean).join(' / ') || 'n/a',
    code: false,
  },
  {
    topic: 'Telemetry source',
    policy: 'OpenTelemetry exporter contract',
    runtime: telemetrySummary.value,
    code: false,
  },
  {
    topic: 'SFU scaling',
    policy: String(infrastructureState.scaling?.strategy || 'not reported'),
    runtime: scalingModesSummary.value,
    code: false,
  },
  {
    topic: 'Write actions',
    policy: 'Provisioning is read-only until audited admin actions exist',
    runtime: infrastructureState.scaling?.write_actions_enabled ? 'Enabled' : 'Disabled',
    code: false,
  },
]);

function setActiveOverviewView(view) {
  if (view === 'dashboard' || view === 'calendar') {
    activeOverviewView.value = view;
  }
}

function formatScheduleRange(startValue, endValue) {
  return formatDateRangeDisplay(startValue, endValue, {
    dateFormat: sessionState.dateFormat,
    timeFormat: sessionState.timeFormat,
    separator: ' -> ',
    fallback: 'n/a',
  });
}

function openWorkspace(row) {
  const routeSegment = String(row?.id || row?.roomId || 'lobby').trim() || 'lobby';
  void router.push(`/workspace/call/${encodeURIComponent(routeSegment)}`);
}

function isoToLocalInput(isoValue) {
  if (typeof isoValue !== 'string' || isoValue.trim() === '') return '';
  const date = new Date(isoValue);
  if (Number.isNaN(date.getTime())) return '';
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  const hour = String(date.getHours()).padStart(2, '0');
  const minute = String(date.getMinutes()).padStart(2, '0');
  return `${year}-${month}-${day}T${hour}:${minute}`;
}

function localInputToIso(localValue) {
  if (typeof localValue !== 'string' || localValue.trim() === '') return '';
  const date = new Date(localValue);
  if (Number.isNaN(date.getTime())) return '';
  return date.toISOString();
}

let roomUuidFallbackCounter = 0;

function uuidFromBytes(bytes) {
  const hex = Array.from(bytes, (value) => value.toString(16).padStart(2, '0')).join('');
  return [
    hex.slice(0, 8),
    hex.slice(8, 12),
    hex.slice(12, 16),
    hex.slice(16, 20),
    hex.slice(20, 32),
  ].join('-');
}

function fallbackDeterministicUuid() {
  const bytes = new Uint8Array(16);
  const now = BigInt(Date.now());
  roomUuidFallbackCounter = (roomUuidFallbackCounter + 1) >>> 0;
  const counter = BigInt(roomUuidFallbackCounter);

  for (let i = 0; i < 8; i += 1) {
    bytes[7 - i] = Number((now >> BigInt(i * 8)) & 0xffn);
  }
  for (let i = 0; i < 4; i += 1) {
    bytes[15 - i] = Number((counter >> BigInt(i * 8)) & 0xffn);
  }

  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  return uuidFromBytes(bytes);
}

function generateRoomUuid() {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }

  if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') {
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    return uuidFromBytes(bytes);
  }

  return fallbackDeterministicUuid();
}

function nextExternalRow(initial = {}) {
  composeExternalRowId += 1;
  return {
    id: composeExternalRowId,
    display_name: String(initial.display_name || ''),
    email: String(initial.email || ''),
  };
}

function resetComposeModal() {
  composeState.calendarEventId = '';
  composeState.title = '';
  composeState.roomUuid = '';
  composeState.startsLocal = '';
  composeState.endsLocal = '';
  composeState.submitting = false;
  composeState.error = '';
  composeParticipants.query = '';
  composeSelectedUserIds.value = [];
  composeExternalRows.value = [nextExternalRow()];
}

function openComposeFromPreset({
  mode = 'schedule',
  eventId = '',
  title = 'New Video Call',
  roomUuid = '',
  startsAt,
  endsAt,
  internalParticipantUserIds = [],
  externalParticipants = [],
} = {}) {
  resetComposeModal();
  composeState.mode = mode === 'edit' ? 'edit' : 'schedule';
  composeState.open = true;
  composeState.calendarEventId = String(eventId || '');
  composeState.title = String(title || 'New Video Call').trim() || 'New Video Call';
  composeState.roomUuid = String(roomUuid || '').trim() || generateRoomUuid();
  composeState.startsLocal = isoToLocalInput(startsAt instanceof Date ? startsAt.toISOString() : String(startsAt || ''));
  composeState.endsLocal = isoToLocalInput(endsAt instanceof Date ? endsAt.toISOString() : String(endsAt || ''));
  composeSelectedUserIds.value = Array.isArray(internalParticipantUserIds)
    ? internalParticipantUserIds.map((id) => Number(id)).filter((id) => Number.isInteger(id) && id > 0)
    : [];

  const normalizedExternal = Array.isArray(externalParticipants)
    ? externalParticipants
      .map((row) => ({
        display_name: String(row?.display_name || '').trim(),
        email: String(row?.email || '').trim(),
      }))
      .filter((row) => row.display_name !== '' || row.email !== '')
    : [];

  composeExternalRows.value = normalizedExternal.length > 0
    ? normalizedExternal.map((row) => nextExternalRow(row))
    : [nextExternalRow()];
}

function openComposeForDoubleClick(dateValue) {
  const start = dateValue instanceof Date ? new Date(dateValue.getTime()) : new Date();
  const end = new Date(start.getTime() + 45 * 60 * 1000);
  openComposeFromPreset({
    mode: 'schedule',
    startsAt: start,
    endsAt: end,
  });
}

function openComposeForSelection(startValue, endValue) {
  const start = startValue instanceof Date ? startValue : new Date(startValue);
  const end = endValue instanceof Date ? endValue : new Date(endValue);
  if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return;
  openComposeFromPreset({
    mode: 'schedule',
    startsAt: start,
    endsAt: end,
  });
}

function openComposeForEvent(eventApi) {
  if (!eventApi) return;
  const extended = eventApi.extendedProps || {};
  openComposeFromPreset({
    mode: 'edit',
    eventId: eventApi.id,
    title: eventApi.title,
    roomUuid: String(extended.roomUuid || extended.roomId || ''),
    startsAt: eventApi.start,
    endsAt: eventApi.end || new Date((eventApi.start instanceof Date ? eventApi.start.getTime() : Date.now()) + 45 * 60 * 1000),
    internalParticipantUserIds: Array.isArray(extended.internalParticipantUserIds) ? extended.internalParticipantUserIds : [],
    externalParticipants: Array.isArray(extended.externalParticipants) ? extended.externalParticipants : [],
  });
}

function closeCompose() {
  composeState.open = false;
  composeState.submitting = false;
  composeState.error = '';
}

function deleteComposeEvent() {
  if (!composeCanDelete.value) return;

  const eventId = String(composeState.calendarEventId || '').trim();
  if (eventId !== '' && calendarInstance) {
    const eventApi = calendarInstance.getEventById(eventId);
    eventApi?.remove();
  }

  if (eventId !== '') {
    myCallsRows.value = myCallsRows.value.filter((row) => String(row.id) !== eventId);
  }

  closeCompose();
}

function isUserSelected(userId) {
  const id = Number(userId);
  return composeSelectedUserIds.value.includes(id);
}

function toggleUserSelection(userId) {
  const id = Number(userId);
  if (!Number.isInteger(id) || id <= 0) return;
  const next = composeSelectedUserIds.value.slice();
  const index = next.indexOf(id);
  if (index >= 0) {
    next.splice(index, 1);
  } else {
    next.push(id);
  }
  composeSelectedUserIds.value = next;
}

function addExternalRow() {
  composeExternalRows.value = [...composeExternalRows.value, nextExternalRow()];
}

function removeExternalRow(index) {
  if (!Number.isInteger(index) || index < 0 || index >= composeExternalRows.value.length) return;
  const next = composeExternalRows.value.slice();
  next.splice(index, 1);
  composeExternalRows.value = next.length > 0 ? next : [nextExternalRow()];
}

function normalizeExternalRows() {
  const rows = [];
  for (let index = 0; index < composeExternalRows.value.length; index += 1) {
    const row = composeExternalRows.value[index];
    const displayName = String(row?.display_name || '').trim();
    const email = String(row?.email || '').trim().toLowerCase();
    if (displayName === '' && email === '') continue;
    if (displayName === '' || email === '') {
      return { ok: false, error: `External participant row ${index + 1} requires both display name and email.`, rows: [] };
    }
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
      return { ok: false, error: `External participant row ${index + 1} has an invalid email.`, rows: [] };
    }
    rows.push({ display_name: displayName, email });
  }
  return { ok: true, error: '', rows };
}

function deriveStatus(startIso, endIso) {
  const now = Date.now();
  const start = Date.parse(String(startIso || ''));
  const end = Date.parse(String(endIso || ''));
  if (Number.isFinite(start) && Number.isFinite(end) && start <= now && now < end) {
    return { label: 'running', tagClass: 'ok' };
  }
  if (Number.isFinite(end) && end <= now) {
    return { label: 'ended', tagClass: 'warn' };
  }
  return { label: 'scheduled', tagClass: 'warn' };
}

function upsertMyCallsRow(eventId, title, roomUuid, startsAtIso, endsAtIso, usersCount) {
  const status = deriveStatus(startsAtIso, endsAtIso);
  const nextRow = {
    id: String(eventId),
    title: String(title || 'New Video Call'),
    scheduleStart: isoToLocalInput(startsAtIso),
    scheduleEnd: isoToLocalInput(endsAtIso),
    statusLabel: status.label,
    statusTagClass: status.tagClass,
    users: Number(usersCount || 0),
    roomId: String(roomUuid || ''),
  };

  const rows = myCallsRows.value.slice();
  const index = rows.findIndex((row) => row.id === nextRow.id);
  if (index >= 0) {
    rows[index] = nextRow;
  } else {
    rows.unshift(nextRow);
  }
  myCallsRows.value = rows;
}

function syncMyCallsRowFromCalendarEvent(eventApi) {
  if (!eventApi) return;
  const eventId = String(eventApi.id || '').trim();
  if (eventId === '') return;

  const startDate = eventApi.start instanceof Date ? new Date(eventApi.start.getTime()) : null;
  let endDate = eventApi.end instanceof Date ? new Date(eventApi.end.getTime()) : null;
  if (!(startDate instanceof Date) || Number.isNaN(startDate.getTime())) {
    return;
  }

  if (!(endDate instanceof Date) || Number.isNaN(endDate.getTime()) || endDate.getTime() <= startDate.getTime()) {
    endDate = new Date(startDate.getTime() + (45 * 60 * 1000));
  }

  const extended = eventApi.extendedProps || {};
  const roomUuid = String(extended.roomUuid || extended.roomId || '').trim();
  const internalParticipantUserIds = Array.isArray(extended.internalParticipantUserIds) ? extended.internalParticipantUserIds : [];
  const externalParticipants = Array.isArray(extended.externalParticipants) ? extended.externalParticipants : [];
  const participantsTotal = internalParticipantUserIds.length + externalParticipants.length;

  upsertMyCallsRow(
    eventId,
    eventApi.title,
    roomUuid,
    startDate.toISOString(),
    endDate.toISOString(),
    participantsTotal,
  );
}

function nextGeneratedEventId() {
  nextCalendarEventId += 1;
  return `call-generated-${nextCalendarEventId}`;
}

function submitCompose() {
  composeState.error = '';
  const title = String(composeState.title || '').trim();
  if (title === '') {
    composeState.error = 'Title is required.';
    return;
  }

  const startsAt = localInputToIso(composeState.startsLocal);
  const endsAt = localInputToIso(composeState.endsLocal);
  if (startsAt === '' || endsAt === '') {
    composeState.error = 'Start and end timestamps are required.';
    return;
  }

  if (new Date(endsAt).getTime() <= new Date(startsAt).getTime()) {
    composeState.error = 'End timestamp must be after start timestamp.';
    return;
  }

  const normalizedExternal = normalizeExternalRows();
  if (!normalizedExternal.ok) {
    composeState.error = normalizedExternal.error;
    return;
  }

  const roomUuid = String(composeState.roomUuid || '').trim() || generateRoomUuid();
  composeState.roomUuid = roomUuid;
  const internalParticipantUserIds = composeSelectedUserIds.value.slice();
  const externalParticipants = normalizedExternal.rows;
  const participantsTotal = internalParticipantUserIds.length + externalParticipants.length;
  const startDate = new Date(startsAt);
  const endDate = new Date(endsAt);

  let eventId = composeState.calendarEventId;
  let eventApi = null;
  if (calendarInstance && eventId) {
    eventApi = calendarInstance.getEventById(String(eventId));
  }

  if (eventApi) {
    eventApi.setProp('title', title);
    eventApi.setDates(startDate, endDate, { allDay: false });
    eventApi.setExtendedProp('roomUuid', roomUuid);
    eventApi.setExtendedProp('roomId', roomUuid);
    eventApi.setExtendedProp('internalParticipantUserIds', internalParticipantUserIds);
    eventApi.setExtendedProp('externalParticipants', externalParticipants);
  } else if (calendarInstance) {
    eventId = nextGeneratedEventId();
    calendarInstance.addEvent({
      id: eventId,
      title,
      start: startDate,
      end: endDate,
      allDay: false,
      extendedProps: {
        roomUuid,
        roomId: roomUuid,
        internalParticipantUserIds,
        externalParticipants,
      },
    });
  } else {
    eventId = nextGeneratedEventId();
  }

  upsertMyCallsRow(eventId, title, roomUuid, startsAt, endsAt, participantsTotal);
  closeCompose();
}

function handleEscape(event) {
  if (event.key !== 'Escape') return;
  if (composeState.open) {
    closeCompose();
  }
}

async function initOverviewCalendar() {
  if (!(overviewCalendarEl.value instanceof HTMLElement) || calendarInstance) return;
  try {
    calendarInstance = new Calendar(overviewCalendarEl.value, {
      plugins: [dayGridPlugin, timeGridPlugin, interactionPlugin],
      initialView: 'dayGridMonth',
      headerToolbar: {
        left: 'prev,next today',
        center: 'title',
        right: 'dayGridMonth,timeGridWeek,timeGridDay',
      },
      height: '100%',
      contentHeight: '100%',
      expandRows: true,
      eventTimeFormat: fullCalendarEventTimeFormat(sessionState.timeFormat),
      selectable: true,
      editable: true,
      eventStartEditable: true,
      eventDurationEditable: true,
      eventResizableFromStart: true,
      events: [],
      dateClick(info) {
        const now = Date.now();
        const dateKey = `${String(info.view?.type || '')}:${info.dateStr}`;
        const isDoubleClick = dateKey === lastDateKey && now - lastDateClickAt < 360;
        lastDateKey = dateKey;
        lastDateClickAt = now;
        if (!isDoubleClick) return;
        openComposeForDoubleClick(info.date instanceof Date ? info.date : new Date(info.dateStr));
      },
      eventClick(info) {
        openComposeForEvent(info.event);
      },
      eventDrop(info) {
        syncMyCallsRowFromCalendarEvent(info.event);
      },
      eventResize(info) {
        syncMyCallsRowFromCalendarEvent(info.event);
      },
      select(info) {
        if (String(info.view?.type || '') !== 'timeGridDay') return;
        openComposeForSelection(info.start, info.end);
        calendarInstance?.unselect();
      },
    });

    calendarInstance.render();
  } catch {
    calendarInstance = null;
  }
}

onMounted(() => {
  window.addEventListener('keydown', handleEscape);
  void loadInfrastructure();
  void loadVideoOperations();
  startVideoOperationsRefreshLoop();
  void initOverviewCalendar();
});

onBeforeUnmount(() => {
  window.removeEventListener('keydown', handleEscape);
  stopVideoOperationsRefreshLoop();
  if (calendarInstance) {
    calendarInstance.destroy();
    calendarInstance = null;
  }
});

watch(activeOverviewView, async (view) => {
  if (view !== 'calendar') return;
  if (!calendarInstance) {
    await initOverviewCalendar();
  }
  if (!calendarInstance) return;
  await nextTick();
  calendarInstance.updateSize();
});

watch(activeOverviewView, (view) => {
  if (view === 'dashboard') {
    void loadVideoOperations({ background: true });
  }
});

watch(
  () => sessionState.timeFormat,
  () => {
    if (!calendarInstance) return;
    calendarInstance.setOption('eventTimeFormat', fullCalendarEventTimeFormat(sessionState.timeFormat));
  }
);
</script>

<style scoped src="./OverviewView.css"></style>
