import { createBackgroundFilterStream } from './stream';

export class BackgroundFilterController {
  constructor() {
    this.revision = 0;
    this.currentHandle = null;
    this.listeners = new Set();
    this.detachHandlePipelineListener = null;
  }

  buildApplyResult(handle, { stale = false } = {}) {
    return {
      stream: handle?.stream || null,
      active: Boolean(handle?.active),
      reason: String(handle?.reason || 'off'),
      backend: String(handle?.backend || 'none'),
      stale,
    };
  }

  disposeCurrentHandle() {
    if (typeof this.detachHandlePipelineListener === 'function') {
      this.detachHandlePipelineListener();
      this.detachHandlePipelineListener = null;
    }
    const handle = this.currentHandle;
    this.currentHandle = null;
    if (!handle) return;
    try {
      handle.dispose();
    } catch {
      // best-effort cleanup; never break call flow.
    }
  }

  async apply(sourceStream, options) {
    const revision = ++this.revision;
    const previousHandle = this.currentHandle;

    if (
      previousHandle
      && previousHandle.sourceStream === sourceStream
      && typeof previousHandle.updateConfig === 'function'
    ) {
      const updatedHandle = await previousHandle.updateConfig(options);
      if (revision !== this.revision) {
        return {
          stream: sourceStream,
          active: false,
          reason: 'off',
          backend: 'none',
          stale: true,
        };
      }
      this.currentHandle = updatedHandle || previousHandle;
      this.attachHandlePipelineListener(this.currentHandle);
      this.notifyListeners();
      return this.buildApplyResult(this.currentHandle, { stale: false });
    }

    const handle = await createBackgroundFilterStream(sourceStream, options);
    const shouldAwaitReadyHandoff = Boolean(previousHandle?.active && handle?.active);
    if (shouldAwaitReadyHandoff && handle?.ready && typeof handle.ready.then === 'function') {
      try {
        await handle.ready;
      } catch {
        // ignore readiness failures; the stream object itself is still authoritative
      }
    }
    if (revision !== this.revision) {
      try {
        handle.dispose();
      } catch {
        // ignore stale dispose errors
      }
      return {
        stream: sourceStream,
        active: false,
        reason: 'off',
        backend: 'none',
        stale: true,
      };
    }

    this.currentHandle = handle;
    this.attachHandlePipelineListener(handle);
    if (previousHandle && previousHandle !== handle) {
      try {
        previousHandle.dispose();
      } catch {
        // best-effort cleanup; never break call flow.
      }
    }
    this.notifyListeners();
    return this.buildApplyResult(handle, { stale: false });
  }

  async updateActiveConfig(options) {
    const handle = this.currentHandle;
    if (!handle || typeof handle.updateConfig !== 'function') return null;
    const nextHandle = await handle.updateConfig(options);
    this.currentHandle = nextHandle || handle;
    this.attachHandlePipelineListener(this.currentHandle);
    this.notifyListeners();
    return this.buildApplyResult(this.currentHandle, { stale: false });
  }

  setSourceActive(active, reason = '') {
    const handle = this.currentHandle;
    if (!handle || typeof handle.setSourceActive !== 'function') return false;
    handle.setSourceActive(active, reason);
    this.notifyListeners();
    return true;
  }

  attachHandlePipelineListener(handle) {
    if (typeof this.detachHandlePipelineListener === 'function') {
      this.detachHandlePipelineListener();
      this.detachHandlePipelineListener = null;
    }
    const subscribe = handle?.pipeline?.controller?.subscribe;
    if (typeof subscribe !== 'function') return;
    this.detachHandlePipelineListener = subscribe(() => {
      this.notifyListeners();
    });
  }

  getPipelineDebugState() {
    const handle = this.currentHandle;
    const stages = Array.isArray(handle?.pipeline?.controller?.getStageSnapshots?.())
      ? handle.pipeline.controller.getStageSnapshots()
      : [];
    const sourceStage = stages.find((stage) => stage?.name === 'source') || null;
    return {
      active: Boolean(handle?.active),
      available: Boolean(handle),
      backend: String(handle?.backend || 'none'),
      mode: String(handle?.mode || 'off'),
      reason: String(handle?.reason || 'idle'),
      reactive: Boolean(handle?.pipeline?.reactive),
      sourceActive: Boolean(handle?.sourceActive),
      sourceState: String(sourceStage?.state || 'idle'),
      stages: stages.map((stage) => ({
        name: String(stage?.name || ''),
        state: String(stage?.state || 'idle'),
      })),
    };
  }

  notifyListeners() {
    const snapshot = this.getPipelineDebugState();
    for (const listener of this.listeners) {
      try {
        listener(snapshot);
      } catch {
        // diagnostics should never break call flow
      }
    }
  }

  subscribe(listener) {
    if (typeof listener !== 'function') return () => {};
    this.listeners.add(listener);
    listener(this.getPipelineDebugState());
    return () => {
      this.listeners.delete(listener);
    };
  }

  dispose() {
    this.revision += 1;
    this.disposeCurrentHandle();
    this.notifyListeners();
  }

  getCurrentMatteMaskSnapshot() {
    const handle = this.currentHandle;
    if (!handle || typeof handle.getMatteMaskSnapshot !== 'function') return null;
    try {
      return handle.getMatteMaskSnapshot();
    } catch {
      return null;
    }
  }
}
