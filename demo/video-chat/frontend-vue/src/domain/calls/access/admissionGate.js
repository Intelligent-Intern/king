export const CALL_UUID_PATTERN = /^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/;

function normalizeUserId(value) {
  const userId = Number(value);
  return Number.isInteger(userId) && userId > 0 ? userId : 0;
}

function normalizeEmail(value) {
  return String(value || '').trim().toLowerCase();
}

function normalizeRoleValue(value) {
  const role = String(value || '').trim().toLowerCase();
  return role === 'admin' ? 'admin' : 'user';
}

function normalizeCallRoleValue(value) {
  const role = String(value || '').trim().toLowerCase();
  return ['owner', 'moderator', 'participant'].includes(role) ? role : 'participant';
}

function normalizeInviteStateValue(value) {
  const state = String(value || '').trim().toLowerCase();
  return ['invited', 'pending', 'allowed', 'accepted', 'declined', 'cancelled'].includes(state) ? state : 'invited';
}

export function callRequiresJoinModalForViewer(callPayload, viewerPayload = {}) {
  const call = callPayload && typeof callPayload === 'object' ? callPayload : {};
  const viewer = viewerPayload && typeof viewerPayload === 'object' ? viewerPayload : {};
  const viewerRole = normalizeRoleValue(viewer.role);
  const viewerUserId = normalizeUserId(viewer.userId ?? viewer.user_id);
  const viewerEmail = normalizeEmail(viewer.email);
  if (viewerRole === 'admin' || (viewerUserId <= 0 && viewerEmail === '')) {
    return false;
  }

  const ownerUserId = normalizeUserId(call?.owner?.user_id ?? call?.ownerUserId ?? call?.owner_user_id);
  if (ownerUserId > 0 && ownerUserId === viewerUserId) {
    return false;
  }
  const ownerEmail = normalizeEmail(call?.owner?.email ?? call?.ownerEmail ?? call?.owner_email);
  if (ownerEmail !== '' && ownerEmail === viewerEmail) {
    return false;
  }

  const internalParticipants = Array.isArray(call?.participants?.internal) ? call.participants.internal : [];
  const viewerParticipant = internalParticipants.find((participant) => (
    (viewerUserId > 0 && normalizeUserId(participant?.user_id ?? participant?.userId) === viewerUserId)
    || (viewerEmail !== '' && normalizeEmail(participant?.email) === viewerEmail)
  ));
  if (!viewerParticipant) {
    return false;
  }

  const callRole = normalizeCallRoleValue(viewerParticipant?.call_role ?? viewerParticipant?.callRole);
  if (callRole === 'owner' || callRole === 'moderator') {
    return false;
  }

  const inviteState = normalizeInviteStateValue(viewerParticipant?.invite_state ?? viewerParticipant?.inviteState);
  return inviteState === 'invited' || inviteState === 'pending' || inviteState === 'accepted';
}

export function joinPathFromAccessPayload(payload) {
  const result = payload?.result && typeof payload.result === 'object' ? payload.result : {};
  const joinPath = String(result?.join_path || '').trim();
  if (joinPath.startsWith('/join/')) {
    return joinPath;
  }

  const accessId = String(result?.access_link?.id || '').trim().toLowerCase();
  if (CALL_UUID_PATTERN.test(accessId)) {
    return `/join/${encodeURIComponent(accessId)}`;
  }

  return '';
}
