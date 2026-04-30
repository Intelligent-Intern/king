export function createVideoFullscreenToggle({
  fullscreenVideoUserId,
  nextTick = (callback) => callback?.(),
  renderCallVideoLayout = () => {},
} = {}) {
  function scheduleLayoutRender() {
    nextTick(() => renderCallVideoLayout());
  }

  function setFullscreenVideoUserId(userId) {
    const normalizedUserId = Number(userId || 0);
    if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return false;
    if (!fullscreenVideoUserId || typeof fullscreenVideoUserId !== 'object') return false;
    fullscreenVideoUserId.value = normalizedUserId;
    scheduleLayoutRender();
    return true;
  }

  function closeVideoFullscreen() {
    if (!fullscreenVideoUserId || typeof fullscreenVideoUserId !== 'object') return;
    if (Number(fullscreenVideoUserId.value || 0) <= 0) return;
    fullscreenVideoUserId.value = 0;
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
