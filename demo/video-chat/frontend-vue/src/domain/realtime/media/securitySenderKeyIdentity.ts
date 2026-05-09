import {
  asString,
  normalizeUserId,
  stableJson,
} from './securityCore.ts';

export function senderKeyIdentityKey({
  senderUserId,
  targetUserId,
  deviceId = '',
  participantSetHash = '',
  participantSetRevision = 0,
  epoch = 0,
  senderKeyId = '',
} = {}) {
  return stableJson({
    device_id: asString(deviceId),
    epoch: Number(epoch || 0),
    participant_set_hash: asString(participantSetHash),
    participant_set_revision: Math.max(0, Number(participantSetRevision || 0)),
    sender_user_id: normalizeUserId(senderUserId),
    sender_key_id: asString(senderKeyId),
    target_user_id: normalizeUserId(targetUserId),
  });
}

export function senderKeyIdentityKeyForPayload(senderUserId, targetUserId, payload = {}) {
  const body = payload && typeof payload === 'object' ? payload : {};
  return senderKeyIdentityKey({
    senderUserId,
    targetUserId,
    deviceId: body.device_id,
    participantSetHash: body.participant_set_hash,
    participantSetRevision: body.participant_set_revision,
    epoch: body.epoch,
    senderKeyId: body.sender_key_id,
  });
}

export function parseSenderKeyIdentityKey(identityKey) {
  try {
    const parsed = JSON.parse(asString(identityKey));
    return parsed && typeof parsed === 'object' ? parsed : null;
  } catch {
    return null;
  }
}

export function senderKeyIdentityMatchesSender(identityKey, senderUserId) {
  const identity = parseSenderKeyIdentityKey(identityKey);
  return normalizeUserId(identity?.sender_user_id) === normalizeUserId(senderUserId);
}
