function normalizedString(value, fallback = '') {
  const normalized = String(value ?? '').trim();
  return normalized !== '' ? normalized : fallback;
}

function normalizedProfile(value, fallback = '') {
  return normalizedString(value, fallback).toLowerCase();
}

function normalizedNumber(value) {
  const numeric = Number(value);
  return Number.isFinite(numeric) ? Math.max(0, numeric) : 0;
}

export function publisherCaptureDiagnosticSurface(details = {}, {
  selectedProfile = '',
} = {}) {
  const activeCaptureBackend = normalizedString(
    details.active_capture_backend
      ?? details.activeCaptureBackend
      ?? details.publisher_source_backend
      ?? details.publisherSourceBackend
      ?? details.source_backend
      ?? details.sourceBackend,
    'unknown_capture_backend',
  );
  const profile = normalizedProfile(
    details.selected_video_quality_profile
      ?? details.selectedVideoQualityProfile
      ?? details.outgoing_video_quality_profile
      ?? details.outgoingVideoQualityProfile
      ?? details.publisher_trace_profile
      ?? details.publisherTraceProfile,
    selectedProfile,
  );

  return {
    active_capture_backend: activeCaptureBackend,
    selected_video_quality_profile: profile,
    source_frame_width: normalizedNumber(
      details.source_frame_width
        ?? details.sourceFrameWidth
        ?? details.source_track_width
        ?? details.sourceTrackWidth,
    ),
    source_frame_height: normalizedNumber(
      details.source_frame_height
        ?? details.sourceFrameHeight
        ?? details.source_track_height
        ?? details.sourceTrackHeight,
    ),
    source_frame_rate: normalizedNumber(
      details.source_frame_rate
        ?? details.sourceFrameRate
        ?? details.source_track_frame_rate
        ?? details.sourceTrackFrameRate,
    ),
    source_draw_image_ms: normalizedNumber(details.source_draw_image_ms ?? details.sourceDrawImageMs ?? details.draw_image_ms ?? details.drawImageMs),
    source_draw_image_budget_ms: normalizedNumber(details.source_draw_image_budget_ms ?? details.sourceDrawImageBudgetMs ?? details.draw_budget_ms ?? details.drawBudgetMs),
    source_readback_ms: normalizedNumber(details.source_readback_ms ?? details.sourceReadbackMs ?? details.readback_ms ?? details.readbackMs),
    source_readback_budget_ms: normalizedNumber(details.source_readback_budget_ms ?? details.sourceReadbackBudgetMs ?? details.readback_budget_ms ?? details.readbackBudgetMs),
  };
}

export function publisherDroppedSourceFrameDiagnosticSurface({
  details = {},
  droppedSourceFrameCount = 0,
  selectedProfile = '',
} = {}) {
  return {
    ...publisherCaptureDiagnosticSurface(details, { selectedProfile }),
    dropped_source_frame_count: normalizedNumber(droppedSourceFrameCount),
  };
}

export function publisherQualityTransitionDiagnosticSurface({
  transitionCount = 0,
  direction = '',
  fromProfile = '',
  toProfile = '',
} = {}) {
  return {
    automatic_quality_transition_count: normalizedNumber(transitionCount),
    automatic_quality_transition_direction: normalizedString(direction, 'unknown'),
    automatic_quality_from_profile: normalizedProfile(fromProfile),
    automatic_quality_to_profile: normalizedProfile(toProfile),
    selected_video_quality_profile: normalizedProfile(toProfile),
  };
}
