import { reactive } from 'vue';
import {
  DEFAULT_SFU_VIDEO_QUALITY_PROFILE,
  normalizeSfuVideoQualityProfile,
} from '../workspace/config';
import { buildOptionalCallAudioCaptureConstraints } from './audioCaptureConstraints';

const CALL_MEDIA_PREFS_KEY = 'ii.videocall.preview_prefs.v1';
const CALL_MEDIA_PREFS_OUTGOING_VIDEO_PROFILE_VERSION = 5;
const CALL_MEDIA_DEVICE_REFRESH_CACHE_MS = 30000;
export const DEFAULT_BACKGROUND_REPLACEMENT_IMAGE_URL = '/assets/orgas/kingrt/social/invitation-preview.png';

function clampVolume(value) {
  const numeric = Number(value);
  if (!Number.isFinite(numeric)) return 100;
  if (numeric < 0) return 0;
  if (numeric > 100) return 100;
  return Math.round(numeric);
}

function clampInteger(value, fallback, min, max) {
  const numeric = Number(value);
  if (!Number.isFinite(numeric)) return fallback;
  return Math.max(min, Math.min(max, Math.round(numeric)));
}

function toStringValue(value) {
  return typeof value === 'string' ? value : '';
}

function toFacingMode(value) {
  return value === 'environment' ? 'environment' : 'user';
}

function toBackgroundFilterMode(value) {
  if (value === 'replace') return 'replace';
  return value === 'blur' ? 'blur' : 'off';
}

function isLegacyBackgroundReplacementImageUrl(value) {
  const path = String(value || '').split(/[?#]/)[0];
  return path === `/assets/images/${'bookshelf.png'}`;
}

function toBackgroundReplacementImageUrl(value) {
  const url = typeof value === 'string' ? value.trim() : '';
  if (isLegacyBackgroundReplacementImageUrl(url)) {
    return DEFAULT_BACKGROUND_REPLACEMENT_IMAGE_URL;
  }
  return url;
}

function toBackgroundBackdropMode(value) {
  if (value === 'image') return 'image';
  if (value === 'green') return 'green';
  if (value === 'blur9') return 'blur9';
  return 'blur7';
}

function toBackgroundQualityProfile(value) {
  if (value === 'quality') return 'quality';
  if (value === 'realtime') return 'realtime';
  return 'balanced';
}

function toAvatarQualityProfile(value) {
  if (value === 'quality') return 'quality';
  if (value === 'realtime') return 'realtime';
  return 'balanced';
}

function toOutgoingVideoQualityProfile(value) {
  return normalizeSfuVideoQualityProfile(value);
}

function toApplyOutgoing(value) {
  return typeof value === 'boolean' ? value : true;
}

function readPersistedCallMediaPrefs() {
  if (typeof localStorage === 'undefined') return null;

  const raw = localStorage.getItem(CALL_MEDIA_PREFS_KEY);
  if (!raw) return null;

  try {
    const parsed = JSON.parse(raw);
    if (!parsed || typeof parsed !== 'object') return null;

    const outgoingVideoProfileVersion = Number(parsed.outgoing_video_quality_profile_version || 0);

    return {
      selectedCameraId: toStringValue(parsed.video_id),
      selectedMicrophoneId: toStringValue(parsed.audio_id),
      selectedSpeakerId: toStringValue(parsed.output_id),
      microphoneVolume: clampVolume(parsed.microphone_volume),
      speakerVolume: clampVolume(parsed.speaker_volume),
      facingMode: toFacingMode(parsed.facing_mode),
      backgroundFilterMode: toBackgroundFilterMode(parsed.background_filter_mode),
      backgroundBackdropMode: toBackgroundBackdropMode(parsed.background_backdrop_mode),
      backgroundQualityProfile: toBackgroundQualityProfile(parsed.background_quality_profile),
      avatarQualityProfile: toAvatarQualityProfile(parsed.avatar_quality_profile),
      outgoingVideoQualityProfile: outgoingVideoProfileVersion >= CALL_MEDIA_PREFS_OUTGOING_VIDEO_PROFILE_VERSION
        ? toOutgoingVideoQualityProfile(parsed.outgoing_video_quality_profile)
        : DEFAULT_SFU_VIDEO_QUALITY_PROFILE,
      backgroundBlurStrength: clampInteger(parsed.background_blur_strength, 2, 0, 4),
      backgroundMaskVariant: clampInteger(parsed.background_mask_variant, 4, 1, 10),
      backgroundBlurTransition: clampInteger(parsed.background_blur_transition, 10, 1, 10),
      backgroundApplyOutgoing: toApplyOutgoing(parsed.background_apply_outgoing),
      backgroundReplacementImageUrl: toBackgroundReplacementImageUrl(parsed.background_replacement_image_url),
      backgroundMaxProcessWidth: clampInteger(parsed.background_max_process_width, 960, 320, 1920),
      backgroundMaxProcessFps: clampInteger(parsed.background_max_process_fps, 24, 8, 30),
    };
  } catch {
    return null;
  }
}

function serializeCallMediaPrefs() {
  return JSON.stringify({
    video_id: String(callMediaPrefs.selectedCameraId || '').trim(),
    audio_id: String(callMediaPrefs.selectedMicrophoneId || '').trim(),
    output_id: String(callMediaPrefs.selectedSpeakerId || '').trim(),
    microphone_volume: clampVolume(callMediaPrefs.microphoneVolume),
    speaker_volume: clampVolume(callMediaPrefs.speakerVolume),
    facing_mode: toFacingMode(callMediaPrefs.facingMode),
    background_filter_mode: toBackgroundFilterMode(callMediaPrefs.backgroundFilterMode),
    background_backdrop_mode: toBackgroundBackdropMode(callMediaPrefs.backgroundBackdropMode),
    background_quality_profile: toBackgroundQualityProfile(callMediaPrefs.backgroundQualityProfile),
    avatar_quality_profile: toAvatarQualityProfile(callMediaPrefs.avatarQualityProfile),
    outgoing_video_quality_profile: toOutgoingVideoQualityProfile(callMediaPrefs.outgoingVideoQualityProfile),
    outgoing_video_quality_profile_version: CALL_MEDIA_PREFS_OUTGOING_VIDEO_PROFILE_VERSION,
    background_blur_strength: clampInteger(callMediaPrefs.backgroundBlurStrength, 2, 0, 4),
    background_mask_variant: clampInteger(callMediaPrefs.backgroundMaskVariant, 4, 1, 10),
    background_blur_transition: clampInteger(callMediaPrefs.backgroundBlurTransition, 10, 1, 10),
    background_apply_outgoing: toApplyOutgoing(callMediaPrefs.backgroundApplyOutgoing),
    background_replacement_image_url: toBackgroundReplacementImageUrl(callMediaPrefs.backgroundReplacementImageUrl),
    background_max_process_width: clampInteger(callMediaPrefs.backgroundMaxProcessWidth, 960, 320, 1920),
    background_max_process_fps: clampInteger(callMediaPrefs.backgroundMaxProcessFps, 24, 8, 30),
  });
}

function persistCallMediaPrefs() {
  if (typeof localStorage === 'undefined') return;
  try {
    localStorage.setItem(CALL_MEDIA_PREFS_KEY, serializeCallMediaPrefs());
  } catch {
    // ignore persistence errors
  }
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

const persistedPrefs = readPersistedCallMediaPrefs();

export const callMediaPrefs = reactive({
  cameras: [],
  microphones: [],
  speakers: [],
  selectedCameraId: persistedPrefs?.selectedCameraId || '',
  selectedMicrophoneId: persistedPrefs?.selectedMicrophoneId || '',
  selectedSpeakerId: persistedPrefs?.selectedSpeakerId || '',
  microphoneVolume: persistedPrefs?.microphoneVolume ?? 100,
  speakerVolume: persistedPrefs?.speakerVolume ?? 100,
  facingMode: persistedPrefs?.facingMode || 'user',
  backgroundFilterMode: persistedPrefs?.backgroundFilterMode || 'off',
  backgroundBackdropMode: persistedPrefs?.backgroundBackdropMode || 'blur7',
  backgroundQualityProfile: persistedPrefs?.backgroundQualityProfile || 'balanced',
  avatarQualityProfile: persistedPrefs?.avatarQualityProfile || 'balanced',
  outgoingVideoQualityProfile: persistedPrefs?.outgoingVideoQualityProfile || DEFAULT_SFU_VIDEO_QUALITY_PROFILE,
  backgroundBlurStrength: persistedPrefs?.backgroundBlurStrength ?? 2,
  backgroundMaskVariant: persistedPrefs?.backgroundMaskVariant ?? 4,
  backgroundBlurTransition: persistedPrefs?.backgroundBlurTransition ?? 10,
  backgroundApplyOutgoing: persistedPrefs?.backgroundApplyOutgoing ?? true,
  backgroundReplacementImageUrl: persistedPrefs?.backgroundReplacementImageUrl || '',
  backgroundMaxProcessWidth: persistedPrefs?.backgroundMaxProcessWidth ?? 960,
  backgroundMaxProcessFps: persistedPrefs?.backgroundMaxProcessFps ?? 24,
  backgroundFilterActive: false,
  backgroundFilterReason: 'idle',
  backgroundFilterBackend: 'none',
  backgroundFilterFps: 0,
  backgroundFilterDetectMs: 0,
  backgroundFilterDetectFps: 0,
  backgroundFilterProcessMs: 0,
  backgroundFilterProcessLoad: 0,
  backgroundBaselineSampleCount: 0,
  backgroundBaselineMedianFps: 0,
  backgroundBaselineP95Fps: 0,
  backgroundBaselineMedianDetectMs: 0,
  backgroundBaselineP95DetectMs: 0,
  backgroundBaselineMedianDetectFps: 0,
  backgroundBaselineP95DetectFps: 0,
  backgroundBaselineMedianProcessMs: 0,
  backgroundBaselineP95ProcessMs: 0,
  backgroundBaselineMedianProcessLoad: 0,
  backgroundBaselineP95ProcessLoad: 0,
  backgroundBaselineGatePass: false,
  backgroundBaselineGateFpsPass: false,
  backgroundBaselineGateDetectPass: false,
  backgroundBaselineGateLoadPass: false,
  ready: false,
  error: '',
});

let refreshPromise = null;
let watcherRefCount = 0;
let deviceChangeListenerAttached = false;
let lastDeviceRefreshAt = 0;
let lastDeviceRefreshHadPermissions = false;

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
    stream = await navigator.mediaDevices.getUserMedia({
      audio: buildOptionalCallAudioCaptureConstraints(true),
      video: true,
    });
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
  lastDeviceRefreshAt = Date.now();
  lastDeviceRefreshHadPermissions = rows.some((device) => String(device?.label || '').trim() !== '');
  persistCallMediaPrefs();
}

export async function refreshCallMediaDevices({ force = false, requestPermissions = false } = {}) {
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

  const now = Date.now();
  const cacheFresh = callMediaPrefs.ready && now - lastDeviceRefreshAt < CALL_MEDIA_DEVICE_REFRESH_CACHE_MS;
  const cacheSatisfiesPermission = !requestPermissions || lastDeviceRefreshHadPermissions;
  if (!force && cacheFresh && cacheSatisfiesPermission) {
    return true;
  }

  refreshPromise = (async () => {
    if (requestPermissions && (!lastDeviceRefreshHadPermissions || force)) {
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
  persistCallMediaPrefs();
}

export function setCallMicrophoneDevice(deviceId) {
  callMediaPrefs.selectedMicrophoneId = resolveSelectedDevice(deviceId, callMediaPrefs.microphones);
  persistCallMediaPrefs();
}

export function setCallSpeakerDevice(deviceId) {
  callMediaPrefs.selectedSpeakerId = resolveSelectedDevice(deviceId, callMediaPrefs.speakers);
  persistCallMediaPrefs();
}

export function setCallSpeakerVolume(value) {
  callMediaPrefs.speakerVolume = clampVolume(value);
  persistCallMediaPrefs();
}

export function setCallMicrophoneVolume(value) {
  callMediaPrefs.microphoneVolume = clampVolume(value);
  persistCallMediaPrefs();
}

export function setCallBackgroundFilterMode(mode) {
  callMediaPrefs.backgroundFilterMode = toBackgroundFilterMode(mode);
  persistCallMediaPrefs();
}

export function setCallBackgroundBackdropMode(mode) {
  callMediaPrefs.backgroundBackdropMode = toBackgroundBackdropMode(mode);
  persistCallMediaPrefs();
}

export function setCallBackgroundQualityProfile(profile) {
  callMediaPrefs.backgroundQualityProfile = toBackgroundQualityProfile(profile);
  persistCallMediaPrefs();
}

export function setCallOutgoingVideoQualityProfile(profile) {
  callMediaPrefs.outgoingVideoQualityProfile = toOutgoingVideoQualityProfile(profile);
  persistCallMediaPrefs();
}

export function setCallBackgroundBlurStrength(value) {
  callMediaPrefs.backgroundBlurStrength = clampInteger(value, 2, 0, 4);
  persistCallMediaPrefs();
}

export function setCallBackgroundApplyOutgoing(value) {
  callMediaPrefs.backgroundApplyOutgoing = Boolean(value);
  persistCallMediaPrefs();
}

export function setCallBackgroundReplacementImageUrl(value) {
  callMediaPrefs.backgroundReplacementImageUrl = toBackgroundReplacementImageUrl(value);
  persistCallMediaPrefs();
}

export function isCallBackgroundPresetActive(preset) {
  const mode = String(callMediaPrefs.backgroundFilterMode || 'off').trim().toLowerCase();
  const applyOutgoing = Boolean(callMediaPrefs.backgroundApplyOutgoing);
  const backdrop = String(callMediaPrefs.backgroundBackdropMode || 'blur7').trim().toLowerCase();

  if (preset === 'off') {
    return mode === 'off' || !applyOutgoing;
  }
  if (preset === 'green') {
    return mode === 'replace' && applyOutgoing && backdrop === 'green';
  }
  if (preset === 'image') {
    return mode === 'replace' && applyOutgoing && backdrop === 'image'
      && String(callMediaPrefs.backgroundReplacementImageUrl || '').trim() !== '';
  }
  if (preset === 'light') {
    return mode === 'blur' && applyOutgoing && backdrop === 'blur7';
  }
  if (preset === 'strong') {
    return mode === 'blur' && applyOutgoing && backdrop === 'blur9';
  }
  return false;
}

export function applyCallBackgroundPreset(preset) {
  if (preset === 'image') {
    setCallBackgroundReplacementImageUrl(DEFAULT_BACKGROUND_REPLACEMENT_IMAGE_URL);
    setCallBackgroundBackdropMode('image');
    setCallBackgroundFilterMode('replace');
    setCallBackgroundApplyOutgoing(true);
    return;
  }
  if (preset === 'green') {
    setCallBackgroundReplacementImageUrl('');
    setCallBackgroundBackdropMode('green');
    setCallBackgroundFilterMode('replace');
    setCallBackgroundApplyOutgoing(true);
    return;
  }
  if (preset !== 'light' && preset !== 'strong') {
    setCallBackgroundFilterMode('off');
    setCallBackgroundApplyOutgoing(false);
    return;
  }

  if (isCallBackgroundPresetActive(preset)) {
    setCallBackgroundFilterMode('off');
    setCallBackgroundApplyOutgoing(false);
    return;
  }

  setCallBackgroundFilterMode('blur');
  setCallBackgroundApplyOutgoing(true);

  if (preset === 'strong') {
    setCallBackgroundBackdropMode('blur9');
    setCallBackgroundQualityProfile('quality');
    setCallBackgroundBlurStrength(4);
    return;
  }

  setCallBackgroundBackdropMode('blur7');
  setCallBackgroundQualityProfile('balanced');
  setCallBackgroundBlurStrength(2);
}

export function setCallBackgroundMaxProcessWidth(value) {
  callMediaPrefs.backgroundMaxProcessWidth = clampInteger(value, 960, 320, 1920);
  persistCallMediaPrefs();
}

export function setCallBackgroundMaxProcessFps(value) {
  callMediaPrefs.backgroundMaxProcessFps = clampInteger(value, 24, 8, 30);
  persistCallMediaPrefs();
}

export function resetCallBackgroundRuntimeState() {
  callMediaPrefs.backgroundFilterActive = false;
  callMediaPrefs.backgroundFilterReason = 'idle';
  callMediaPrefs.backgroundFilterBackend = 'none';
  callMediaPrefs.backgroundFilterFps = 0;
  callMediaPrefs.backgroundFilterDetectMs = 0;
  callMediaPrefs.backgroundFilterDetectFps = 0;
  callMediaPrefs.backgroundFilterProcessMs = 0;
  callMediaPrefs.backgroundFilterProcessLoad = 0;
  callMediaPrefs.backgroundBaselineSampleCount = 0;
  callMediaPrefs.backgroundBaselineMedianFps = 0;
  callMediaPrefs.backgroundBaselineP95Fps = 0;
  callMediaPrefs.backgroundBaselineMedianDetectMs = 0;
  callMediaPrefs.backgroundBaselineP95DetectMs = 0;
  callMediaPrefs.backgroundBaselineMedianDetectFps = 0;
  callMediaPrefs.backgroundBaselineP95DetectFps = 0;
  callMediaPrefs.backgroundBaselineMedianProcessMs = 0;
  callMediaPrefs.backgroundBaselineP95ProcessMs = 0;
  callMediaPrefs.backgroundBaselineMedianProcessLoad = 0;
  callMediaPrefs.backgroundBaselineP95ProcessLoad = 0;
  callMediaPrefs.backgroundBaselineGatePass = false;
  callMediaPrefs.backgroundBaselineGateFpsPass = false;
  callMediaPrefs.backgroundBaselineGateDetectPass = false;
  callMediaPrefs.backgroundBaselineGateLoadPass = false;
}

function handleDeviceChange() {
  void refreshCallMediaDevices({ force: true });
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
