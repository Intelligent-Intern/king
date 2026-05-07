import { createBackgroundPipelineMessage, BACKGROUND_PIPELINE_MESSAGE_TYPES } from './messages';
import {
  BACKGROUND_PIPELINE_STAGE_NAMES,
  BACKGROUND_PIPELINE_STAGE_STATES,
  createBackgroundPipelineStageSnapshot,
} from './stages';

export function createBackgroundPipelineController(options = {}) {
  const sourceId = String(options.sourceId || 'local-video').trim() || 'local-video';
  const listeners = new Set();
  const stageState = new Map(Object.values(BACKGROUND_PIPELINE_STAGE_NAMES).map((name) => [
    name,
    createBackgroundPipelineStageSnapshot(name, BACKGROUND_PIPELINE_STAGE_STATES.IDLE),
  ]));

  function emit(type, payload = {}, meta = {}) {
    const message = createBackgroundPipelineMessage(type, payload, {
      sourceId,
      ...meta,
    });
    for (const listener of listeners) {
      try {
        listener(message);
      } catch {
        // Phase 0 keeps listeners best-effort so the legacy path cannot break.
      }
    }
    return message;
  }

  function updateStage(name, state, extra = {}) {
    const snapshot = createBackgroundPipelineStageSnapshot(name, state, extra);
    stageState.set(name, snapshot);
    emit(BACKGROUND_PIPELINE_MESSAGE_TYPES.STATS_SAMPLE, {
      stage: snapshot,
      stages: getStageSnapshots(),
    });
    return snapshot;
  }

  function getStageSnapshots() {
    return Object.freeze(Array.from(stageState.values()));
  }

  function subscribe(listener) {
    if (typeof listener !== 'function') return () => {};
    listeners.add(listener);
    return () => {
      listeners.delete(listener);
    };
  }

  return {
    emit,
    getStageSnapshots,
    sourceId,
    subscribe,
    updateStage,
  };
}
