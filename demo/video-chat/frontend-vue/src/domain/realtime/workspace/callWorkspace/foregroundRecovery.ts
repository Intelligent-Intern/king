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
  captureClientDiagnostic,
  getArmed,
  getConnectionState,
  getDocument,
  getLastAt,
  getManualSocketClose,
  getRouteBusy,
  getSessionToken,
  hasRealtimeRoomSync,
  isSocketOpen,
  minIntervalMs = 1000,
  requestRoomSnapshot,
  setArmed,
  setLastAt,
} = {}) {
  const captureDiagnostic = typeof captureClientDiagnostic === 'function'
    ? captureClientDiagnostic
    : () => {};

  function emitLifecycleDiagnostic(eventType, level, message, context = {}, payload = {}, immediate = false) {
    captureDiagnostic({
      category: 'realtime',
      level,
      eventType,
      code: eventType,
      message,
      payload: {
        reason: String(context?.reason || context?.type || ''),
        hidden: context?.hidden === true,
        visibility_state: String(context?.visibility_state || callGetter(getDocument, null)?.visibilityState || ''),
        connection_state: String(callGetter(getConnectionState, '')),
        socket_open: isSocketOpen?.() === true,
        room_sync_healthy: hasRealtimeRoomSync?.() === true,
        ...payload,
      },
      immediate,
    });
  }

  const canRecover = () => {
    if (!isForegroundVisible(getDocument)) return false;
    if (callGetter(getArmed, false) !== true) return false;
    if (callGetter(getManualSocketClose, false) === true) return false;
    if (isBlockedConnectionState(String(callGetter(getConnectionState, '')))) return false;
    if (callGetter(getRouteBusy, false) === true) return false;
    return String(callGetter(getSessionToken, '') || '').trim() !== '';
  };

  const mark = (context = {}) => {
    if (callGetter(getManualSocketClose, false) === true) return false;
    if (isBlockedConnectionState(String(callGetter(getConnectionState, '')))) return false;
    setArmed?.(true);
    emitLifecycleDiagnostic(
      'call_workspace_lifecycle_background_observed',
      'info',
      'Call workspace observed a browser background lifecycle state without opening a new socket.',
      context,
      { new_socket_allowed: false },
    );
    return true;
  };

  const recover = (context = {}) => {
    if (!canRecover()) {
      emitLifecycleDiagnostic(
        'call_workspace_lifecycle_foreground_ignored',
        'info',
        'Call workspace ignored foreground lifecycle state because opening a socket is not a lifecycle action.',
        context,
        { new_socket_allowed: false },
      );
      return { recovered: false, reason: 'not_ready' };
    }
    const now = Date.now();
    if ((now - Number(callGetter(getLastAt, 0) || 0)) < minIntervalMs) {
      return { recovered: false, reason: 'cooldown' };
    }

    setArmed?.(false);
    setLastAt?.(now);

    const socketOpen = isSocketOpen?.() === true;
    const socketHealthy = socketOpen && String(callGetter(getConnectionState, '')) === 'online';
    const roomSyncHealthy = hasRealtimeRoomSync?.() === true;

    if (socketOpen) {
      requestRoomSnapshot?.();
    }

    const action = socketHealthy && roomSyncHealthy
      ? 'snapshot_only'
      : (socketOpen ? 'snapshot_backfill' : 'connect_suppressed');
    emitLifecycleDiagnostic(
      'call_workspace_lifecycle_foreground_state_sync',
      socketOpen ? 'info' : 'warning',
      'Call workspace foreground lifecycle handling stayed within state and diagnostics.',
      context,
      {
        action,
        new_socket_allowed: false,
      },
      !socketOpen,
    );
    return { recovered: socketOpen, action };
  };

  return { mark, recover };
}
