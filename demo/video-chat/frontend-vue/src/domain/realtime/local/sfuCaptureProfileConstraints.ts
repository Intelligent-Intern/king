import {
  detectPublisherCapturePipelineCapabilities,
  publisherCaptureCapabilityDiagnosticPayload,
} from './capturePipelineCapabilities';
import { callAudioSettingsDiagnosticPayload } from '../media/audioCaptureConstraints';

function positiveNumber(value) {
  const normalized = Number(value);
  return Number.isFinite(normalized) && normalized > 0 ? normalized : 0;
}

function finiteTrackSetting(value) {
  const normalized = Number(value);
  return Number.isFinite(normalized) && normalized > 0 ? normalized : 0;
}

function firstTrack(stream, getterName) {
  const getter = stream && typeof stream[getterName] === 'function' ? stream[getterName] : null;
  if (!getter) return null;
  const tracks = getter.call(stream);
  return Array.isArray(tracks) ? tracks[0] || null : null;
}

function resolveCappedConstraint(targetValue, capability = {}) {
  const target = positiveNumber(targetValue);
  if (target <= 0) return null;

  const min = positiveNumber(capability?.min);
  const max = positiveNumber(capability?.max);
  let capped = target;
  if (max > 0) capped = Math.min(capped, max);
  if (min > 0) capped = Math.max(capped, min);

  return { ideal: capped, max: capped };
}

function profileId(videoProfile = {}) {
  return String(videoProfile.id || '').trim() || 'balanced';
}

function trackSettings(videoTrack) {
  if (!videoTrack || typeof videoTrack.getSettings !== 'function') return {};
  try {
    return videoTrack.getSettings() || {};
  } catch {
    return {};
  }
}

function trackCapabilities(videoTrack) {
  if (!videoTrack || typeof videoTrack.getCapabilities !== 'function') return {};
  try {
    return videoTrack.getCapabilities() || {};
  } catch {
    return {};
  }
}

export function buildSfuVideoProfileTrackConstraints(videoProfile = {}, videoTrack = null) {
  const capabilities = trackCapabilities(videoTrack);
  const constraints = {};
  const width = resolveCappedConstraint(videoProfile.captureWidth, capabilities.width);
  const height = resolveCappedConstraint(videoProfile.captureHeight, capabilities.height);
  const frameRate = resolveCappedConstraint(videoProfile.captureFrameRate, capabilities.frameRate);

  if (width) constraints.width = width;
  if (height) constraints.height = height;
  if (frameRate) constraints.frameRate = frameRate;
  return constraints;
}

export function captureSettingsPayload({ videoTrack, audioTrack, videoProfile = {}, reason = 'unknown' } = {}) {
  const settings = trackSettings(videoTrack);
  const settingsWidth = finiteTrackSetting(settings.width);
  const settingsHeight = finiteTrackSetting(settings.height);
  const settingsFrameRate = finiteTrackSetting(settings.frameRate);
  const captureCapabilities = detectPublisherCapturePipelineCapabilities();
  const staleAfterDowngrade = (profileId(videoProfile) === 'realtime' || profileId(videoProfile) === 'rescue')
    && (
      (settingsWidth > 0 && settingsWidth > Number(videoProfile.captureWidth || 0) * 1.25)
      || (settingsHeight > 0 && settingsHeight > Number(videoProfile.captureHeight || 0) * 1.25)
    );

  return {
    payload: {
      reason: String(reason || 'unknown'),
      outgoing_video_quality_profile: profileId(videoProfile),
      requested_capture_width: Number(videoProfile.captureWidth || 0),
      requested_capture_height: Number(videoProfile.captureHeight || 0),
      requested_capture_frame_rate: Number(videoProfile.captureFrameRate || 0),
      requested_readback_frame_rate: Number(videoProfile.readbackFrameRate || 0),
      requested_readback_interval_ms: Number(videoProfile.readbackIntervalMs || videoProfile.encodeIntervalMs || 0),
      requested_keyframe_interval: Number(videoProfile.keyFrameInterval || 0),
      requested_wire_budget_bytes_per_second: Number(videoProfile.maxWireBytesPerSecond || 0),
      publisher_frame_width: Number(videoProfile.frameWidth || 0),
      publisher_frame_height: Number(videoProfile.frameHeight || 0),
      track_settings_width: settingsWidth,
      track_settings_height: settingsHeight,
      track_settings_frame_rate: settingsFrameRate,
      ...callAudioSettingsDiagnosticPayload(audioTrack),
      ...publisherCaptureCapabilityDiagnosticPayload(captureCapabilities),
      stale_hd_capture_after_downgrade: staleAfterDowngrade,
    },
    staleAfterDowngrade,
  };
}

export function reportSfuLocalCaptureSettings({
  stream,
  videoProfile = {},
  reason = 'unknown',
  captureDiagnostic = () => {},
} = {}) {
  const videoTrack = firstTrack(stream, 'getVideoTracks');
  const audioTrack = firstTrack(stream, 'getAudioTracks');
  if (!audioTrack && (!videoTrack || typeof videoTrack.getSettings !== 'function')) return false;

  const { payload, staleAfterDowngrade } = captureSettingsPayload({
    videoTrack,
    audioTrack,
    videoProfile,
    reason,
  });
  captureDiagnostic({
    category: 'media',
    level: staleAfterDowngrade ? 'warning' : 'info',
    eventType: 'sfu_local_capture_constraints_applied',
    code: 'sfu_local_capture_constraints_applied',
    message: 'Browser-reported local capture settings after applying the selected SFU video profile.',
    payload,
  });
  return true;
}

export async function applySfuVideoProfileConstraintsToStream({
  stream,
  videoProfile = {},
  reason = 'unknown',
  captureDiagnostic = () => {},
  captureClientDiagnosticError = () => {},
  mediaRuntimePath = '',
} = {}) {
  const videoTrack = firstTrack(stream, 'getVideoTracks');
  if (!videoTrack || typeof videoTrack.applyConstraints !== 'function') {
    return { ok: false, applied: false, reason: 'sfu_capture_track_constraints_unsupported' };
  }

  const constraints = buildSfuVideoProfileTrackConstraints(videoProfile, videoTrack);
  if (Object.keys(constraints).length === 0) {
    return { ok: false, applied: false, reason: 'sfu_capture_track_constraints_empty' };
  }

  try {
    await videoTrack.applyConstraints(constraints);
    captureDiagnostic({
      category: 'media',
      level: 'info',
      eventType: 'sfu_capture_track_constraints_enforced',
      code: 'sfu_capture_track_constraints_enforced',
      message: 'Local camera track constraints were enforced for the active automatic SFU video profile.',
      payload: {
        reason: String(reason || 'unknown'),
        outgoing_video_quality_profile: profileId(videoProfile),
        requested_capture_width: Number(videoProfile.captureWidth || 0),
        requested_capture_height: Number(videoProfile.captureHeight || 0),
        requested_capture_frame_rate: Number(videoProfile.captureFrameRate || 0),
        applied_width_max: Number(constraints.width?.max || 0),
        applied_height_max: Number(constraints.height?.max || 0),
        applied_frame_rate_max: Number(constraints.frameRate?.max || 0),
        media_runtime_path: String(mediaRuntimePath || ''),
      },
    });
    return { ok: true, applied: true, constraints };
  } catch (error) {
    captureClientDiagnosticError('sfu_capture_track_constraints_failed', error, {
      reason: String(reason || 'unknown'),
      outgoing_video_quality_profile: profileId(videoProfile),
      requested_capture_width: Number(videoProfile.captureWidth || 0),
      requested_capture_height: Number(videoProfile.captureHeight || 0),
      requested_capture_frame_rate: Number(videoProfile.captureFrameRate || 0),
      media_runtime_path: String(mediaRuntimePath || ''),
    }, {
      code: 'sfu_capture_track_constraints_failed',
      immediate: true,
    });
    return { ok: false, applied: false, reason: 'sfu_capture_track_constraints_failed', error };
  }
}
