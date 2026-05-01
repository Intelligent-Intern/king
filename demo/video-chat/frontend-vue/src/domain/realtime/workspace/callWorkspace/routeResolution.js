export function createCallWorkspaceRouteResolutionHelpers({
  callbacks,
  refs,
  state,
}) {
  const {
    apiRequest,
    callRequiresJoinModalForViewer,
    joinPathFromAccessPayload,
    normalizeRole,
    normalizeRoomId,
  } = callbacks;

  function isUuidLike(value) {
    const normalized = String(value || '').trim().toLowerCase();
    return refs.callUuidPattern.test(normalized);
  }

  function applyRouteCallResolution({
    accessId = '',
    callId = '',
    roomId = 'lobby',
    error = '',
    pending = false,
    redirecting = false,
  } = {}) {
    refs.routeCallResolve.accessId = String(accessId || '').trim().toLowerCase();
    refs.routeCallResolve.callId = String(callId || '').trim();
    refs.routeCallResolve.roomId = normalizeRoomId(String(roomId || '').trim() || 'lobby');
    refs.routeCallResolve.error = String(error || '').trim();
    refs.routeCallResolve.pending = Boolean(pending);
    refs.routeCallResolve.redirecting = Boolean(redirecting);

    if (refs.routeCallResolve.callId !== '') {
      refs.activeCallId.value = refs.routeCallResolve.callId;
      refs.loadedCallId.value = '';
    }
  }

  function callPayloadToRouteResolution(callPayload) {
    const call = callPayload && typeof callPayload === 'object' ? callPayload : {};
    return {
      callId: String(call.id || '').trim(),
      roomId: String(call.room_id || '').trim() || 'lobby',
    };
  }

  async function resolveRouteRefSafely(callRef) {
    const payload = await apiRequest(`/api/calls/resolve/${encodeURIComponent(callRef)}`);
    const result = payload?.result && typeof payload.result === 'object' ? payload.result : {};
    const stateValue = String(result.state || '').trim().toLowerCase();
    const accessLink = result?.access_link || {};
    const call = result?.call || {};
    const resolution = callPayloadToRouteResolution(call);
    const normalizedAccessId = String(accessLink?.id || '').trim().toLowerCase();
    return {
      state: stateValue,
      reason: String(result.reason || '').trim().toLowerCase(),
      resolvedAs: String(result.resolved_as || '').trim().toLowerCase(),
      accessId: normalizedAccessId,
      callId: resolution.callId,
      roomId: resolution.roomId,
      call,
    };
  }

  function currentWorkspaceEntryMode() {
    return String(refs.route.query.entry || '').trim().toLowerCase();
  }

  async function createSelfJoinPathForCall(callId) {
    const normalizedCallId = String(callId || '').trim().toLowerCase();
    if (!refs.callUuidPattern.test(normalizedCallId)) {
      return '';
    }

    const payload = await apiRequest(`/api/calls/${encodeURIComponent(normalizedCallId)}/access-link`, {
      method: 'POST',
    });
    return joinPathFromAccessPayload(payload);
  }

  async function redirectInvitedRouteToJoinModal(callResolution) {
    if (String(refs.route.name || '') !== 'call-workspace' || currentWorkspaceEntryMode() === 'invite') {
      return false;
    }

    const call = callResolution?.call && typeof callResolution.call === 'object' ? callResolution.call : {};
    const hasCallPayload = Object.keys(call).length > 0;
    if (hasCallPayload && !callRequiresJoinModalForViewer(call, {
      userId: refs.currentUserId.value,
      role: refs.sessionState.role,
      email: refs.sessionState.email,
    })) {
      return false;
    }
    if (!hasCallPayload && normalizeRole(refs.sessionState.role) === 'admin') {
      return false;
    }

    const directAccessId = String(callResolution?.accessId || '').trim().toLowerCase();
    let joinPath = refs.callUuidPattern.test(directAccessId) ? `/join/${encodeURIComponent(directAccessId)}` : '';
    if (joinPath === '') {
      joinPath = await createSelfJoinPathForCall(callResolution?.callId || call?.id || '');
    }
    if (joinPath === '') {
      refs.workspaceError.value = 'Could not open the join modal for this invited call.';
      refs.workspaceNotice.value = '';
      return true;
    }

    await refs.router.replace(joinPath);
    return true;
  }

  async function resolveRouteCallRef(callRef) {
    const normalized = String(callRef || '').trim();
    const seq = state.getRouteCallResolveSeq() + 1;
    state.setRouteCallResolveSeq(seq);
    const looksLikeUuid = isUuidLike(normalized);

    if (normalized === '') {
      if (seq !== state.getRouteCallResolveSeq()) return false;
      applyRouteCallResolution({
        accessId: '',
        callId: '',
        roomId: 'lobby',
        error: '',
        pending: false,
      });
      return true;
    }

    if (seq === state.getRouteCallResolveSeq()) {
      applyRouteCallResolution({
        accessId: '',
        callId: '',
        roomId: normalized,
        error: '',
        pending: true,
      });
    }

    try {
      const callResolution = await resolveRouteRefSafely(normalized);
      if (seq !== state.getRouteCallResolveSeq()) return false;
      if (callResolution.state === 'resolved') {
        if (await redirectInvitedRouteToJoinModal(callResolution)) {
          applyRouteCallResolution({
            ...callResolution,
            error: '',
            pending: false,
            redirecting: true,
          });
          return false;
        }
        applyRouteCallResolution({
          ...callResolution,
          error: '',
          pending: false,
        });
        return true;
      }

      if (looksLikeUuid) {
        const isExpired = callResolution.state === 'expired';
        applyRouteCallResolution({
          accessId: isExpired ? normalized.toLowerCase() : '',
          callId: '',
          roomId: 'lobby',
          error: isExpired ? 'route_call_access_expired' : 'route_call_ref_not_found',
          pending: false,
        });

        const fallbackRouteName = normalizeRole(refs.sessionState.role) === 'admin' ? 'admin-calls' : 'user-dashboard';
        if (String(refs.route.name || '') === 'call-workspace' && String(refs.routeCallRef.value || '').trim() !== '') {
          void refs.router.replace({ name: fallbackRouteName });
        }
        return false;
      }
    } catch {
      // Fall back to treating param as room id.
    }

    if (seq !== state.getRouteCallResolveSeq()) return false;
    applyRouteCallResolution({
      accessId: '',
      callId: '',
      roomId: normalized,
      error: '',
      pending: false,
    });
    return true;
  }

  return {
    applyRouteCallResolution,
    callPayloadToRouteResolution,
    createSelfJoinPathForCall,
    currentWorkspaceEntryMode,
    isUuidLike,
    redirectInvitedRouteToJoinModal,
    resolveRouteCallRef,
    resolveRouteRefSafely,
  };
}
