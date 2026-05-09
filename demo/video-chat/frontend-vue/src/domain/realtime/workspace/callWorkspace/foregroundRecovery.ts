function callGetter(getter, fallback = null) {
  return typeof getter === 'function' ? getter() : fallback;
}

function isBlockedConnectionState(state) {
  return state === 'blocked' || state === 'expired';
}

function isForegroundVisible(getDocument) {
  const documentRef = callGetter(getDocument, null);
  return String(documentRef?.visibilityState || 'visible') !== 'hidden';
}

export function shouldArmWorkspaceForegroundRecovery(context = null, documentRef = null) {
  const reason = String(context?.reason || context?.type || '').trim().toLowerCase();
  const visibilityState = String(context?.visibility_state || documentRef?.visibilityState || '').trim().toLowerCase();
  return context?.hidden === true
    || visibilityState === 'hidden'
    || reason === 'pagehide'
    || reason === 'document_hidden';
}

export function createWorkspaceForegroundRecoveryController({
  connectSocket,
  getArmed,
  getConnectionState,
  getDocument,
  getLastAt,
  getManualSocketClose,
  getRouteBusy,
  getSessionToken,
  hasLiveLocalMedia,
  hasRealtimeRoomSync,
  initSfu,
  isSfuClientOpen,
  isSfuConnected,
  isSocketOpen,
  minIntervalMs = 1000,
  publishLocalTracks,
  recycleSfu,
  requestRoomSnapshot,
  resetReconnectAttempt,
  setArmed,
  setLastAt,
  shouldAcquireLocalMedia,
  shouldConnectSfu,
} = {}) {
  const canRecover = () => {
    if (!isForegroundVisible(getDocument)) return false;
    if (callGetter(getArmed, false) !== true) return false;
    if (callGetter(getManualSocketClose, false) === true) return false;
    if (isBlockedConnectionState(String(callGetter(getConnectionState, '')))) return false;
    if (callGetter(getRouteBusy, false) === true) return false;
    return String(callGetter(getSessionToken, '') || '').trim() !== '';
  };

  const mark = () => {
    if (callGetter(getManualSocketClose, false) === true) return false;
    if (isBlockedConnectionState(String(callGetter(getConnectionState, '')))) return false;
    setArmed?.(true);
    return true;
  };

  const recover = () => {
    if (!canRecover()) return { recovered: false, reason: 'not_ready' };
    const now = Date.now();
    if ((now - Number(callGetter(getLastAt, 0) || 0)) < minIntervalMs) {
      return { recovered: false, reason: 'cooldown' };
    }

    setArmed?.(false);
    setLastAt?.(now);

    if (shouldAcquireLocalMedia?.() === true && hasLiveLocalMedia?.() !== true) {
      void publishLocalTracks?.();
    }

    const socketHealthy = isSocketOpen?.() === true
      && hasRealtimeRoomSync?.() === true
      && String(callGetter(getConnectionState, '')) === 'online';
    const sfuExpected = shouldConnectSfu?.() === true;
    const sfuHealthy = !sfuExpected || (isSfuConnected?.() === true && isSfuClientOpen?.() === true);

    if (socketHealthy && sfuHealthy) {
      requestRoomSnapshot?.();
      return { recovered: true, action: 'snapshot_only' };
    }

    if (!socketHealthy) {
      resetReconnectAttempt?.();
      void connectSocket?.();
    } else {
      requestRoomSnapshot?.();
    }

    if (sfuExpected && !sfuHealthy) {
      recycleSfu?.();
      initSfu?.();
    }

    return { recovered: true, action: socketHealthy ? 'sfu_recover' : 'socket_reconnect' };
  };

  return { mark, recover };
}
