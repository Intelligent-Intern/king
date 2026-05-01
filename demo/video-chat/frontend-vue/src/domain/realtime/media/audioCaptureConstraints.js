export const CALL_AUDIO_CAPTURE_PROCESSING_CONSTRAINTS = Object.freeze({
  echoCancellation: true,
  noiseSuppression: true,
  autoGainControl: true,
  channelCount: { ideal: 1 },
});

export function buildCallAudioCaptureConstraints(microphoneDeviceId = '') {
  const normalizedDeviceId = String(microphoneDeviceId || '').trim();
  return normalizedDeviceId === ''
    ? { ...CALL_AUDIO_CAPTURE_PROCESSING_CONSTRAINTS }
    : {
        ...CALL_AUDIO_CAPTURE_PROCESSING_CONSTRAINTS,
        deviceId: { exact: normalizedDeviceId },
      };
}

export function buildOptionalCallAudioCaptureConstraints(enabled = true, microphoneDeviceId = '') {
  return enabled === false ? false : buildCallAudioCaptureConstraints(microphoneDeviceId);
}

export function callAudioSettingsDiagnosticPayload(track) {
  const settings = track && typeof track.getSettings === 'function' ? track.getSettings() || {} : {};
  return {
    audio_echo_cancellation: settings.echoCancellation === true,
    audio_noise_suppression: settings.noiseSuppression === true,
    audio_auto_gain_control: settings.autoGainControl === true,
    audio_channel_count: Number(settings.channelCount || 0),
  };
}
