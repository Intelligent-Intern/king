import { reactive } from 'vue';

function clampVolume(value) {
  const numeric = Number(value);
  if (!Number.isFinite(numeric)) return 100;
  if (numeric < 0) return 0;
  if (numeric > 100) return 100;
  return Math.round(numeric);
}

function normalizeDeviceLabel(device, fallbackLabel, index) {
  const raw = String(device?.label || '').trim();
  if (raw !== '') return raw;
  return `${fallbackLabel} ${index + 1}`;
}

function mapMediaDevices(devices, kind, fallbackLabel) {
  return devices
    .filter((device) => String(device?.kind || '').toLowerCase() === kind)
    .map((device, index) => ({
      id: String(device?.deviceId || '').trim(),
      label: normalizeDeviceLabel(device, fallbackLabel, index),
    }))
    .filter((device) => device.id !== '');
}

function resolveSelectedDevice(previousId, rows) {
  const normalizedPreviousId = String(previousId || '').trim();
  if (normalizedPreviousId !== '' && rows.some((row) => row.id === normalizedPreviousId)) {
    return normalizedPreviousId;
  }
  return rows[0]?.id || '';
}

export const callMediaPrefs = reactive({
  cameras: [],
  microphones: [],
  speakers: [],
  selectedCameraId: '',
  selectedMicrophoneId: '',
  selectedSpeakerId: '',
  microphoneVolume: 100,
  speakerVolume: 100,
  ready: false,
  error: '',
});

let refreshPromise = null;
let watcherRefCount = 0;
let deviceChangeListenerAttached = false;

async function maybeRequestDeviceLabels() {
  if (
    typeof navigator === 'undefined'
    || !navigator.mediaDevices
    || typeof navigator.mediaDevices.getUserMedia !== 'function'
  ) {
    return;
  }

  let stream = null;
  try {
    stream = await navigator.mediaDevices.getUserMedia({ audio: true, video: true });
  } finally {
    if (stream instanceof MediaStream) {
      for (const track of stream.getTracks()) {
        track.stop();
      }
    }
  }
}

function applyEnumeratedDevices(devices) {
  const rows = Array.isArray(devices) ? devices : [];
  const nextCameras = mapMediaDevices(rows, 'videoinput', 'Camera');
  const nextMicrophones = mapMediaDevices(rows, 'audioinput', 'Microphone');
  const nextSpeakers = mapMediaDevices(rows, 'audiooutput', 'Speaker');

  callMediaPrefs.cameras = nextCameras;
  callMediaPrefs.microphones = nextMicrophones;
  callMediaPrefs.speakers = nextSpeakers;
  callMediaPrefs.selectedCameraId = resolveSelectedDevice(callMediaPrefs.selectedCameraId, nextCameras);
  callMediaPrefs.selectedMicrophoneId = resolveSelectedDevice(callMediaPrefs.selectedMicrophoneId, nextMicrophones);
  callMediaPrefs.selectedSpeakerId = resolveSelectedDevice(callMediaPrefs.selectedSpeakerId, nextSpeakers);
  callMediaPrefs.ready = true;
  callMediaPrefs.error = '';
}

export async function refreshCallMediaDevices({ requestPermissions = false } = {}) {
  if (
    typeof navigator === 'undefined'
    || !navigator.mediaDevices
    || typeof navigator.mediaDevices.enumerateDevices !== 'function'
  ) {
    callMediaPrefs.ready = false;
    callMediaPrefs.error = 'Media devices are not supported in this browser.';
    return false;
  }

  if (refreshPromise) {
    return refreshPromise;
  }

  refreshPromise = (async () => {
    if (requestPermissions) {
      try {
        await maybeRequestDeviceLabels();
      } catch {
        // Permissions can be denied; enumerateDevices still returns device ids.
      }
    }

    const devices = await navigator.mediaDevices.enumerateDevices();
    applyEnumeratedDevices(devices);
    return true;
  })().catch((error) => {
    callMediaPrefs.ready = false;
    callMediaPrefs.error = error instanceof Error ? error.message : 'Could not read media devices.';
    return false;
  }).finally(() => {
    refreshPromise = null;
  });

  return refreshPromise;
}

export function setCallCameraDevice(deviceId) {
  callMediaPrefs.selectedCameraId = resolveSelectedDevice(deviceId, callMediaPrefs.cameras);
}

export function setCallMicrophoneDevice(deviceId) {
  callMediaPrefs.selectedMicrophoneId = resolveSelectedDevice(deviceId, callMediaPrefs.microphones);
}

export function setCallSpeakerDevice(deviceId) {
  callMediaPrefs.selectedSpeakerId = resolveSelectedDevice(deviceId, callMediaPrefs.speakers);
}

export function setCallSpeakerVolume(value) {
  callMediaPrefs.speakerVolume = clampVolume(value);
}

export function setCallMicrophoneVolume(value) {
  callMediaPrefs.microphoneVolume = clampVolume(value);
}

function handleDeviceChange() {
  void refreshCallMediaDevices();
}

export function attachCallMediaDeviceWatcher({ requestPermissions = false } = {}) {
  watcherRefCount += 1;

  if (
    !deviceChangeListenerAttached
    && typeof navigator !== 'undefined'
    && navigator.mediaDevices
    && typeof navigator.mediaDevices.addEventListener === 'function'
  ) {
    navigator.mediaDevices.addEventListener('devicechange', handleDeviceChange);
    deviceChangeListenerAttached = true;
  }

  void refreshCallMediaDevices({ requestPermissions });

  return () => {
    watcherRefCount = Math.max(0, watcherRefCount - 1);
    if (
      watcherRefCount === 0
      && deviceChangeListenerAttached
      && typeof navigator !== 'undefined'
      && navigator.mediaDevices
      && typeof navigator.mediaDevices.removeEventListener === 'function'
    ) {
      navigator.mediaDevices.removeEventListener('devicechange', handleDeviceChange);
      deviceChangeListenerAttached = false;
    }
  };
}
