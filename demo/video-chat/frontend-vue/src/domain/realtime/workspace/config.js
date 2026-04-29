import { debugLog } from '../../../support/debugLogs';

export const USERS_PAGE_SIZE = 10;
export const LOBBY_PAGE_SIZE = 10;
export const ROSTER_VIRTUAL_ROW_HEIGHT = 72;
export const ROSTER_VIRTUAL_OVERSCAN = 6;
export const TYPING_LOCAL_STOP_MS = 1200;
export const TYPING_SWEEP_MS = 600;
export const RECONNECT_DELAYS_MS = [750, 1250, 2000, 3200, 5000, 8000];
export const COMPACT_BREAKPOINT = 1180;
export const REACTION_CLIENT_WINDOW_MS = 1000;
export const REACTION_CLIENT_DIRECT_PER_WINDOW = 5;
export const REACTION_CLIENT_BATCH_SIZE = 5;
export const REACTION_CLIENT_FLUSH_INTERVAL_MS = 40;
export const REACTION_CLIENT_MAX_QUEUE = 500;
export const MODERATION_SYNC_FLUSH_INTERVAL_MS = 90;
export const SFU_PUBLISH_RETRY_DELAY_MS = 500;
export const SFU_PUBLISH_MAX_RETRIES = 24;
export const SFU_CONNECT_RETRY_DELAY_MS = 1200;
export const SFU_CONNECT_MAX_RETRIES = 1;
export const SFU_TRACK_ANNOUNCE_INTERVAL_MS = 3000;
export const LOCAL_REACTION_ECHO_TTL_MS = 6000;
export const WLVC_ENCODE_FAILURE_THRESHOLD = 18;
export const WLVC_ENCODE_FAILURE_WINDOW_MS = 4000;
export const WLVC_ENCODE_WARMUP_MS = 2500;
export const WLVC_ENCODE_ERROR_LOG_COOLDOWN_MS = 3000;
export const LOCAL_CAMERA_CAPTURE_WIDTH = 1280;
export const LOCAL_CAMERA_CAPTURE_HEIGHT = 720;
export const LOCAL_CAMERA_CAPTURE_FRAME_RATE = 27;
export const SFU_WLVC_FRAME_WIDTH = 1280;
export const SFU_WLVC_FRAME_HEIGHT = 720;
export const SFU_WLVC_FRAME_QUALITY = 43;
export const SFU_WLVC_KEYFRAME_INTERVAL = 8;
export const SFU_WLVC_ENCODE_INTERVAL_MS = 92;
export const SFU_WLVC_SEND_BUFFER_HIGH_WATER_BYTES = 2 * 1024 * 1024;
export const SFU_WLVC_SEND_BUFFER_LOW_WATER_BYTES = 512 * 1024;
export const SFU_WLVC_SEND_BUFFER_CRITICAL_BYTES = 5 * 1024 * 1024;
export const SFU_WLVC_BACKPRESSURE_MIN_PAUSE_MS = 350;
export const SFU_WLVC_BACKPRESSURE_MAX_PAUSE_MS = 2500;
export const SFU_WLVC_BACKPRESSURE_HARD_RESET_AFTER_MS = 30_000;
export const DEFAULT_SFU_VIDEO_QUALITY_PROFILE = 'balanced';
export const SFU_VIDEO_QUALITY_PROFILE_BUDGETS = Object.freeze({
  rescue: Object.freeze({
    maxEncodedBytesPerFrame: 180 * 1024,
    maxKeyframeBytesPerFrame: 256 * 1024,
    maxWireBytesPerSecond: 420 * 1024,
    maxEncodeMs: 45,
    maxDrawImageMs: 8,
    maxReadbackMs: 10,
    maxQueueAgeMs: 120,
    maxBufferedBytes: 384 * 1024,
    payloadSoftLimitRatio: 0.9,
    minKeyframeRetryMs: 1400,
    expectedRecovery: 'hold_rescue_until_socket_low_water',
  }),
  realtime: Object.freeze({
    maxEncodedBytesPerFrame: 360 * 1024,
    maxKeyframeBytesPerFrame: 560 * 1024,
    maxWireBytesPerSecond: 850 * 1024,
    maxEncodeMs: 55,
    maxDrawImageMs: 10,
    maxReadbackMs: 14,
    maxQueueAgeMs: 140,
    maxBufferedBytes: 640 * 1024,
    payloadSoftLimitRatio: 0.88,
    minKeyframeRetryMs: 1100,
    expectedRecovery: 'downshift_to_rescue_before_critical_buffer',
  }),
  balanced: Object.freeze({
    maxEncodedBytesPerFrame: 720 * 1024,
    maxKeyframeBytesPerFrame: 960 * 1024,
    maxWireBytesPerSecond: 1400 * 1024,
    maxEncodeMs: 70,
    maxDrawImageMs: 14,
    maxReadbackMs: 18,
    maxQueueAgeMs: 180,
    maxBufferedBytes: 1024 * 1024,
    payloadSoftLimitRatio: 0.86,
    minKeyframeRetryMs: 900,
    expectedRecovery: 'downshift_to_realtime_before_critical_buffer',
  }),
  quality: Object.freeze({
    maxEncodedBytesPerFrame: 1280 * 1024,
    maxKeyframeBytesPerFrame: 1536 * 1024,
    maxWireBytesPerSecond: 2200 * 1024,
    maxEncodeMs: 90,
    maxDrawImageMs: 18,
    maxReadbackMs: 24,
    maxQueueAgeMs: 220,
    maxBufferedBytes: 1536 * 1024,
    payloadSoftLimitRatio: 0.84,
    minKeyframeRetryMs: 800,
    expectedRecovery: 'downshift_to_balanced_before_critical_buffer',
  }),
});

function readbackFrameRateForInterval(intervalMs) {
  const normalizedIntervalMs = Number(intervalMs || 0);
  if (!Number.isFinite(normalizedIntervalMs) || normalizedIntervalMs <= 0) return 0;
  return Number((1000 / normalizedIntervalMs).toFixed(3));
}

export const SFU_VIDEO_QUALITY_PROFILES = Object.freeze({
  rescue: Object.freeze({
    id: 'rescue',
    label: 'Low',
    captureWidth: 320,
    captureHeight: 180,
    captureFrameRate: 7,
    frameWidth: 320,
    frameHeight: 180,
    frameQuality: 20,
    keyFrameInterval: 16,
    encodeIntervalMs: 244,
    readbackFrameRate: readbackFrameRateForInterval(244),
    readbackIntervalMs: 244,
    ...SFU_VIDEO_QUALITY_PROFILE_BUDGETS.rescue,
  }),
  realtime: Object.freeze({
    id: 'realtime',
    label: 'Fast',
    captureWidth: 512,
    captureHeight: 288,
    captureFrameRate: 11,
    frameWidth: 512,
    frameHeight: 288,
    frameQuality: 29,
    keyFrameInterval: 12,
    encodeIntervalMs: 167,
    readbackFrameRate: readbackFrameRateForInterval(167),
    readbackIntervalMs: 167,
    ...SFU_VIDEO_QUALITY_PROFILE_BUDGETS.realtime,
  }),
  balanced: Object.freeze({
    id: 'balanced',
    label: 'Balanced',
    captureWidth: 640,
    captureHeight: 360,
    captureFrameRate: 14,
    frameWidth: 640,
    frameHeight: 360,
    frameQuality: 33,
    keyFrameInterval: 12,
    encodeIntervalMs: 111,
    readbackFrameRate: readbackFrameRateForInterval(111),
    readbackIntervalMs: 111,
    ...SFU_VIDEO_QUALITY_PROFILE_BUDGETS.balanced,
  }),
  quality: Object.freeze({
    id: 'quality',
    label: 'Quality',
    captureWidth: LOCAL_CAMERA_CAPTURE_WIDTH,
    captureHeight: LOCAL_CAMERA_CAPTURE_HEIGHT,
    captureFrameRate: LOCAL_CAMERA_CAPTURE_FRAME_RATE,
    frameWidth: 1280,
    frameHeight: 720,
    frameQuality: SFU_WLVC_FRAME_QUALITY,
    keyFrameInterval: SFU_WLVC_KEYFRAME_INTERVAL,
    encodeIntervalMs: SFU_WLVC_ENCODE_INTERVAL_MS,
    readbackFrameRate: readbackFrameRateForInterval(SFU_WLVC_ENCODE_INTERVAL_MS),
    readbackIntervalMs: SFU_WLVC_ENCODE_INTERVAL_MS,
    ...SFU_VIDEO_QUALITY_PROFILE_BUDGETS.quality,
  }),
});
export function normalizeSfuVideoQualityProfile(value) {
  const normalized = String(value || '').trim().toLowerCase();
  return Object.prototype.hasOwnProperty.call(SFU_VIDEO_QUALITY_PROFILES, normalized)
    ? normalized
    : DEFAULT_SFU_VIDEO_QUALITY_PROFILE;
}

export function resolveSfuVideoQualityProfile(value) {
  return SFU_VIDEO_QUALITY_PROFILES[normalizeSfuVideoQualityProfile(value)];
}

export function resolveSfuVideoQualityProfileBudget(value) {
  return SFU_VIDEO_QUALITY_PROFILE_BUDGETS[normalizeSfuVideoQualityProfile(value)];
}
export const LOCAL_TRACK_RECOVERY_BASE_DELAY_MS = 1200;
export const LOCAL_TRACK_RECOVERY_MAX_DELAY_MS = 10_000;
export const LOCAL_TRACK_RECOVERY_MAX_ATTEMPTS = 10;
export const VISIBLE_PARTICIPANTS_LIMIT = 5;
export const PARTICIPANT_ACTIVITY_WINDOW_MS = 15_000;
export const ALONE_IDLE_PROMPT_AFTER_MS = 15 * 60 * 1000;
export const ALONE_IDLE_COUNTDOWN_MS = 5 * 60 * 1000;
export const ALONE_IDLE_TICK_MS = 1000;
export const ALONE_IDLE_POLL_MS = 5000;
export const ALONE_IDLE_ACTIVITY_EVENTS = ['pointerdown', 'keydown', 'wheel', 'touchstart'];

function normalizeIceServerEntry(value) {
  if (!value || typeof value !== 'object') return null;

  let urls = value.urls;
  if (Array.isArray(urls)) {
    urls = urls.map((entry) => String(entry || '').trim()).filter(Boolean);
    if (urls.length === 0) return null;
  } else {
    urls = String(urls || '').trim();
    if (urls === '') return null;
  }

  const normalized = { urls };
  const username = String(value.username || '').trim();
  const credential = String(value.credential || '').trim();

  if (username !== '') normalized.username = username;
  if (credential !== '') normalized.credential = credential;

  return normalized;
}

function parseIceServersFromEnv(rawValue) {
  const raw = String(rawValue || '').trim();
  if (raw === '') return null;

  try {
    const parsed = JSON.parse(raw);
    if (!Array.isArray(parsed)) return null;

    const normalized = parsed.map((entry) => normalizeIceServerEntry(entry)).filter(Boolean);
    return normalized.length > 0 ? normalized : null;
  } catch {
    const normalized = raw
      .split(',')
      .map((entry) => String(entry || '').trim())
      .filter(Boolean)
      .map((entry) => normalizeIceServerEntry({ urls: entry }))
      .filter(Boolean);
    return normalized.length > 0 ? normalized : null;
  }
}

function parseEnvFlag(value, fallback = false) {
  const normalized = String(value ?? '').trim().toLowerCase();
  if (normalized === '') return fallback;
  return ['1', 'true', 'yes', 'on'].includes(normalized);
}

function defaultTurnHostFromLocation() {
  if (typeof window === 'undefined' || !window.location || !window.location.hostname) return '';

  const hostname = String(window.location.hostname || '').trim().toLowerCase();
  if (hostname === '') return '';
  if (hostname === 'localhost' || hostname === '127.0.0.1' || hostname === '::1') return hostname;
  if (hostname.startsWith('turn.')) return hostname;
  if (/^[0-9.]+$/.test(hostname) || hostname.includes(':')) return hostname;

  return `turn.${hostname.replace(/^(api|ws|sfu|cdn|cnd)\./, '')}`;
}

function buildDefaultNativeIceServers() {
  const turnHost = defaultTurnHostFromLocation();
  if (turnHost === '') return [];
  return [{ urls: `stun:${turnHost}:3478` }];
}

export const DEFAULT_NATIVE_ICE_SERVERS = parseIceServersFromEnv(import.meta.env.VITE_VIDEOCHAT_ICE_SERVERS) || buildDefaultNativeIceServers();
export const SFU_RUNTIME_ENABLED = parseEnvFlag(import.meta.env.VITE_VIDEOCHAT_ENABLE_SFU, false);

export function mediaDebugLog(...args) {
  debugLog(...args);
}

export const reactionOptions = ['👍', '❤️', '🐘', '🥳', '😂', '😮', '😢', '🤔', '👏', '👎'];
export const chatEmojiOptions = [
  ...reactionOptions,
  '🙏',
  '🔥',
  '✅',
  '👀',
  '💯',
  '🤣',
  '😍',
  '🙌',
  '🚀',
  '💬',
];
