import {
  SCREEN_SHARE_MEDIA_SOURCE,
  screenShareUserIdForOwner,
} from '../screenShareIdentity.js';

export function screenShareGossipPublisherId(ownerUserId) {
  const normalizedOwnerUserId = Number(ownerUserId || 0);
  return Number.isInteger(normalizedOwnerUserId) && normalizedOwnerUserId > 0
    ? `screen_share:${normalizedOwnerUserId}`
    : '';
}

export function screenShareGossipFrameFromEncodedFrame(frame, ownerUserId) {
  if (!frame || typeof frame !== 'object') return frame;
  const normalizedOwnerUserId = Number(ownerUserId || 0);
  const screenShareUserId = screenShareUserIdForOwner(normalizedOwnerUserId);
  const publisherId = screenShareGossipPublisherId(normalizedOwnerUserId)
    || String(frame.publisherId || '');
  return {
    ...frame,
    publisherId,
    publisherUserId: String(normalizedOwnerUserId || frame.publisherUserId || ''),
    publisherMediaSource: SCREEN_SHARE_MEDIA_SOURCE,
    screenShareOwnerUserId: normalizedOwnerUserId,
    screenShareParticipantUserId: screenShareUserId,
    transportMetrics: {
      ...(frame.transportMetrics || {}),
      publisher_media_source: SCREEN_SHARE_MEDIA_SOURCE,
      screen_share_owner_user_id: normalizedOwnerUserId,
      screen_share_participant_user_id: screenShareUserId,
    },
  };
}
