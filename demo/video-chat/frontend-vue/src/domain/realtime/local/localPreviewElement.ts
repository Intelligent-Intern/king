export function clearLocalPreviewElement({
  localVideoElementRef,
  renderCallVideoLayout,
} = {}) {
  const node = localVideoElementRef?.value;
  if (node instanceof HTMLVideoElement) {
    try {
      node.pause();
    } catch {
      // ignore stale preview nodes during publisher teardown
    }
    node.srcObject = null;
    node.remove();
  }
  if (localVideoElementRef && typeof localVideoElementRef === 'object') {
    localVideoElementRef.value = null;
  }

  const container = document.getElementById('local-video-container');
  if (container) {
    container.innerHTML = '';
  }
  renderCallVideoLayout?.();
}

export async function attachLocalPreviewTrack({
  localVideoElementRef,
  renderCallVideoLayout,
  videoTrack,
} = {}) {
  if (!videoTrack || String(videoTrack.readyState || '').toLowerCase() === 'ended') {
    return null;
  }
  if (typeof document === 'undefined' || typeof HTMLVideoElement === 'undefined') {
    return null;
  }

  let video = localVideoElementRef?.value;
  if (!(video instanceof HTMLVideoElement)) {
    video = document.createElement('video');
    video.muted = true;
    video.playsInline = true;
    video.autoplay = true;
    if (localVideoElementRef && typeof localVideoElementRef === 'object') {
      localVideoElementRef.value = video;
    }
  }

  video.dataset.callLocalPreview = '1';
  video.srcObject = new MediaStream([videoTrack]);

  const container = document.getElementById('local-video-container');
  if (container && video.parentElement !== container) {
    container.replaceChildren(video);
  }

  try {
    await video.play();
  } catch {
    // The local capture watchdog retries autoplay before restarting capture.
  }
  renderCallVideoLayout?.();
  return video;
}
