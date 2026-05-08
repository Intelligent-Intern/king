import { localizedApiErrorMessage } from '../../../modules/localization/apiErrorMessages.js';
import { callAccessVerifiedContextFromSession } from './admissionGate';

export function callAccessJoinHeaders(sessionState) {
  const headers = { accept: 'application/json' };
  const token = String(sessionState.sessionToken || '').trim();
  if (token !== '') headers.authorization = `Bearer ${token}`;
  return headers;
}

export function isStrongPersonalizedMismatchPayload(payload) {
  const details = payload?.error?.details && typeof payload.error.details === 'object'
    ? payload.error.details
    : {};
  const fields = details.fields && typeof details.fields === 'object' ? details.fields : {};
  return payload?.error?.code === 'call_access_forbidden'
    && details.mismatch === 'strong_personalized_link'
    && (fields.host_name === 'not_verified' || fields.host_name === 'manual_review_required');
}

export function createJoinStrongMismatchFlow({
  state,
  route,
  sessionState,
  t,
  normalizeAccessId,
  normalizeCallId,
  normalizeRoomId,
  loginWithCallAccess,
  requestCallAccessAccountUpdateConfirmation,
  startAdmissionWait,
}) {
  function reset() {
    state.strongMismatchRequired = false;
    state.hostName = '';
    state.verifyingHost = false;
    state.hostVerified = false;
    state.hostVerificationError = '';
    state.accountUpdateDisplayName = '';
    state.accountUpdateSending = false;
    state.accountUpdatePending = false;
    state.accountUpdateError = '';
    state.accountUpdateRecipient = '';
  }

  function show() {
    state.callId = '';
    state.roomId = 'lobby';
    state.callTitle = t('public.join.default_call_title');
    state.linkKind = 'personal';
    state.guestName = '';
    state.joining = false;
    state.waitingForAdmission = false;
    state.admissionMessage = '';
    state.joinError = '';
    state.verifiedAccessContext = callAccessVerifiedContextFromSession(sessionState);
    reset();
    state.strongMismatchRequired = true;
  }

  async function verifyHost() {
    if (state.verifyingHost || state.hostVerified) return;
    const hostName = String(state.hostName || '').trim();
    if (hostName === '') {
      state.hostVerificationError = t('public.join.host_name_required');
      return;
    }

    state.verifyingHost = true;
    state.hostVerificationError = '';
    const result = await loginWithCallAccess(normalizeAccessId(route.params.accessId), {
      hostName,
      verifiedContext: state.verifiedAccessContext,
    });
    state.verifyingHost = false;

    if (!result.ok) {
      const errorPayload = result.errorCode ? { error: { code: result.errorCode } } : null;
      const fallback = result.errorCode === 'call_access_forbidden'
        ? t('public.join.host_name_unverified')
        : t('public.join.start_session_failed');
      state.hostVerificationError = localizedApiErrorMessage(errorPayload, fallback);
      return;
    }

    const call = result.call && typeof result.call === 'object' ? result.call : {};
    state.callId = normalizeCallId(call.id || state.callId);
    state.roomId = normalizeRoomId(call.room_id || state.roomId || 'lobby');
    state.callTitle = String(call.title || '').trim() || state.callTitle;
    state.hostVerified = true;
    state.joining = false;
  }

  function continueWithoutUpdate() {
    if (!state.hostVerified) return;
    startAdmissionWait(normalizeAccessId(route.params.accessId));
  }

  async function requestUpdate() {
    if (!state.hostVerified || state.accountUpdateSending || state.accountUpdatePending) return;
    const displayName = String(state.accountUpdateDisplayName || '').trim();
    if (displayName === '') {
      state.accountUpdateError = t('public.join.manual_display_name_required');
      return;
    }

    state.accountUpdateSending = true;
    state.accountUpdateError = '';
    const result = await requestCallAccessAccountUpdateConfirmation(normalizeAccessId(route.params.accessId), {
      display_name: displayName,
    });
    state.accountUpdateSending = false;

    if (!result.ok) {
      const errorPayload = result.errorCode ? { error: { code: result.errorCode } } : null;
      state.accountUpdateError = localizedApiErrorMessage(errorPayload, t('public.join.confirmation_request_failed'));
      return;
    }

    state.accountUpdatePending = true;
    state.accountUpdateRecipient = String(result.result?.recipient_email || '').trim();
  }

  return { reset, show, verifyHost, continueWithoutUpdate, requestUpdate };
}
