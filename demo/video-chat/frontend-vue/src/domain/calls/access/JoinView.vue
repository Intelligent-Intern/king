<template>
  <main class="call-access-join-page">
    <div class="calls-modal call-access-join-shell" role="presentation">
      <div class="calls-modal-backdrop"></div>
      <section
        class="calls-modal-dialog calls-modal-dialog-enter call-access-join-modal"
        role="dialog"
        aria-modal="true"
        :aria-label="t('public.join.dialog_aria')"
      >
        <header class="calls-modal-header calls-modal-header-enter">
          <div class="calls-modal-header-enter-left">
            <img class="calls-modal-header-enter-logo" src="/assets/orgas/kingrt/logo.svg" alt="" />
            <h1 class="calls-enter-title">{{ t('public.join.title') }}</h1>
          </div>
          <button class="icon-mini-btn" type="button" :aria-label="t('public.join.cancel_join')" @click="goToLogin">
            <img src="/assets/orgas/kingrt/icons/cancel.png" alt="" />
          </button>
        </header>

        <div v-if="state.loadingContext" class="calls-modal-body call-access-join-context-body">
          <section class="call-access-join-status">{{ t('public.join.resolving_access') }}</section>
        </div>
        <div v-else-if="state.contextError" class="calls-modal-body call-access-join-context-body">
          <section class="call-access-join-status error">{{ state.contextError }}</section>
        </div>
        <template v-else>
          <div class="calls-modal-body calls-enter-body">
            <div class="calls-enter-layout">
              <section class="calls-enter-preview">
                <section v-if="state.linkKind === 'open'" class="call-access-join-guest-name call-left-settings-block" :aria-label="t('public.join.guest_name')">
                  <div class="call-left-settings-title">{{ t('public.join.guest_name') }}</div>
                  <input
                    v-model.trim="state.guestName"
                    class="input"
                    type="text"
                    maxlength="96"
                    :placeholder="t('public.join.guest_name_placeholder')"
                    :disabled="state.joining || state.waitingForAdmission"
                    @keydown.enter.prevent="startSessionAndJoin"
                  />
                </section>
                <div class="calls-enter-preview-frame">
                  <video ref="previewVideoRef" autoplay playsinline muted></video>
                  <p v-if="state.previewError" class="calls-inline-error">{{ state.previewError }}</p>
                  <p v-else-if="!state.previewReady" class="calls-inline-hint">{{ t('public.join.preparing_preview') }}</p>
                  <div class="call-access-join-call-badge">
                    <strong>{{ state.callTitle }}</strong>
                    <span>{{ state.linkKind === 'open' ? t('public.join.free_for_all_link') : t('public.join.personalized_link') }}</span>
                  </div>
                </div>
              </section>

              <section class="calls-enter-right calls-enter-right-settings">
                <div class="call-left-settings">
                  <JoinStrongMismatchPanel
                    v-if="state.strongMismatchRequired"
                    :state="state"
                    @verify-host="strongMismatchFlow.verifyHost"
                    @continue-without-update="strongMismatchFlow.continueWithoutUpdate"
                    @request-update="strongMismatchFlow.requestUpdate"
                  />
                  <CallBackgroundControls v-if="!state.strongMismatchRequired" i18n-prefix="public.join" :image-title="t('public.join.background_images')" />

                  <section v-if="!state.strongMismatchRequired" class="call-left-settings-block join-settings-microphone" :aria-label="t('public.join.microphone')">
                    <div class="call-left-settings-title">{{ t('public.join.mic') }}</div>
                    <div class="call-left-settings-field">
                      <AppSelect
                        id="guest-enter-call-mic-select"
                        :aria-label="t('public.join.microphone')"
                        :model-value="callMediaPrefs.selectedMicrophoneId"
                        @update:model-value="setCallMicrophoneDevice"
                      >
                        <option value="">{{ callMediaPrefs.microphones.length === 0 ? t('public.join.no_microphone_detected') : t('public.join.select_microphone') }}</option>
                        <option v-for="microphone in callMediaPrefs.microphones" :key="microphone.id" :value="microphone.id">
                          {{ microphone.label }}
                        </option>
                      </AppSelect>
                    </div>
                    <div class="call-left-settings-field">
                      <label for="guest-enter-call-mic-volume">{{ t('public.join.volume') }}</label>
                      <div class="call-left-volume-row">
                        <input
                          id="guest-enter-call-mic-volume"
                          class="call-left-range"
                          type="range"
                          min="0"
                          max="100"
                          step="1"
                          :value="callMediaPrefs.microphoneVolume"
                          @input="setCallMicrophoneVolume($event.target.value)"
                        />
                        <span class="call-left-volume-value">{{ callMediaPrefs.microphoneVolume }}%</span>
                      </div>
                      <div
                        class="call-left-meter"
                        role="meter"
                        :aria-label="t('public.join.microphone_level')"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        :aria-valuenow="state.micLevelPercent"
                      >
                        <span class="call-left-meter-bar" :style="{ width: `${state.micLevelPercent}%` }"></span>
                      </div>
                    </div>
                  </section>

                  <section v-if="!state.strongMismatchRequired" class="call-left-settings-block join-settings-speaker" :aria-label="t('public.join.speaker')">
                    <div class="call-left-settings-title">{{ t('public.join.speaker') }}</div>
                    <div class="call-left-settings-field">
                      <button class="btn full call-left-test-btn" type="button" @click="playSpeakerTestSound">
                        {{ t('public.join.play_test_sound') }}
                      </button>
                    </div>
                    <div class="call-left-settings-field">
                      <AppSelect
                        id="guest-enter-call-speaker-select"
                        :aria-label="t('public.join.speaker')"
                        :model-value="callMediaPrefs.selectedSpeakerId"
                        @update:model-value="setCallSpeakerDevice"
                      >
                        <option value="">{{ callMediaPrefs.speakers.length === 0 ? t('public.join.no_speaker_detected') : t('public.join.select_speaker') }}</option>
                        <option v-for="speaker in callMediaPrefs.speakers" :key="speaker.id" :value="speaker.id">
                          {{ speaker.label }}
                        </option>
                      </AppSelect>
                    </div>
                    <div class="call-left-settings-field">
                      <label for="guest-enter-call-speaker-volume">{{ t('public.join.volume') }}</label>
                      <div class="call-left-volume-row">
                        <input
                          id="guest-enter-call-speaker-volume"
                          class="call-left-range"
                          type="range"
                          min="0"
                          max="100"
                          step="1"
                          :value="callMediaPrefs.speakerVolume"
                          @input="setCallSpeakerVolume($event.target.value)"
                        />
                        <span class="call-left-volume-value">{{ callMediaPrefs.speakerVolume }}%</span>
                      </div>
                    </div>
                  </section>

                  <section v-if="!state.strongMismatchRequired" class="call-left-settings-block join-settings-camera" :aria-label="t('public.join.camera')">
                    <div class="call-left-settings-title">{{ t('public.join.camera') }}</div>
                    <div class="call-left-settings-field">
                      <AppSelect
                        id="guest-enter-call-camera-select"
                        :aria-label="t('public.join.camera')"
                        :model-value="callMediaPrefs.selectedCameraId"
                        @update:model-value="setCallCameraDevice"
                      >
                        <option value="">{{ callMediaPrefs.cameras.length === 0 ? t('public.join.no_camera_detected') : t('public.join.select_camera') }}</option>
                        <option v-for="camera in callMediaPrefs.cameras" :key="camera.id" :value="camera.id">
                          {{ camera.label }}
                        </option>
                      </AppSelect>
                    </div>
                  </section>

                  <div v-if="!state.strongMismatchRequired && callMediaPrefs.error" class="call-left-settings-error">{{ callMediaPrefs.error }}</div>
                </div>
              </section>
            </div>
          </div>

          <footer class="calls-modal-footer calls-modal-footer-enter">
            <p v-if="state.admissionMessage" class="calls-enter-admission-status" role="status" aria-live="polite">
              {{ state.admissionMessage }}
            </p>
            <p v-if="state.joinError" class="calls-inline-error calls-enter-footer-error">
              {{ state.joinError }}
            </p>
            <button class="btn" type="button" :disabled="state.joining" @click="goToLogin">{{ t('common.cancel') }}</button>
            <button
              v-if="!state.strongMismatchRequired"
              class="btn btn-cyan"
              type="button"
              :disabled="state.joining || state.waitingForAdmission"
              @click="startSessionAndJoin"
            >
              {{ state.waitingForAdmission ? t('public.join.waiting_for_host') : (state.joining ? t('public.join.joining') : t('public.join.join_call')) }}
            </button>
          </footer>
        </template>
      </section>
    </div>
  </main>
</template>

<script setup>
import { onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AppSelect from '../../../components/AppSelect.vue';
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
import { localizedApiErrorMessage } from '../../../modules/localization/apiErrorMessages.js';
import { t } from '../../../modules/localization/i18nRuntime.js';
import { buildOptionalCallAudioCaptureConstraints } from '../../realtime/media/audioCaptureConstraints';
import CallBackgroundControls from '../../realtime/background/CallBackgroundControls.vue';
import {
  attachCallMediaDeviceWatcher,
  callMediaPrefs,
  refreshCallMediaDevices,
  setCallCameraDevice,
  setCallMicrophoneDevice,
  setCallMicrophoneVolume,
  setCallSpeakerDevice,
  setCallSpeakerVolume,
} from '../../realtime/media/preferences';
import {
  CALL_UUID_PATTERN,
  callAccessVerifiedContextFromSession,
  safeCallAccessInvalidMessage,
} from './admissionGate';
import { loginWithCallAccess, requestCallAccessAccountUpdateConfirmation } from './callAccessSession';
import { createJoinAccessPreviewController } from './joinPreview';
import JoinStrongMismatchPanel from './JoinStrongMismatchPanel.vue';
import { callAccessJoinHeaders, createJoinStrongMismatchFlow, isStrongPersonalizedMismatchPayload } from './joinStrongMismatchFlow';

const route = useRoute();
const router = useRouter();
const previewVideoRef = ref(null);

let detachDeviceWatcher = null;
let detachForegroundReconnect = null;
let admissionSocket = null;
let admissionSocketGeneration = 0;
let admissionAccepted = false;
let admissionManuallyClosed = false;
let admissionReconnectTimer = 0;
let admissionReconnectAttempt = 0;
let admissionReconnectAfterForeground = false;
let admissionLastForegroundReconnectAt = 0;

const ADMISSION_RECONNECT_DELAYS_MS = [500, 1000, 2000, 3000, 5000];
const ADMISSION_FOREGROUND_RECONNECT_DEBOUNCE_MS = 1500;

const state = reactive({
  loadingContext: true,
  contextError: '',
  callId: '',
  roomId: '',
  callTitle: '',
  linkKind: 'personal',
  guestName: '',
  joining: false,
  waitingForAdmission: false,
  admissionMessage: '',
  joinError: '',
  previewReady: false,
  previewError: '',
  micLevelPercent: 0,
  verifiedAccessContext: null,
  strongMismatchRequired: false,
  hostName: '',
  verifyingHost: false,
  hostVerified: false,
  hostVerificationError: '',
  accountUpdateDisplayName: '',
  accountUpdateSending: false,
  accountUpdatePending: false,
  accountUpdateError: '',
  accountUpdateRecipient: '',
});

const {
  applyPreviewAudioVolume,
  playSpeakerTestSound,
  releasePreviewForCallEntry,
  startPreview,
  stopPreview,
} = createJoinAccessPreviewController({
  previewVideoRef,
  state,
  buildOptionalCallAudioCaptureConstraints,
});

function normalizeAccessId(value) {
  return String(value || '').trim().toLowerCase();
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

const strongMismatchFlow = createJoinStrongMismatchFlow({
  state,
  route,
  sessionState,
  t,
  normalizeAccessId,
  normalizeCallId,
  normalizeRoomId,
  loginWithCallAccess,
  requestCallAccessAccountUpdateConfirmation,
  startAdmissionWait,
});

function resetJoinContextDetails() {
  state.callId = '';
  state.roomId = '';
  state.callTitle = '';
  state.linkKind = 'personal';
  state.guestName = '';
  state.joining = false;
  state.waitingForAdmission = false;
  state.admissionMessage = '';
  state.joinError = '';
  state.verifiedAccessContext = null;
  strongMismatchFlow.reset();
}

function showSafeInvalidAccessState() {
  resetJoinContextDetails();
  state.contextError = safeCallAccessInvalidMessage(t);
}

function admissionSocketUrlForOrigin(origin) {
  const query = appendAssetVersionQuery(new URLSearchParams());
  query.set('room', normalizeRoomId(state.roomId || 'lobby'));

  const callId = normalizeCallId(state.callId);
  if (callId !== '') {
    query.set('call_id', callId);
  }

  const token = String(sessionState.sessionToken || '').trim();
  if (token !== '') {
    query.set('session', token);
  }

  return buildWebSocketUrl(origin, '/ws', query);
}

function clearAdmissionReconnectTimer() {
  if (admissionReconnectTimer > 0 && typeof window !== 'undefined') {
    window.clearTimeout(admissionReconnectTimer);
  }
  admissionReconnectTimer = 0;
}

function admissionSocketIsOpen(socket = admissionSocket) {
  if (typeof WebSocket === 'undefined') return false;
  return socket instanceof WebSocket && socket.readyState === WebSocket.OPEN;
}

function sendAdmissionFrame(payload) {
  if (!admissionSocketIsOpen()) return false;
  try {
    admissionSocket.send(JSON.stringify(payload));
    return true;
  } catch {
    return false;
  }
}

function retireAdmissionSocket(closeReason = 'admission_reconnect') {
  const socket = admissionSocket;
  admissionSocket = null;
  if (typeof WebSocket !== 'undefined' && socket instanceof WebSocket) {
    try {
      socket.close(1000, closeReason);
    } catch {
      // ignore
    }
  }
}

function closeAdmissionSocket({ cancel = false } = {}) {
  admissionManuallyClosed = true;
  clearAdmissionReconnectTimer();

  if (cancel && state.waitingForAdmission && !admissionAccepted) {
    sendAdmissionFrame({
      type: 'lobby/queue/cancel',
      room_id: normalizeRoomId(state.roomId || 'lobby'),
    });
  }

  retireAdmissionSocket(cancel ? 'admission_cancelled' : 'admission_closed');
}

function scheduleAdmissionReconnect(accessId) {
  clearAdmissionReconnectTimer();
  if (admissionManuallyClosed || admissionAccepted || !state.waitingForAdmission) return;
  if (typeof window === 'undefined') return;

  admissionReconnectAttempt += 1;
  const delay = ADMISSION_RECONNECT_DELAYS_MS[
    Math.min(admissionReconnectAttempt - 1, ADMISSION_RECONNECT_DELAYS_MS.length - 1)
  ];
  state.admissionMessage = t('public.join.reconnecting_lobby');
  admissionReconnectTimer = window.setTimeout(() => {
    admissionReconnectTimer = 0;
    connectAdmissionSocket(accessId);
  }, delay);
}

function markAdmissionReconnectAfterForeground() {
  if (!state.waitingForAdmission || admissionAccepted || admissionManuallyClosed) return;
  admissionReconnectAfterForeground = true;
}

function reconnectAdmissionAfterForeground() {
  if (typeof document !== 'undefined' && document.visibilityState === 'hidden') return;
  if (!state.waitingForAdmission || admissionAccepted || admissionManuallyClosed) return;
  if (!admissionReconnectAfterForeground) return;

  const accessId = normalizeAccessId(route.params.accessId);
  if (accessId === '') return;

  const now = Date.now();
  if ((now - admissionLastForegroundReconnectAt) < ADMISSION_FOREGROUND_RECONNECT_DEBOUNCE_MS) {
    return;
  }

  admissionReconnectAfterForeground = false;
  admissionLastForegroundReconnectAt = now;
  admissionReconnectAttempt = 0;
  clearAdmissionReconnectTimer();
  state.admissionMessage = t('public.join.reconnecting_lobby');
  connectAdmissionSocket(accessId);
}

async function enterAdmittedCall(accessId) {
  const callRef = normalizeCallId(state.callId) || normalizeAccessId(accessId);
  if (callRef === '') {
    state.waitingForAdmission = false;
    state.joining = false;
    state.admissionMessage = '';
    state.joinError = t('public.join.missing_call_reference');
    return;
  }

  admissionAccepted = true;
  closeAdmissionSocket({ cancel: false });
  await releasePreviewForCallEntry();
  state.waitingForAdmission = false;
  state.joining = false;
  state.admissionMessage = '';

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

function handleAdmissionLobbySnapshot(payload, accessId) {
  const snapshotRoomId = normalizeRoomId(payload?.room_id || '');
  const expectedRoomId = normalizeRoomId(state.roomId || 'lobby');
  if (snapshotRoomId !== expectedRoomId) return;

  const admittedRows = Array.isArray(payload?.admitted) ? payload.admitted : [];
  const currentUserId = Number(sessionState.userId || 0);
  const isAdmitted = admittedRows.some((entry) => Number(entry?.user_id || 0) === currentUserId);
  if (!isAdmitted) return;

  void enterAdmittedCall(accessId);
}

function handleAdmissionWelcome(payload, accessId) {
  const admission = payload && typeof payload.admission === 'object' ? payload.admission : null;
  const requiresAdmission = Boolean(admission?.requires_admission);
  const pendingRoomId = normalizeRoomId(admission?.pending_room_id || state.roomId || 'lobby');
  state.roomId = pendingRoomId;

  if (!requiresAdmission) {
    void enterAdmittedCall(accessId);
    return;
  }

  if (!sendAdmissionFrame({ type: 'lobby/queue/join', room_id: pendingRoomId })) {
    state.waitingForAdmission = false;
    state.admissionMessage = '';
    state.joinError = t('public.join.notify_owner_offline');
    return;
  }

  state.waitingForAdmission = true;
  state.joining = false;
  state.joinError = '';
  state.admissionMessage = t('public.join.admission_wait');
}

function handleAdmissionSocketMessage(event, accessId) {
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
    handleAdmissionWelcome(payload, accessId);
    return;
  }

  if (type === 'lobby/snapshot') {
    handleAdmissionLobbySnapshot(payload, accessId);
    return;
  }

  if (type === 'system/error') {
    const code = String(payload.code || '').trim().toLowerCase();
    if (code === 'lobby_command_failed') {
      state.joinError = t('public.join.notify_owner_failed');
      state.admissionMessage = '';
      state.waitingForAdmission = false;
    }
  }
}

function connectAdmissionSocketWithOriginAt(candidates, originIndex, generation, accessId) {
  if (generation !== admissionSocketGeneration || admissionManuallyClosed || admissionAccepted) return;

  if (originIndex >= candidates.length) {
    if (state.waitingForAdmission) {
      scheduleAdmissionReconnect(accessId);
    } else {
      state.joining = false;
      state.waitingForAdmission = false;
      state.admissionMessage = '';
      state.joinError = t('public.join.lobby_connect_failed');
    }
    return;
  }

  const socketOrigin = candidates[originIndex];
  const wsUrl = admissionSocketUrlForOrigin(socketOrigin);
  if (!wsUrl) {
    connectAdmissionSocketWithOriginAt(candidates, originIndex + 1, generation, accessId);
    return;
  }

  const socket = new WebSocket(wsUrl);
  admissionSocket = socket;
  let opened = false;
  let failedOver = false;

  const failOverToNextOrigin = () => {
    if (failedOver) return;
    failedOver = true;
    if (admissionSocket === socket) {
      admissionSocket = null;
    }
    try {
      socket.close(1000, 'admission_failover');
    } catch {
      // ignore
    }
    connectAdmissionSocketWithOriginAt(candidates, originIndex + 1, generation, accessId);
  };

  socket.addEventListener('open', () => {
    if (generation !== admissionSocketGeneration || admissionManuallyClosed || admissionAccepted) {
      try {
        socket.close(1000, 'stale_admission_socket');
      } catch {
        // ignore
      }
      return;
    }

    opened = true;
    admissionReconnectAttempt = 0;
    admissionReconnectAfterForeground = false;
    setBackendWebSocketOrigin(socketOrigin);
  });

  socket.addEventListener('message', (event) => {
    if (generation !== admissionSocketGeneration || admissionManuallyClosed || admissionAccepted) return;
    handleAdmissionSocketMessage(event, accessId);
  });

  socket.addEventListener('error', () => {
    if (generation !== admissionSocketGeneration || admissionManuallyClosed || admissionAccepted) return;
    if (!opened) {
      failOverToNextOrigin();
      return;
    }
    state.admissionMessage = t('public.join.reconnecting_lobby');
  });

  socket.addEventListener('close', (event) => {
    if (generation !== admissionSocketGeneration) return;
    if (admissionSocket === socket) {
      admissionSocket = null;
    }
    if (admissionManuallyClosed || admissionAccepted) return;
    if (handleAssetVersionSocketClose(event)) return;

    if (!opened) {
      failOverToNextOrigin();
      return;
    }

    scheduleAdmissionReconnect(accessId);
  });
}

function connectAdmissionSocket(accessId) {
  retireAdmissionSocket('admission_reconnect');
  const candidates = resolveBackendWebSocketOriginCandidates();
  connectAdmissionSocketWithOriginAt(candidates, 0, admissionSocketGeneration, accessId);
}

function startAdmissionWait(accessId) {
  if (typeof WebSocket === 'undefined') {
    state.joining = false;
    state.joinError = t('public.join.realtime_lobby_unsupported');
    return false;
  }

  closeAdmissionSocket({ cancel: false });
  admissionAccepted = false;
  admissionManuallyClosed = false;
  admissionReconnectAttempt = 0;
  admissionReconnectAfterForeground = false;
  admissionSocketGeneration += 1;
  state.joining = false;
  state.waitingForAdmission = true;
  state.admissionMessage = t('public.join.connecting_lobby');
  connectAdmissionSocket(accessId);
  return true;
}

async function loadJoinContext() {
  state.loadingContext = true;
  state.contextError = '';
  resetJoinContextDetails();

  const accessId = normalizeAccessId(route.params.accessId);
  if (!CALL_UUID_PATTERN.test(accessId)) {
    state.loadingContext = false;
    resetJoinContextDetails();
    state.contextError = localizedApiErrorMessage({ error: { code: 'call_access_validation_failed' } }, t('public.join.access_invalid'));
    return;
  }

  try {
    const { response } = await fetchBackend(`/api/call-access/${encodeURIComponent(accessId)}/join`, {
      method: 'GET',
      headers: callAccessJoinHeaders(sessionState),
    });
    let payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'ok') {
      resetJoinContextDetails();
      if (response.status === 403 && isStrongPersonalizedMismatchPayload(payload)) {
        strongMismatchFlow.show();
        return;
      }
      payload = { error: { code: 'call_access_validation_failed' } };
      state.contextError = localizedApiErrorMessage(payload, t('public.join.resolve_failed'));
      return;
    }

    const call = payload?.result?.call || {};
    state.callId = String(call.id || '').trim();
    state.roomId = normalizeRoomId(call.room_id || 'lobby');
    state.callTitle = String(call.title || '').trim() || t('public.join.default_call_title');
    const linkKind = String(payload?.result?.link_kind || '').trim().toLowerCase();
    state.linkKind = linkKind === 'open' ? 'open' : 'personal';
    state.verifiedAccessContext = callAccessVerifiedContextFromSession(sessionState);
  } catch (error) {
    const message = error instanceof Error ? error.message : '';
    if (message === '' || /failed to fetch|socket|connection/i.test(message)) {
      state.contextError = t('public.join.backend_unreachable', { origin: currentBackendOrigin() });
    } else {
      showSafeInvalidAccessState();
    }
  } finally {
    state.loadingContext = false;
  }
}

function goToLogin() {
  closeAdmissionSocket({ cancel: true });
  state.waitingForAdmission = false;
  state.admissionMessage = '';
  router.replace('/login');
}

async function startSessionAndJoin() {
  if (state.joining || state.waitingForAdmission || state.loadingContext || state.contextError || state.strongMismatchRequired) return;
  if (state.linkKind === 'open' && String(state.guestName || '').trim() === '') {
    state.joinError = t('public.join.name_required');
    return;
  }
  state.joining = true;
  state.joinError = '';
  state.admissionMessage = '';

  const accessId = normalizeAccessId(route.params.accessId);
  const result = await loginWithCallAccess(accessId, {
    guestName: state.linkKind === 'open' ? state.guestName : '',
    verifiedContext: state.verifiedAccessContext,
  });
  if (!result.ok) {
    state.joining = false;
    const errorPayload = result.errorCode ? { error: { code: result.errorCode } } : null;
    state.joinError = localizedApiErrorMessage(errorPayload, t('public.join.start_session_failed'));
    return;
  }

  const call = result.call && typeof result.call === 'object' ? result.call : {};
  state.callId = normalizeCallId(call.id || state.callId);
  state.roomId = normalizeRoomId(call.room_id || state.roomId || 'lobby');
  startAdmissionWait(accessId);
}

watch(
  () => [
    callMediaPrefs.selectedCameraId,
    callMediaPrefs.selectedMicrophoneId,
    callMediaPrefs.backgroundFilterMode,
    callMediaPrefs.backgroundBackdropMode,
    callMediaPrefs.backgroundQualityProfile,
    callMediaPrefs.backgroundBlurStrength,
    callMediaPrefs.backgroundMaskVariant,
    callMediaPrefs.backgroundBlurTransition,
    callMediaPrefs.backgroundApplyOutgoing,
    callMediaPrefs.backgroundReplacementImageUrl,
    callMediaPrefs.backgroundMaxProcessWidth,
    callMediaPrefs.backgroundMaxProcessFps,
  ],
  () => {
    if (state.loadingContext || state.contextError) return;
    void startPreview();
  },
);

watch(
  () => callMediaPrefs.microphoneVolume,
  applyPreviewAudioVolume,
);

onMounted(async () => {
  detachForegroundReconnect = attachForegroundReconnectHandlers({
    onBackground: markAdmissionReconnectAfterForeground,
    onForeground: reconnectAdmissionAfterForeground,
  });
  detachDeviceWatcher = attachCallMediaDeviceWatcher({ requestPermissions: true });
  await loadJoinContext();
  if (state.contextError) return;
  await refreshCallMediaDevices({ requestPermissions: true });
  await startPreview();
});

onBeforeUnmount(() => {
  closeAdmissionSocket({ cancel: state.waitingForAdmission && !admissionAccepted });
  if (typeof detachDeviceWatcher === 'function') {
    detachDeviceWatcher();
    detachDeviceWatcher = null;
  }
  if (typeof detachForegroundReconnect === 'function') {
    detachForegroundReconnect();
    detachForegroundReconnect = null;
  }
  stopPreview();
});
</script>

<style scoped src="./JoinView.css"></style>
