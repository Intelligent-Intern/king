export const BACKGROUND_PIPELINE_MESSAGE_TYPES = Object.freeze({
  PIPELINE_START: 'pipeline.start',
  PIPELINE_STOP: 'pipeline.stop',
  PIPELINE_PAUSE: 'pipeline.pause',
  PIPELINE_RESUME: 'pipeline.resume',
  CONFIG_UPDATE: 'config.update',
  SOURCE_STARTED: 'source.started',
  SOURCE_STOPPED: 'source.stopped',
  SOURCE_CHANGED: 'source.changed',
  FRAME_AVAILABLE: 'frame.available',
  SEGMENTATION_READY: 'segmentation.ready',
  SEGMENTATION_UNAVAILABLE: 'segmentation.unavailable',
  EFFECTS_READY: 'effects.ready',
  COMPOSE_DONE: 'compose.done',
  OVERLOAD_DETECTED: 'overload.detected',
  STATS_SAMPLE: 'stats.sample',
});

export function createBackgroundPipelineMessage(type, payload = {}, meta = {}) {
  return {
    type: String(type || '').trim(),
    payload: payload && typeof payload === 'object' ? payload : {},
    meta: {
      emittedAt: Date.now(),
      ...meta,
    },
  };
}

export function isBackgroundPipelineMessage(value) {
  return Boolean(value)
    && typeof value === 'object'
    && typeof value.type === 'string'
    && value.type.trim() !== '';
}
