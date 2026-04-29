import { PUBLISHER_CAPTURE_BACKENDS } from './capturePipelineCapabilities.js';

export const PUBLISHER_VIDEO_FRAME_SOURCE_BACKEND = 'video_frame_processor_canvas_readback';

function functionRef(value) {
  return typeof value === 'function' ? value : null;
}

function positiveTimeoutMs(value, fallback = 1200) {
  const normalized = Number(value || 0);
  return Number.isFinite(normalized) && normalized > 0 ? normalized : fallback;
}

export function canUsePublisherVideoFrameSource(capabilities = {}) {
  return Boolean(
    capabilities.supportsMediaStreamTrackProcessor
      && capabilities.supportsVideoFrame
      && capabilities.supportsVideoFrameClose,
  );
}

export function closePublisherVideoFrame(frame) {
  if (frame && typeof frame.close === 'function') {
    try {
      frame.close();
    } catch {
      // Closing is best-effort; a stale VideoFrame must not hide fallback recovery.
    }
  }
}

function closePublisherVideoFrameReadResult(result) {
  if (result?.done || !result?.value) return;
  closePublisherVideoFrame(result.value);
}

export function createPublisherVideoFrameSourceReader({
  videoTrack,
  MediaStreamTrackProcessorCtor = functionRef(globalThis?.MediaStreamTrackProcessor),
  readTimeoutMs = 1200,
} = {}) {
  if (!videoTrack) {
    throw new Error('publisher_video_frame_track_missing');
  }
  if (typeof MediaStreamTrackProcessorCtor !== 'function') {
    throw new Error('publisher_video_frame_processor_unsupported');
  }

  const processor = new MediaStreamTrackProcessorCtor({ track: videoTrack, maxBufferSize: 1 });
  const reader = processor?.readable && typeof processor.readable.getReader === 'function'
    ? processor.readable.getReader()
    : null;
  if (!reader || typeof reader.read !== 'function') {
    throw new Error('publisher_video_frame_reader_missing');
  }

  let closed = false;
  const activeReadPromises = new Set();

  function trackReadPromise(readPromise) {
    activeReadPromises.add(readPromise);
    readPromise.finally(() => {
      activeReadPromises.delete(readPromise);
    }).catch(() => {});
    return readPromise;
  }

  function closePendingReadResults() {
    for (const readPromise of activeReadPromises) {
      readPromise.then(closePublisherVideoFrameReadResult).catch(() => {});
    }
  }

  async function close(reason = 'publisher_video_frame_source_closed') {
    if (closed) return;
    closed = true;
    closePendingReadResults();
    try {
      if (typeof reader.cancel === 'function') {
        await reader.cancel(reason);
      }
    } catch {
      // Ignore cancel failures during local pipeline turnover.
    }
    try {
      if (typeof reader.releaseLock === 'function') {
        reader.releaseLock();
      }
    } catch {
      // Ignore stale lock release failures.
    }
  }

  async function readFrame({ timeoutMs = readTimeoutMs } = {}) {
    if (closed) {
      return { ok: false, reason: 'publisher_video_frame_source_closed', fatal: true };
    }

    const readPromise = trackReadPromise(Promise.resolve().then(() => reader.read()));
    let timeoutId = null;
    const timeoutPromise = new Promise((resolve) => {
      timeoutId = setTimeout(() => resolve({ timeout: true }), positiveTimeoutMs(timeoutMs));
    });
    let result = null;
    try {
      result = await Promise.race([readPromise, timeoutPromise]);
    } catch {
      if (timeoutId !== null) clearTimeout(timeoutId);
      await close('publisher_video_frame_read_failed');
      return { ok: false, reason: 'publisher_video_frame_read_failed', fatal: true };
    }
    if (timeoutId !== null) clearTimeout(timeoutId);

    if (result?.timeout) {
      readPromise.then(closePublisherVideoFrameReadResult).catch(() => {});
      await close('publisher_video_frame_read_timeout');
      return { ok: false, reason: 'publisher_video_frame_read_timeout', fatal: true };
    }

    if (closed) {
      closePublisherVideoFrameReadResult(result);
      return { ok: false, reason: 'publisher_video_frame_source_closed', fatal: true };
    }

    if (result?.done || !result?.value) {
      await close('publisher_video_frame_source_done');
      return { ok: false, reason: 'publisher_video_frame_source_done', fatal: true };
    }

    return {
      ok: true,
      backend: PUBLISHER_CAPTURE_BACKENDS.VIDEO_FRAME_COPY,
      sourceBackend: PUBLISHER_VIDEO_FRAME_SOURCE_BACKEND,
      frame: result.value,
    };
  }

  return {
    readFrame,
    close,
    get closed() {
      return closed;
    },
  };
}
