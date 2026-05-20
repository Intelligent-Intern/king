function liveVideoTrack(stream) {
  if (!(stream instanceof MediaStream)) return null;
  return stream.getVideoTracks().find((track) => (
    track && String(track.readyState || '').toLowerCase() === 'live'
  )) || null;
}

function isHtmlVideoElement(value) {
  return typeof HTMLVideoElement !== 'undefined' && value instanceof HTMLVideoElement;
}

export function createLocalCaptureWatchdog({
  captureDiagnostic = () => {},
  controlState,
  isLocalScreenShareActive = () => false,
  reconfigureLocalTracks = async () => false,
  refs,
  state,
} = {}) {
  let timer = null;
  let observedTrackId = '';
  let lastVideoTime = 0;
  let stalledChecks = 0;

  function stop() {
    if (timer !== null) {
      clearInterval(timer);
      timer = null;
    }
    observedTrackId = '';
    lastVideoTime = 0;
    stalledChecks = 0;
  }

  function shouldWatch() {
    return controlState?.cameraEnabled !== false
      && !isLocalScreenShareActive()
      && state?.localTrackReconfigureInFlight !== true;
  }

  function captureStream() {
    const stream = refs?.localStreamRef?.value;
    return stream instanceof MediaStream ? stream : null;
  }

  async function retryPreviewAutoplay(video) {
    if (!isHtmlVideoElement(video) || typeof video.play !== 'function') return;
    try {
      await video.play();
    } catch {
      // A muted preview should normally autoplay; restart capture only after repeated stalls.
    }
  }

  function previewHasAdvanced(video) {
    if (!isHtmlVideoElement(video)) return false;
    const readyState = Number(video.readyState || 0);
    const currentTime = Number(video.currentTime || 0);
    const advanced = readyState >= 2 && currentTime > lastVideoTime + 0.001;
    if (readyState >= 2) {
      lastVideoTime = currentTime;
    }
    return advanced;
  }

  async function check(reason) {
    if (!shouldWatch()) return;
    const stream = captureStream();
    const track = liveVideoTrack(stream);
    if (!track) {
      stop();
      return;
    }
    const trackId = String(track.id || '');
    if (trackId !== observedTrackId) {
      observedTrackId = trackId;
      stalledChecks = 0;
      lastVideoTime = 0;
    }

    const video = refs?.localVideoElement?.value;
    if (previewHasAdvanced(video)) {
      stalledChecks = 0;
      return;
    }

    await retryPreviewAutoplay(video);
    stalledChecks += 1;
    if (stalledChecks < 2 || state?.localTrackReconfigureInFlight === true) return;

    captureDiagnostic({
      category: 'media',
      level: 'warning',
      eventType: 'local_capture_stall_restart',
      code: 'local_capture_stall_restart',
      message: 'Local camera capture stalled; restarting only the local media tracks.',
      payload: {
        reason: String(reason || 'local_capture_watchdog'),
        track_id: trackId,
        track_ready_state: String(track.readyState || ''),
        video_ready_state: isHtmlVideoElement(video) ? Number(video.readyState || 0) : 0,
      },
      immediate: true,
    });
    stop();
    void reconfigureLocalTracks();
  }

  function start(stream, reason = 'local_capture_watchdog') {
    stop();
    if (!shouldWatch() || !liveVideoTrack(stream)) return false;
    timer = setInterval(() => {
      void check(reason);
    }, 4000);
    return true;
  }

  return { start, stop };
}
