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
