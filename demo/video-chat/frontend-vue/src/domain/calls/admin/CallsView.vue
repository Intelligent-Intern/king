<template src="./CallsView.template.html"></template>

<script setup>
import { computed, inject, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import AppPagination from '../../../components/AppPagination.vue';
import AppSelect from '../../../components/AppSelect.vue';
import CallBackgroundControls from '../../realtime/background/CallBackgroundControls.vue';
import ChatArchiveModal from '../components/ChatArchiveModal.vue';
import CallsListTable from '../components/ListTable.vue';
import {
  createCallListStore,
  createChatArchiveStore,
  createNoticeStore,
} from '../dashboard/viewState';
import { sessionState } from '../../auth/session';
import { currentBackendOrigin, fetchBackend } from '../../../support/backendFetch';
import { formatDateRangeDisplay, formatDateTimeDisplay } from '../../../support/dateTimeFormat';
import { createAdminSyncSocket } from '../../../support/adminSyncSocket';
import { t } from '../../../modules/localization/i18nRuntime.js';
import {
  callMediaPrefs,
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
const workspaceSidebarState = inject('workspaceSidebarState', null);

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
  queryDraft,
  queryApplied,
  statusFilter,
  scopeFilter,
  calls,
  loadingCalls,
  callsError,
  pagination,
} = callListStore;
const primaryActionLabel = computed(() => t('calls.admin.new_video_call'));
const deleteAllCallsBusy = ref(false);
const canDeleteAllCalls = computed(() => !deleteAllCallsBusy.value && !loadingCalls.value);

function openPrimaryCompose() {
  openCompose('create');
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
  await loadCalls();
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

async function loadCalendar() {
  // Calendar rendering moved to the dedicated Calendar module.
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
    await loadCalls();
  } catch (error) {
    setNotice('error', error instanceof Error ? error.message : 'Could not delete all calls.');
  } finally {
    deleteAllCallsBusy.value = false;
  }
}

async function applyFilters() {
  clearNotice();
  queryApplied.value = queryDraft.value.trim();
  pagination.page = 1;
  await loadCalls();
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

  void loadCalls();
});

onBeforeUnmount(() => {
  clearAdminSyncReloadTimer();
  stopAdminSyncSocket();
  window.removeEventListener('keydown', handleEscape);
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

<style scoped src="./CallsView.css"></style>
<style scoped src="./CallsViewResponsive.css"></style>
