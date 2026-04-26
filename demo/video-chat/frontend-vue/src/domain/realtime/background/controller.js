import { createBackgroundFilterStream } from './stream';

export class BackgroundFilterController {
  constructor() {
    this.revision = 0;
    this.currentHandle = null;
  }

  disposeCurrentHandle() {
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
    if (previousHandle && previousHandle !== handle) {
      try {
        previousHandle.dispose();
      } catch {
        // best-effort cleanup; never break call flow.
      }
    }
    return {
      stream: handle.stream,
      active: Boolean(handle.active),
      reason: handle.reason,
      backend: handle.backend,
      stale: false,
    };
  }

  dispose() {
    this.revision += 1;
    this.disposeCurrentHandle();
  }
}
