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
            />
          </label>
        </section>

        <section class="call-access-join-grid">
          <label class="field">
            <span>Camera</span>
            <select
              class="input"
              :value="callMediaPrefs.selectedCameraId"
              @change="setCallCameraDevice($event.target.value)"
            >
              <option value="">{{ callMediaPrefs.cameras.length === 0 ? 'No camera detected' : 'Select camera' }}</option>
              <option v-for="camera in callMediaPrefs.cameras" :key="camera.id" :value="camera.id">
                {{ camera.label }}
              </option>
            </select>
          </label>

          <label class="field">
            <span>Microphone</span>
            <select
              class="input"
              :value="callMediaPrefs.selectedMicrophoneId"
              @change="setCallMicrophoneDevice($event.target.value)"
            >
              <option value="">{{ callMediaPrefs.microphones.length === 0 ? 'No microphone detected' : 'Select mic' }}</option>
              <option v-for="microphone in callMediaPrefs.microphones" :key="microphone.id" :value="microphone.id">
                {{ microphone.label }}
              </option>
            </select>
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
            <select
              class="input"
              :value="callMediaPrefs.selectedSpeakerId"
              @change="setCallSpeakerDevice($event.target.value)"
            >
              <option value="">{{ callMediaPrefs.speakers.length === 0 ? 'No speaker detected' : 'Select speaker' }}</option>
              <option v-for="speaker in callMediaPrefs.speakers" :key="speaker.id" :value="speaker.id">
                {{ speaker.label }}
              </option>
            </select>
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

        <footer class="call-access-join-actions">
          <button class="btn" type="button" :disabled="state.joining" @click="goToLogin">Cancel</button>
          <button class="btn" type="button" :disabled="state.joining" @click="startSessionAndJoin">
            {{ state.joining ? 'Joining...' : 'Join call' }}
          </button>
        </footer>
      </template>
    </section>
  </main>
</template>

<script setup>
import { nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { loginWithCallAccess } from '../auth/session';
import { currentBackendOrigin, fetchBackend } from '../../support/backendFetch';
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

const state = reactive({
  loadingContext: true,
  contextError: '',
  callId: '',
  callTitle: '',
  linkKind: 'personal',
  guestName: '',
  joining: false,
  joinError: '',
  previewReady: false,
  previewError: '',
});

function normalizeAccessId(value) {
  return String(value || '').trim().toLowerCase();
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
  state.callTitle = '';
  state.linkKind = 'personal';
  state.guestName = '';
  state.joinError = '';

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
  router.replace('/login');
}

async function startSessionAndJoin() {
  if (state.joining || state.loadingContext || state.contextError) return;
  if (state.linkKind === 'open' && String(state.guestName || '').trim() === '') {
    state.joinError = 'Name is required for this link.';
    return;
  }
  state.joining = true;
  state.joinError = '';

  const accessId = normalizeAccessId(route.params.accessId);
  const result = await loginWithCallAccess(accessId, {
    guestName: state.linkKind === 'open' ? state.guestName : '',
  });
  if (!result.ok) {
    state.joining = false;
    state.joinError = result.message || 'Could not start call session.';
    return;
  }

  stopPreview();
  state.joining = false;
  router.replace({
    name: 'call-workspace',
    params: { callRef: accessId },
    query: { entry: 'invite' },
  });
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
  background: #0B1324;
  padding: 24px;
}

.call-access-join-modal {
  width: min(920px, 100%);
  max-height: calc(100vh - 24px);
  overflow: auto;
  background: #182c4d;
  border: 1px solid #133262;
  border-radius: 14px;
  padding: 18px;
  color: #f7f7f7;
  display: grid;
  gap: 14px;
  box-shadow: 0 8px 26px rgba(0, 0, 0, 0.26);
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
  color: #c9d5ea;
}

.call-access-join-preview {
  position: relative;
  border-radius: 10px;
  overflow: hidden;
  background: #0B1324;
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
  background: rgba(11, 19, 36, 0.78);
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

.call-access-join-status.error,
.call-access-join-preview-status.error {
  color: #ff0000;
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
