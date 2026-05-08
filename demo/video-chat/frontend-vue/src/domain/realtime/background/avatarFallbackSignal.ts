import { DEFAULT_BACKGROUND_FALLBACK_AVATAR_URL } from '../media/preferences';

export const BACKGROUND_FALLBACK_AVATAR_MODE = 'avatar';
export const BACKGROUND_FALLBACK_NONE_MODE = 'none';

export function normalizeBackgroundFallbackAvatarUrl(value = '') {
  const normalized = String(value || '').trim();
  return normalized === '' ? DEFAULT_BACKGROUND_FALLBACK_AVATAR_URL : normalized;
}

export function normalizeBackgroundFallbackMode(value = '') {
  return String(value || '').trim().toLowerCase() === BACKGROUND_FALLBACK_AVATAR_MODE
    ? BACKGROUND_FALLBACK_AVATAR_MODE
    : BACKGROUND_FALLBACK_NONE_MODE;
}

export function createBackgroundFallbackAudioOnlyStream(sourceStream) {
  if (typeof MediaStream === 'undefined') return sourceStream;
  const out = new MediaStream();
  if (!(sourceStream instanceof MediaStream)) return out;

  for (const audioTrack of sourceStream.getAudioTracks()) {
    if (audioTrack?.readyState === 'ended') continue;
    out.addTrack(audioTrack);
  }

  return out;
}

export function backgroundFallbackControlStateFromPrefs(callMediaPrefs) {
  const mode = normalizeBackgroundFallbackMode(callMediaPrefs?.backgroundFallbackVideoMode);
  const avatarUrl = mode === BACKGROUND_FALLBACK_AVATAR_MODE
    ? normalizeBackgroundFallbackAvatarUrl(callMediaPrefs?.backgroundFallbackAvatarImageUrl)
    : '';

  return {
    backgroundFallbackVideoMode: mode,
    backgroundFallbackAvatarImageUrl: avatarUrl,
    videoSubstitution: mode === BACKGROUND_FALLBACK_AVATAR_MODE ? BACKGROUND_FALLBACK_AVATAR_MODE : '',
  };
}
