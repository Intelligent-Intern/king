export function createVideoFullscreenToggle({
  callLayoutState,
  currentLayoutMode,
  nextTick = (callback) => callback?.(),
  normalizeCallLayoutMode,
  primaryVideoUserId,
  renderCallVideoLayout = () => {},
  replaceNumericArray,
  sendLayoutCommand = () => false,
  syncCallLayoutSidebarControls = () => {},
} = {}) {
  let restoreLayoutMode = 'main_mini';

  function setMainUserId(userId) {
    const normalizedUserId = Number(userId || 0);
    if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return false;
    callLayoutState.main_user_id = normalizedUserId;
    if (!callLayoutState.selection || typeof callLayoutState.selection !== 'object') {
      callLayoutState.selection = {};
    }
    if (!Array.isArray(callLayoutState.selected_user_ids)) callLayoutState.selected_user_ids = [];
    if (!Array.isArray(callLayoutState.selection.visible_user_ids)) callLayoutState.selection.visible_user_ids = [];
    callLayoutState.selection.main_user_id = normalizedUserId;
    if (typeof replaceNumericArray === 'function') {
      replaceNumericArray(callLayoutState.selected_user_ids, [normalizedUserId]);
      replaceNumericArray(callLayoutState.selection.visible_user_ids, [normalizedUserId]);
    } else {
      callLayoutState.selected_user_ids = [normalizedUserId];
      callLayoutState.selection.visible_user_ids = [normalizedUserId];
    }
    return true;
  }

  function scheduleLayoutRender() {
    syncCallLayoutSidebarControls();
    nextTick(() => renderCallVideoLayout());
  }

  function toggleVideoFullscreen(userId) {
    const normalizedUserId = Number(userId || 0);
    if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return;
    const activeMode = normalizeCallLayoutMode(currentLayoutMode.value, 'main_mini');
    const activePrimaryUserId = Number(primaryVideoUserId.value || 0);
    const exitingActiveFullscreen = activeMode === 'main_only' && activePrimaryUserId === normalizedUserId;

    if (exitingActiveFullscreen) {
      const nextMode = normalizeCallLayoutMode(restoreLayoutMode, 'main_mini');
      callLayoutState.mode = nextMode === 'main_only' ? 'main_mini' : nextMode;
      sendLayoutCommand('layout/mode', { mode: callLayoutState.mode });
      scheduleLayoutRender();
      return;
    }

    if (activeMode !== 'main_only') {
      restoreLayoutMode = activeMode;
    }
    if (!setMainUserId(normalizedUserId)) return;
    callLayoutState.mode = 'main_only';
    sendLayoutCommand('layout/mode', { mode: 'main_only' });
    sendLayoutCommand('layout/selection', {
      main_user_id: normalizedUserId,
      selected_user_ids: [normalizedUserId],
    });
    scheduleLayoutRender();
  }

  return {
    toggleVideoFullscreen,
  };
}
