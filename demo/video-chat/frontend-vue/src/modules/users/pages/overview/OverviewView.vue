<template src="./OverviewView.template.html"></template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { Calendar } from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import interactionPlugin from '@fullcalendar/interaction';
import { currentBackendOrigin, fetchBackend } from '../../../../support/backendFetch';
import { logoutSession, refreshSession, sessionState } from '../../../../domain/auth/session';
import { fullCalendarEventTimeFormat } from '../../../../support/dateTimeFormat';
import { t } from '../../../localization/i18nRuntime.js';
import { deriveStatus, generateRoomUuid, isoToLocalInput, localInputToIso, normalizeArray, normalizeNonNegativeInteger } from './helpers';
import { useOverviewDashboardMetrics } from './useOverviewDashboardMetrics';

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
  transport: {
    recent_frame_count: 0,
    matte_guided_frame_count: 0,
    avg_selection_tile_ratio: 0,
    avg_roi_area_ratio: 0,
    frame_kinds: [],
  },
  runningCalls: [],
});

const myCallsRows = ref([]);

const {
  clusterHealthRows,
  healthyNodesMetric,
  infrastructureStatusLabel,
  infrastructureStatusTagClass,
  infrastructureSubtitle,
  liveCallsMetric,
  nodesUnderLoadMetric,
  participantsMetric,
  providerRows,
  routingPolicyRows,
  runningCallsRows,
  transportAvgRoiMetric,
  transportAvgSelectionMetric,
  transportFrameKindRows,
  transportMatteGuidedMetric,
  transportRecentFramesMetric,
} = useOverviewDashboardMetrics({ infrastructureState, operationsState });

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
  composeState.mode === 'edit' ? t('calls.compose.headline_edit') : t('calls.compose.headline_schedule')
));

const composeSubmitLabel = computed(() => (
  composeState.mode === 'edit' ? t('common.save_changes') : t('calls.compose.submit_schedule')
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
      throw new Error(t('errors.api.backend_unreachable', { origin: currentBackendOrigin() }));
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
      throw new Error(t('errors.api.session_expired'));
    }
    throw new Error(extractErrorMessage(payload, t('errors.api.request_failed_status', { status: response.status })));
  }

  if (!payload || payload.status !== 'ok') {
    throw new Error(t('errors.api.invalid_payload'));
  }

  return payload;
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
    infrastructureState.error = error instanceof Error ? error.message : t('users.overview.load_infrastructure_failed');
  } finally {
    if (seq === infrastructureLoadSeq) {
      infrastructureState.loading = false;
    }
  }
}

function applyVideoOperationsPayload(payload) {
  const metrics = payload?.metrics && typeof payload.metrics === 'object' ? payload.metrics : {};
  const transport = payload?.transport && typeof payload.transport === 'object' ? payload.transport : {};
  operationsState.metrics = {
    live_calls: normalizeNonNegativeInteger(metrics.live_calls),
    concurrent_participants: normalizeNonNegativeInteger(metrics.concurrent_participants),
  };
  operationsState.transport = {
    recent_frame_count: normalizeNonNegativeInteger(transport.recent_frame_count),
    matte_guided_frame_count: normalizeNonNegativeInteger(transport.matte_guided_frame_count),
    avg_selection_tile_ratio: Number(transport.avg_selection_tile_ratio || 0),
    avg_roi_area_ratio: Number(transport.avg_roi_area_ratio || 0),
    frame_kinds: normalizeArray(transport.frame_kinds),
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
    operationsState.error = error instanceof Error ? error.message : t('users.overview.load_video_operations_failed');
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

function setActiveOverviewView(view) {
  if (view === 'dashboard' || view === 'calendar') {
    activeOverviewView.value = view;
  }
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
  title = '',
  roomUuid = '',
  startsAt,
  endsAt,
  internalParticipantUserIds = [],
  externalParticipants = [],
} = {}) {
  resetComposeModal();
  const fallbackTitle = t('calls.compose.headline_new');
  composeState.mode = mode === 'edit' ? 'edit' : 'schedule';
  composeState.open = true;
  composeState.calendarEventId = String(eventId || '');
  composeState.title = String(title || fallbackTitle).trim() || fallbackTitle;
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
      return { ok: false, error: t('calls.compose.external_row_required', { number: index + 1 }), rows: [] };
    }
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
      return { ok: false, error: t('calls.compose.external_row_email_invalid', { number: index + 1 }), rows: [] };
    }
    rows.push({ display_name: displayName, email });
  }
  return { ok: true, error: '', rows };
}

function upsertMyCallsRow(eventId, title, roomUuid, startsAtIso, endsAtIso, usersCount) {
  const status = deriveStatus(startsAtIso, endsAtIso);
  const nextRow = {
    id: String(eventId),
    title: String(title || t('calls.compose.headline_new')),
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
    composeState.error = t('calls.compose.title_required');
    return;
  }

  const startsAt = localInputToIso(composeState.startsLocal);
  const endsAt = localInputToIso(composeState.endsLocal);
  if (startsAt === '' || endsAt === '') {
    composeState.error = t('calls.compose.start_end_required');
    return;
  }

  if (new Date(endsAt).getTime() <= new Date(startsAt).getTime()) {
    composeState.error = t('calls.compose.end_after_start');
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

function overviewCalendarButtonText() {
  return {
    today: t('users.overview.calendar_today'),
    month: t('users.overview.fullcalendar_month'),
    week: t('users.overview.fullcalendar_week'),
    day: t('users.overview.fullcalendar_day'),
  };
}

async function initOverviewCalendar() {
  if (!(overviewCalendarEl.value instanceof HTMLElement) || calendarInstance) return;
  try {
    calendarInstance = new Calendar(overviewCalendarEl.value, {
      plugins: [dayGridPlugin, timeGridPlugin, interactionPlugin],
      initialView: 'dayGridMonth',
      locale: sessionState.locale || 'en',
      buttonText: overviewCalendarButtonText(),
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
  () => [sessionState.timeFormat, sessionState.locale],
  () => {
    if (!calendarInstance) return;
    calendarInstance.setOption('locale', sessionState.locale || 'en');
    calendarInstance.setOption('buttonText', overviewCalendarButtonText());
    calendarInstance.setOption('eventTimeFormat', fullCalendarEventTimeFormat(sessionState.timeFormat));
  }
);
</script>

<style scoped src="./OverviewView.css"></style>
