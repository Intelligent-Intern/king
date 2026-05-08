import { openBackgroundReplacementUnavailablePrompt } from '../media/preferences';

function normalizeFailureList(value) {
  return Array.isArray(value)
    ? value.map((entry) => String(entry || '').trim()).filter(Boolean).slice(0, 5)
    : [];
}

export function handleBackgroundReplacementUnavailable({
  callMediaPrefs,
  captureDiagnostic,
  details = {},
  refs,
  runtimeToken,
  state,
}) {
  if (runtimeToken !== state.backgroundRuntimeToken) return;
  const failures = normalizeFailureList(details?.failures);
  callMediaPrefs.backgroundFilterActive = false;
  callMediaPrefs.backgroundFilterReason = 'segmentation_unavailable';
  callMediaPrefs.backgroundFilterBackend = String(details?.backend || 'none');
  openBackgroundReplacementUnavailablePrompt({
    reason: String(details?.reason || 'segmentation_unavailable'),
    failures,
  });
  captureDiagnostic({
    category: 'media',
    level: 'warning',
    eventType: 'local_background_replacement_unavailable',
    code: 'background_replacement_unavailable',
    message: 'Local background replacement is unavailable; the user must choose avatar or unfiltered camera video.',
    payload: {
      media_runtime_path: refs.mediaRuntimePathRef.value,
      background_filter_mode: callMediaPrefs.backgroundFilterMode,
      background_backdrop_mode: callMediaPrefs.backgroundBackdropMode,
      background_quality_profile: callMediaPrefs.backgroundQualityProfile,
      requested_backend: String(details?.requested || 'worker-segmenter'),
      selected_backend: String(details?.backend || 'none'),
      failures,
    },
    immediate: true,
  });
}
