import { openBackgroundReplacementUnavailablePrompt } from '../media/preferences';
import {
  BACKGROUND_RUNTIME_DIAGNOSTIC_THROTTLE_MS,
  createBackgroundRuntimeDiagnosticPayload,
  shouldEmitBackgroundRuntimeDiagnostic,
} from './diagnostics/runtimeDiagnostics';

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
  const diagnosticPayload = createBackgroundRuntimeDiagnosticPayload({
    backgroundBackdropMode: callMediaPrefs.backgroundBackdropMode,
    backgroundFilterMode: callMediaPrefs.backgroundFilterMode,
    backgroundQualityProfile: callMediaPrefs.backgroundQualityProfile,
    backend: details?.backend || 'none',
    backendFailureSignature: failures[0] || details?.reason || 'segmentation_unavailable',
    failures,
    mediaRuntimePath: refs.mediaRuntimePathRef.value,
    reasonUserChoiceRequired: details?.reason || 'segmentation_unavailable',
    requestedBackend: details?.requested || 'worker-segmenter',
    selectedBackend: details?.backend || 'none',
  });
  const diagnosticThrottleKey = [
    'background-unavailable',
    diagnosticPayload.reason_user_choice_required,
    diagnosticPayload.selected_backend,
    diagnosticPayload.backend_failure_signature,
  ].join(':');
  if (!shouldEmitBackgroundRuntimeDiagnostic(diagnosticThrottleKey, BACKGROUND_RUNTIME_DIAGNOSTIC_THROTTLE_MS)) {
    return;
  }
  if (typeof captureDiagnostic !== 'function') return;
  captureDiagnostic({
    category: 'media',
    level: 'warning',
    eventType: 'local_background_replacement_unavailable',
    code: 'background_replacement_unavailable',
    message: 'Local background replacement is unavailable; the user must choose avatar or unfiltered camera video.',
    payload: diagnosticPayload,
    immediate: true,
  });
}
