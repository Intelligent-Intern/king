export function createVideoFrameScheduler({ onFrame, video }) {
  let active = false;
  let rafId = 0;
  let videoFrameCallbackId = 0;

  function clearPending() {
    if (rafId) {
      cancelAnimationFrame(rafId);
      rafId = 0;
    }
    if (videoFrameCallbackId && typeof video.cancelVideoFrameCallback === 'function') {
      video.cancelVideoFrameCallback(videoFrameCallbackId);
      videoFrameCallbackId = 0;
    }
  }

  function scheduleWithRaf() {
    rafId = requestAnimationFrame((now) => {
      rafId = 0;
      if (!active) return;
      onFrame(now);
      if (!active) return;
      scheduleWithRaf();
    });
  }

  function scheduleWithVideoFrames() {
    videoFrameCallbackId = video.requestVideoFrameCallback((now) => {
      videoFrameCallbackId = 0;
      if (!active) return;
      onFrame(now);
      if (!active) return;
      scheduleWithVideoFrames();
    });
  }

  function start() {
    if (active) return;
    active = true;
    if (typeof video.requestVideoFrameCallback === 'function') {
      scheduleWithVideoFrames();
      return;
    }
    scheduleWithRaf();
  }

  function stop() {
    active = false;
    clearPending();
  }

  return {
    start,
    stop,
  };
}
