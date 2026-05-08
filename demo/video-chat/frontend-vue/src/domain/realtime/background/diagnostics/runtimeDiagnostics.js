import { reportClientDiagnostic } from '../../../../support/clientDiagnostics';

const VIDEOCHAT_CDN_ORIGIN = String(import.meta.env.VITE_VIDEOCHAT_CDN_ORIGIN || '').replace(/\/+$/, '');
const MEDIAPIPE_MODEL_BASE_PATH = '/cdn/vendor/mediapipe/models/';
const DEFAULT_MODEL_ASSET = 'selfie_multiclass_256x256.tflite';
const DEFAULT_MODEL_PATH = `${VIDEOCHAT_CDN_ORIGIN}${MEDIAPIPE_MODEL_BASE_PATH}${DEFAULT_MODEL_ASSET}`;

export const BACKGROUND_RUNTIME_DIAGNOSTIC_THROTTLE_MS = 60000;
export const BACKGROUND_MODAL_CHOICE_DIAGNOSTIC_THROTTLE_MS = 10000;

const diagnosticThrottleState = new Map();

function normalizeString(value, fallback = '', maxLength = 160) {
  const normalized = String(value ?? '').trim();
  if (normalized === '') return fallback;
  return normalized.length > maxLength ? normalized.slice(0, maxLength) : normalized;
}

function normalizeCode(value, fallback = 'unknown') {
  return normalizeString(value, fallback, 120)
    .toLowerCase()
    .replace(/[^a-z0-9._:-]+/g, '_')
    .replace(/^[_:.-]+|[_:.-]+$/g, '') || fallback;
}

function errorMessage(value) {
  if (value instanceof Error) return String(value.message || value.name || '').trim();
  if (typeof value === 'string') return value;
  if (value && typeof value === 'object') {
    if (typeof value.message === 'string') return value.message;
    if (typeof value.error === 'string') return value.error;
  }
  return '';
}

export function normalizeBackgroundFailureSignature(value, fallback = '') {
  const raw = normalizeString(errorMessage(value) || value, fallback, 240);
  if (!raw) return fallback;
  return raw
    .replace(/https?:\/\/[^\s"'<>]+/gi, '[url]')
    .replace(/[A-Za-z0-9+/=_-]{48,}/g, '[opaque]')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, 240);
}

export function normalizeBackgroundFailureList(value) {
  return Array.isArray(value)
    ? value
      .map((entry) => normalizeBackgroundFailureSignature(entry, ''))
      .filter(Boolean)
      .slice(0, 5)
    : [];
}

export function resolveBackgroundBrowserFamily() {
  const nav = typeof navigator !== 'undefined' ? navigator : null;
  const brands = Array.isArray(nav?.userAgentData?.brands)
    ? nav.userAgentData.brands.map((brand) => String(brand?.brand || '').toLowerCase()).join(' ')
    : '';
  const userAgent = String(nav?.userAgent || '').toLowerCase();
  const source = `${brands} ${userAgent}`;

  if (source.includes('firefox')) return 'firefox';
  if (source.includes('edg/')) return 'edge';
  if (source.includes('samsungbrowser')) return 'samsung';
  if (source.includes('crios') || source.includes('chrome') || source.includes('chromium')) return 'chromium';
  if (source.includes('safari')) return 'safari';
  return 'unknown';
}

export function resolveBackgroundGpuAvailability(failureSignature = '') {
  const normalizedFailure = normalizeBackgroundFailureSignature(failureSignature, '');
  if (typeof document === 'undefined') {
    return {
      gpu_available: false,
      gpu_api: 'none',
      gpu_failure_signature: normalizedFailure || 'document_unavailable',
    };
  }

  try {
    const canvas = document.createElement('canvas');
    const webgl2 = canvas.getContext?.('webgl2');
    if (webgl2) {
      return {
        gpu_available: true,
        gpu_api: 'webgl2',
        gpu_failure_signature: normalizedFailure,
      };
    }

    const webgl = canvas.getContext?.('webgl') || canvas.getContext?.('experimental-webgl');
    if (webgl) {
      return {
        gpu_available: true,
        gpu_api: 'webgl',
        gpu_failure_signature: normalizedFailure,
      };
    }

    return {
      gpu_available: false,
      gpu_api: 'none',
      gpu_failure_signature: normalizedFailure || 'webgl_context_unavailable',
    };
  } catch (error) {
    return {
      gpu_available: false,
      gpu_api: 'none',
      gpu_failure_signature: normalizedFailure || normalizeBackgroundFailureSignature(error, 'webgl_probe_failed'),
    };
  }
}

export function resolveBackgroundModelDescriptor(modelAssetPath = DEFAULT_MODEL_PATH) {
  const source = normalizeString(modelAssetPath, DEFAULT_MODEL_PATH, 500);
  let modelSource = 'same_origin_cdn';

  if (/^https?:\/\//i.test(source)) {
    modelSource = VIDEOCHAT_CDN_ORIGIN && source.startsWith(VIDEOCHAT_CDN_ORIGIN)
      ? 'configured_cdn'
      : 'remote_url';
  } else if (source.startsWith('/wasm/') || source.startsWith('/cdn/')) {
    modelSource = 'same_origin_static';
  } else if (source.startsWith('.')) {
    modelSource = 'relative_static';
  }

  return {
    model_asset: DEFAULT_MODEL_ASSET,
    model_source: modelSource,
  };
}

function optionalCodePayload(target, key, value) {
  const normalized = normalizeCode(value, '');
  if (normalized) target[key] = normalized;
}

function optionalStringPayload(target, key, value, maxLength = 160) {
  const normalized = normalizeString(value, '', maxLength);
  if (normalized) target[key] = normalized;
}

export function createBackgroundRuntimeDiagnosticPayload(details = {}) {
  const failures = normalizeBackgroundFailureList(details.failures);
  const failureSignature = normalizeBackgroundFailureSignature(
    details.backendFailureSignature || details.error || failures[0] || '',
    '',
  );
  const gpu = resolveBackgroundGpuAvailability(details.gpuFailureSignature || details.error || failureSignature);
  const model = resolveBackgroundModelDescriptor(details.modelAssetPath);
  const requestedBackend = normalizeCode(details.requestedBackend || details.requested || 'worker-segmenter', 'worker-segmenter');
  const selectedBackend = normalizeCode(details.selectedBackend || details.selected || details.backend || 'none', 'none');
  const payload = {
    browser_family: resolveBackgroundBrowserFamily(),
    backend: selectedBackend || requestedBackend,
    requested_backend: requestedBackend,
    selected_backend: selectedBackend,
    model_asset: model.model_asset,
    model_source: model.model_source,
    gpu_available: Boolean(gpu.gpu_available),
    gpu_api: normalizeCode(gpu.gpu_api, 'none'),
    gpu_failure_signature: normalizeBackgroundFailureSignature(gpu.gpu_failure_signature, ''),
    backend_failure_signature: failureSignature,
    reason_user_choice_required: normalizeCode(details.reasonUserChoiceRequired || details.reason_user_choice_required || 'not_required', 'not_required'),
    failure_count: failures.length,
    failures,
  };

  optionalCodePayload(payload, 'init_phase', details.initPhase || details.init_phase);
  optionalCodePayload(payload, 'matte_rejection_reason', details.matteRejectionReason || details.matte_rejection_reason);
  optionalCodePayload(payload, 'modal_choice', details.modalChoice || details.modal_choice);
  optionalCodePayload(payload, 'background_filter_mode', details.backgroundFilterMode || details.background_filter_mode);
  optionalCodePayload(payload, 'background_backdrop_mode', details.backgroundBackdropMode || details.background_backdrop_mode);
  optionalCodePayload(payload, 'background_quality_profile', details.backgroundQualityProfile || details.background_quality_profile);
  optionalCodePayload(payload, 'media_runtime_path', details.mediaRuntimePath || details.media_runtime_path);
  optionalCodePayload(payload, 'mask_kind', details.maskKind || details.mask_kind);
  optionalCodePayload(payload, 'choice_apply_state', details.choiceApplyState || details.choice_apply_state);
  optionalStringPayload(payload, 'source_stage_reason', details.sourceStageReason || details.source_stage_reason);

  if (Number.isFinite(Number(details.maskWidth))) {
    payload.mask_width = Math.max(0, Math.round(Number(details.maskWidth)));
  }
  if (Number.isFinite(Number(details.maskHeight))) {
    payload.mask_height = Math.max(0, Math.round(Number(details.maskHeight)));
  }
  if (Number.isFinite(Number(details.detectSampleMs))) {
    payload.detect_sample_ms = Math.max(0, Math.round(Number(details.detectSampleMs)));
  }

  return payload;
}

export function shouldEmitBackgroundRuntimeDiagnostic(throttleKey, throttleMs = BACKGROUND_RUNTIME_DIAGNOSTIC_THROTTLE_MS) {
  const key = normalizeString(throttleKey, 'background-runtime', 240);
  const now = Date.now();
  const last = diagnosticThrottleState.get(key) || 0;
  if (now - last < Math.max(1000, Number(throttleMs) || BACKGROUND_RUNTIME_DIAGNOSTIC_THROTTLE_MS)) {
    return false;
  }
  diagnosticThrottleState.set(key, now);
  return true;
}

export function captureBackgroundRuntimeDiagnostic({
  captureDiagnostic = null,
  code = '',
  details = {},
  eventType = '',
  immediate = false,
  level = 'warning',
  message = '',
  throttleKey = '',
  throttleMs = BACKGROUND_RUNTIME_DIAGNOSTIC_THROTTLE_MS,
} = {}) {
  const normalizedEventType = normalizeCode(eventType, '');
  if (!normalizedEventType) return null;
  const normalizedCode = normalizeCode(code || normalizedEventType, normalizedEventType);
  const payload = createBackgroundRuntimeDiagnosticPayload(details);
  const key = throttleKey || `${normalizedEventType}:${normalizedCode}:${payload.backend}:${payload.backend_failure_signature}`;
  if (!shouldEmitBackgroundRuntimeDiagnostic(key, throttleMs)) return null;

  const diagnostic = {
    category: 'media',
    level,
    eventType: normalizedEventType,
    code: normalizedCode,
    message: normalizeString(message, 'Background replacement runtime diagnostic captured.', 500),
    payload,
    immediate,
  };

  if (typeof captureDiagnostic === 'function') {
    return captureDiagnostic(diagnostic);
  }
  return reportClientDiagnostic(diagnostic);
}

export function captureBackgroundBackendInitDiagnostic({
  backend = 'none',
  captureDiagnostic = null,
  error = null,
  failures = [],
  phase = 'starting',
} = {}) {
  const initPhase = normalizeCode(phase, 'starting');
  const failed = initPhase === 'failed';
  const failureSignature = normalizeBackgroundFailureSignature(error || failures[0] || '', '');
  return captureBackgroundRuntimeDiagnostic({
    captureDiagnostic,
    eventType: 'local_background_backend_init',
    code: failed ? 'background_backend_init_failed' : `background_backend_init_${initPhase}`,
    level: failed ? 'error' : 'warning',
    message: failed
      ? 'Local background segmentation backend failed to initialize.'
      : 'Local background segmentation backend initialization observed.',
    details: {
      backend,
      backendFailureSignature: failureSignature,
      error,
      failures,
      initPhase,
      reasonUserChoiceRequired: failed ? 'segmentation_backend_init_failed' : 'not_required',
      requestedBackend: 'worker-segmenter',
      selectedBackend: backend,
    },
    immediate: failed,
    throttleKey: `background-backend-init:${initPhase}:${backend}:${failureSignature || 'none'}`,
  });
}

export function resolveBackgroundMatteRejection(segmentation = {}) {
  if (segmentation?.detectSampleMs === null || typeof segmentation?.detectSampleMs === 'undefined') {
    return null;
  }

  const hasBitmap = typeof ImageBitmap !== 'undefined' && segmentation?.matteMaskBitmap instanceof ImageBitmap;
  const hasValues = segmentation?.matteMaskValues instanceof Float32Array;
  const maskWidth = Math.max(0, Math.round(Number(segmentation?.matteMaskWidth) || 0));
  const maskHeight = Math.max(0, Math.round(Number(segmentation?.matteMaskHeight) || 0));

  if (!hasBitmap && !hasValues) {
    return {
      maskKind: 'none',
      maskWidth,
      maskHeight,
      reason: 'missing_matte_mask',
    };
  }

  if (maskWidth <= 1 || maskHeight <= 1) {
    return {
      maskKind: hasBitmap ? 'bitmap' : 'values',
      maskWidth,
      maskHeight,
      reason: 'invalid_matte_dimensions',
    };
  }

  if (hasValues) {
    const pixelCount = maskWidth * maskHeight;
    if (segmentation.matteMaskValues.length < pixelCount) {
      return {
        maskKind: 'values',
        maskWidth,
        maskHeight,
        reason: 'short_matte_values',
      };
    }

    let maxValue = 0;
    for (let index = 0; index < pixelCount; index += 1) {
      const value = Number(segmentation.matteMaskValues[index]) || 0;
      if (value > maxValue) maxValue = value;
      if (maxValue > 0.5) break;
    }
    if (maxValue <= 0.5) {
      return {
        maskKind: 'values',
        maskWidth,
        maskHeight,
        reason: maxValue <= 0 ? 'empty_matte_mask' : 'low_confidence_matte_mask',
      };
    }
  }

  return null;
}

export function captureBackgroundMatteRejectionDiagnostic({
  backend = 'none',
  detectSampleMs = 0,
  maskHeight = 0,
  maskKind = 'none',
  maskWidth = 0,
  mode = '',
  reason = 'matte_rejected',
} = {}) {
  const normalizedReason = normalizeCode(reason, 'matte_rejected');
  return captureBackgroundRuntimeDiagnostic({
    eventType: 'local_background_matte_rejected',
    code: 'background_matte_rejected',
    level: 'warning',
    message: 'Local background segmentation produced a matte that could not be rendered.',
    details: {
      backend,
      backgroundFilterMode: mode,
      detectSampleMs,
      maskHeight,
      maskKind,
      maskWidth,
      matteRejectionReason: normalizedReason,
      reasonUserChoiceRequired: normalizedReason,
      requestedBackend: 'worker-segmenter',
      selectedBackend: backend,
    },
    throttleKey: `background-matte-rejected:${backend}:${normalizedReason}:${maskKind}`,
  });
}

export function captureBackgroundModalChoiceDiagnostic(choice, details = {}) {
  const modalChoice = normalizeCode(choice, 'unknown_choice');
  return captureBackgroundRuntimeDiagnostic({
    eventType: 'local_background_replacement_modal_choice',
    code: `background_modal_choice_${modalChoice}`,
    level: 'warning',
    message: 'Background replacement unavailable modal choice selected.',
    details: {
      ...details,
      modalChoice,
      reasonUserChoiceRequired: details.reasonUserChoiceRequired || details.reason || 'background_replacement_requires_user_choice',
      requestedBackend: details.requestedBackend || 'worker-segmenter',
      selectedBackend: details.selectedBackend || details.backend || 'none',
    },
    immediate: true,
    throttleKey: `background-modal-choice:${modalChoice}:${details.reason || details.reasonUserChoiceRequired || 'unknown'}`,
    throttleMs: BACKGROUND_MODAL_CHOICE_DIAGNOSTIC_THROTTLE_MS,
  });
}
