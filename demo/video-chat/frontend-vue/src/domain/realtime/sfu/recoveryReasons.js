export const SFU_FULL_KEYFRAME_RECOVERY_REASONS = Object.freeze([
  'sfu_browser_decode_frame_failed',
  'sfu_browser_decoder_error',
  'sfu_protected_frame_decrypt_failed',
  'sfu_receiver_sequence_gap',
  'sfu_remote_video_decoder_waiting_keyframe',
  'sfu_remote_video_never_started',
]);

export const SFU_COMPATIBILITY_CODEC_RECOVERY_ACTION = 'prefer_compatibility_video_codec';

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

export function shouldRequestSfuCompatibilityCodecFallback(requestedAction = '', details = {}) {
  const normalizedAction = String(requestedAction || details?.requested_action || details?.requestedAction || '')
    .trim()
    .toLowerCase();
  const requestedCodecId = String(details?.requested_codec_id || details?.requestedCodecId || '').trim().toLowerCase();
  return normalizedAction === SFU_COMPATIBILITY_CODEC_RECOVERY_ACTION
    || normalizedAction === 'disable_browser_encoder'
    || normalizedAction === 'fallback_to_wlvc'
    || requestedCodecId === 'wlvc_sfu'
    || requestedCodecId === 'wlvc_wasm'
    || requestedCodecId === 'wlvc_ts';
}
