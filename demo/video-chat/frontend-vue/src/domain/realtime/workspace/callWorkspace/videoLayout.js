export function createCallWorkspaceVideoLayoutHelpers({
  callbacks,
  refs,
}) {
  const {
    applyCallOutputPreferences,
    bumpMediaRenderVersion,
    currentLayoutMode,
    gridVideoParticipants,
    gridVideoSlotId,
    hasRenderableMediaForParticipant,
    lookupMediaNodeForUserId,
    miniVideoParticipants,
    miniVideoSlotId,
    primaryVideoUserId,
    remotePeerMediaNode,
  } = callbacks;

  let deferredVideoLayoutQueued = false;

  function scheduleDeferredVideoLayout() {
    if (deferredVideoLayoutQueued) return;
    deferredVideoLayoutQueued = true;
    const run = () => {
      deferredVideoLayoutQueued = false;
      renderCallVideoLayout();
    };
    if (typeof requestAnimationFrame === 'function') {
      requestAnimationFrame(run);
      return;
    }
    setTimeout(run, 0);
  }

  function markRemotePeerRenderable(peer) {
    if (!peer || typeof peer !== 'object') return;
    if (Number(peer.frameCount || 0) !== 1) return;
    bumpMediaRenderVersion();
    renderCallVideoLayout();
    scheduleDeferredVideoLayout();
  }

  function participantHasRenderableMedia(userId) {
    refs.mediaRenderVersion.value;
    return hasRenderableMediaForParticipant({
      currentUserId: refs.currentUserId.value,
      localFilteredStream: refs.localFilteredStreamRef.value,
      localRawStream: refs.localRawStreamRef.value,
      localStream: refs.localStreamRef.value,
      nativePeers: refs.nativePeerConnectionsRef.value.values(),
      remotePeers: refs.remotePeersRef.value.values(),
      userId,
    });
  }

  function participantInitials(displayName) {
    const parts = String(displayName || '')
      .trim()
      .split(/\s+/)
      .filter(Boolean);
    if (parts.length === 0) return '?';
    const initials = parts.slice(0, 2).map((part) => part.slice(0, 1).toUpperCase()).join('');
    return initials || '?';
  }

  function mediaNodeForUserId(userId) {
    return lookupMediaNodeForUserId({
      currentUserId: refs.currentUserId.value,
      localVideoElement: refs.localVideoElement.value,
      nativePeers: refs.nativePeerConnectionsRef.value.values(),
      remotePeers: refs.remotePeersRef.value.values(),
      userId,
    });
  }

  function mountVideoNode(target, node, assignedNodes) {
    if (!(target instanceof HTMLElement) || !(node instanceof HTMLElement)) return false;
    assignedNodes.add(node);
    if (node.parentElement !== target || target.children.length !== 1 || target.firstElementChild !== node) {
      target.replaceChildren(node);
    }
    return true;
  }

  function clearUnassignedChildren(target, assignedNodes) {
    if (!(target instanceof HTMLElement)) return;
    for (const child of Array.from(target.children)) {
      if (child instanceof HTMLElement && assignedNodes.has(child)) continue;
      child.remove();
    }
  }

  function mountRemotePeerFallback(peer, assignedNodes) {
    if (!peer || typeof peer !== 'object') return;
    const node = remotePeerMediaNode(peer);
    if (!(node instanceof HTMLElement) || assignedNodes.has(node)) return;
    const userId = Number(peer.userId || 0);
    if (!Number.isInteger(userId) || userId <= 0) return;

    const primaryUserId = primaryVideoUserId();
    if (userId === primaryUserId) {
      const primaryTarget = userId === refs.currentUserId.value
        ? document.getElementById('local-video-container')
        : document.getElementById('remote-video-container');
      if (mountVideoNode(primaryTarget, node, assignedNodes)) return;
    }

    const miniSlot = document.getElementById(miniVideoSlotId(userId));
    if (mountVideoNode(miniSlot, node, assignedNodes)) return;

    const gridSlot = document.getElementById(gridVideoSlotId(userId));
    mountVideoNode(gridSlot, node, assignedNodes);
  }

  function renderCallVideoLayout() {
    if (typeof document === 'undefined') return;
    const assignedNodes = new Set();
    const localContainer = document.getElementById('local-video-container');
    const remoteContainer = document.getElementById('remote-video-container');
    if (currentLayoutMode() === 'grid') {
      for (const participant of gridVideoParticipants()) {
        const userId = Number(participant?.userId || 0);
        const slot = document.getElementById(gridVideoSlotId(userId));
        const node = mediaNodeForUserId(userId);
        if (!mountVideoNode(slot, node, assignedNodes)) {
          clearUnassignedChildren(slot, assignedNodes);
        }
      }
      if (localContainer) clearUnassignedChildren(localContainer, assignedNodes);
      if (remoteContainer) clearUnassignedChildren(remoteContainer, assignedNodes);
    } else {
      const primaryUserId = primaryVideoUserId();
      const primaryNode = mediaNodeForUserId(primaryUserId);

      if (primaryUserId === refs.currentUserId.value) {
        mountVideoNode(localContainer, primaryNode, assignedNodes);
      } else {
        mountVideoNode(remoteContainer, primaryNode, assignedNodes);
      }

      for (const participant of miniVideoParticipants()) {
        const userId = Number(participant?.userId || 0);
        const slot = document.getElementById(miniVideoSlotId(userId));
        const node = mediaNodeForUserId(userId);
        if (!mountVideoNode(slot, node, assignedNodes)) {
          clearUnassignedChildren(slot, assignedNodes);
        }
      }

      clearUnassignedChildren(localContainer, assignedNodes);
      clearUnassignedChildren(remoteContainer, assignedNodes);
    }

    const allRemotePeers = [
      ...refs.remotePeersRef.value.values(),
      ...refs.nativePeerConnectionsRef.value.values(),
    ];
    for (const peer of allRemotePeers) {
      mountRemotePeerFallback(peer, assignedNodes);
    }
    for (const peer of allRemotePeers) {
      const node = remotePeerMediaNode(peer);
      if (node instanceof HTMLElement && node.parentElement instanceof HTMLElement && !assignedNodes.has(node)) {
        node.remove();
      }
    }

    applyCallOutputPreferences();
  }

  function renderNativeRemoteVideos() {
    if (typeof document === 'undefined') return;
    if (!refs.shouldMaintainNativePeerConnections()) return;
    renderCallVideoLayout();
  }

  return {
    clearUnassignedChildren,
    markRemotePeerRenderable,
    mediaNodeForUserId,
    mountVideoNode,
    participantHasRenderableMedia,
    participantInitials,
    renderCallVideoLayout,
    renderNativeRemoteVideos,
  };
}
