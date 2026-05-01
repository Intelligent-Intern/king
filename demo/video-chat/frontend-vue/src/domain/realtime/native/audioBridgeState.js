export const NATIVE_AUDIO_BRIDGE_STATES = Object.freeze({
  NEW: 'new',
  WAITING_SECURITY: 'waiting_security',
  PROTECTED_FRAMES_TEMPORARILY_DISABLED: 'protected_frames_temporarily_disabled',
  WAITING_TRACK: 'waiting_track',
  TRACK_RECEIVED: 'track_received',
  PLAYING: 'playing',
  BLOCKED_PLAYBACK: 'blocked_playback',
  PLAY_FAILED: 'play_failed',
  STALLED_NO_TRACK: 'stalled_no_track',
  TRANSFORM_ATTACH_FAILED: 'transform_attach_failed',
  CLOSED: 'closed',
});

const ANY_STATE = '*';

const NATIVE_AUDIO_BRIDGE_ALLOWED_TRANSITIONS = Object.freeze({
  [ANY_STATE]: new Set([
    NATIVE_AUDIO_BRIDGE_STATES.TRANSFORM_ATTACH_FAILED,
    NATIVE_AUDIO_BRIDGE_STATES.CLOSED,
  ]),
  [NATIVE_AUDIO_BRIDGE_STATES.NEW]: new Set([
    NATIVE_AUDIO_BRIDGE_STATES.WAITING_SECURITY,
    NATIVE_AUDIO_BRIDGE_STATES.PROTECTED_FRAMES_TEMPORARILY_DISABLED,
    NATIVE_AUDIO_BRIDGE_STATES.WAITING_TRACK,
    NATIVE_AUDIO_BRIDGE_STATES.TRACK_RECEIVED,
  ]),
  [NATIVE_AUDIO_BRIDGE_STATES.WAITING_SECURITY]: new Set([
    NATIVE_AUDIO_BRIDGE_STATES.WAITING_TRACK,
    NATIVE_AUDIO_BRIDGE_STATES.TRACK_RECEIVED,
    NATIVE_AUDIO_BRIDGE_STATES.PROTECTED_FRAMES_TEMPORARILY_DISABLED,
  ]),
  [NATIVE_AUDIO_BRIDGE_STATES.PROTECTED_FRAMES_TEMPORARILY_DISABLED]: new Set([
    NATIVE_AUDIO_BRIDGE_STATES.WAITING_SECURITY,
    NATIVE_AUDIO_BRIDGE_STATES.WAITING_TRACK,
    NATIVE_AUDIO_BRIDGE_STATES.TRACK_RECEIVED,
  ]),
  [NATIVE_AUDIO_BRIDGE_STATES.WAITING_TRACK]: new Set([
    NATIVE_AUDIO_BRIDGE_STATES.TRACK_RECEIVED,
    NATIVE_AUDIO_BRIDGE_STATES.STALLED_NO_TRACK,
    NATIVE_AUDIO_BRIDGE_STATES.WAITING_SECURITY,
  ]),
  [NATIVE_AUDIO_BRIDGE_STATES.TRACK_RECEIVED]: new Set([
    NATIVE_AUDIO_BRIDGE_STATES.PLAYING,
    NATIVE_AUDIO_BRIDGE_STATES.WAITING_TRACK,
    NATIVE_AUDIO_BRIDGE_STATES.BLOCKED_PLAYBACK,
    NATIVE_AUDIO_BRIDGE_STATES.PLAY_FAILED,
  ]),
  [NATIVE_AUDIO_BRIDGE_STATES.PLAYING]: new Set([
    NATIVE_AUDIO_BRIDGE_STATES.TRACK_RECEIVED,
    NATIVE_AUDIO_BRIDGE_STATES.WAITING_TRACK,
    NATIVE_AUDIO_BRIDGE_STATES.BLOCKED_PLAYBACK,
    NATIVE_AUDIO_BRIDGE_STATES.PLAY_FAILED,
  ]),
  [NATIVE_AUDIO_BRIDGE_STATES.BLOCKED_PLAYBACK]: new Set([
    NATIVE_AUDIO_BRIDGE_STATES.TRACK_RECEIVED,
    NATIVE_AUDIO_BRIDGE_STATES.PLAYING,
  ]),
  [NATIVE_AUDIO_BRIDGE_STATES.PLAY_FAILED]: new Set([
    NATIVE_AUDIO_BRIDGE_STATES.TRACK_RECEIVED,
    NATIVE_AUDIO_BRIDGE_STATES.WAITING_TRACK,
    NATIVE_AUDIO_BRIDGE_STATES.PLAYING,
  ]),
  [NATIVE_AUDIO_BRIDGE_STATES.STALLED_NO_TRACK]: new Set([
    NATIVE_AUDIO_BRIDGE_STATES.WAITING_SECURITY,
    NATIVE_AUDIO_BRIDGE_STATES.WAITING_TRACK,
    NATIVE_AUDIO_BRIDGE_STATES.TRACK_RECEIVED,
  ]),
  [NATIVE_AUDIO_BRIDGE_STATES.TRANSFORM_ATTACH_FAILED]: new Set([
    NATIVE_AUDIO_BRIDGE_STATES.WAITING_SECURITY,
    NATIVE_AUDIO_BRIDGE_STATES.WAITING_TRACK,
  ]),
  [NATIVE_AUDIO_BRIDGE_STATES.CLOSED]: new Set([
    NATIVE_AUDIO_BRIDGE_STATES.NEW,
  ]),
});

function normalizeAudioBridgeState(value) {
  const normalized = String(value || '').trim().toLowerCase();
  if (normalized === '') return NATIVE_AUDIO_BRIDGE_STATES.NEW;
  return Object.values(NATIVE_AUDIO_BRIDGE_STATES).includes(normalized)
    ? normalized
    : NATIVE_AUDIO_BRIDGE_STATES.NEW;
}

export function canTransitionNativeAudioBridgeState(fromState, toState) {
  const normalizedFromState = normalizeAudioBridgeState(fromState);
  const normalizedToState = normalizeAudioBridgeState(toState);
  if (normalizedFromState === normalizedToState) return true;
  if (NATIVE_AUDIO_BRIDGE_ALLOWED_TRANSITIONS[ANY_STATE]?.has(normalizedToState)) return true;
  return Boolean(NATIVE_AUDIO_BRIDGE_ALLOWED_TRANSITIONS[normalizedFromState]?.has(normalizedToState));
}

export function createNativeAudioBridgeStateHelpers(nativeAudioBridgeStatusVersion) {
  function bumpNativeAudioBridgeStatusVersion() {
    nativeAudioBridgeStatusVersion.value = nativeAudioBridgeStatusVersion.value >= 1_000_000
      ? 0
      : nativeAudioBridgeStatusVersion.value + 1;
  }

  function setNativePeerAudioBridgeState(peer, state = '', errorMessage = '', options = {}) {
    if (!peer || typeof peer !== 'object') return false;
    const nextState = normalizeAudioBridgeState(state);
    const nextErrorMessage = String(errorMessage || '').trim();
    const currentState = normalizeAudioBridgeState(peer.audioBridgeState);
    const force = options?.force === true;
    if (!force && !canTransitionNativeAudioBridgeState(currentState, nextState)) {
      return false;
    }
    if (
      currentState === nextState
      && String(peer.audioBridgeErrorMessage || '').trim() === nextErrorMessage
    ) {
      return false;
    }
    peer.audioBridgeState = nextState;
    peer.audioBridgeErrorMessage = nextErrorMessage;
    bumpNativeAudioBridgeStatusVersion();
    return true;
  }

  function clearNativePeerAudioTrackDeadline(peer) {
    if (!peer || typeof peer !== 'object') return;
    if (peer.audioTrackDeadlineTimer !== null && peer.audioTrackDeadlineTimer !== undefined) {
      clearTimeout(peer.audioTrackDeadlineTimer);
    }
    peer.audioTrackDeadlineTimer = null;
  }

  return {
    bumpNativeAudioBridgeStatusVersion,
    clearNativePeerAudioTrackDeadline,
    setNativePeerAudioBridgeState,
  };
}
