import {
  BACKGROUND_FALLBACK_AVATAR_MODE,
  normalizeBackgroundFallbackAvatarUrl,
  normalizeBackgroundFallbackMode,
} from './avatarFallbackSignal';

export function createBackgroundStaticAvatarRenderState({
  callMediaPrefs,
  currentUserId,
  peerControlStateByUserId,
} = {}) {
  const avatarNodesByUserId = new Map();

  function stateForUserId(userId) {
    const normalizedUserId = Number(userId || 0);
    if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return null;

    if (normalizedUserId === Number(currentUserId?.value || 0)) {
      return {
        mode: normalizeBackgroundFallbackMode(callMediaPrefs?.backgroundFallbackVideoMode),
        imageUrl: callMediaPrefs?.backgroundFallbackAvatarImageUrl,
      };
    }

    const peerState = peerControlStateByUserId?.[normalizedUserId];
    if (!peerState || typeof peerState !== 'object') return null;
    return {
      mode: normalizeBackgroundFallbackMode(peerState.backgroundFallbackVideoMode || peerState.videoSubstitution),
      imageUrl: peerState.backgroundFallbackAvatarImageUrl,
    };
  }

  function staticAvatarUrlForUserId(userId) {
    const state = stateForUserId(userId);
    if (!state || state.mode !== BACKGROUND_FALLBACK_AVATAR_MODE) return '';
    return normalizeBackgroundFallbackAvatarUrl(state.imageUrl);
  }

  function hasStaticAvatarForUserId(userId) {
    return staticAvatarUrlForUserId(userId) !== '';
  }

  function staticAvatarNodeForUserId(userId) {
    const normalizedUserId = Number(userId || 0);
    const imageUrl = staticAvatarUrlForUserId(normalizedUserId);
    if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0 || imageUrl === '') return null;
    if (typeof document === 'undefined') return null;

    let node = avatarNodesByUserId.get(normalizedUserId) || null;
    if (!(node instanceof HTMLImageElement)) {
      node = document.createElement('img');
      node.className = 'workspace-static-avatar-media';
      node.alt = '';
      node.decoding = 'async';
      node.loading = 'eager';
      node.dataset.callStaticAvatar = '1';
      node.dataset.userId = String(normalizedUserId);
      avatarNodesByUserId.set(normalizedUserId, node);
    }
    if (node.dataset.staticAvatarSrc !== imageUrl) {
      node.src = imageUrl;
      node.dataset.staticAvatarSrc = imageUrl;
    }
    return node;
  }

  function clearStaticAvatarNodes() {
    for (const node of avatarNodesByUserId.values()) {
      if (node?.parentElement) node.remove();
    }
    avatarNodesByUserId.clear();
  }

  return {
    clearStaticAvatarNodes,
    hasStaticAvatarForUserId,
    staticAvatarNodeForUserId,
    staticAvatarUrlForUserId,
  };
}
