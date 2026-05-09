function parseEnvFlag(value, fallback = false) {
  if (typeof value === 'boolean') return value;
  const normalized = String(value ?? '').trim().toLowerCase();
  if (normalized === '') return fallback;
  return normalized === '1'
    || normalized === 'true'
    || normalized === 'yes'
    || normalized === 'on';
}

const SEGMENTATION_UNAVAILABLE_SMOKE_FLAG = 'bgf07-segmentation-unavailable';

function queryParamValue(name) {
  if (typeof window === 'undefined') return '';
  try {
    return new URLSearchParams(window.location?.search || '').get(name) || '';
  } catch {
    return '';
  }
}

function normalizedFlagValue(value) {
  return String(value ?? '').trim().toLowerCase();
}

function hasNamedSegmentationUnavailableSmokeFlag() {
  const envSmokeFlag = normalizedFlagValue(import.meta.env.VITE_VIDEOCHAT_BACKGROUND_SMOKE_FLAG);
  const querySmokeFlag = normalizedFlagValue(queryParamValue('kingrt_background_smoke'));
  const sharedQuerySmokeFlag = normalizedFlagValue(queryParamValue('kingrt_smoke'));
  return envSmokeFlag === SEGMENTATION_UNAVAILABLE_SMOKE_FLAG
    || querySmokeFlag === SEGMENTATION_UNAVAILABLE_SMOKE_FLAG
    || sharedQuerySmokeFlag === SEGMENTATION_UNAVAILABLE_SMOKE_FLAG;
}

export const REACTIVE_BACKGROUND_PIPELINE_ENABLED = parseEnvFlag(
  import.meta.env.VITE_VIDEOCHAT_ENABLE_REACTIVE_MEDIA_PIPELINE,
  true,
);

export function shouldUseReactiveBackgroundPipeline() {
  return REACTIVE_BACKGROUND_PIPELINE_ENABLED;
}

export function shouldForceSegmentationUnavailableForSmoke() {
  if (!hasNamedSegmentationUnavailableSmokeFlag()) return false;
  return parseEnvFlag(import.meta.env.VITE_VIDEOCHAT_FORCE_SEGMENTATION_UNAVAILABLE_FOR_SMOKE, false)
    || parseEnvFlag(queryParamValue('kingrt_force_segmentation_unavailable'), false)
    || parseEnvFlag(queryParamValue('kingrt_background_force_segmentation_unavailable'), false);
}

export { SEGMENTATION_UNAVAILABLE_SMOKE_FLAG };
