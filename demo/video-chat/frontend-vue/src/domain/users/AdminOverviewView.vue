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

      <section class="panel-grid grid-2">
        <article class="card">
          <h2 class="table-title">Running Calls</h2>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Call</th>
                  <th>Host</th>
                  <th>Users</th>
                  <th>Uptime</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in runningCallsRows" :key="row.id">
                  <td>{{ row.call }}</td>
                  <td>{{ row.host }}</td>
                  <td>{{ row.users }}</td>
                  <td>{{ row.uptime }}</td>
                  <td><span class="tag" :class="row.statusTagClass">{{ row.statusLabel }}</span></td>
                </tr>
              </tbody>
            </table>
          </div>
        </article>

        <article class="card">
          <h2 class="table-title">Cluster Health</h2>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Node</th>
                  <th>Region</th>
                  <th>CPU</th>
                  <th>Peers</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in clusterHealthRows" :key="row.node">
                  <td>{{ row.node }}</td>
                  <td>{{ row.region }}</td>
                  <td>{{ row.cpu }}</td>
                  <td>{{ row.peers }}</td>
                  <td><span class="tag" :class="row.statusTagClass">{{ row.status }}</span></td>
                </tr>
              </tbody>
            </table>
          </div>
        </article>

        <article class="card grid-full">
          <h2 class="table-title">Routing Policy</h2>
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
import { sessionState } from '../auth/session';
import { formatDateRangeDisplay, fullCalendarEventTimeFormat } from '../../support/dateTimeFormat';

const router = useRouter();
const activeOverviewView = ref('dashboard');
const overviewCalendarEl = ref(null);
let calendarInstance = null;
let lastDateKey = '';
let lastDateClickAt = 0;
let nextCalendarEventId = 1000;

const runningCallsRows = ref([
  {
    id: 'running-sales-standup',
    call: 'Sales Standup',
    host: 'jochen',
    users: 8,
    uptime: '00:42:18',
    statusLabel: 'running',
    statusTagClass: 'ok',
  },
  {
    id: 'running-incident-bridge',
    call: 'Incident Bridge',
    host: 'ops.bot',
    users: 21,
    uptime: '01:17:04',
    statusLabel: 'running',
    statusTagClass: 'ok',
  },
  {
    id: 'running-quarterly-sync',
    call: 'Quarterly Sync',
    host: 'lisa',
    users: 0,
    uptime: 'scheduled',
    statusLabel: 'waiting',
    statusTagClass: 'warn',
  },
]);

const clusterHealthRows = ref([
  {
    node: 'king-call-eu-01',
    region: 'eu-central',
    cpu: '42%',
    peers: 342,
    status: 'healthy',
    statusTagClass: 'ok',
  },
  {
    node: 'king-call-eu-02',
    region: 'eu-central',
    cpu: '57%',
    peers: 410,
    status: 'healthy',
    statusTagClass: 'ok',
  },
  {
    node: 'king-call-us-01',
    region: 'us-east',
    cpu: '79%',
    peers: 602,
    status: 'high load',
    statusTagClass: 'warn',
  },
]);

const routingPolicyRows = ref([
  {
    topic: 'Invite routing',
    policy: 'UUID route per call',
    runtime: '/call/{uuid}',
    code: true,
  },
  {
    topic: 'External discovery',
    policy: 'No per-call subdomain mapping',
    runtime: 'Hidden call topology',
    code: false,
  },
  {
    topic: 'Realtime monitoring',
    policy: 'Control center + metrics board',
    runtime: 'Enabled for operations team',
    code: false,
  },
]);

const myCallsRows = ref([
  {
    id: 'call-sales-standup',
    title: 'Sales Standup',
    scheduleStart: '2026-04-13T09:00',
    scheduleEnd: '2026-04-13T09:30',
    statusLabel: 'running',
    statusTagClass: 'ok',
    users: 8,
    roomId: '2fcb4d0f-2616-43f7-bfe5-8e108f9e9e6a',
  },
  {
    id: 'call-backend-sync',
    title: 'Backend Sync',
    scheduleStart: '2026-04-13T10:00',
    scheduleEnd: '2026-04-13T11:00',
    statusLabel: 'scheduled',
    statusTagClass: 'warn',
    users: 0,
    roomId: '0e5f2d9f-83b4-4f39-94f4-f83347fef04b',
  },
]);

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

const liveCallsMetric = computed(() => String(
  runningCallsRows.value.filter((row) => String(row.statusLabel).toLowerCase() === 'running').length,
));

const participantsMetric = computed(() => String(
  runningCallsRows.value.reduce((sum, row) => (
    String(row.statusLabel).toLowerCase() === 'running' ? sum + Number(row.users || 0) : sum
  ), 0),
));

const healthyNodesMetric = computed(() => {
  const total = clusterHealthRows.value.length;
  const healthy = clusterHealthRows.value.filter((row) => String(row.status).toLowerCase() === 'healthy').length;
  return `${healthy} / ${total}`;
});

const nodesUnderLoadMetric = computed(() => String(
  clusterHealthRows.value.filter((row) => String(row.status).toLowerCase() !== 'healthy').length,
));

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
      events: [
        {
          id: 'call-sales-standup',
          title: 'Sales Standup',
          start: '2026-04-13T09:00:00',
          end: '2026-04-13T09:30:00',
          extendedProps: {
            roomUuid: '2fcb4d0f-2616-43f7-bfe5-8e108f9e9e6a',
            roomId: '2fcb4d0f-2616-43f7-bfe5-8e108f9e9e6a',
            internalParticipantUserIds: [1, 2, 3],
            externalParticipants: [],
          },
        },
        {
          id: 'call-backend-sync',
          title: 'Backend Sync',
          start: '2026-04-13T10:00:00',
          end: '2026-04-13T11:00:00',
          extendedProps: {
            roomUuid: '0e5f2d9f-83b4-4f39-94f4-f83347fef04b',
            roomId: '0e5f2d9f-83b4-4f39-94f4-f83347fef04b',
            internalParticipantUserIds: [],
            externalParticipants: [],
          },
        },
        {
          id: 'call-incident-bridge',
          title: 'Incident Bridge',
          start: '2026-04-13T11:30:00',
          end: '2026-04-13T12:30:00',
          extendedProps: {
            roomUuid: '8ee8fbf5-7f9f-47dd-8ece-5a19f7aa8059',
            roomId: '8ee8fbf5-7f9f-47dd-8ece-5a19f7aa8059',
            internalParticipantUserIds: [1, 2, 4],
            externalParticipants: [],
          },
        },
      ],
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
  void initOverviewCalendar();
});

onBeforeUnmount(() => {
  window.removeEventListener('keydown', handleEscape);
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

watch(
  () => sessionState.timeFormat,
  () => {
    if (!calendarInstance) return;
    calendarInstance.setOption('eventTimeFormat', fullCalendarEventTimeFormat(sessionState.timeFormat));
  }
);
</script>

<style scoped>
.admin-overview-view {
  height: 100%;
  min-height: 0;
  display: grid;
  grid-template-rows: auto minmax(0, 1fr);
  gap: 0;
  background: transparent;
  overflow: hidden;
}

.admin-overview-view > :first-child {
  border-top-left-radius: 0;
  border-top-right-radius: 5px;
}

.overview-toolbar {
  background: var(--bg-ui-chrome);
}

.overview-toolbar {
  padding: 10px;
  margin-bottom: 15px;
}

.overview-view-tabs {
  display: inline-grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1px;
  background: var(--border-subtle);
}

.overview-view-tabs .tab {
  min-width: 120px;
  height: 40px;
}

.view-panel {
  display: none;
  min-height: 0;
  overflow: auto;
}

.view-panel.active {
  display: grid;
  align-content: start;
  gap: 10px;
  min-height: 0;
}

.dashboard-panel {
  padding: 10px;
  background: var(--bg-main);
}

.dashboard-panel .metrics {
  margin-bottom: 10px;
}

.panel-grid {
  min-height: 0;
  overflow: auto;
  background: var(--bg-main);
}

.calendar-panel {
  padding: 10px;
  background: var(--bg-main);
  min-height: 0;
  grid-template-rows: minmax(0, 1fr) auto;
  align-content: stretch;
}

#overviewCalendar {
  background: var(--bg-surface);
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  padding: 10px;
  min-height: 0;
  height: 100%;
}

.calendar-help {
  margin: 0 0 10px;
  padding: 0 2px;
  font-size: 12px;
  color: var(--text-muted);
}

.calls-modal {
  position: fixed;
  inset: 0;
  z-index: 70;
  display: grid;
  place-items: center;
}

.calls-modal[hidden] {
  display: none;
}

.calls-modal-backdrop {
  position: absolute;
  inset: 0;
  background: #09111e;
}

.calls-modal-dialog {
  --calls-enter-dialog-padding: 12px;
  position: relative;
  width: min(1020px, calc(100vw - 30px));
  max-height: calc(100vh - 30px);
  overflow: auto;
  border: 1px solid var(--border-subtle);
  border-radius: 8px;
  background: var(--bg-surface-strong);
  box-shadow: 0 16px 32px #000000;
  padding: 12px;
  display: grid;
  gap: 12px;
}

.calls-modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 10px;
}

.calls-modal-header h4 {
  margin: 5px 0 0;
  font-size: 17px;
}

.calls-modal-header .calls-enter-title {
  margin: 8px 0 0;
  font-size: 14px;
  line-height: 1;
}

.calls-modal-header-enter {
  margin: calc(var(--calls-enter-dialog-padding) * -1) calc(var(--calls-enter-dialog-padding) * -1) 0;
  padding: 10px;
  background: var(--brand-bg);
  border: 0;
}

.calls-modal-header-enter-left {
  min-width: 0;
  display: inline-flex;
  align-items: center;
  gap: 10px;
}

.calls-modal-header-enter-logo {
  width: auto;
  height: 24px;
  display: block;
}

.calls-modal-body {
  display: grid;
  gap: 10px;
}

.calls-modal-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}

.calls-field-wide {
  grid-column: 1 / -1;
}

.calls-participants-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  gap: 10px;
}

.calls-participants-panel {
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  background: #122340;
  padding: 10px;
  min-height: 0;
  display: grid;
  gap: 10px;
  align-content: start;
}

.calls-participants-head {
  display: grid;
  gap: 8px;
}

.calls-participants-head h5 {
  margin: 0;
  font-size: 13px;
}

.calls-search {
  display: inline-grid;
  grid-template-columns: minmax(220px, 1fr) auto;
  gap: 8px;
  align-items: center;
}

.calls-search.small {
  grid-template-columns: minmax(0, 1fr);
}

.calls-participants-list {
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  background: #0f1f37;
  max-height: 280px;
  overflow: auto;
  display: grid;
  align-content: start;
}

.calls-participant-row {
  padding: 8px 10px;
  border-bottom: 1px solid var(--border-subtle);
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  column-gap: 8px;
  align-items: start;
}

.calls-participant-row:last-child {
  border-bottom: 0;
}

.calls-participant-main {
  font-size: 12px;
  color: #ffffff;
}

.calls-participant-meta {
  grid-column: 2;
  font-size: 11px;
  color: var(--text-muted);
}

.calls-external-list {
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  background: #0f1f37;
  max-height: 280px;
  overflow: auto;
  padding: 8px;
  display: grid;
  gap: 8px;
  align-content: start;
}

.calls-external-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) auto;
  gap: 8px;
  align-items: center;
}

.calls-empty-inline {
  margin: 0;
  padding: 8px 10px;
  color: var(--text-muted);
  font-size: 12px;
}

.calls-inline-error {
  border: 1px solid #6b1f1f;
  border-radius: 6px;
  background: #331616;
  color: #ffb5b5;
  font-size: 12px;
  padding: 8px 10px;
}

.calls-modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}

.calls-btn-danger {
  background: var(--danger);
}

.calls-btn-danger:hover {
  background: #cc0000;
}

:deep(.fc-theme-standard .fc-scrollgrid),
:deep(.fc-theme-standard td),
:deep(.fc-theme-standard th) {
  border-color: var(--border-subtle);
}

:deep(.fc .fc-toolbar-title) {
  color: var(--text-main);
  font-size: 19px;
}

:deep(.fc .fc-col-header-cell-cushion),
:deep(.fc .fc-daygrid-day-number),
:deep(.fc .fc-timegrid-axis-cushion),
:deep(.fc .fc-timegrid-slot-label-cushion) {
  color: var(--text-main);
}

:deep(.fc .fc-daygrid-day.fc-day-today),
:deep(.fc .fc-timegrid-col.fc-day-today) {
  background: #213a63;
}

:deep(.fc .fc-button-primary) {
  background: var(--bg-action);
  border-color: var(--bg-action);
}

:deep(.fc .fc-button-primary:hover),
:deep(.fc .fc-button-primary:focus),
:deep(.fc .fc-button-primary:active) {
  background: var(--bg-action-hover);
  border-color: var(--bg-action-hover);
}

:deep(.fc .fc-event) {
  border: 0;
  background: var(--bg-row);
  color: #ffffff;
  cursor: pointer;
}

@media (max-width: 1180px) {
  .calls-modal-grid,
  .calls-participants-grid {
    grid-template-columns: 1fr;
  }
}

</style>
