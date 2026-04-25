import { debugLog } from '../../support/debugLogs';

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
export const LOCAL_CAMERA_CAPTURE_FRAME_RATE = 24;
export const SFU_WLVC_FRAME_WIDTH = 960;
export const SFU_WLVC_FRAME_HEIGHT = 540;
export const SFU_WLVC_FRAME_QUALITY = 76;
export const SFU_WLVC_KEYFRAME_INTERVAL = 24;
export const SFU_WLVC_ENCODE_INTERVAL_MS = 50;
export const SFU_WLVC_SEND_BUFFER_HIGH_WATER_BYTES = 2 * 1024 * 1024;
export const SFU_WLVC_SEND_BUFFER_CRITICAL_BYTES = 6 * 1024 * 1024;
export const SFU_WLVC_BACKPRESSURE_DOWNGRADE_THRESHOLD = 10;
export const DEFAULT_SFU_VIDEO_QUALITY_PROFILE = 'balanced';
export const SFU_VIDEO_QUALITY_PROFILES = Object.freeze({
  realtime: Object.freeze({
    label: 'Fast',
    captureWidth: 960,
    captureHeight: 540,
    captureFrameRate: 18,
    frameWidth: 640,
    frameHeight: 360,
    frameQuality: 62,
    keyFrameInterval: 36,
    encodeIntervalMs: 66,
  }),
  balanced: Object.freeze({
    label: 'Balanced',
    captureWidth: LOCAL_CAMERA_CAPTURE_WIDTH,
    captureHeight: LOCAL_CAMERA_CAPTURE_HEIGHT,
    captureFrameRate: LOCAL_CAMERA_CAPTURE_FRAME_RATE,
    frameWidth: SFU_WLVC_FRAME_WIDTH,
    frameHeight: SFU_WLVC_FRAME_HEIGHT,
    frameQuality: SFU_WLVC_FRAME_QUALITY,
    keyFrameInterval: SFU_WLVC_KEYFRAME_INTERVAL,
    encodeIntervalMs: SFU_WLVC_ENCODE_INTERVAL_MS,
  }),
  quality: Object.freeze({
    label: 'Sharp',
    captureWidth: 1920,
    captureHeight: 1080,
    captureFrameRate: 24,
    frameWidth: 1280,
    frameHeight: 720,
    frameQuality: 82,
    keyFrameInterval: 20,
    encodeIntervalMs: 50,
  }),
});
export const SFU_VIDEO_QUALITY_PROFILE_OPTIONS = Object.freeze(
  Object.entries(SFU_VIDEO_QUALITY_PROFILES).map(([value, profile]) => ({
    value,
    label: profile.label,
  }))
);

export function normalizeSfuVideoQualityProfile(value) {
  const normalized = String(value || '').trim().toLowerCase();
  return Object.prototype.hasOwnProperty.call(SFU_VIDEO_QUALITY_PROFILES, normalized)
    ? normalized
    : DEFAULT_SFU_VIDEO_QUALITY_PROFILE;
}

export function resolveSfuVideoQualityProfile(value) {
  return SFU_VIDEO_QUALITY_PROFILES[normalizeSfuVideoQualityProfile(value)];
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
