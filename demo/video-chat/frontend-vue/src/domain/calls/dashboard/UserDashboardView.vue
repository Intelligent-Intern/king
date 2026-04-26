<template src="./UserDashboardView.template.html"></template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import AppPagination from '../../../components/AppPagination.vue';
import AppSelect from '../../../components/AppSelect.vue';
import ChatArchiveModal from '../components/ChatArchiveModal.vue';
import CallsListTable from '../components/ListTable.vue';
import {
  createCallListStore,
  createChatArchiveStore,
  createNoticeStore,
  createParticipantDirectoryStore,
} from './viewState';
import { sessionState } from '../../auth/session';
import { currentBackendOrigin, fetchBackend } from '../../../support/backendFetch';
import {
  buildWebSocketUrl,
  resolveBackendWebSocketOriginCandidates,
  setBackendWebSocketOrigin,
} from '../../../support/backendOrigin';
import {
  appendAssetVersionQuery,
  handleAssetVersionSocketClose,
  handleAssetVersionSocketPayload,
} from '../../../support/assetVersion';
import { attachForegroundReconnectHandlers } from '../../../support/foregroundReconnect';
import { createAdminSyncSocket } from '../../../support/adminSyncSocket';
import {
  formatDateDisplay,
  formatDateRangeDisplay,
  formatDateTimeDisplay,
  formatWeekdayShort,
} from '../../../support/dateTimeFormat';
import {
  applyCallBackgroundPreset,
  attachCallMediaDeviceWatcher,
  callMediaPrefs,
  isCallBackgroundPresetActive,
  refreshCallMediaDevices,
  setCallCameraDevice,
  setCallMicrophoneDevice,
  setCallMicrophoneVolume,
  setCallSpeakerDevice,
  setCallSpeakerVolume,
} from '../../realtime/media/preferences';
import { BackgroundFilterController } from '../../realtime/background/controller';

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
let detachForegroundReconnect = null;

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

  const keys = Array.from(buckets.keys()).sort((a, b) => a.localeCompare(b));

  return keys.map((key) => {
    const rows = buckets.get(key).slice().sort((left, right) => {
      const leftStart = String(left?.starts_at || '');
      const rightStart = String(right?.starts_at || '');
      return leftStart.localeCompare(rightStart);
    });

    let label = 'Unscheduled';
    if (key !== 'unscheduled') {
      const keyDate = new Date(`${key}T00:00:00`);
      if (!Number.isNaN(keyDate.getTime())) {
        const weekday = formatWeekdayShort(keyDate, { fallback: '' });
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

const enterCallPreviewVideoRef = ref(null);
const enterCallPreviewRawStreamRef = ref(null);
const enterCallPreviewStreamRef = ref(null);
const enterCallPreviewBackgroundController = new BackgroundFilterController();
let detachCallMediaWatcher = null;
let enterAdmissionSocket = null;
let enterAdmissionSocketGeneration = 0;
let enterAdmissionAccepted = false;
let enterAdmissionManuallyClosed = false;
let enterAdmissionReconnectTimer = 0;
let enterAdmissionReconnectAttempt = 0;
let enterAdmissionReconnectAfterForeground = false;
let enterAdmissionLastForegroundReconnectAt = 0;

const ENTER_ADMISSION_WAIT_MESSAGE = 'Call owner has been notified.';
const ENTER_ADMISSION_RECONNECT_DELAYS_MS = [500, 1000, 2000, 3000, 5000];
const ENTER_ADMISSION_FOREGROUND_RECONNECT_DEBOUNCE_MS = 1500;

const enterCallState = reactive({
  open: false,
  loading: false,
  error: '',
  code: '',
  expiresAt: '',
  callId: '',
  roomId: '',
  copyNotice: '',
  previewReady: false,
  previewError: '',
  waitingForAdmission: false,
  admissionMessage: '',
});

function resetEnterCallState() {
  enterCallState.loading = false;
  enterCallState.error = '';
  enterCallState.code = '';
  enterCallState.expiresAt = '';
  enterCallState.callId = '';
  enterCallState.roomId = '';
  enterCallState.copyNotice = '';
  enterCallState.previewReady = false;
  enterCallState.previewError = '';
  enterCallState.waitingForAdmission = false;
  enterCallState.admissionMessage = '';
}

function normalizeRoomId(value) {
  const candidate = String(value || '').trim().toLowerCase();
  if (candidate === '' || candidate.length > 120) return 'lobby';
  return /^[a-z0-9._-]+$/.test(candidate) ? candidate : 'lobby';
}

function normalizeCallId(value) {
  const candidate = String(value || '').trim();
  if (candidate === '') return '';
  return /^[A-Za-z0-9._-]{1,200}$/.test(candidate) ? candidate : '';
}

function enterAdmissionSocketUrlForOrigin(origin) {
  const query = appendAssetVersionQuery(new URLSearchParams());
  query.set('room', normalizeRoomId(enterCallState.roomId || 'lobby'));

  const callId = normalizeCallId(enterCallState.callId);
  if (callId !== '') {
    query.set('call_id', callId);
  }

  const token = String(sessionState.sessionToken || '').trim();
  if (token !== '') {
    query.set('session', token);
  }

  return buildWebSocketUrl(origin, '/ws', query);
}

function clearEnterAdmissionReconnectTimer() {
  if (enterAdmissionReconnectTimer > 0 && typeof window !== 'undefined') {
    window.clearTimeout(enterAdmissionReconnectTimer);
  }
  enterAdmissionReconnectTimer = 0;
}

function enterAdmissionSocketIsOpen(socket = enterAdmissionSocket) {
  if (typeof WebSocket === 'undefined') return false;
  return socket instanceof WebSocket && socket.readyState === WebSocket.OPEN;
}

function sendEnterAdmissionFrame(payload) {
  if (!enterAdmissionSocketIsOpen()) return false;
  try {
    enterAdmissionSocket.send(JSON.stringify(payload));
    return true;
  } catch {
    return false;
  }
}

function retireEnterAdmissionSocket(closeReason = 'admission_reconnect') {
  const socket = enterAdmissionSocket;
  enterAdmissionSocket = null;
  if (typeof WebSocket !== 'undefined' && socket instanceof WebSocket) {
    try {
      socket.close(1000, closeReason);
    } catch {
      // ignore
    }
  }
}

function closeEnterAdmissionSocket({ cancel = false } = {}) {
  enterAdmissionManuallyClosed = true;
  clearEnterAdmissionReconnectTimer();

  if (cancel && enterCallState.waitingForAdmission && !enterAdmissionAccepted) {
    sendEnterAdmissionFrame({
      type: 'lobby/queue/cancel',
      room_id: normalizeRoomId(enterCallState.roomId || 'lobby'),
    });
  }

  retireEnterAdmissionSocket(cancel ? 'admission_cancelled' : 'admission_closed');
}

function scheduleEnterAdmissionReconnect() {
  clearEnterAdmissionReconnectTimer();
  if (enterAdmissionManuallyClosed || enterAdmissionAccepted || !enterCallState.waitingForAdmission) return;
  if (typeof window === 'undefined') return;

  enterAdmissionReconnectAttempt += 1;
  const delay = ENTER_ADMISSION_RECONNECT_DELAYS_MS[
    Math.min(enterAdmissionReconnectAttempt - 1, ENTER_ADMISSION_RECONNECT_DELAYS_MS.length - 1)
  ];
  enterCallState.admissionMessage = 'Reconnecting lobby connection...';
  enterAdmissionReconnectTimer = window.setTimeout(() => {
    enterAdmissionReconnectTimer = 0;
    connectEnterAdmissionSocket();
  }, delay);
}

function markEnterAdmissionReconnectAfterForeground() {
  if (!enterCallState.waitingForAdmission || enterAdmissionAccepted || enterAdmissionManuallyClosed) return;
  enterAdmissionReconnectAfterForeground = true;
}

function reconnectEnterAdmissionAfterForeground() {
  if (typeof document !== 'undefined' && document.visibilityState === 'hidden') return;
  if (!enterCallState.waitingForAdmission || enterAdmissionAccepted || enterAdmissionManuallyClosed) return;
  if (!enterAdmissionReconnectAfterForeground) return;

  const now = Date.now();
  if ((now - enterAdmissionLastForegroundReconnectAt) < ENTER_ADMISSION_FOREGROUND_RECONNECT_DEBOUNCE_MS) {
    return;
  }

  enterAdmissionReconnectAfterForeground = false;
  enterAdmissionLastForegroundReconnectAt = now;
  enterAdmissionReconnectAttempt = 0;
  clearEnterAdmissionReconnectTimer();
  enterCallState.admissionMessage = 'Reconnecting lobby connection...';
  connectEnterAdmissionSocket();
}

async function enterAdmittedCall() {
  const callRef = normalizeCallId(enterCallState.callId);
  if (callRef === '') {
    enterCallState.loading = false;
    enterCallState.waitingForAdmission = false;
    enterCallState.admissionMessage = '';
    enterCallState.error = 'Could not open the call because the call ID is missing.';
    return;
  }

  enterAdmissionAccepted = true;
  closeEnterAdmissionSocket({ cancel: false });
  enterCallState.open = false;
  enterCallState.loading = false;
  enterCallState.waitingForAdmission = false;
  enterCallState.admissionMessage = '';
  stopEnterCallPreview();
  resetEnterCallState();

  const target = router.resolve({
    name: 'call-workspace',
    params: { callRef },
    query: { entry: 'invite' },
  });
  if (typeof window !== 'undefined') {
    window.location.replace(target.href);
    return;
  }
  await router.replace(target.fullPath);
}

function handleEnterAdmissionLobbySnapshot(payload) {
  const admittedRows = Array.isArray(payload?.admitted) ? payload.admitted : [];
  const currentUserId = Number(sessionState.userId || 0);
  const isAdmitted = admittedRows.some((entry) => Number(entry?.user_id || 0) === currentUserId);
  if (!isAdmitted) return;

  void enterAdmittedCall();
}

function handleEnterAdmissionWelcome(payload) {
  const admission = payload && typeof payload === 'object' ? payload.admission : null;
  const requiresAdmission = Boolean(admission?.requires_admission);
  const pendingRoomId = normalizeRoomId(admission?.pending_room_id || enterCallState.roomId || 'lobby');
  enterCallState.roomId = pendingRoomId;

  if (!requiresAdmission) {
    void enterAdmittedCall();
    return;
  }

  if (!sendEnterAdmissionFrame({ type: 'lobby/queue/join', room_id: pendingRoomId })) {
    enterCallState.loading = false;
    enterCallState.waitingForAdmission = false;
    enterCallState.admissionMessage = '';
    enterCallState.error = 'Could not notify call owner because the lobby connection is offline.';
    return;
  }

  enterCallState.loading = false;
  enterCallState.waitingForAdmission = true;
  enterCallState.error = '';
  enterCallState.admissionMessage = ENTER_ADMISSION_WAIT_MESSAGE;
}

function handleEnterAdmissionSocketMessage(event) {
  let payload = null;
  try {
    payload = JSON.parse(String(event.data || ''));
  } catch {
    return;
  }

  if (!payload || typeof payload !== 'object') return;
  if (handleAssetVersionSocketPayload(payload)) return;
  const type = String(payload.type || '').trim().toLowerCase();
  if (type === 'system/welcome') {
    handleEnterAdmissionWelcome(payload);
    return;
  }

  if (type === 'lobby/snapshot') {
    handleEnterAdmissionLobbySnapshot(payload);
    return;
  }

  if (type === 'system/error') {
    const code = String(payload.code || '').trim().toLowerCase();
    if (code === 'lobby_command_failed') {
      enterCallState.error = 'Could not notify call owner.';
      enterCallState.admissionMessage = '';
      enterCallState.waitingForAdmission = false;
      enterCallState.loading = false;
    }
  }
}

function connectEnterAdmissionSocketWithOriginAt(candidates, originIndex, generation) {
  if (generation !== enterAdmissionSocketGeneration || enterAdmissionManuallyClosed || enterAdmissionAccepted) return;

  if (originIndex >= candidates.length) {
    if (enterCallState.waitingForAdmission) {
      scheduleEnterAdmissionReconnect();
    } else {
      enterCallState.loading = false;
      enterCallState.waitingForAdmission = false;
      enterCallState.admissionMessage = '';
      enterCallState.error = 'Could not connect to call lobby.';
    }
    return;
  }

  const socketOrigin = candidates[originIndex];
  const wsUrl = enterAdmissionSocketUrlForOrigin(socketOrigin);
  if (!wsUrl) {
    connectEnterAdmissionSocketWithOriginAt(candidates, originIndex + 1, generation);
    return;
  }

  const socket = new WebSocket(wsUrl);
  enterAdmissionSocket = socket;
  let opened = false;
  let failedOver = false;

  const failOverToNextOrigin = () => {
    if (failedOver) return;
    failedOver = true;
    if (enterAdmissionSocket === socket) {
      enterAdmissionSocket = null;
    }
    try {
      socket.close(1000, 'admission_failover');
    } catch {
      // ignore
    }
    connectEnterAdmissionSocketWithOriginAt(candidates, originIndex + 1, generation);
  };

  socket.addEventListener('open', () => {
    if (generation !== enterAdmissionSocketGeneration || enterAdmissionManuallyClosed || enterAdmissionAccepted) {
      try {
        socket.close(1000, 'stale_admission_socket');
      } catch {
        // ignore
      }
      return;
    }

    opened = true;
    enterAdmissionReconnectAttempt = 0;
    enterAdmissionReconnectAfterForeground = false;
    setBackendWebSocketOrigin(socketOrigin);
  });

  socket.addEventListener('message', (event) => {
    if (generation !== enterAdmissionSocketGeneration || enterAdmissionManuallyClosed || enterAdmissionAccepted) return;
    handleEnterAdmissionSocketMessage(event);
  });

  socket.addEventListener('error', () => {
    if (generation !== enterAdmissionSocketGeneration || enterAdmissionManuallyClosed || enterAdmissionAccepted) return;
    if (!opened) {
      failOverToNextOrigin();
      return;
    }
    enterCallState.admissionMessage = 'Reconnecting lobby connection...';
  });

  socket.addEventListener('close', (event) => {
    if (generation !== enterAdmissionSocketGeneration) return;
    if (enterAdmissionSocket === socket) {
      enterAdmissionSocket = null;
    }
    if (enterAdmissionManuallyClosed || enterAdmissionAccepted) return;
    if (handleAssetVersionSocketClose(event)) return;

    if (!opened) {
      failOverToNextOrigin();
      return;
    }

    scheduleEnterAdmissionReconnect();
  });
}

function connectEnterAdmissionSocket() {
  retireEnterAdmissionSocket('admission_reconnect');
  const candidates = resolveBackendWebSocketOriginCandidates();
  connectEnterAdmissionSocketWithOriginAt(candidates, 0, enterAdmissionSocketGeneration);
}

function startEnterAdmissionWait(target = null) {
  if (typeof WebSocket === 'undefined') {
    enterCallState.loading = false;
    enterCallState.error = 'Realtime lobby is not supported in this browser.';
    return false;
  }

  const normalizedTarget = target && typeof target === 'object' ? target : {};
  enterCallState.callId = normalizeCallId(normalizedTarget.callId || enterCallState.callId);
  enterCallState.roomId = normalizeRoomId(normalizedTarget.roomId || enterCallState.roomId || 'lobby');
  enterCallState.error = '';
  enterCallState.loading = true;
  enterCallState.waitingForAdmission = true;
  enterCallState.admissionMessage = 'Connecting lobby connection...';

  closeEnterAdmissionSocket({ cancel: false });
  enterAdmissionAccepted = false;
  enterAdmissionManuallyClosed = false;
  enterAdmissionReconnectAttempt = 0;
  enterAdmissionReconnectAfterForeground = false;
  enterAdmissionSocketGeneration += 1;
  connectEnterAdmissionSocket();
  return true;
}

function resolvePreviewBackgroundFilterOptions() {
  const toFiniteNumber = (value, fallback) => {
    const numeric = Number(value);
    return Number.isFinite(numeric) ? numeric : fallback;
  };
  const mode = String(callMediaPrefs.backgroundFilterMode || 'off').trim().toLowerCase() === 'blur'
    ? 'blur'
    : 'off';
  const applyOutgoing = Boolean(callMediaPrefs.backgroundApplyOutgoing);
  if (!applyOutgoing || mode !== 'blur') {
    return { mode: 'off' };
  }

  const backdrop = String(callMediaPrefs.backgroundBackdropMode || 'blur7').trim().toLowerCase();
  const qualityProfile = String(callMediaPrefs.backgroundQualityProfile || 'balanced').trim().toLowerCase();
  const baseBlurLevel = Math.max(0, Math.min(4, Math.round(toFiniteNumber(callMediaPrefs.backgroundBlurStrength, 2))));
  const blurStepPx = [1, 2, 3, 4, 5];
  let blurPx = blurStepPx[baseBlurLevel] ?? 3;
  if (backdrop === 'blur9') {
    blurPx = Math.round(blurPx * 1.35);
  }
  blurPx = Math.max(1, Math.min(12, blurPx));

  let detectIntervalMs = 150;
  if (qualityProfile === 'quality') {
    detectIntervalMs = 110;
  } else if (qualityProfile === 'realtime') {
    detectIntervalMs = 190;
  }

  let temporalSmoothingAlpha = 0.28;
  if (qualityProfile === 'quality') {
    temporalSmoothingAlpha = 0.22;
  } else if (qualityProfile === 'realtime') {
    temporalSmoothingAlpha = 0.38;
  }

  const maskVariant = Math.max(1, Math.min(10, Math.round(toFiniteNumber(callMediaPrefs.backgroundMaskVariant, 4))));
  const transitionGain = Math.max(1, Math.min(10, Math.round(toFiniteNumber(callMediaPrefs.backgroundBlurTransition, 10))));
  const requestedProcessWidth = Math.max(320, Math.min(1920, Math.round(toFiniteNumber(callMediaPrefs.backgroundMaxProcessWidth, 960))));
  const requestedProcessFps = Math.max(8, Math.min(30, Math.round(toFiniteNumber(callMediaPrefs.backgroundMaxProcessFps, 24))));
  let processWidthCap = 720;
  let processFpsCap = 15;
  if (qualityProfile === 'quality') {
    processWidthCap = 960;
    processFpsCap = 24;
  } else if (qualityProfile === 'realtime') {
    processWidthCap = 640;
    processFpsCap = 12;
  }

  return {
    mode,
    blurPx,
    detectIntervalMs,
    temporalSmoothingAlpha,
    preferFastMatte: qualityProfile !== 'quality',
    maskVariant,
    transitionGain,
    maxProcessWidth: Math.max(320, Math.min(processWidthCap, requestedProcessWidth)),
    maxProcessFps: Math.max(8, Math.min(processFpsCap, requestedProcessFps)),
    autoDisableOnOverload: false,
  };
}

function stopEnterCallPreview() {
  enterCallPreviewBackgroundController.dispose();

  const previewNode = enterCallPreviewVideoRef.value;
  if (previewNode instanceof HTMLVideoElement) {
    try {
      previewNode.pause();
    } catch {
      // ignore
    }
    previewNode.srcObject = null;
  }

  const rawStream = enterCallPreviewRawStreamRef.value;
  if (rawStream instanceof MediaStream) {
    for (const track of rawStream.getTracks()) {
      track.stop();
    }
  }
  enterCallPreviewRawStreamRef.value = null;

  const stream = enterCallPreviewStreamRef.value;
  if (stream instanceof MediaStream) {
    for (const track of stream.getTracks()) {
      track.stop();
    }
  }
  enterCallPreviewStreamRef.value = null;
  enterCallState.previewReady = false;
}

function buildPreviewConstraints() {
  const cameraDeviceId = String(callMediaPrefs.selectedCameraId || '').trim();
  const microphoneDeviceId = String(callMediaPrefs.selectedMicrophoneId || '').trim();

  const video = cameraDeviceId === '' ? true : { deviceId: { exact: cameraDeviceId } };
  const audio = microphoneDeviceId === '' ? true : { deviceId: { exact: microphoneDeviceId } };

  return { video, audio };
}

async function startEnterCallPreview() {
  stopEnterCallPreview();
  enterCallState.previewReady = false;
  enterCallState.previewError = '';

  if (
    typeof navigator === 'undefined'
    || !navigator.mediaDevices
    || typeof navigator.mediaDevices.getUserMedia !== 'function'
  ) {
    enterCallState.previewError = 'Camera preview is not supported in this browser.';
    return;
  }

  try {
    const rawStream = await navigator.mediaDevices.getUserMedia(buildPreviewConstraints());
    enterCallPreviewRawStreamRef.value = rawStream;
    const volume = Math.max(0, Math.min(100, Number(callMediaPrefs.microphoneVolume || 100))) / 100;
    for (const track of rawStream.getAudioTracks()) {
      if (typeof track.applyConstraints === 'function') {
        track.applyConstraints({ volume }).catch(() => {});
      }
    }

    let previewStream = rawStream;
    const backgroundOptions = resolvePreviewBackgroundFilterOptions();
    if (backgroundOptions.mode === 'blur') {
      try {
        const result = await enterCallPreviewBackgroundController.apply(rawStream, backgroundOptions);
        if (result?.stream instanceof MediaStream) {
          previewStream = result.stream;
        }
      } catch {
        previewStream = rawStream;
      }
    }
    enterCallPreviewStreamRef.value = previewStream;

    await nextTick();
    const previewNode = enterCallPreviewVideoRef.value;
    if (!(previewNode instanceof HTMLVideoElement)) {
      return;
    }

    previewNode.muted = true;
    previewNode.srcObject = previewStream;
    await previewNode.play().catch(() => {});
    enterCallState.previewReady = true;
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Could not start camera preview.';
    enterCallState.previewError = message || 'Could not start camera preview.';
  }
}

async function playSpeakerTestSound() {
  if (typeof window === 'undefined') return;
  const AudioContextCtor = window.AudioContext || window.webkitAudioContext;
  if (!AudioContextCtor) return;

  let context = null;
  const audio = new Audio();
  try {
    context = new AudioContextCtor();
    const destination = context.createMediaStreamDestination();
    const oscillator = context.createOscillator();
    const gainNode = context.createGain();
    const normalizedVolume = Math.max(0, Math.min(100, Number(callMediaPrefs.speakerVolume || 100))) / 100;

    oscillator.type = 'sine';
    oscillator.frequency.value = 880;
    gainNode.gain.value = Math.max(0.01, normalizedVolume * 0.45);
    oscillator.connect(gainNode);
    gainNode.connect(destination);

    audio.srcObject = destination.stream;
    audio.playsInline = true;
    audio.muted = false;
    audio.volume = 1;

    const speakerDeviceId = String(callMediaPrefs.selectedSpeakerId || '').trim();
    if (speakerDeviceId !== '' && typeof audio.setSinkId === 'function') {
      await audio.setSinkId(speakerDeviceId).catch(() => {});
    }

    await audio.play();
    oscillator.start();
    oscillator.stop(context.currentTime + 0.22);
    await new Promise((resolve) => setTimeout(resolve, 260));
  } catch {
    // ignore
  } finally {
    try {
      audio.pause();
    } catch {
      // ignore
    }
    audio.srcObject = null;
    if (context && typeof context.close === 'function') {
      await context.close().catch(() => {});
    }
  }
}

function closeEnterCallModal() {
  closeEnterAdmissionSocket({
    cancel: enterCallState.waitingForAdmission && !enterAdmissionAccepted,
  });
  enterCallState.open = false;
  resetEnterCallState();
  stopEnterCallPreview();
}

async function openEnterCallModal(call) {
  if (!call || !call.id || !isInvitable(call)) {
    return;
  }

  clearNotice();
  enterCallState.open = true;
  enterCallState.loading = true;
  enterCallState.error = '';
  enterCallState.code = '';
  enterCallState.expiresAt = '';
  enterCallState.callId = String(call.id);
  enterCallState.roomId = String(call.room_id || 'lobby');
  enterCallState.copyNotice = '';
  enterCallState.waitingForAdmission = false;
  enterCallState.admissionMessage = '';
  closeEnterAdmissionSocket({ cancel: false });

  try {
    await refreshCallMediaDevices({ requestPermissions: true });
    await startEnterCallPreview();
  } finally {
    enterCallState.loading = false;
  }
}

async function copyInviteCode() {
  const code = String(enterCallState.code || '').trim();
  if (code === '') {
    return;
  }

  try {
    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
      await navigator.clipboard.writeText(code);
    } else {
      const textarea = document.createElement('textarea');
      textarea.value = code;
      textarea.setAttribute('readonly', 'readonly');
      textarea.style.position = 'fixed';
      textarea.style.top = '-1000px';
      document.body.appendChild(textarea);
      textarea.select();
      document.execCommand('copy');
      document.body.removeChild(textarea);
    }

    enterCallState.copyNotice = 'Copied.';
  } catch {
    enterCallState.copyNotice = 'Copy failed.';
  }
}

function resolveJoinTarget(joinContext) {
  const callAccess = joinContext?.call_access;
  const accessId = String(callAccess?.id || '').trim();
  const callId = String(joinContext?.call?.id || '').trim();
  const roomId = String(joinContext?.room?.id || joinContext?.call?.room_id || '').trim();
  return {
    accessId,
    callId,
    roomId: roomId === '' ? 'lobby' : roomId,
  };
}

async function openCallWorkspace(target = null) {
  const normalizedTarget = target && typeof target === 'object' ? target : {};
  enterCallState.callId = normalizeCallId(normalizedTarget.callId || enterCallState.callId);
  enterCallState.roomId = normalizeRoomId(normalizedTarget.roomId || enterCallState.roomId || 'lobby');
  startEnterAdmissionWait({
    callId: enterCallState.callId,
    roomId: enterCallState.roomId,
  });
}

watch(
  () => [callMediaPrefs.selectedCameraId, callMediaPrefs.selectedMicrophoneId],
  () => {
    if (!enterCallState.open) return;
    void startEnterCallPreview();
  },
);

watch(
  () => [
    callMediaPrefs.backgroundFilterMode,
    callMediaPrefs.backgroundBackdropMode,
    callMediaPrefs.backgroundQualityProfile,
    callMediaPrefs.backgroundBlurStrength,
    callMediaPrefs.backgroundApplyOutgoing,
    callMediaPrefs.backgroundMaskVariant,
    callMediaPrefs.backgroundBlurTransition,
    callMediaPrefs.backgroundMaxProcessWidth,
    callMediaPrefs.backgroundMaxProcessFps,
  ],
  () => {
    if (!enterCallState.open) return;
    void startEnterCallPreview();
  },
);

watch(
  () => callMediaPrefs.microphoneVolume,
  () => {
    const stream = enterCallPreviewStreamRef.value;
    if (!(stream instanceof MediaStream)) return;
    const volume = Math.max(0, Math.min(100, Number(callMediaPrefs.microphoneVolume || 100))) / 100;
    for (const track of stream.getAudioTracks()) {
      if (typeof track.applyConstraints === 'function') {
        track.applyConstraints({ volume }).catch(() => {});
      }
    }
  },
);

const joinState = reactive({
  open: false,
  submitting: false,
  error: '',
  code: '',
});

function openJoinModal() {
  clearNotice();
  closeEnterCallModal();
  joinState.open = true;
  joinState.submitting = false;
  joinState.error = '';
  joinState.code = '';
}

function closeJoinModal() {
  joinState.open = false;
  joinState.submitting = false;
  joinState.error = '';
}

async function submitJoinInvite() {
  clearNotice();
  joinState.error = '';

  const code = String(joinState.code || '').trim();
  if (code === '') {
    joinState.error = 'Invite code is required.';
    return;
  }

  joinState.submitting = true;

  try {
    const payload = await apiRequest('/api/invite-codes/redeem', {
      method: 'POST',
      body: {
        code,
      },
    });

    const redemption = payload?.result?.redemption || {};
    const joinContext = redemption?.join_context || {};
    const joinTarget = resolveJoinTarget(joinContext);
    const scope = String(joinContext?.scope || '');

    closeJoinModal();
    setNotice('ok', `Invite redeemed for ${scope || 'invite'} context.`);
    enterCallState.open = true;
    enterCallState.loading = true;
    enterCallState.error = '';
    enterCallState.callId = normalizeCallId(joinTarget.callId || '');
    enterCallState.roomId = normalizeRoomId(joinTarget.roomId || 'lobby');
    enterCallState.copyNotice = '';
    enterCallState.waitingForAdmission = false;
    enterCallState.admissionMessage = '';
    closeEnterAdmissionSocket({ cancel: false });
    try {
      await refreshCallMediaDevices({ requestPermissions: true });
      await startEnterCallPreview();
    } finally {
      enterCallState.loading = false;
    }
  } catch (error) {
    joinState.error = error instanceof Error ? error.message : 'Could not redeem invite code.';
  } finally {
    joinState.submitting = false;
  }
}

const composeState = reactive({
  open: false,
  mode: 'create',
  callId: '',
  title: '',
  accessMode: 'invite_only',
  startsLocal: '',
  endsLocal: '',
  replaceParticipants: false,
  participantsReady: false,
  submitting: false,
  error: '',
});

const composeParticipantStore = createParticipantDirectoryStore();
const composeParticipants = composeParticipantStore.state;

const composeSelectedUserIds = ref([]);
const composeExternalRows = ref([]);
let composeExternalRowId = 0;

const composeHeadline = computed(() => {
  if (composeState.mode === 'edit') return 'Edit video call';
  if (composeState.mode === 'schedule') return 'Schedule video call';
  return 'New video call';
});

const composeSubmitLabel = computed(() => {
  if (composeState.mode === 'edit') return 'Save changes';
  if (composeState.mode === 'schedule') return 'Schedule call';
  return 'Create call';
});

const shouldSendParticipants = computed(
  () => composeState.mode !== 'edit' || composeState.replaceParticipants,
);

function currentSessionUserId() {
  const id = Number(sessionState.userId || 0);
  return Number.isInteger(id) && id > 0 ? id : 0;
}

function normalizedInternalParticipantUserIds() {
  const ownUserId = currentSessionUserId();
  const seen = new Set();
  const ids = [];
  for (const rawId of composeSelectedUserIds.value) {
    const id = Number(rawId);
    if (!Number.isInteger(id) || id <= 0 || id === ownUserId || seen.has(id)) {
      continue;
    }
    seen.add(id);
    ids.push(id);
  }
  return ids;
}

function nextExternalRow() {
  composeExternalRowId += 1;
  return {
    id: composeExternalRowId,
    display_name: '',
    email: '',
  };
}

function seedComposeWindow(mode) {
  const now = new Date();
  const start = new Date(now.getTime());
  if (mode === 'create') {
    start.setMinutes(start.getMinutes() + 5);
  } else {
    start.setMinutes(start.getMinutes() + 60);
  }

  const end = new Date(start.getTime());
  end.setMinutes(end.getMinutes() + 30);

  composeState.startsLocal = isoToLocalInput(start.toISOString());
  composeState.endsLocal = isoToLocalInput(end.toISOString());
}

function resetComposeModal() {
  composeState.callId = '';
  composeState.title = '';
  composeState.accessMode = 'invite_only';
  composeState.replaceParticipants = false;
  composeState.participantsReady = false;
  composeState.submitting = false;
  composeState.error = '';
  composeParticipantStore.reset();
  composeSelectedUserIds.value = [];
  composeExternalRows.value = [];
}

function openCompose(mode, call = null) {
  clearNotice();
  closeEnterCallModal();
  resetComposeModal();
  composeState.mode = mode;
  composeState.open = true;

  if (mode === 'edit' && call) {
    composeState.callId = String(call.id || '');
    composeState.title = String(call.title || '');
    composeState.accessMode = String(call.access_mode || 'invite_only').trim() || 'invite_only';
    composeState.startsLocal = isoToLocalInput(String(call.starts_at || ''));
    composeState.endsLocal = isoToLocalInput(String(call.ends_at || ''));
    seedComposeParticipantsFromCall(call);
    void loadEditableCallParticipants(composeState.callId);
  } else {
    seedComposeWindow(mode);
    composeState.replaceParticipants = true;
    composeExternalRows.value = [nextExternalRow()];
  }

  if (shouldSendParticipants.value) {
    void loadComposeParticipants();
  }
}

function seedComposeParticipantsFromCall(call) {
  const participants = call?.participants || {};
  const hasDetailedParticipants = Array.isArray(participants.internal) || Array.isArray(participants.external);
  if (!hasDetailedParticipants) {
    return false;
  }

  const ownUserId = currentSessionUserId();
  const internalRows = Array.isArray(participants.internal) ? participants.internal : [];
  const externalRows = Array.isArray(participants.external) ? participants.external : [];
  const selectedIds = [];
  const seen = new Set();

  for (const participant of internalRows) {
    const id = Number(participant?.user_id ?? participant?.id ?? 0);
    if (!Number.isInteger(id) || id <= 0 || id === ownUserId || seen.has(id)) {
      continue;
    }
    seen.add(id);
    selectedIds.push(id);
  }

  composeSelectedUserIds.value = selectedIds;
  composeExternalRows.value = externalRows.map((participant) => ({
    ...nextExternalRow(),
    display_name: String(participant?.display_name || ''),
    email: String(participant?.email || ''),
  }));
  composeState.participantsReady = true;
  return true;
}

async function loadEditableCallParticipants(callId) {
  const normalizedCallId = String(callId || '').trim();
  if (normalizedCallId === '') return false;

  try {
    const payload = await apiRequest(`/api/calls/${encodeURIComponent(normalizedCallId)}`);
    if (
      composeState.open
      && composeState.mode === 'edit'
      && composeState.callId === normalizedCallId
      && payload?.call
    ) {
      return seedComposeParticipantsFromCall(payload.call);
    }
  } catch {
    // Metadata edits still work; participant replacement is blocked until details load.
  }
  return false;
}

async function handleReplaceParticipantsToggle() {
  if (!shouldSendParticipants.value) {
    composeState.error = '';
    return;
  }

  composeState.error = '';
  if (composeState.mode === 'edit' && !composeState.participantsReady) {
    const loaded = await loadEditableCallParticipants(composeState.callId);
    if (!loaded) {
      composeState.replaceParticipants = false;
      composeState.error = 'Could not load existing participants. Try again before replacing the list.';
      return;
    }
  }

  void loadComposeParticipants();
}

function closeCompose() {
  composeState.open = false;
  composeState.submitting = false;
  composeState.error = '';
}

function handleShellCreateCall() {
  openCompose('create');
}

async function loadComposeParticipants() {
  if (!composeState.open) return;

  composeParticipants.loading = true;
  composeParticipants.error = '';

  try {
    const payload = await apiRequest('/api/user/directory', {
      query: {
        query: composeParticipants.query,
        page: composeParticipants.page,
        page_size: composeParticipants.pageSize,
      },
    });

    const ownUserId = currentSessionUserId();
    const allRows = Array.isArray(payload.users) ? payload.users : [];
    const rows = allRows.filter((row) => {
      const candidateId = Number(row?.id ?? row?.user_id ?? 0);
      return !Number.isInteger(candidateId) || candidateId !== ownUserId;
    });
    composeParticipantStore.applyRows(rows, payload.pagination || {});
    if (ownUserId > 0) {
      composeSelectedUserIds.value = composeSelectedUserIds.value.filter((id) => Number(id) !== ownUserId);
    }
  } catch (error) {
    composeParticipantStore.fail(error instanceof Error ? error.message : 'Could not load users.');
  } finally {
    composeParticipants.loading = false;
  }
}

async function applyParticipantSearch() {
  composeParticipants.page = 1;
  await loadComposeParticipants();
}

async function goToParticipantPage(nextPage) {
  if (!Number.isInteger(nextPage) || nextPage < 1 || nextPage === composeParticipants.page) {
    return;
  }

  composeParticipants.page = nextPage;
  await loadComposeParticipants();
}

function isUserSelected(userId) {
  const id = Number(userId);
  return composeSelectedUserIds.value.includes(id);
}

function toggleUserSelection(userId) {
  const id = Number(userId);
  const ownUserId = currentSessionUserId();
  if (!Number.isInteger(id) || id <= 0 || id === ownUserId) {
    return;
  }

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
  if (!Number.isInteger(index) || index < 0 || index >= composeExternalRows.value.length) {
    return;
  }

  const next = composeExternalRows.value.slice();
  next.splice(index, 1);
  composeExternalRows.value = next;
}

function normalizeExternalRows() {
  const rows = [];

  for (let index = 0; index < composeExternalRows.value.length; index += 1) {
    const row = composeExternalRows.value[index];
    const displayName = String(row?.display_name || '').trim();
    const email = String(row?.email || '').trim().toLowerCase();

    if (displayName === '' && email === '') {
      continue;
    }

    if (displayName === '' || email === '') {
      return {
        ok: false,
        error: `External participant row ${index + 1} requires both display name and email.`,
        rows: [],
      };
    }

    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
      return {
        ok: false,
        error: `External participant row ${index + 1} has an invalid email.`,
        rows: [],
      };
    }

    rows.push({
      display_name: displayName,
      email,
    });
  }

  return {
    ok: true,
    error: '',
    rows,
  };
}

async function submitCompose() {
  composeState.error = '';
  clearNotice();

  const title = composeState.title.trim();
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

  const payload = {
    title,
    access_mode: String(composeState.accessMode || 'invite_only').trim() || 'invite_only',
    starts_at: startsAt,
    ends_at: endsAt,
  };

  if (shouldSendParticipants.value) {
    if (composeState.mode === 'edit' && !composeState.participantsReady) {
      composeState.error = 'Could not load existing participants. Try again before replacing the list.';
      return;
    }

    const normalizedExternal = normalizeExternalRows();
    if (!normalizedExternal.ok) {
      composeState.error = normalizedExternal.error;
      return;
    }

    payload.internal_participant_user_ids = normalizedInternalParticipantUserIds();
    payload.external_participants = normalizedExternal.rows;
  }

  composeState.submitting = true;

  try {
    if (composeState.mode === 'edit') {
      const callId = encodeURIComponent(composeState.callId);
      await apiRequest(`/api/calls/${callId}`, {
        method: 'PATCH',
        body: payload,
      });
      setNotice('ok', 'Call updated.');
    } else {
      await apiRequest('/api/calls', {
        method: 'POST',
        body: payload,
      });
      setNotice('ok', 'Call created.');
    }

    closeCompose();
    await Promise.all([loadCalls(), loadCalendar()]);
  } catch (error) {
    composeState.error = error instanceof Error ? error.message : 'Could not save call.';
  } finally {
    composeState.submitting = false;
  }
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
  detachCallMediaWatcher = attachCallMediaDeviceWatcher({ requestPermissions: false });
  detachForegroundReconnect = attachForegroundReconnectHandlers({
    onBackground: markEnterAdmissionReconnectAfterForeground,
    onForeground: reconnectEnterAdmissionAfterForeground,
  });
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
  if (typeof detachCallMediaWatcher === 'function') {
    detachCallMediaWatcher();
    detachCallMediaWatcher = null;
  }
  if (typeof detachForegroundReconnect === 'function') {
    detachForegroundReconnect();
    detachForegroundReconnect = null;
  }
  closeEnterAdmissionSocket({
    cancel: enterCallState.waitingForAdmission && !enterAdmissionAccepted,
  });
  stopEnterCallPreview();
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
