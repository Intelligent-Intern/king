import { createBackgroundFilterStream } from './backgroundFilterStream';

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
    this.disposeCurrentHandle();

    const handle = await createBackgroundFilterStream(sourceStream, options);
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
