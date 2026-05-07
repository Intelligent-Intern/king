import {
  isScreenShareMediaSource,
  isScreenShareUserId,
  screenShareOwnerOrUserId,
} from '../screenShareIdentity.js';

export function mediaSecurityPublisherUserIdForFrame(peer, frame, publisherUserId = 0) {
  const peerUserId = Number(peer?.userId || 0);
  const peerPublisherUserId = Number(peer?.publisherUserId || peer?.publisher_user_id || 0);
  const framePublisherUserId = Number(publisherUserId || frame?.publisherUserId || frame?.publisher_user_id || 0);
  const screenShareOwnerUserId = Number(peer?.screenShareOwnerUserId || peer?.screen_share_owner_user_id || 0);
  const isScreenShareFrame = isScreenShareUserId(peerUserId)
    || isScreenShareUserId(peerPublisherUserId)
    || isScreenShareUserId(framePublisherUserId)
    || isScreenShareMediaSource(peer?.mediaSource || peer?.media_source)
    || isScreenShareMediaSource(frame?.publisherMediaSource || frame?.publisher_media_source);
  const resolvedUserId = isScreenShareFrame
    ? screenShareOwnerOrUserId(
      screenShareOwnerUserId
        || peerPublisherUserId
        || framePublisherUserId
        || peerUserId
    )
    : framePublisherUserId;
  return Number.isInteger(resolvedUserId) && resolvedUserId > 0 ? resolvedUserId : framePublisherUserId;
}
