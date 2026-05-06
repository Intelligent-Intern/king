<template src="./CallsView.template.html"></template>

<script setup>
import { computed, inject, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { Calendar } from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import interactionPlugin from '@fullcalendar/interaction';
import AppPagination from '../../../components/AppPagination.vue';
import AppSelect from '../../../components/AppSelect.vue';
import BackgroundPipelineDebugPanel from '../../realtime/background/BackgroundPipelineDebugPanel.vue';
import AppointmentConfigPanel from '../appointment/AppointmentConfigPanel.vue';
import ChatArchiveModal from '../components/ChatArchiveModal.vue';
import CallsListTable from '../components/ListTable.vue';
import {
  createCallListStore,
  createChatArchiveStore,
  createNoticeStore,
} from '../dashboard/viewState';
import { sessionState } from '../../auth/session';
import { currentBackendOrigin, fetchBackend } from '../../../support/backendFetch';
import { formatDateRangeDisplay, formatDateTimeDisplay, fullCalendarEventTimeFormat } from '../../../support/dateTimeFormat';
import { createAdminSyncSocket } from '../../../support/adminSyncSocket';
import { t } from '../../../modules/localization/i18nRuntime.js';
import {
  applyCallBackgroundPreset,
  callMediaPrefs,
  isCallBackgroundPresetActive,
  setCallCameraDevice,
  setCallMicrophoneDevice,
  setCallMicrophoneVolume,
  setCallSpeakerDevice,
  setCallSpeakerVolume,
} from '../../realtime/media/preferences';
import { createCancelDeleteController } from './cancelDelete';
import { createComposeController } from './compose';
import { createEnterCallController, normalizeCallAccessMode } from './enterCall';

const router = useRouter();
const applyBackgroundPreset = applyCallBackgroundPreset;
const isBackgroundPresetActive = isCallBackgroundPresetActive;
const workspaceSidebarState = inject('workspaceSidebarState', null);

function activeBackgroundPreset() {
  if (isBackgroundPresetActive('image')) return 'image';
  if (isBackgroundPresetActive('green')) return 'green';
  if (isBackgroundPresetActive('strong')) return 'strong';
  if (isBackgroundPresetActive('light')) return 'light';
  return 'off';
}

const callsCalendarEl = ref(null);
let calendarInstance = null;
let calendarRootEl = null;
let lastCalendarDateClickAt = 0;
let lastCalendarDateKey = '';

function requestHeaders(withBody = false) {
  const headers = { accept: 'application/json' };
  if (withBody) {
    headers['content-type'] = 'application/json';
  }

  const token = String(sessionState.sessionToken || '').trim();
  if (token !== '') {
    headers.authorization = `Bearer ${token}`;
  }

  return headers;
}

function extractErrorMessage(payload, fallback) {
  if (payload && typeof payload === 'object') {
    const message = payload?.error?.message;
    if (typeof message === 'string' && message.trim() !== '') {
      return message.trim();
    }
  }

  return fallback;
}

async function apiRequest(path, { method = 'GET', query = null, body = null } = {}) {
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
    throw new Error(extractErrorMessage(payload, `Request failed (${response.status}).`));
  }

  if (!payload || payload.status !== 'ok') {
    throw new Error('Backend returned an invalid payload.');
  }

  return payload;
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

function formatDateTime(isoValue) {
  return formatDateTimeDisplay(isoValue, {
    dateFormat: sessionState.dateFormat,
    timeFormat: sessionState.timeFormat,
    fallback: 'n/a',
  });
}

function formatRange(startsAt, endsAt) {
  return formatDateRangeDisplay(startsAt, endsAt, {
    dateFormat: sessionState.dateFormat,
    timeFormat: sessionState.timeFormat,
    separator: ' → ',
    fallback: 'n/a',
  });
}

function statusTagClass(status) {
  const normalized = String(status || '').toLowerCase();
  if (normalized === 'scheduled' || normalized === 'active') return 'ok';
  if (normalized === 'ended') return 'warn';
  if (normalized === 'cancelled') return 'danger';
  return 'warn';
}

const showInlineSidebarButton = computed(() => {
  const collapsed = Boolean(workspaceSidebarState?.leftSidebarCollapsed?.value);
  const isTablet = Boolean(workspaceSidebarState?.isTabletViewport?.value);
  const isMobile = Boolean(workspaceSidebarState?.isMobileViewport?.value);
  return collapsed && !isTablet && !isMobile;
});

function showLeftSidebarFromHeader() {
  if (typeof workspaceSidebarState?.showLeftSidebar === 'function') {
    workspaceSidebarState.showLeftSidebar();
  }
}

function isEditable(call) {
  const status = String(call?.status || '').toLowerCase();
  return status !== 'cancelled' && status !== 'ended';
}

function isCancellable(call) {
  const status = String(call?.status || '').toLowerCase();
  return status !== 'cancelled' && status !== 'ended';
}

function isDeletable(call) {
  return Boolean(call?.id);
}

function isInvitable(call) {
  const status = String(call?.status || '').toLowerCase();
  return status !== 'cancelled' && status !== 'ended';
}

const callListStore = createCallListStore({ defaultScope: 'all' });
const {
  viewMode,
  queryDraft,
  queryApplied,
  statusFilter,
  scopeFilter,
  calls,
  loadingCalls,
  callsError,
  pagination,
  calendarCalls,
  loadingCalendar,
  calendarError,
} = callListStore;
const primaryActionLabel = computed(() => (viewMode.value === 'calendar'
  ? t('calls.admin.schedule_video_call')
  : t('calls.admin.new_video_call')));
const deleteAllCallsBusy = ref(false);
const canDeleteAllCalls = computed(() => !deleteAllCallsBusy.value && !loadingCalls.value);

function openPrimaryCompose() {
  openCompose(viewMode.value === 'calendar' ? 'schedule' : 'create');
}

const {
  noticeKind,
  noticeMessage,
  noticeKindClass,
  setNotice,
  clearNotice,
} = createNoticeStore();
const chatArchiveStore = createChatArchiveStore();
const chatArchiveState = chatArchiveStore.state;
const { openChatArchive, closeChatArchive } = chatArchiveStore;

let adminSyncReloadTimer = 0;
let adminSyncClient = null;

function clearAdminSyncReloadTimer() {
  if (adminSyncReloadTimer > 0) {
    window.clearTimeout(adminSyncReloadTimer);
    adminSyncReloadTimer = 0;
  }
}

async function reloadCallsFromAdminSync() {
  await Promise.all([
    loadCalls(),
    loadCalendar({ background: true }),
  ]);
}

function queueReloadCallsFromAdminSync() {
  if (adminSyncReloadTimer > 0) return;
  adminSyncReloadTimer = window.setTimeout(() => {
    adminSyncReloadTimer = 0;
    void reloadCallsFromAdminSync();
  }, 120);
}

function publishAdminSync(topic, reason) {
  if (!adminSyncClient) return;
  adminSyncClient.publish(topic, reason);
}

function handleAdminSyncEvent(payload) {
  const sourceSessionId = String(payload?.source_session_id || '').trim();
  const ownSessionId = String(sessionState.sessionId || sessionState.sessionToken || '').trim();
  if (sourceSessionId !== '' && sourceSessionId === ownSessionId) {
    return;
  }

  const topic = String(payload?.topic || '').trim().toLowerCase();
  if (!['all', 'calls', 'overview'].includes(topic)) {
    return;
  }

  queueReloadCallsFromAdminSync();
}

function startAdminSyncSocket() {
  if (adminSyncClient) {
    adminSyncClient.disconnect();
    adminSyncClient = null;
  }

  adminSyncClient = createAdminSyncSocket({
    getSessionToken: () => String(sessionState.sessionToken || '').trim(),
    onSync: handleAdminSyncEvent,
  });
  adminSyncClient.connect();
}

function stopAdminSyncSocket() {
  if (!adminSyncClient) return;
  adminSyncClient.disconnect();
  adminSyncClient = null;
}

async function loadCalls() {
  loadingCalls.value = true;
  callsError.value = '';

  try {
    const payload = await apiRequest('/api/calls', {
      query: {
        scope: scopeFilter.value,
        status: statusFilter.value,
        query: queryApplied.value,
        page: pagination.page,
        page_size: pagination.pageSize,
      },
    });

    calls.value = Array.isArray(payload.calls) ? payload.calls : [];
    callListStore.applyPagination(payload.pagination || {}, calls.value.length);
  } catch (error) {
    calls.value = [];
    callsError.value = error instanceof Error ? error.message : 'Could not load calls.';
    callListStore.resetPagination();
  } finally {
    loadingCalls.value = false;
  }
}

async function loadCalendar({ background = false } = {}) {
  const hasVisibleCalendarData = Array.isArray(calendarCalls.value) && calendarCalls.value.length > 0;
  const useBlockingLoadingState = !background || !hasVisibleCalendarData;

  if (useBlockingLoadingState) {
    loadingCalendar.value = true;
    calendarError.value = '';
  }

  try {
    const payload = await apiRequest('/api/calls', {
      query: {
        scope: scopeFilter.value,
        status: statusFilter.value,
        query: queryApplied.value,
        page: 1,
        page_size: 100,
      },
    });

    calendarCalls.value = Array.isArray(payload.calls) ? payload.calls : [];
    calendarError.value = '';
  } catch (error) {
    if (useBlockingLoadingState) {
      calendarCalls.value = [];
      calendarError.value = error instanceof Error ? error.message : 'Could not load calendar calls.';
    }
  } finally {
    if (useBlockingLoadingState) {
      loadingCalendar.value = false;
    }
  }
}

async function deleteAllCalls() {
  if (!canDeleteAllCalls.value) return;
  const confirmed = window.confirm('Alle Video Calls wirklich löschen? Das entfernt auch Teilnehmer, Einladungen und Call-Verlauf.');
  if (!confirmed) return;

  clearNotice();
  deleteAllCallsBusy.value = true;
  try {
    const payload = await apiRequest('/api/calls', {
      method: 'DELETE',
      body: {
        confirm: 'delete_all_calls',
      },
    });
    const deletedCount = Math.max(0, Number(payload?.result?.deleted_count || 0));
    pagination.page = 1;
    setNotice('ok', deletedCount === 1 ? '1 call deleted.' : `${deletedCount} calls deleted.`);
    publishAdminSync('calls', 'all_calls_deleted');
    publishAdminSync('overview', 'all_calls_deleted');
    await Promise.all([loadCalls(), loadCalendar()]);
  } catch (error) {
    setNotice('error', error instanceof Error ? error.message : 'Could not delete all calls.');
  } finally {
    deleteAllCallsBusy.value = false;
  }
}

function toCalendarEvents() {
  const events = [];
  for (const call of calendarCalls.value) {
    const startsAt = new Date(String(call?.starts_at || ''));
    const endsAt = new Date(String(call?.ends_at || ''));
    if (Number.isNaN(startsAt.getTime()) || Number.isNaN(endsAt.getTime())) {
      continue;
    }

    events.push({
      id: String(call.id || ''),
      title: String(call.title || call.id || 'Video call'),
      start: startsAt,
      end: endsAt,
      allDay: false,
      editable: isEditable(call),
      extendedProps: {
        callPayload: call,
      },
    });
  }
  return events;
}

function syncCalendarEvents() {
  if (!calendarInstance) return;
  calendarInstance.removeAllEvents();
  for (const event of toCalendarEvents()) {
    calendarInstance.addEvent(event);
  }
}

function openComposeForCalendarDoubleClick(dateValue) {
  const start = dateValue instanceof Date ? new Date(dateValue.getTime()) : new Date();
  const end = new Date(start.getTime() + (45 * 60 * 1000));
  openCompose('schedule');
  composeState.startsLocal = isoToLocalInput(start.toISOString());
  composeState.endsLocal = isoToLocalInput(end.toISOString());
}

function openComposeForCalendarSelection(startValue, endValue) {
  const start = startValue instanceof Date ? startValue : new Date(startValue);
  const end = endValue instanceof Date ? endValue : new Date(endValue);
  if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return;
  openCompose('schedule');
  composeState.startsLocal = isoToLocalInput(start.toISOString());
  composeState.endsLocal = isoToLocalInput(end.toISOString());
}

function openComposeForCalendarEvent(eventApi) {
  const call = eventApi?.extendedProps?.callPayload;
  if (call && typeof call === 'object') {
    openCompose('edit', call);
  }
}

function resolveCalendarEventCall(eventApi) {
  const payloadCall = eventApi?.extendedProps?.callPayload;
  if (payloadCall && typeof payloadCall === 'object') {
    return payloadCall;
  }

  const callId = String(eventApi?.id || '').trim();
  if (callId === '') {
    return null;
  }

  for (const call of calendarCalls.value) {
    if (String(call?.id || '') === callId) {
      return call;
    }
  }

  return null;
}

async function persistCalendarEventWindow(eventApi, revert) {
  const call = resolveCalendarEventCall(eventApi);
  const callId = String(call?.id || eventApi?.id || '').trim();
  if (callId === '') {
    if (typeof revert === 'function') revert();
    setNotice('error', 'Could not update call schedule (missing call id).');
    return;
  }

  if (!isEditable(call)) {
    if (typeof revert === 'function') revert();
    setNotice('error', 'Only scheduled and active calls can be rescheduled.');
    return;
  }

  const startDate = eventApi?.start instanceof Date ? new Date(eventApi.start.getTime()) : null;
  let endDate = eventApi?.end instanceof Date ? new Date(eventApi.end.getTime()) : null;
  if (!(startDate instanceof Date) || Number.isNaN(startDate.getTime())) {
    if (typeof revert === 'function') revert();
    setNotice('error', 'Could not update call schedule (invalid start timestamp).');
    return;
  }

  if (!(endDate instanceof Date) || Number.isNaN(endDate.getTime())) {
    const fallbackStart = new Date(String(call?.starts_at || ''));
    const fallbackEnd = new Date(String(call?.ends_at || ''));
    if (!Number.isNaN(fallbackStart.getTime()) && !Number.isNaN(fallbackEnd.getTime()) && fallbackEnd.getTime() > fallbackStart.getTime()) {
      endDate = new Date(startDate.getTime() + (fallbackEnd.getTime() - fallbackStart.getTime()));
    }
  }

  if (!(endDate instanceof Date) || Number.isNaN(endDate.getTime()) || endDate.getTime() <= startDate.getTime()) {
    if (typeof revert === 'function') revert();
    setNotice('error', 'End timestamp must be after start timestamp.');
    return;
  }

  const startsAt = startDate.toISOString();
  const endsAt = endDate.toISOString();

  try {
    await apiRequest(`/api/calls/${encodeURIComponent(callId)}`, {
      method: 'PATCH',
      body: {
        starts_at: startsAt,
        ends_at: endsAt,
      },
    });

    if (call && typeof call === 'object') {
      call.starts_at = startsAt;
      call.ends_at = endsAt;
    }
    setNotice('ok', 'Call schedule updated.');
    publishAdminSync('calls', 'call_schedule_updated');
    await Promise.all([loadCalls(), loadCalendar({ background: true })]);
  } catch (error) {
    if (typeof revert === 'function') revert();
    setNotice('error', error instanceof Error ? error.message : 'Could not update call schedule.');
  }
}

function handleCalendarEventMoveOrResize(info) {
  if (!info || !info.event) return;
  const revert = typeof info.revert === 'function' ? info.revert : null;
  void persistCalendarEventWindow(info.event, revert);
}

async function initCallsCalendar() {
  if (!(callsCalendarEl.value instanceof HTMLElement)) return;
  if (calendarInstance && calendarRootEl !== callsCalendarEl.value) {
    calendarInstance.destroy();
    calendarInstance = null;
    calendarRootEl = null;
  }
  if (calendarInstance) return;
  try {
    calendarRootEl = callsCalendarEl.value;
    calendarInstance = new Calendar(callsCalendarEl.value, {
      plugins: [dayGridPlugin, timeGridPlugin, interactionPlugin],
      initialView: 'dayGridMonth',
      headerToolbar: {
        left: 'prev,next today',
        center: 'title',
        right: 'dayGridMonth,timeGridWeek,timeGridDay',
      },
      height: '100%',
      expandRows: true,
      eventTimeFormat: fullCalendarEventTimeFormat(sessionState.timeFormat),
      selectable: true,
      selectMirror: true,
      selectMinDistance: 1,
      editable: true,
      eventStartEditable: true,
      eventDurationEditable: true,
      eventResizableFromStart: true,
      events: [],
      dateClick(info) {
        const now = Date.now();
        const dateKey = `${String(info.view?.type || '')}:${info.dateStr}`;
        const isDoubleClick = dateKey === lastCalendarDateKey && now - lastCalendarDateClickAt < 360;
        lastCalendarDateKey = dateKey;
        lastCalendarDateClickAt = now;
        if (!isDoubleClick) return;
        openComposeForCalendarDoubleClick(info.date instanceof Date ? info.date : new Date(info.dateStr));
      },
      select(info) {
        const viewType = String(info.view?.type || '');
        if (!viewType.startsWith('timeGrid')) {
          calendarInstance?.unselect();
          return;
        }
        openComposeForCalendarSelection(info.start, info.end);
        calendarInstance?.unselect();
      },
      eventClick(info) {
        openComposeForCalendarEvent(info.event);
      },
      eventDrop(info) {
        handleCalendarEventMoveOrResize(info);
      },
      eventResize(info) {
        handleCalendarEventMoveOrResize(info);
      },
    });
    calendarInstance.render();
    syncCalendarEvents();
  } catch {
    calendarInstance = null;
    calendarRootEl = null;
    if (!calendarError.value) {
      calendarError.value = 'Could not load FullCalendar.';
    }
  }
}

function setViewMode(nextMode) {
  if (nextMode !== 'calls' && nextMode !== 'calendar' && nextMode !== 'personalCalendar') {
    return;
  }

  viewMode.value = nextMode;
  if (nextMode === 'calendar' && calendarCalls.value.length === 0 && !loadingCalendar.value) {
    void loadCalendar();
  }
}

async function ensureCalendarUiReady() {
  if (viewMode.value !== 'calendar') return;
  if (loadingCalendar.value || calendarError.value) return;
  await nextTick();
  await initCallsCalendar();
  if (!calendarInstance) return;
  await nextTick();
  calendarInstance.updateSize();
  syncCalendarEvents();
}

watch(viewMode, () => {
  void ensureCalendarUiReady();
});

watch(loadingCalendar, () => {
  void ensureCalendarUiReady();
});

watch(calendarError, () => {
  void ensureCalendarUiReady();
});

watch(calendarCalls, () => {
  syncCalendarEvents();
  void ensureCalendarUiReady();
});

async function applyFilters() {
  clearNotice();
  queryApplied.value = queryDraft.value.trim();
  pagination.page = 1;
  await Promise.all([loadCalls(), loadCalendar()]);
}

async function goToPage(nextPage) {
  if (!Number.isInteger(nextPage) || nextPage < 1 || nextPage === pagination.page) {
    return;
  }

  pagination.page = nextPage;
  await loadCalls();
}

const {
  enterCallPreviewVideoRef,
  enterCallPreviewPipelineDebug,
  enterCallState,
  closeEnterCallModal,
  openEnterCallModal,
  generateEnterCallLink,
  handleEnterLinkSettingsChanged,
  copyInviteCode,
  openCallWorkspace,
  playSpeakerTestSound,
  mountEnterCallPreview,
  unmountEnterCallPreview,
} = createEnterCallController({
  apiRequest,
  clearNotice,
  isInvitable,
  router,
  sessionState,
});

const {
  composeState,
  composeParticipants,
  composeExternalRows,
  shouldSendParticipants,
  composeHeadline,
  composeSubmitLabel,
  openCompose,
  closeCompose,
  handleReplaceParticipantsToggle,
  applyParticipantSearch,
  goToParticipantPage,
  isUserSelected,
  toggleUserSelection,
  addExternalRow,
  removeExternalRow,
  submitCompose,
} = createComposeController({
  apiRequest,
  clearNotice,
  setNotice,
  publishAdminSync,
  closeEnterCallModal,
  loadCalls,
  loadCalendar,
  openCallWorkspace,
  normalizeCallAccessMode,
  isoToLocalInput,
  localInputToIso,
  sessionState,
});

const {
  cancelTemplates,
  cancelEditorRef,
  cancelState,
  deleteState,
  openCancel,
  closeCancel,
  applyCancelTemplate,
  handleCancelEditorInput,
  execCancelEditorCommand,
  toggleCancelOverride,
  saveCancelTemplate,
  openDelete,
  closeDelete,
  submitCancel,
  submitDelete,
} = createCancelDeleteController({
  apiRequest,
  clearNotice,
  setNotice,
  publishAdminSync,
  loadCalls,
  loadCalendar,
  closeEnterCallModal,
  isDeletable,
});

function handleEscape(event) {
  if (event.key !== 'Escape') return;

  if (composeState.open) {
    closeCompose();
    return;
  }

  if (cancelState.open) {
    closeCancel();
    return;
  }

  if (deleteState.open) {
    closeDelete();
    return;
  }

  if (enterCallState.open) {
    closeEnterCallModal();
  }
}

onMounted(() => {
  mountEnterCallPreview();
  startAdminSyncSocket();
  window.addEventListener('keydown', handleEscape);

  void Promise.all([loadCalls(), loadCalendar()]);
});

onBeforeUnmount(() => {
  clearAdminSyncReloadTimer();
  stopAdminSyncSocket();
  window.removeEventListener('keydown', handleEscape);
  unmountEnterCallPreview();
  if (calendarInstance) {
    calendarInstance.destroy();
    calendarInstance = null;
    calendarRootEl = null;
  }
});

watch(
  () => sessionState.sessionToken,
  (nextValue, previousValue) => {
    const nextToken = String(nextValue || '').trim();
    const previousToken = String(previousValue || '').trim();
    if (nextToken === previousToken) return;
    if (!adminSyncClient) return;

    if (nextToken === '') {
      adminSyncClient.disconnect();
      return;
    }

    adminSyncClient.reconnect();
  }
);

watch(
  () => sessionState.timeFormat,
  () => {
    if (!calendarInstance) return;
    calendarInstance.setOption('eventTimeFormat', fullCalendarEventTimeFormat(sessionState.timeFormat));
  }
);
</script>

<style scoped src="./CallsView.css"></style>
<style scoped src="./CallsViewResponsive.css"></style>
