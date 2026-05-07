import {
  REMOTE_RENDER_SURFACE_ROLES,
  applyRemoteVideoSurfaceRole,
} from '../../sfu/remoteRenderScheduler';
import { isScreenShareMediaSource, isScreenShareUserId } from '../../screenShareIdentity.js';
import { applyScreenSharePanSurface, clearScreenSharePanSurface } from './screenSharePan';

export function createCallWorkspaceVideoLayoutHelpers({
  callbacks,
  refs,
}) {
  const {
    applyCallOutputPreferences,
    bumpMediaRenderVersion,
    currentLayoutMode,
    fullscreenVideoUserId,
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

  function targetAspectRatioForSurface(target, role) {
    if (!(target instanceof HTMLElement)) return 0;
    const rect = typeof target.getBoundingClientRect === 'function' ? target.getBoundingClientRect() : null;
    const width = Number(rect?.width || target.clientWidth || 0);
    const height = Number(rect?.height || target.clientHeight || 0);
    if (!Number.isFinite(width) || !Number.isFinite(height) || width <= 0 || height <= 0) return 0;
    return width / height;
  }

  function framingForSurface(target, role) {
    const targetAspectRatio = targetAspectRatioForSurface(target, role);
    const isPortraitOrSquare = targetAspectRatio > 0 && targetAspectRatio <= 1.02;
    const shouldCover = role === REMOTE_RENDER_SURFACE_ROLES.MINI
      || (
        isPortraitOrSquare
        && [
          REMOTE_RENDER_SURFACE_ROLES.FULLSCREEN,
          REMOTE_RENDER_SURFACE_ROLES.GRID,
          REMOTE_RENDER_SURFACE_ROLES.MAIN,
        ].includes(role)
      );
    return {
      framingMode: shouldCover ? 'cover' : 'contain',
      targetAspectRatio: targetAspectRatio || 0,
    };
  }

  function mountVideoNode(target, node, assignedNodes, {
    role = REMOTE_RENDER_SURFACE_ROLES.FALLBACK,
    userId = 0,
    visibleParticipantCount = 0,
  } = {}) {
    if (!(target instanceof HTMLElement) || !(node instanceof HTMLElement)) return false;
    const framing = framingForSurface(target, role);
    applyRemoteVideoSurfaceRole(node, {
      framingMode: framing.framingMode,
      layoutMode: currentLayoutMode(),
      role,
      targetAspectRatio: framing.targetAspectRatio,
      userId,
      visibleParticipantCount,
    });
    applyScreenSharePanSurface(node, target, { userId });
    if (!isScreenShareUserId(userId) && !isScreenShareMediaSource(node.dataset?.mediaSource)) {
      clearScreenSharePanSurface(node);
    }
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

  function mountFullscreenVideoNode(assignedNodes) {
    const normalizedUserId = Number(fullscreenVideoUserId?.() || 0);
    const target = document.getElementById('workspace-fullscreen-video-slot');
    if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0 || !(target instanceof HTMLElement)) {
      clearUnassignedChildren(target, assignedNodes);
      return 0;
    }
    const node = mediaNodeForUserId(normalizedUserId);
    if (!mountVideoNode(target, node, assignedNodes, {
      role: REMOTE_RENDER_SURFACE_ROLES.FULLSCREEN,
      userId: normalizedUserId,
      visibleParticipantCount: 1,
    })) {
      clearUnassignedChildren(target, assignedNodes);
      return normalizedUserId;
    }
    return normalizedUserId;
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
      const primaryRole = currentLayoutMode() === 'main_only'
        ? REMOTE_RENDER_SURFACE_ROLES.FULLSCREEN
        : REMOTE_RENDER_SURFACE_ROLES.MAIN;
      if (mountVideoNode(primaryTarget, node, assignedNodes, { role: primaryRole, userId })) return;
    }

    const miniSlot = document.getElementById(miniVideoSlotId(userId));
    if (mountVideoNode(miniSlot, node, assignedNodes, { role: REMOTE_RENDER_SURFACE_ROLES.MINI, userId })) return;

    const gridSlot = document.getElementById(gridVideoSlotId(userId));
    if (mountVideoNode(gridSlot, node, assignedNodes, { role: REMOTE_RENDER_SURFACE_ROLES.GRID, userId })) return;

    if (isScreenShareUserId(userId) || isScreenShareMediaSource(peer?.mediaSource || peer?.media_source)) return;

    const decodedFallback = document.getElementById('decoded-video-container');
    mountVideoNode(decodedFallback, node, assignedNodes, { role: REMOTE_RENDER_SURFACE_ROLES.FALLBACK, userId });
  }

  function renderCallVideoLayout() {
    if (typeof document === 'undefined') return;
    const assignedNodes = new Set();
    const activeFullscreenUserId = mountFullscreenVideoNode(assignedNodes);
    const localContainer = document.getElementById('local-video-container');
    const remoteContainer = document.getElementById('remote-video-container');
    if (currentLayoutMode() === 'grid') {
      const participants = gridVideoParticipants();
      const visibleParticipantCount = participants.length;
      for (const participant of participants) {
        const userId = Number(participant?.userId || 0);
        const slot = document.getElementById(gridVideoSlotId(userId));
        if (userId === activeFullscreenUserId) {
          clearUnassignedChildren(slot, assignedNodes);
          continue;
        }
        const node = mediaNodeForUserId(userId);
        if (!mountVideoNode(slot, node, assignedNodes, { role: REMOTE_RENDER_SURFACE_ROLES.GRID, userId, visibleParticipantCount })) {
          clearUnassignedChildren(slot, assignedNodes);
        }
      }
      if (localContainer) clearUnassignedChildren(localContainer, assignedNodes);
      if (remoteContainer) clearUnassignedChildren(remoteContainer, assignedNodes);
    } else {
      const primaryUserId = primaryVideoUserId();
      const primaryNode = mediaNodeForUserId(primaryUserId);
      const miniParticipants = miniVideoParticipants();
      const visibleParticipantCount = 1 + miniParticipants.length;

      if (primaryUserId !== activeFullscreenUserId && primaryUserId === refs.currentUserId.value) {
        mountVideoNode(localContainer, primaryNode, assignedNodes, {
          role: currentLayoutMode() === 'main_only' ? REMOTE_RENDER_SURFACE_ROLES.FULLSCREEN : REMOTE_RENDER_SURFACE_ROLES.MAIN,
          userId: primaryUserId,
          visibleParticipantCount,
        });
      } else if (primaryUserId !== activeFullscreenUserId) {
        mountVideoNode(remoteContainer, primaryNode, assignedNodes, {
          role: currentLayoutMode() === 'main_only' ? REMOTE_RENDER_SURFACE_ROLES.FULLSCREEN : REMOTE_RENDER_SURFACE_ROLES.MAIN,
          userId: primaryUserId,
          visibleParticipantCount,
        });
      }

      for (const participant of miniParticipants) {
        const userId = Number(participant?.userId || 0);
        const slot = document.getElementById(miniVideoSlotId(userId));
        if (userId === activeFullscreenUserId) {
          clearUnassignedChildren(slot, assignedNodes);
          continue;
        }
        const node = mediaNodeForUserId(userId);
        if (!mountVideoNode(slot, node, assignedNodes, { role: REMOTE_RENDER_SURFACE_ROLES.MINI, userId, visibleParticipantCount })) {
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
