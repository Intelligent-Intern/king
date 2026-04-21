<template>
  <main class="call-access-join-page">
    <section class="call-access-join-modal" role="dialog" aria-modal="true" aria-label="Join video call">
      <header class="call-access-join-header">
        <img class="call-access-join-logo" src="/assets/orgas/kingrt/icon.svg" alt="" />
        <h1>Join Video Call</h1>
      </header>

      <section v-if="state.loadingContext" class="call-access-join-status">
        Resolving call access...
      </section>
      <section v-else-if="state.contextError" class="call-access-join-status error">
        {{ state.contextError }}
      </section>
      <template v-else>
        <section class="call-access-join-call">
          <div class="call-access-join-call-title">{{ state.callTitle }}</div>
          <div class="call-access-join-call-meta">{{ state.callId }}</div>
          <div class="call-access-join-call-meta">
            {{ state.linkKind === 'open' ? 'Free-for-all link' : 'Personalized link' }}
          </div>
        </section>

        <section class="call-access-join-preview" :style="{ aspectRatio: previewAspectRatio }">
          <video ref="previewVideoRef" autoplay playsinline muted></video>
          <p v-if="state.previewError" class="call-access-join-preview-status error">{{ state.previewError }}</p>
          <p v-else-if="!state.previewReady" class="call-access-join-preview-status">Preparing preview...</p>
        </section>

        <section v-if="state.linkKind === 'open'" class="call-access-join-grid">
          <label class="field">
            <span>Your name</span>
            <input
              v-model.trim="state.guestName"
              class="input"
              type="text"
              maxlength="96"
              placeholder="Enter your display name"
              :disabled="state.joining || state.waitingForAdmission"
            />
          </label>
        </section>

        <section class="call-access-join-grid">
          <label class="field">
            <span>Camera</span>
            <AppSelect
              :model-value="callMediaPrefs.selectedCameraId"
              @update:model-value="setCallCameraDevice"
            >
              <option value="">{{ callMediaPrefs.cameras.length === 0 ? 'No camera detected' : 'Select camera' }}</option>
              <option v-for="camera in callMediaPrefs.cameras" :key="camera.id" :value="camera.id">
                {{ camera.label }}
              </option>
            </AppSelect>
          </label>

          <label class="field">
            <span>Microphone</span>
            <AppSelect
              :model-value="callMediaPrefs.selectedMicrophoneId"
              @update:model-value="setCallMicrophoneDevice"
            >
              <option value="">{{ callMediaPrefs.microphones.length === 0 ? 'No microphone detected' : 'Select mic' }}</option>
              <option v-for="microphone in callMediaPrefs.microphones" :key="microphone.id" :value="microphone.id">
                {{ microphone.label }}
              </option>
            </AppSelect>
          </label>

          <label class="field">
            <span>Mic volume</span>
            <input
              class="input"
              type="range"
              min="0"
              max="100"
              step="1"
              :value="callMediaPrefs.microphoneVolume"
              @input="setCallMicrophoneVolume($event.target.value)"
            />
          </label>

          <label class="field">
            <span>Speaker</span>
            <AppSelect
              :model-value="callMediaPrefs.selectedSpeakerId"
              @update:model-value="setCallSpeakerDevice"
            >
              <option value="">{{ callMediaPrefs.speakers.length === 0 ? 'No speaker detected' : 'Select speaker' }}</option>
              <option v-for="speaker in callMediaPrefs.speakers" :key="speaker.id" :value="speaker.id">
                {{ speaker.label }}
              </option>
            </AppSelect>
          </label>

          <label class="field">
            <span>Speaker volume</span>
            <input
              class="input"
              type="range"
              min="0"
              max="100"
              step="1"
              :value="callMediaPrefs.speakerVolume"
              @input="setCallSpeakerVolume($event.target.value)"
            />
          </label>
        </section>

        <p v-if="state.joinError" class="call-access-join-status error">{{ state.joinError }}</p>
        <p v-if="state.admissionMessage" class="call-access-join-status waiting" role="status" aria-live="polite">
          {{ state.admissionMessage }}
        </p>

        <footer class="call-access-join-actions">
          <button class="btn" type="button" :disabled="state.joining" @click="goToLogin">Cancel</button>
          <button class="btn" type="button" :disabled="state.joining || state.waitingForAdmission" @click="startSessionAndJoin">
            {{ state.waitingForAdmission ? 'Waiting for host...' : (state.joining ? 'Joining...' : 'Join call') }}
          </button>
        </footer>
      </template>
    </section>
  </main>
</template>

<script setup>
import { nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AppSelect from '../../components/AppSelect.vue';
import { loginWithCallAccess, sessionState } from '../auth/session';
import { currentBackendOrigin, fetchBackend } from '../../support/backendFetch';
import {
  buildWebSocketUrl,
  resolveBackendWebSocketOriginCandidates,
  setBackendWebSocketOrigin,
} from '../../support/backendOrigin';
import {
  attachCallMediaDeviceWatcher,
  callMediaPrefs,
  refreshCallMediaDevices,
  setCallCameraDevice,
  setCallMicrophoneDevice,
  setCallMicrophoneVolume,
  setCallSpeakerDevice,
  setCallSpeakerVolume,
} from '../realtime/callMediaPreferences';

const route = useRoute();
const router = useRouter();
const previewVideoRef = ref(null);
const previewStreamRef = ref(null);
const previewAspectRatio = ref('16 / 9');

let detachDeviceWatcher = null;
let resizeBound = null;
let admissionSocket = null;
let admissionSocketGeneration = 0;
let admissionAccepted = false;
let admissionManuallyClosed = false;
let admissionReconnectTimer = 0;
let admissionReconnectAttempt = 0;

const ADMISSION_WAIT_MESSAGE = 'Call owner wurde benachrichtigt.';
const ADMISSION_RECONNECT_DELAYS_MS = [500, 1000, 2000, 3000, 5000];

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

function admissionSocketUrlForOrigin(origin) {
  const query = new URLSearchParams();
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

function updatePreviewAspectRatio() {
  if (typeof window === 'undefined') return;
  const width = Math.max(1, Number(window.innerWidth || 0));
  const height = Math.max(1, Number(window.innerHeight || 0));
  previewAspectRatio.value = `${width} / ${height}`;
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

function stopPreview() {
  const node = previewVideoRef.value;
  if (node instanceof HTMLVideoElement) {
    try {
      node.pause();
    } catch {
      // ignore
    }
    node.srcObject = null;
  }

  const stream = previewStreamRef.value;
  if (stream instanceof MediaStream) {
    for (const track of stream.getTracks()) {
      track.stop();
    }
  }
  previewStreamRef.value = null;
  state.previewReady = false;
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

function closeAdmissionSocket({ cancel = false } = {}) {
  admissionManuallyClosed = true;
  clearAdmissionReconnectTimer();

  if (cancel && state.waitingForAdmission && !admissionAccepted) {
    sendAdmissionFrame({
      type: 'lobby/queue/cancel',
      room_id: normalizeRoomId(state.roomId || 'lobby'),
    });
  }

  const socket = admissionSocket;
  admissionSocket = null;
  if (typeof WebSocket !== 'undefined' && socket instanceof WebSocket) {
    try {
      socket.close(1000, cancel ? 'admission_cancelled' : 'admission_closed');
    } catch {
      // ignore
    }
  }
}

function scheduleAdmissionReconnect(accessId) {
  clearAdmissionReconnectTimer();
  if (admissionManuallyClosed || admissionAccepted || !state.waitingForAdmission) return;
  if (typeof window === 'undefined') return;

  admissionReconnectAttempt += 1;
  const delay = ADMISSION_RECONNECT_DELAYS_MS[
    Math.min(admissionReconnectAttempt - 1, ADMISSION_RECONNECT_DELAYS_MS.length - 1)
  ];
  state.admissionMessage = 'Lobby-Verbindung wird wiederhergestellt...';
  admissionReconnectTimer = window.setTimeout(() => {
    admissionReconnectTimer = 0;
    connectAdmissionSocket(accessId);
  }, delay);
}

async function enterAdmittedCall(accessId) {
  admissionAccepted = true;
  closeAdmissionSocket({ cancel: false });
  stopPreview();
  state.waitingForAdmission = false;
  state.joining = false;
  state.admissionMessage = '';

  const callRef = normalizeCallId(state.callId) || normalizeAccessId(accessId);
  await router.replace({
    name: 'call-workspace',
    params: { callRef },
    query: { entry: 'invite' },
  });
}

function handleAdmissionLobbySnapshot(payload, accessId) {
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
    state.joinError = 'Could not notify call owner while lobby websocket is offline.';
    return;
  }

  state.waitingForAdmission = true;
  state.joining = false;
  state.joinError = '';
  state.admissionMessage = ADMISSION_WAIT_MESSAGE;
}

function handleAdmissionSocketMessage(event, accessId) {
  let payload = null;
  try {
    payload = JSON.parse(String(event.data || ''));
  } catch {
    return;
  }

  if (!payload || typeof payload !== 'object') return;
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
      state.joinError = 'Could not notify call owner.';
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
      state.joinError = 'Could not connect to call lobby.';
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
    state.admissionMessage = 'Lobby-Verbindung wird wiederhergestellt...';
  });

  socket.addEventListener('close', () => {
    if (generation !== admissionSocketGeneration) return;
    if (admissionSocket === socket) {
      admissionSocket = null;
    }
    if (admissionManuallyClosed || admissionAccepted) return;

    if (!opened) {
      failOverToNextOrigin();
      return;
    }

    scheduleAdmissionReconnect(accessId);
  });
}

function connectAdmissionSocket(accessId) {
  const candidates = resolveBackendWebSocketOriginCandidates();
  connectAdmissionSocketWithOriginAt(candidates, 0, admissionSocketGeneration, accessId);
}

function startAdmissionWait(accessId) {
  if (typeof WebSocket === 'undefined') {
    state.joining = false;
    state.joinError = 'Realtime lobby is not supported in this browser.';
    return false;
  }

  closeAdmissionSocket({ cancel: false });
  admissionAccepted = false;
  admissionManuallyClosed = false;
  admissionReconnectAttempt = 0;
  admissionSocketGeneration += 1;
  state.joining = false;
  state.waitingForAdmission = true;
  state.admissionMessage = 'Lobby-Verbindung wird hergestellt...';
  connectAdmissionSocket(accessId);
  return true;
}

function buildPreviewConstraints() {
  const cameraDeviceId = String(callMediaPrefs.selectedCameraId || '').trim();
  const microphoneDeviceId = String(callMediaPrefs.selectedMicrophoneId || '').trim();
  return {
    video: cameraDeviceId === '' ? true : { deviceId: { exact: cameraDeviceId } },
    audio: microphoneDeviceId === '' ? true : { deviceId: { exact: microphoneDeviceId } },
  };
}

async function startPreview() {
  stopPreview();
  state.previewReady = false;
  state.previewError = '';

  if (
    typeof navigator === 'undefined'
    || !navigator.mediaDevices
    || typeof navigator.mediaDevices.getUserMedia !== 'function'
  ) {
    state.previewError = 'Camera preview is not supported in this browser.';
    return;
  }

  try {
    const stream = await navigator.mediaDevices.getUserMedia(buildPreviewConstraints());
    previewStreamRef.value = stream;
    const micVolume = Math.max(0, Math.min(100, Number(callMediaPrefs.microphoneVolume || 100))) / 100;
    for (const track of stream.getAudioTracks()) {
      if (typeof track.applyConstraints === 'function') {
        track.applyConstraints({ volume: micVolume }).catch(() => {});
      }
    }

    await nextTick();
    const node = previewVideoRef.value;
    if (!(node instanceof HTMLVideoElement)) return;
    node.muted = true;
    node.srcObject = stream;
    await node.play().catch(() => {});
    state.previewReady = true;
  } catch (error) {
    state.previewError = error instanceof Error ? error.message : 'Could not start camera preview.';
  }
}

async function loadJoinContext() {
  state.loadingContext = true;
  state.contextError = '';
  state.callId = '';
  state.roomId = '';
  state.callTitle = '';
  state.linkKind = 'personal';
  state.guestName = '';
  state.joinError = '';
  state.waitingForAdmission = false;
  state.admissionMessage = '';

  const accessId = normalizeAccessId(route.params.accessId);
  if (!/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(accessId)) {
    state.loadingContext = false;
    state.contextError = 'Call access id is invalid.';
    return;
  }

  try {
    const { response } = await fetchBackend(`/api/call-access/${encodeURIComponent(accessId)}/join`, {
      method: 'GET',
      headers: {
        accept: 'application/json',
      },
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'ok') {
      state.contextError = extractErrorMessage(payload, 'Could not resolve call access.');
      return;
    }

    const call = payload?.result?.call || {};
    state.callId = String(call.id || '').trim();
    state.roomId = normalizeRoomId(call.room_id || 'lobby');
    state.callTitle = String(call.title || '').trim() || 'Video call';
    const linkKind = String(payload?.result?.link_kind || '').trim().toLowerCase();
    state.linkKind = linkKind === 'open' ? 'open' : 'personal';
  } catch (error) {
    const message = error instanceof Error ? error.message : '';
    if (message === '' || /failed to fetch|socket|connection/i.test(message)) {
      state.contextError = `Could not reach backend (${currentBackendOrigin()}).`;
    } else {
      state.contextError = message;
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
  if (state.joining || state.waitingForAdmission || state.loadingContext || state.contextError) return;
  if (state.linkKind === 'open' && String(state.guestName || '').trim() === '') {
    state.joinError = 'Name is required for this link.';
    return;
  }
  state.joining = true;
  state.joinError = '';
  state.admissionMessage = '';

  const accessId = normalizeAccessId(route.params.accessId);
  const result = await loginWithCallAccess(accessId, {
    guestName: state.linkKind === 'open' ? state.guestName : '',
  });
  if (!result.ok) {
    state.joining = false;
    state.joinError = result.message || 'Could not start call session.';
    return;
  }

  const call = result.call && typeof result.call === 'object' ? result.call : {};
  state.callId = normalizeCallId(call.id || state.callId);
  state.roomId = normalizeRoomId(call.room_id || state.roomId || 'lobby');
  startAdmissionWait(accessId);
}

watch(
  () => [callMediaPrefs.selectedCameraId, callMediaPrefs.selectedMicrophoneId],
  () => {
    if (state.loadingContext || state.contextError) return;
    void startPreview();
  },
);

watch(
  () => callMediaPrefs.microphoneVolume,
  () => {
    const stream = previewStreamRef.value;
    if (!(stream instanceof MediaStream)) return;
    const volume = Math.max(0, Math.min(100, Number(callMediaPrefs.microphoneVolume || 100))) / 100;
    for (const track of stream.getAudioTracks()) {
      if (typeof track.applyConstraints === 'function') {
        track.applyConstraints({ volume }).catch(() => {});
      }
    }
  },
);

onMounted(async () => {
  updatePreviewAspectRatio();
  resizeBound = () => updatePreviewAspectRatio();
  if (typeof window !== 'undefined') {
    window.addEventListener('resize', resizeBound);
    window.addEventListener('orientationchange', resizeBound);
  }
  detachDeviceWatcher = attachCallMediaDeviceWatcher({ requestPermissions: true });
  await loadJoinContext();
  if (state.contextError) return;
  await refreshCallMediaDevices({ requestPermissions: true });
  await startPreview();
});

onBeforeUnmount(() => {
  closeAdmissionSocket({ cancel: state.waitingForAdmission && !admissionAccepted });
  if (typeof window !== 'undefined' && typeof resizeBound === 'function') {
    window.removeEventListener('resize', resizeBound);
    window.removeEventListener('orientationchange', resizeBound);
  }
  resizeBound = null;
  if (typeof detachDeviceWatcher === 'function') {
    detachDeviceWatcher();
    detachDeviceWatcher = null;
  }
  stopPreview();
});
</script>

<style scoped>
.call-access-join-page {
  min-height: 100vh;
  display: grid;
  place-items: center;
  background: var(--color-0b1324);
  padding: 24px;
}

.call-access-join-modal {
  width: min(920px, 100%);
  max-height: calc(100vh - 24px);
  overflow: auto;
  background: var(--color-182c4d);
  border: 1px solid var(--color-133262);
  border-radius: 14px;
  padding: 18px;
  color: var(--color-f7f7f7);
  display: grid;
  gap: 14px;
  box-shadow: 0 8px 26px var(--color-rgba-0-0-0-0-26);
}

.call-access-join-header {
  display: flex;
  align-items: center;
  gap: 10px;
}

.call-access-join-header h1 {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 600;
}

.call-access-join-logo {
  width: 28px;
  height: 28px;
}

.call-access-join-call {
  display: grid;
  gap: 2px;
}

.call-access-join-call-title {
  font-weight: 600;
}

.call-access-join-call-meta {
  font-size: 0.83rem;
  color: var(--color-c9d5ea);
}

.call-access-join-preview {
  position: relative;
  border-radius: 10px;
  overflow: hidden;
  background: var(--color-0b1324);
  width: 100%;
  min-height: 240px;
  max-height: min(60vh, 520px);
  display: grid;
  place-items: center;
}

.call-access-join-preview video {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transform: scaleX(-1);
}

.call-access-join-preview-status {
  position: absolute;
  left: 12px;
  bottom: 10px;
  margin: 0;
  padding: 4px 8px;
  border-radius: 7px;
  background: var(--color-rgba-11-19-36-0-78);
  font-size: 0.78rem;
}

.call-access-join-grid {
  display: grid;
  gap: 10px;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.field {
  display: grid;
  gap: 6px;
}

.field span {
  font-size: 0.82rem;
}

.input {
  width: 100%;
}

.call-access-join-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}

.call-access-join-status {
  font-size: 0.85rem;
}

.call-access-join-status.waiting {
  border: 1px solid var(--brand-cyan);
  border-radius: 8px;
  background: color-mix(in srgb, var(--brand-cyan) 18%, var(--color-182c4d) 82%);
  color: var(--color-f7f7f7);
  padding: 10px 12px;
  font-weight: 700;
}

.call-access-join-status.error,
.call-access-join-preview-status.error {
  color: var(--color-ff0000);
}

@media (max-width: 760px) {
  .call-access-join-page {
    padding: 0;
  }

  .call-access-join-modal {
    width: 100%;
    min-height: 100vh;
    border: 0;
    border-radius: 0;
    padding: 14px;
  }

  .call-access-join-grid {
    grid-template-columns: 1fr;
  }

  .call-access-join-preview {
    max-height: 48vh;
  }
}

@media (max-width: 760px) and (orientation: landscape) {
  .call-access-join-modal {
    padding: 12px;
    gap: 10px;
  }

  .call-access-join-preview {
    max-height: 44vh;
  }
}
</style>
