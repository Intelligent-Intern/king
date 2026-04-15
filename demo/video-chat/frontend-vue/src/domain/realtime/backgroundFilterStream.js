function toNumber(value, fallback) {
  const numeric = Number(value);
  return Number.isFinite(numeric) ? numeric : fallback;
}

function uniqueMediaStreams(values) {
  const out = [];
  const seen = new Set();
  for (const value of values) {
    if (!(value instanceof MediaStream)) continue;
    if (seen.has(value)) continue;
    seen.add(value);
    out.push(value);
  }
  return out;
}

function stopStreamTracks(stream) {
  if (!(stream instanceof MediaStream)) return;
  for (const track of stream.getTracks()) {
    try {
      track.stop();
    } catch {
      // ignore
    }
  }
}

async function waitForVideoReady(video) {
  if (!(video instanceof HTMLVideoElement)) return false;
  if (video.readyState >= 2) return true;
  return await new Promise((resolve) => {
    let done = false;
    const finish = (value) => {
      if (done) return;
      done = true;
      cleanup();
      resolve(Boolean(value));
    };
    const cleanup = () => {
      video.removeEventListener('loadeddata', handleLoadedData);
      video.removeEventListener('canplay', handleCanPlay);
      video.removeEventListener('error', handleError);
    };
    const handleLoadedData = () => finish(true);
    const handleCanPlay = () => finish(true);
    const handleError = () => finish(false);
    video.addEventListener('loadeddata', handleLoadedData);
    video.addEventListener('canplay', handleCanPlay);
    video.addEventListener('error', handleError);
    setTimeout(() => finish(video.readyState >= 2), 2500);
  });
}

function resolveFps(track) {
  const settings = typeof track?.getSettings === 'function' ? track.getSettings() : null;
  return Math.max(8, Math.min(30, Math.round(toNumber(settings?.frameRate, 15))));
}

export async function createBackgroundFilterStream(sourceStream, options = {}) {
  if (!(sourceStream instanceof MediaStream)) {
    return {
      stream: sourceStream,
      active: false,
      reason: 'setup_failed',
      backend: 'none',
      dispose: () => {},
    };
  }

  const mode = String(options.mode || 'off').trim().toLowerCase();
  if (mode !== 'blur') {
    return {
      stream: sourceStream,
      active: false,
      reason: 'off',
      backend: 'none',
      dispose: () => {},
    };
  }

  const videoTrack = sourceStream.getVideoTracks()[0] || null;
  if (!videoTrack) {
    return {
      stream: sourceStream,
      active: false,
      reason: 'no_video_track',
      backend: 'none',
      dispose: () => {},
    };
  }

  if (typeof document === 'undefined') {
    return {
      stream: sourceStream,
      active: false,
      reason: 'unsupported',
      backend: 'none',
      dispose: () => {},
    };
  }

  const video = document.createElement('video');
  video.autoplay = true;
  video.playsInline = true;
  video.muted = true;
  video.srcObject = new MediaStream([videoTrack]);

  const ready = await waitForVideoReady(video);
  if (!ready) {
    try {
      video.pause();
    } catch {
      // ignore
    }
    video.srcObject = null;
    return {
      stream: sourceStream,
      active: false,
      reason: 'setup_failed',
      backend: 'none',
      dispose: () => {},
    };
  }

  try {
    await video.play();
  } catch {
    // keep processing attempt; frame loop guards readyState.
  }

  const settings = typeof videoTrack.getSettings === 'function' ? videoTrack.getSettings() : {};
  const width = Math.max(2, Math.round(toNumber(settings?.width, 640)));
  const height = Math.max(2, Math.round(toNumber(settings?.height, 480)));
  const fps = resolveFps(videoTrack);
  const blurPx = Math.max(4, Math.min(28, Math.round(toNumber(options.blurPx, 12))));

  const canvas = document.createElement('canvas');
  canvas.width = width;
  canvas.height = height;
  const ctx = canvas.getContext('2d', { alpha: false, desynchronized: true });
  if (!ctx || typeof canvas.captureStream !== 'function') {
    try {
      video.pause();
    } catch {
      // ignore
    }
    video.srcObject = null;
    return {
      stream: sourceStream,
      active: false,
      reason: 'unsupported',
      backend: 'none',
      dispose: () => {},
    };
  }

  const captured = canvas.captureStream(fps);
  const output = new MediaStream();
  const filteredVideoTrack = captured.getVideoTracks()[0] || null;
  if (filteredVideoTrack) {
    output.addTrack(filteredVideoTrack);
  }
  for (const audioTrack of sourceStream.getAudioTracks()) {
    output.addTrack(audioTrack);
  }

  let disposed = false;
  let rafId = 0;
  const draw = () => {
    if (disposed) return;
    if (video.readyState >= 2) {
      ctx.save();
      ctx.filter = `blur(${blurPx}px)`;
      ctx.drawImage(video, 0, 0, width, height);
      ctx.restore();
    }
    rafId = requestAnimationFrame(draw);
  };
  rafId = requestAnimationFrame(draw);

  const dispose = () => {
    if (disposed) return;
    disposed = true;
    if (rafId) cancelAnimationFrame(rafId);
    try {
      video.pause();
    } catch {
      // ignore
    }
    video.srcObject = null;
    for (const stream of uniqueMediaStreams([captured])) {
      stopStreamTracks(stream);
    }
  };

  return {
    stream: output,
    active: true,
    reason: 'ok_fallback',
    backend: 'center_mask',
    dispose,
  };
}
