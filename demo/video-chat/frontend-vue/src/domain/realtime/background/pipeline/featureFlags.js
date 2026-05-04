function parseEnvFlag(value, fallback = false) {
  if (typeof value === 'boolean') return value;
  const normalized = String(value ?? '').trim().toLowerCase();
  if (normalized === '') return fallback;
  return normalized === '1'
    || normalized === 'true'
    || normalized === 'yes'
    || normalized === 'on';
}

export const REACTIVE_BACKGROUND_PIPELINE_ENABLED = parseEnvFlag(
  import.meta.env.VITE_VIDEOCHAT_ENABLE_REACTIVE_MEDIA_PIPELINE,
  true,
);

export function shouldUseReactiveBackgroundPipeline() {
  return REACTIVE_BACKGROUND_PIPELINE_ENABLED;
}
