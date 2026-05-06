export const SCREEN_SHARE_MEDIA_SOURCE = 'screen_share';
export const SCREEN_SHARE_TRACK_LABEL = 'Screen share';
export const SCREEN_SHARE_USER_ID_OFFSET = 2_000_000_000;

export function normalizeScreenShareMediaSource(value) {
  const normalized = String(value || '').trim().toLowerCase();
  if (normalized === 'screen' || normalized === 'screen_share' || normalized === 'screenshare') {
    return SCREEN_SHARE_MEDIA_SOURCE;
  }
  return '';
}

export function isScreenShareMediaSource(value) {
  return normalizeScreenShareMediaSource(value) === SCREEN_SHARE_MEDIA_SOURCE;
}

export function isScreenShareTrack(track) {
  const row = track && typeof track === 'object' ? track : {};
  const label = String(row.label || row.track_label || '').trim().toLowerCase();
  const source = normalizeScreenShareMediaSource(row.media_source || row.mediaSource || row.source);
  return source === SCREEN_SHARE_MEDIA_SOURCE
    || label === SCREEN_SHARE_TRACK_LABEL.toLowerCase()
    || label.includes('screen share')
    || label.includes('screenshare');
}

export function sfuTrackListHasScreenShare(tracks) {
  return (Array.isArray(tracks) ? tracks : []).some((track) => isScreenShareTrack(track));
}

export function screenShareUserIdForOwner(ownerUserId) {
  const normalizedOwnerUserId = Number(ownerUserId || 0);
  if (!Number.isInteger(normalizedOwnerUserId) || normalizedOwnerUserId <= 0) return 0;
  if (normalizedOwnerUserId > Number.MAX_SAFE_INTEGER - SCREEN_SHARE_USER_ID_OFFSET) return 0;
  return SCREEN_SHARE_USER_ID_OFFSET + normalizedOwnerUserId;
}

export function screenShareOwnerUserIdFromSynthetic(userId) {
  const normalizedUserId = Number(userId || 0);
  if (!Number.isInteger(normalizedUserId) || normalizedUserId <= SCREEN_SHARE_USER_ID_OFFSET) return 0;
  return normalizedUserId - SCREEN_SHARE_USER_ID_OFFSET;
}

export function screenShareOwnerOrUserId(userId) {
  const normalizedUserId = Number(userId || 0);
  if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return 0;
  return screenShareOwnerUserIdFromSynthetic(normalizedUserId) || normalizedUserId;
}

export function isScreenShareUserId(userId) {
  return screenShareOwnerUserIdFromSynthetic(userId) > 0;
}

export function screenShareDisplayName(ownerDisplayName, ownerUserId = 0) {
  const normalizedName = String(ownerDisplayName || '').trim();
  if (normalizedName !== '') return `${normalizedName} screen`;
  const normalizedOwnerUserId = Number(ownerUserId || 0);
  if (Number.isInteger(normalizedOwnerUserId) && normalizedOwnerUserId > 0) {
    return `User ${normalizedOwnerUserId} screen`;
  }
  return SCREEN_SHARE_TRACK_LABEL;
}

export function resolveScreenSharePeerIdentity({
  publisherUserId = 0,
  publisherName = '',
  tracks = [],
  mediaSource = '',
} = {}) {
  const rawPublisherUserId = Number(publisherUserId || 0);
  const alreadySyntheticUserId = isScreenShareUserId(rawPublisherUserId) ? rawPublisherUserId : 0;
  const ownerUserId = alreadySyntheticUserId > 0
    ? screenShareOwnerUserIdFromSynthetic(alreadySyntheticUserId)
    : rawPublisherUserId;
  const screenShare = isScreenShareMediaSource(mediaSource) || sfuTrackListHasScreenShare(tracks);
  const syntheticUserId = screenShare
    ? (alreadySyntheticUserId || screenShareUserIdForOwner(ownerUserId))
    : 0;
  return {
    isScreenShare: Boolean(screenShare && syntheticUserId > 0),
    ownerUserId: Number.isInteger(ownerUserId) && ownerUserId > 0 ? ownerUserId : 0,
    userId: syntheticUserId > 0 ? syntheticUserId : ownerUserId,
    displayName: screenShare
      ? screenShareDisplayName(publisherName, ownerUserId)
      : String(publisherName || '').trim(),
    mediaSource: screenShare ? SCREEN_SHARE_MEDIA_SOURCE : '',
  };
}
