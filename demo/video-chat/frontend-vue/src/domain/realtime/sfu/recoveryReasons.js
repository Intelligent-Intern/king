export const SFU_FULL_KEYFRAME_RECOVERY_REASONS = Object.freeze([
  'sfu_browser_decode_frame_failed',
  'sfu_browser_decoder_error',
  'sfu_protected_frame_decrypt_failed',
  'sfu_receiver_sequence_gap',
  'sfu_remote_video_decoder_waiting_keyframe',
  'sfu_remote_video_never_started',
]);

export function normalizeSfuRecoveryReason(reason, fallback = 'sfu_receiver_media_recovery') {
  const normalized = String(reason || '').trim().toLowerCase();
  return normalized || fallback;
}

export function shouldRequestSfuFullKeyframeForReason(reason) {
  return SFU_FULL_KEYFRAME_RECOVERY_REASONS.includes(normalizeSfuRecoveryReason(reason, ''));
}

export function resolveSfuRecoveryRequestedAction(reason, explicitAction = '') {
  const normalizedAction = String(explicitAction || '').trim().toLowerCase();
  if (normalizedAction !== '') return normalizedAction;
  return shouldRequestSfuFullKeyframeForReason(reason)
    ? 'force_full_keyframe'
    : 'downgrade_outgoing_video';
}
