export const BACKGROUND_PIPELINE_STAGE_NAMES = Object.freeze({
  SOURCE: 'source',
  SEGMENTER: 'segmenter',
  EFFECTS: 'effects',
  COMPOSITOR: 'compositor',
  OUTPUT: 'output',
});

export const BACKGROUND_PIPELINE_STAGE_STATES = Object.freeze({
  IDLE: 'idle',
  RUNNING: 'running',
  PAUSED: 'paused',
  STOPPED: 'stopped',
  FAILED: 'failed',
});

export function createBackgroundPipelineStageSnapshot(name, state, extra = {}) {
  return {
    name: String(name || '').trim(),
    state: String(state || BACKGROUND_PIPELINE_STAGE_STATES.IDLE).trim(),
    updatedAt: Date.now(),
    ...extra,
  };
}
