<template src="./UserDashboardView.template.html"></template>

<script setup>
import { computed, onBeforeUnmount, onMounted, watch } from 'vue';
import { useRouter } from 'vue-router';
import AppPagination from '../../../components/AppPagination.vue';
import AppSelect from '../../../components/AppSelect.vue';
import ChatArchiveModal from '../components/ChatArchiveModal.vue';
import CallsListTable from '../components/ListTable.vue';
import {
  createCallListStore,
  createChatArchiveStore,
  createNoticeStore,
} from './viewState';
import { sessionState } from '../../auth/session';
import { currentBackendOrigin, fetchBackend } from '../../../support/backendFetch';
import { createAdminSyncSocket } from '../../../support/adminSyncSocket';
import {
  compareDateTimeStrings,
  formatDateDisplay,
  formatDateRangeDisplay,
  formatDateTimeDisplay,
  formatWeekdayShort,
} from '../../../support/dateTimeFormat';
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
import { createDashboardComposeController } from './compose';
import { createDashboardEnterCallController } from './enterCall';
import { createJoinInviteController } from './joinInvite';

const router = useRouter();
const USER_CALL_CREATE_EVENT = 'king:user-calls:create';
const applyBackgroundPreset = applyCallBackgroundPreset;
const isBackgroundPresetActive = isCallBackgroundPresetActive;

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
    locale: sessionState.locale,
    dateFormat: sessionState.dateFormat,
    timeFormat: sessionState.timeFormat,
    separator: ' -> ',
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

function isOwnerCall(call) {
  return Number(call?.owner?.user_id || 0) === Number(sessionState.userId || 0);
}

function isParticipantCall(call) {
  if (isOwnerCall(call)) return true;
  return Boolean(call?.my_participation);
}

function isEditable(call) {
  const status = String(call?.status || '').toLowerCase();
  if (status === 'cancelled' || status === 'ended') {
    return false;
  }

  if (sessionState.role === 'admin') {
    return true;
  }

  return isOwnerCall(call);
}

function isCallWindowOpen(call) {
  const startsAt = Date.parse(String(call?.starts_at || ''));
  const endsAt = Date.parse(String(call?.ends_at || ''));
  if (!Number.isFinite(startsAt) || !Number.isFinite(endsAt)) {
    return false;
  }
  const now = Date.now();
  return startsAt <= now && now < endsAt;
}

function isInvitable(call) {
  const status = String(call?.status || '').toLowerCase();
  const isJoinableStatus = status === 'active' || (status === 'scheduled' && isCallWindowOpen(call));
  return isJoinableStatus && isParticipantCall(call);
}

const canReadAllScope = computed(() => sessionState.role === 'admin');

const callListStore = createCallListStore({ defaultScope: 'my' });
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
let fallbackRefreshTimer = 0;

function clearAdminSyncReloadTimer() {
  if (adminSyncReloadTimer > 0) {
    window.clearTimeout(adminSyncReloadTimer);
    adminSyncReloadTimer = 0;
  }
}

async function reloadCallsFromAdminSync() {
  await Promise.all([
    loadCalls(),
    loadCalendar(),
  ]);
}

function queueReloadCallsFromAdminSync() {
  if (adminSyncReloadTimer > 0) return;
  adminSyncReloadTimer = window.setTimeout(() => {
    adminSyncReloadTimer = 0;
    void reloadCallsFromAdminSync();
  }, 120);
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

function startFallbackRefreshLoop() {
  if (fallbackRefreshTimer > 0) {
    window.clearInterval(fallbackRefreshTimer);
    fallbackRefreshTimer = 0;
  }

  fallbackRefreshTimer = window.setInterval(() => {
    if (document.visibilityState === 'hidden') return;
    if (loadingCalls.value || loadingCalendar.value) return;
    void Promise.all([loadCalls(), loadCalendar()]);
  }, 5000);
}

function stopFallbackRefreshLoop() {
  if (fallbackRefreshTimer > 0) {
    window.clearInterval(fallbackRefreshTimer);
    fallbackRefreshTimer = 0;
  }
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

async function loadCalendar() {
  loadingCalendar.value = true;
  calendarError.value = '';

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
  } catch (error) {
    calendarCalls.value = [];
    calendarError.value = error instanceof Error ? error.message : 'Could not load calendar calls.';
  } finally {
    loadingCalendar.value = false;
  }
}

const calendarBuckets = computed(() => {
  const buckets = new Map();

  for (const call of calendarCalls.value) {
    const key = typeof call?.starts_at === 'string' && call.starts_at.length >= 10
      ? call.starts_at.slice(0, 10)
      : 'unscheduled';

    if (!buckets.has(key)) {
      buckets.set(key, []);
    }

    buckets.get(key).push(call);
  }

  const keys = Array.from(buckets.keys()).sort(compareDateTimeStrings);

  return keys.map((key) => {
    const rows = buckets.get(key).slice().sort((left, right) => {
      return compareDateTimeStrings(left?.starts_at, right?.starts_at);
    });

    let label = 'Unscheduled';
    if (key !== 'unscheduled') {
      const keyDate = new Date(`${key}T00:00:00`);
      if (!Number.isNaN(keyDate.getTime())) {
        const weekday = formatWeekdayShort(keyDate, { locale: sessionState.locale, fallback: '' });
        const dateLabel = formatDateDisplay(keyDate, {
          dateFormat: sessionState.dateFormat,
          fallback: key,
        });
        label = weekday !== '' ? `${weekday}, ${dateLabel}` : dateLabel;
      }
    }

    return { key, label, rows };
  });
});

function setViewMode(nextMode) {
  if (nextMode !== 'calls' && nextMode !== 'calendar') {
    return;
  }

  viewMode.value = nextMode;
  if (nextMode === 'calendar' && calendarCalls.value.length === 0 && !loadingCalendar.value) {
    void loadCalendar();
  }
}

async function applyFilters() {
  clearNotice();
  queryApplied.value = queryDraft.value.trim();
  if (!canReadAllScope.value && scopeFilter.value !== 'my') {
    scopeFilter.value = 'my';
  }
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
  enterCallState,
  closeEnterCallModal,
  openEnterCallModal,
  openRedeemedInvitePreview,
  copyInviteCode,
  openCallWorkspace,
  playSpeakerTestSound,
  mountEnterCallPreview,
  unmountEnterCallPreview,
} = createDashboardEnterCallController({
  clearNotice,
  isInvitable,
  router,
  sessionState,
});

const {
  joinState,
  openJoinModal,
  closeJoinModal,
  submitJoinInvite,
} = createJoinInviteController({
  apiRequest,
  clearNotice,
  setNotice,
  closeEnterCallModal,
  openRedeemedInvitePreview,
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
} = createDashboardComposeController({
  apiRequest,
  clearNotice,
  setNotice,
  closeEnterCallModal,
  loadCalls,
  loadCalendar,
  isoToLocalInput,
  localInputToIso,
  sessionState,
});

function handleShellCreateCall() {
  openCompose('create');
}

function handleEscape(event) {
  if (event.key !== 'Escape') return;

  if (joinState.open) {
    closeJoinModal();
    return;
  }

  if (composeState.open) {
    closeCompose();
    return;
  }

  if (enterCallState.open) {
    closeEnterCallModal();
  }
}

onMounted(() => {
  mountEnterCallPreview();
  startAdminSyncSocket();
  startFallbackRefreshLoop();
  window.addEventListener('keydown', handleEscape);
  window.addEventListener(USER_CALL_CREATE_EVENT, handleShellCreateCall);

  void Promise.all([loadCalls(), loadCalendar()]);
});

onBeforeUnmount(() => {
  clearAdminSyncReloadTimer();
  stopAdminSyncSocket();
  stopFallbackRefreshLoop();
  window.removeEventListener('keydown', handleEscape);
  window.removeEventListener(USER_CALL_CREATE_EVENT, handleShellCreateCall);
  unmountEnterCallPreview();
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
</script>

<style scoped src="./UserDashboardView.css"></style>
