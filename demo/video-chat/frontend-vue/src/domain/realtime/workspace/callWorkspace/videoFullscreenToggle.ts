export function createVideoFullscreenToggle({
  callLayoutState = null,
  fullscreenVideoUserId,
  nextTick = (callback) => callback?.(),
  renderCallVideoLayout = () => {},
} = {}) {
  let previousLayoutMode = 'main_mini';

  function scheduleLayoutRender() {
    nextTick(() => renderCallVideoLayout());
  }

  function enterFullscreenLayout() {
    if (!callLayoutState || typeof callLayoutState !== 'object') return;
    const nextMode = String(callLayoutState.mode || 'main_mini').trim() || 'main_mini';
    previousLayoutMode = nextMode === 'main_only' ? previousLayoutMode : nextMode;
    callLayoutState.mode = 'main_only';
  }

  function exitFullscreenLayout() {
    if (!callLayoutState || typeof callLayoutState !== 'object') return;
    const nextMode = String(previousLayoutMode || callLayoutState.mode || 'main_mini').trim() || 'main_mini';
    callLayoutState.mode = nextMode === 'main_only' ? 'main_mini' : nextMode;
  }

  function setFullscreenVideoUserId(userId) {
    const normalizedUserId = Number(userId || 0);
    if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return false;
    if (!fullscreenVideoUserId || typeof fullscreenVideoUserId !== 'object') return false;
    enterFullscreenLayout();
    fullscreenVideoUserId.value = normalizedUserId;
    scheduleLayoutRender();
    return true;
  }

  function closeVideoFullscreen() {
    if (!fullscreenVideoUserId || typeof fullscreenVideoUserId !== 'object') return;
    if (Number(fullscreenVideoUserId.value || 0) <= 0) return;
    fullscreenVideoUserId.value = 0;
    exitFullscreenLayout();
    scheduleLayoutRender();
  }

  function toggleVideoFullscreen(userId) {
    const normalizedUserId = Number(userId || 0);
    if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return;
    const activeFullscreenUserId = Number(fullscreenVideoUserId?.value || 0);
    if (activeFullscreenUserId === normalizedUserId) {
      closeVideoFullscreen();
      return;
    }
    setFullscreenVideoUserId(normalizedUserId);
  }

  return {
    closeVideoFullscreen,
    toggleVideoFullscreen,
  };
}
