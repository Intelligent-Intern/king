<template>
  <section class="view-card admin-overview-view">
    <section class="overview-toolbar">
      <div class="view-tabs" role="tablist" aria-label="Overview views">
        <button
          class="view-tab"
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
          class="view-tab"
          :class="{ active: activeOverviewView === 'calendar' }"
          type="button"
          role="tab"
          data-view="calendar"
          :aria-selected="activeOverviewView === 'calendar'"
          @click="setActiveOverviewView('calendar')"
        >
          Calendar
        </button>
        <button
          class="view-tab"
          :class="{ active: activeOverviewView === 'my-calls' }"
          type="button"
          role="tab"
          data-view="my-calls"
          :aria-selected="activeOverviewView === 'my-calls'"
          @click="setActiveOverviewView('my-calls')"
        >
          My Video Calls
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
      <p class="calendar-help">Double-click any date or time slot to schedule a new call.</p>
    </section>

    <section class="view-panel my-calls-panel" :class="{ active: activeOverviewView === 'my-calls' }" data-panel="my-calls">
      <article class="card">
        <h2 class="table-title">My Video Calls</h2>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Title</th>
                <th>Schedule</th>
                <th>Status</th>
                <th>Users</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in myCallsRows" :key="row.id">
                <td>{{ row.title }}</td>
                <td>{{ formatScheduleRange(row.scheduleStart, row.scheduleEnd) }}</td>
                <td><span class="tag" :class="row.statusTagClass">{{ row.statusLabel }}</span></td>
                <td>{{ row.users }}</td>
                <td>
                  <span class="actions-inline">
                    <button class="icon-mini-btn" type="button" title="Join call" aria-label="Join call" @click="openWorkspace(row.roomId)">
                      <img src="/assets/orgas/kingrt/icons/add_to_call.png" alt="" />
                    </button>
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </article>
    </section>
  </section>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';

const router = useRouter();
const activeOverviewView = ref('dashboard');
const overviewCalendarEl = ref(null);
let calendarInstance = null;
let lastDateKey = '';
let lastDateClickAt = 0;
const FULLCALENDAR_SCRIPT_URL = 'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js';
let fullCalendarScriptPromise = null;

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
    id: 'my-sales-standup',
    title: 'Sales Standup',
    scheduleStart: '2026-04-13T09:00',
    scheduleEnd: '2026-04-13T09:30',
    statusLabel: 'running',
    statusTagClass: 'ok',
    users: 8,
    roomId: 'lobby',
  },
  {
    id: 'my-backend-sync',
    title: 'Backend Sync',
    scheduleStart: '2026-04-13T10:00',
    scheduleEnd: '2026-04-13T11:00',
    statusLabel: 'scheduled',
    statusTagClass: 'warn',
    users: 0,
    roomId: 'lobby',
  },
]);

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
  if (view === 'dashboard' || view === 'calendar' || view === 'my-calls') {
    activeOverviewView.value = view;
  }
}

function formatScheduleRange(startValue, endValue) {
  const start = String(startValue || '').replace('T', ' ');
  const end = String(endValue || '').replace('T', ' ');
  return `${start} -> ${end}`;
}

function openWorkspace(roomId) {
  const safeRoomId = String(roomId || 'lobby').trim() || 'lobby';
  void router.push(`/workspace/call/${encodeURIComponent(safeRoomId)}`);
}

function loadFullCalendarGlobal() {
  if (typeof window === 'undefined') return Promise.resolve(null);
  if (window.FullCalendar && typeof window.FullCalendar.Calendar === 'function') {
    return Promise.resolve(window.FullCalendar);
  }

  if (!fullCalendarScriptPromise) {
    fullCalendarScriptPromise = new Promise((resolve, reject) => {
      const existing = document.querySelector('script[data-fullcalendar-global="true"]');
      if (existing instanceof HTMLScriptElement) {
        existing.addEventListener('load', () => resolve(window.FullCalendar || null), { once: true });
        existing.addEventListener('error', () => reject(new Error('Could not load FullCalendar.')), { once: true });
        return;
      }

      const script = document.createElement('script');
      script.src = FULLCALENDAR_SCRIPT_URL;
      script.async = true;
      script.dataset.fullcalendarGlobal = 'true';
      script.addEventListener('load', () => {
        if (window.FullCalendar && typeof window.FullCalendar.Calendar === 'function') {
          resolve(window.FullCalendar);
          return;
        }
        reject(new Error('FullCalendar global runtime is unavailable.'));
      }, { once: true });
      script.addEventListener('error', () => reject(new Error('Could not load FullCalendar.')), { once: true });
      document.head.appendChild(script);
    });
  }

  return fullCalendarScriptPromise;
}

async function initOverviewCalendar() {
  if (!(overviewCalendarEl.value instanceof HTMLElement) || calendarInstance) return;
  const fullCalendar = await loadFullCalendarGlobal().catch(() => null);
  if (!fullCalendar || !(overviewCalendarEl.value instanceof HTMLElement) || calendarInstance) return;

  calendarInstance = new fullCalendar.Calendar(overviewCalendarEl.value, {
    initialView: 'dayGridMonth',
    headerToolbar: {
      left: 'prev,next today',
      center: 'title',
      right: 'dayGridMonth,timeGridWeek,timeGridDay',
    },
    eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
    selectable: false,
    editable: false,
    events: [
      { title: 'Sales Standup', start: '2026-04-13T09:00:00', end: '2026-04-13T09:30:00' },
      { title: 'Backend Sync', start: '2026-04-13T10:00:00', end: '2026-04-13T11:00:00' },
      { title: 'Incident Bridge', start: '2026-04-13T11:30:00', end: '2026-04-13T12:30:00' },
    ],
    dateClick(info) {
      const now = Date.now();
      const dateKey = info.dateStr;
      const isDoubleClick = dateKey === lastDateKey && now - lastDateClickAt < 360;
      lastDateKey = dateKey;
      lastDateClickAt = now;

      if (!isDoubleClick) return;
      const start = info.date instanceof Date ? info.date : new Date(info.dateStr);
      const end = new Date(start.getTime() + 45 * 60 * 1000);
      calendarInstance?.addEvent({
        title: 'New Video Call',
        start,
        end,
        allDay: false,
      });
    },
  });

  calendarInstance.render();
}

onMounted(() => {
  void initOverviewCalendar();
});

onBeforeUnmount(() => {
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
</script>

<style scoped>
.admin-overview-view {
  min-height: 0;
  display: grid;
  grid-template-rows: auto minmax(0, 1fr);
  gap: 1px;
  background: var(--bg-main);
}

.overview-toolbar {
  padding: 10px;
  background: var(--bg-surface);
}

.view-tabs {
  display: inline-flex;
  gap: 6px;
  flex-wrap: nowrap;
}

.view-tab {
  height: 36px;
  min-width: 138px;
  border: 0;
  border-radius: 6px;
  background: var(--bg-action);
  color: var(--text-main);
  font-weight: 700;
  cursor: pointer;
}

.view-tab:hover {
  background: var(--bg-action-hover);
}

.view-tab.active {
  background: var(--bg-row);
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
}

#overviewCalendar {
  background: var(--bg-surface);
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  padding: 10px;
}

.calendar-help {
  margin: 0;
  padding: 0 2px;
  font-size: 12px;
  color: var(--text-muted);
}

.my-calls-panel {
  padding: 10px;
  background: var(--bg-main);
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
}

@media (max-width: 760px) {
  .view-tabs {
    width: 100%;
    display: grid;
    grid-template-columns: 1fr;
  }

  .view-tab {
    width: 100%;
  }
}
</style>
