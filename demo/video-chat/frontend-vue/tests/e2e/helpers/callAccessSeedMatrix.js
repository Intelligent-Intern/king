import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const backendOrigin = process.env.VITE_VIDEOCHAT_BACKEND_ORIGIN || 'http://127.0.0.1:18080';
export const sessionStorageKey = 'ii_videocall_v1_session';

const helperDir = path.dirname(fileURLToPath(import.meta.url));
export const callAccessSeedMatrixPath = path.resolve(
  helperDir,
  '../../../../contracts/v1/iam-call-access-seeding.matrix.json',
);

function readSeedMatrix() {
  const inlineMatrix = String(process.env.VIDEOCHAT_CALL_ACCESS_SEED_MATRIX_JSON || '').trim();
  if (inlineMatrix !== '') {
    return JSON.parse(inlineMatrix);
  }
  return JSON.parse(fs.readFileSync(callAccessSeedMatrixPath, 'utf8'));
}

function clone(value) {
  return JSON.parse(JSON.stringify(value));
}

function byKey(rows, label) {
  const index = new Map();
  for (const row of Array.isArray(rows) ? rows : []) {
    const key = String(row?.key || '').trim();
    if (key === '') throw new Error(`${label} matrix row is missing key.`);
    if (index.has(key)) throw new Error(`${label} matrix row key is duplicated: ${key}`);
    index.set(key, row);
  }
  return index;
}

export const iamCallAccessSeedMatrix = Object.freeze(readSeedMatrix());

const tenantIndex = byKey(iamCallAccessSeedMatrix.tenants, 'tenant');
const organizationIndex = byKey(iamCallAccessSeedMatrix.organizations || [], 'organization');
const userIndex = byKey(iamCallAccessSeedMatrix.users, 'user');
const callIndex = byKey(iamCallAccessSeedMatrix.calls, 'call');
const accessLinkIndex = byKey(iamCallAccessSeedMatrix.access_links, 'access link');
const scenarioIndex = byKey(iamCallAccessSeedMatrix.scenarios, 'scenario');

function requiredRow(index, key, label) {
  const normalizedKey = String(key || '').trim();
  const row = index.get(normalizedKey);
  if (!row) throw new Error(`Unknown ${label} matrix key: ${normalizedKey}`);
  return row;
}

export function getSeedTenant(key) {
  return clone(requiredRow(tenantIndex, key, 'tenant'));
}

export function getSeedOrganization(key) {
  return clone(requiredRow(organizationIndex, key, 'organization'));
}

export function getSeedUser(key) {
  return clone(requiredRow(userIndex, key, 'user'));
}

export function getSeedCall(key) {
  return clone(requiredRow(callIndex, key, 'call'));
}

export function getSeedAccessLink(key) {
  return clone(requiredRow(accessLinkIndex, key, 'access link'));
}

export function getSeedScenario(key) {
  return clone(requiredRow(scenarioIndex, key, 'scenario'));
}

export function accessIdFromJoinPath(joinPath) {
  const match = String(joinPath || '').match(/\/join\/([a-f0-9-]{36})(?:[/?#].*)?$/i);
  return match ? match[1].toLowerCase() : '';
}

export function seedUserKeys() {
  return [...userIndex.keys()];
}

export function seedScenarioKeys() {
  return [...scenarioIndex.keys()];
}

function tenantForCall(call) {
  const tenantKey = typeof call?.tenant_key === 'string' ? call.tenant_key : '';
  return tenantKey === '' ? null : requiredRow(tenantIndex, tenantKey, 'tenant');
}

function organizationForCall(call) {
  const organizationKey = typeof call?.organization_key === 'string' ? call.organization_key : '';
  return organizationKey === '' ? null : requiredRow(organizationIndex, organizationKey, 'organization');
}

function membershipForTenant(user, tenantKey) {
  return (Array.isArray(user?.memberships) ? user.memberships : [])
    .find((membership) => String(membership?.tenant_key || '') === tenantKey) || null;
}

function membershipForOrganization(user, organizationKey) {
  return (Array.isArray(user?.organization_memberships) ? user.organization_memberships : [])
    .find((membership) => String(membership?.organization_key || '') === organizationKey) || null;
}

function permissionsFor(user, membershipRole) {
  const normalizedRole = String(membershipRole || 'member').trim().toLowerCase();
  const isTenantAdmin = normalizedRole === 'owner' || normalizedRole === 'admin';
  const isPlatformAdmin = user?.system_admin === true || String(user?.role || '').trim().toLowerCase() === 'admin';
  const elevated = isTenantAdmin || isPlatformAdmin;
  return {
    platform_admin: isPlatformAdmin,
    tenant_admin: elevated,
    manage_users: elevated,
    manage_organizations: elevated,
    manage_groups: elevated,
    manage_permission_grants: elevated,
    edit_themes: elevated,
    export_import: elevated,
    manage_lobby: elevated,
    admit_participants: elevated,
    reject_participants: elevated,
    kick_participants: elevated,
  };
}

export function tenantSnapshotForSeedUser(userKey, callKey) {
  const user = requiredRow(userIndex, userKey, 'user');
  const call = requiredRow(callIndex, callKey, 'call');
  return clone(tenantSnapshotFor(user, call));
}

function tenantSnapshotFor(user, call) {
  const tenant = tenantForCall(call);
  if (!tenant) return null;
  const tenantKey = String(call.tenant_key || '');
  const membership = membershipForTenant(user, tenantKey);
  const role = String(membership?.role || 'member').trim().toLowerCase() || 'member';
  return {
    id: tenant.id,
    tenant_id: tenant.id,
    uuid: tenant.uuid,
    public_id: tenant.uuid,
    slug: tenant.slug,
    label: tenant.label,
    role,
    membership_id: membership ? Number(user.id) * 100 + Number(tenant.id) : 0,
    permissions: permissionsFor(user, role),
  };
}

function userPayload(user, tenant = null, overrides = {}) {
  return {
    id: user.id,
    email: user.email,
    display_name: overrides.displayName || user.display_name,
    role: user.role,
    status: 'active',
    time_format: '24h',
    date_format: 'dmy_dot',
    theme: 'dark',
    locale: 'en',
    direction: 'ltr',
    supported_locales: ['en'],
    avatar_path: null,
    post_logout_landing_url: '',
    account_type: user.account_type,
    is_guest: Boolean(user.is_guest),
    tenant,
  };
}

function ownerPayload(call) {
  const owner = requiredRow(userIndex, call.owner_user_key, 'user');
  return {
    user_id: owner.id,
    display_name: owner.display_name,
    email: owner.email,
  };
}

function participantPayload(user, callRole = 'participant', inviteState = 'allowed') {
  return {
    user_id: user.id,
    display_name: user.display_name,
    email: user.email,
    call_role: callRole,
    invite_state: inviteState,
    joined_at: null,
    connected_at: null,
  };
}

function callPayload(call, viewerUser = null, inviteState = 'pending', options = {}) {
  const payloadOptions = options && typeof options === 'object' ? options : {};
  const includeViewerIfMissing = payloadOptions.includeViewerIfMissing !== false;
  const owner = requiredRow(userIndex, call.owner_user_key, 'user');
  const guestUsers = (Array.isArray(call.guest_list_user_keys) ? call.guest_list_user_keys : [])
    .map((key) => requiredRow(userIndex, key, 'user'));
  const internal = [
    participantPayload(owner, 'owner', 'allowed'),
    ...guestUsers.map((user) => participantPayload(user, 'participant', 'allowed')),
  ];
  const viewerHasParticipantRow = viewerUser
    ? internal.some((participant) => Number(participant.user_id) === Number(viewerUser.id))
    : false;
  if (includeViewerIfMissing && viewerUser && !viewerHasParticipantRow) {
    internal.push(participantPayload(viewerUser, 'participant', inviteState));
  }
  const viewerIsOwner = viewerUser && Number(viewerUser.id) === Number(owner.id);
  const viewerHasParticipation = Boolean(viewerUser && (viewerHasParticipantRow || includeViewerIfMissing || viewerIsOwner));

  return {
    id: call.id,
    room_id: call.room_id,
    title: call.title,
    status: call.status,
    starts_at: call.starts_at,
    ends_at: call.ends_at,
    owner: ownerPayload(call),
    participants: {
      total: internal.length,
      internal,
      external: [],
    },
    my_participation: viewerHasParticipation ? {
      call_role: Number(viewerUser.id) === Number(owner.id) ? 'owner' : 'participant',
      invite_state: inviteState,
    } : false,
  };
}

function seedUserIsSystemAdmin(user) {
  return user?.system_admin === true && String(user?.role || '').trim().toLowerCase() === 'admin';
}

function seedUserIsOrganizationAdminForCall(user, call) {
  const organization = organizationForCall(call);
  if (!organization) return false;
  const membership = membershipForOrganization(user, String(organization.key || ''));
  return String(membership?.role || '').trim().toLowerCase() === 'admin';
}

function directJoinDecisionFor(user, call) {
  const status = String(call?.status || '').trim().toLowerCase();
  if (!['scheduled', 'active'].includes(status)) {
    return {
      allowed: false,
      reason: 'call_not_joinable_from_status',
      source: 'none',
      scope: 'none',
      can_manage_lobby: false,
      can_admit: false,
      can_reject: false,
      can_kick: false,
    };
  }

  if (seedUserIsSystemAdmin(user)) {
    return {
      allowed: true,
      reason: 'system_admin',
      source: 'system_admin',
      scope: 'system',
      can_manage_lobby: true,
      can_admit: true,
      can_reject: true,
      can_kick: true,
    };
  }

  if (String(call?.owner_user_key || '') === String(user?.key || '')) {
    return {
      allowed: true,
      reason: 'owner',
      source: 'owner',
      scope: 'call',
      can_manage_lobby: true,
      can_admit: true,
      can_reject: true,
      can_kick: true,
    };
  }

  if (seedUserIsOrganizationAdminForCall(user, call)) {
    return {
      allowed: true,
      reason: 'organization_admin',
      source: 'organization_admin',
      scope: 'organization',
      can_manage_lobby: true,
      can_admit: true,
      can_reject: true,
      can_kick: true,
    };
  }

  if (String(call?.access_mode || 'invite_only').trim().toLowerCase() === 'free_for_all') {
    return {
      allowed: true,
      reason: 'free_for_all',
      source: 'free_for_all',
      scope: 'call',
      can_manage_lobby: false,
      can_admit: false,
      can_reject: false,
      can_kick: false,
    };
  }

  const guestListKeys = Array.isArray(call?.guest_list_user_keys) ? call.guest_list_user_keys : [];
  if (guestListKeys.includes(user?.key)) {
    return {
      allowed: true,
      reason: 'guest_list',
      source: 'guest_list',
      scope: 'call',
      can_manage_lobby: false,
      can_admit: false,
      can_reject: false,
      can_kick: false,
    };
  }

  return {
    allowed: false,
    reason: 'not_on_guest_list',
    source: 'none',
    scope: 'none',
    can_manage_lobby: false,
    can_admit: false,
    can_reject: false,
    can_kick: false,
  };
}

export function directJoinDecisionForSeedUser(userKey, callKey) {
  const user = requiredRow(userIndex, userKey, 'user');
  const call = requiredRow(callIndex, callKey, 'call');
  return clone(directJoinDecisionFor(user, call));
}

function accessLinkPayload(link, call, targetUser = null) {
  const tenant = tenantForCall(call);
  return {
    id: link.id,
    call_id: call.id,
    room_id: call.room_id,
    tenant_id: tenant?.id || null,
    link_kind: link.link_kind,
    participant_user_id: targetUser?.id || null,
    participant_email: targetUser?.email || null,
    created_by_user_id: ownerPayload(call).user_id,
    created_at: '2026-05-08T10:00:00.000Z',
    expires_at: '2030-01-01T00:00:00.000Z',
    consumed_at: null,
    last_used_at: null,
  };
}

function seedSessionIdForUser(user) {
  return `sess_iam_seed_${String(user.key || user.id).replace(/[^a-z0-9_]+/gi, '_')}`;
}

function callAccessSessionId(link, user) {
  return `sess_iam_call_access_${String(link.key).replace(/[^a-z0-9_]+/gi, '_')}_${String(user.key).replace(/[^a-z0-9_]+/gi, '_')}`;
}

export function storedSessionForSeedUser(userKey, callKey = 'alpha_active', overrides = {}) {
  const user = requiredRow(userIndex, userKey, 'user');
  const call = requiredRow(callIndex, callKey, 'call');
  const baseSession = {
    role: user.role,
    displayName: user.display_name,
    email: user.email,
    userId: user.id,
    avatarPath: null,
    timeFormat: '24h',
    theme: 'dark',
    status: 'active',
    sessionId: seedSessionIdForUser(user),
    sessionToken: seedSessionIdForUser(user),
    expiresAt: '2030-01-01T00:00:00.000Z',
    tenant: tenantSnapshotFor(user, call),
  };
  const normalizedOverrides = overrides && typeof overrides === 'object' ? overrides : {};
  return {
    ...baseSession,
    ...clone(normalizedOverrides),
    tenant: normalizedOverrides.tenant && typeof normalizedOverrides.tenant === 'object'
      ? {
        ...(baseSession.tenant || {}),
        ...clone(normalizedOverrides.tenant),
        permissions: {
          ...((baseSession.tenant || {}).permissions || {}),
          ...(normalizedOverrides.tenant.permissions || {}),
        },
      }
      : baseSession.tenant,
  };
}

export async function installStoredSeedSession(context, userKey, callKey = 'alpha_active', overrides = {}) {
  await context.addInitScript(
    ({ key, value }) => {
      localStorage.setItem(key, JSON.stringify(value));
    },
    { key: sessionStorageKey, value: storedSessionForSeedUser(userKey, callKey, overrides) },
  );
}

function jsonHeaders() {
  return {
    'access-control-allow-origin': '*',
    'access-control-allow-credentials': 'true',
    'access-control-allow-headers': 'content-type, authorization, x-session-id',
    'access-control-allow-methods': 'GET, POST, PATCH, DELETE, OPTIONS',
    'access-control-expose-headers': 'content-disposition, content-type',
    'access-control-max-age': '86400',
    'content-type': 'application/json; charset=utf-8',
  };
}

async function fulfillJson(route, status, payload) {
  await route.fulfill({
    status,
    headers: jsonHeaders(),
    json: payload,
  });
}

function parseJsonBody(request) {
  const raw = String(request.postData() || '').trim();
  if (raw === '') return {};
  try {
    const parsed = JSON.parse(raw);
    return parsed && typeof parsed === 'object' ? parsed : {};
  } catch {
    return {};
  }
}

function bearerToken(request) {
  const authorization = String(request.headers().authorization || '').trim();
  const match = authorization.match(/^Bearer\s+(.+)$/i);
  if (match) return match[1].trim();
  return String(request.headers()['x-session-id'] || '').trim();
}

function seededSessionRecordFromToken(token) {
  for (const user of userIndex.values()) {
    const sessionId = seedSessionIdForUser(user);
    if (sessionId === token) {
      const firstCall = [...callIndex.values()].find((call) => call.tenant_key) || [...callIndex.values()][0];
      return {
        session: {
          id: sessionId,
          token: sessionId,
          token_type: 'session_id',
          issued_at: '2026-05-08T10:00:00.000Z',
          expires_at: '2030-01-01T00:00:00.000Z',
        },
        user,
        call: firstCall,
        tenant: tenantSnapshotFor(user, firstCall),
      };
    }
  }
  return null;
}

function sessionStatePayload(record) {
  const tenant = record.tenant || tenantSnapshotFor(record.user, record.call);
  return {
    status: 'ok',
    result: { state: 'authenticated' },
    session: record.session,
    user: userPayload(record.user, tenant),
    tenant,
    time: '2026-05-08T10:00:00.000Z',
  };
}

function targetUserForAccessLink(link, requestBody = {}) {
  if (link.link_kind === 'open') {
    const anonymousKey = String(link.anonymous_user_key || 'temporary_anonymous_guest');
    const anonymousUser = requiredRow(userIndex, anonymousKey, 'user');
    const guestName = String(requestBody.guest_name || '').trim();
    return {
      ...anonymousUser,
      display_name: guestName || anonymousUser.display_name,
    };
  }
  return requiredRow(userIndex, link.target_user_key, 'user');
}

function resolveAccessLinkById(accessId) {
  const normalizedAccessId = String(accessId || '').trim().toLowerCase();
  return [...accessLinkIndex.values()].find((link) => String(link.id).toLowerCase() === normalizedAccessId) || null;
}

export async function installCallAccessSeedRoutes(context, options = {}) {
  const issuedSessions = new Map();
  const routeOptions = options && typeof options === 'object' ? options : {};
  const directJoinDecisions = Array.isArray(routeOptions.directJoinDecisions)
    ? routeOptions.directJoinDecisions
    : null;

  function sessionRecordForRequest(request) {
    const token = bearerToken(request);
    return issuedSessions.get(token) || seededSessionRecordFromToken(token);
  }

  function callByRef(callRef) {
    const normalizedCallRef = String(callRef || '').trim();
    return [...callIndex.values()].find((row) => row.id === normalizedCallRef || row.room_id === normalizedCallRef) || null;
  }

  function logDirectJoinDecision({ request, user, call, decision }) {
    if (!directJoinDecisions) return;
    directJoinDecisions.push({
      method: request.method(),
      pathname: new URL(request.url()).pathname,
      authorization: request.headers().authorization || '',
      user_key: String(user?.key || ''),
      user_id: Number(user?.id || 0),
      call_key: String(call?.key || ''),
      call_id: String(call?.id || ''),
      allowed: Boolean(decision?.allowed),
      reason: String(decision?.reason || ''),
      source: String(decision?.source || ''),
      scope: String(decision?.scope || ''),
      can_manage_lobby: Boolean(decision?.can_manage_lobby),
    });
  }

  await context.route('**/api/**', async (route) => {
    const request = route.request();
    if (request.method() === 'OPTIONS') {
      await route.fulfill({ status: 204, headers: jsonHeaders() });
      return;
    }

    const url = new URL(request.url());
    const joinMatch = url.pathname.match(/^\/api\/call-access\/([a-f0-9-]{36})\/join$/i);
    if (joinMatch && request.method() === 'GET') {
      const link = resolveAccessLinkById(joinMatch[1]);
      if (!link) {
        await fulfillJson(route, 404, {
          status: 'error',
          error: { code: 'call_access_not_found', message: 'Call access link does not exist.' },
        });
        return;
      }
      const call = requiredRow(callIndex, link.call_key, 'call');
      const targetUser = link.link_kind === 'personal' ? requiredRow(userIndex, link.target_user_key, 'user') : null;
      await fulfillJson(route, 200, {
        status: 'ok',
        result: {
          state: 'resolved',
          access_link: accessLinkPayload(link, call, targetUser),
          link_kind: link.link_kind,
          call: callPayload(call, targetUser, link.requires_admission ? 'pending' : 'allowed'),
          target_user: targetUser ? userPayload(targetUser, tenantSnapshotFor(targetUser, call)) : null,
          target_hint: { participant_email: targetUser?.email || null },
          join_path: link.join_path,
        },
        time: '2026-05-08T10:00:00.000Z',
      });
      return;
    }

    const sessionMatch = url.pathname.match(/^\/api\/call-access\/([a-f0-9-]{36})\/session$/i);
    if (sessionMatch && request.method() === 'POST') {
      const link = resolveAccessLinkById(sessionMatch[1]);
      if (!link) {
        await fulfillJson(route, 404, {
          status: 'error',
          error: { code: 'call_access_not_found', message: 'Call access link does not exist.' },
        });
        return;
      }
      const body = parseJsonBody(request);
      if (link.link_kind === 'open' && String(body.guest_name || '').trim() === '') {
        await fulfillJson(route, 422, {
          status: 'error',
          error: { code: 'call_access_validation_failed', message: 'Guest name is required.' },
        });
        return;
      }
      const call = requiredRow(callIndex, link.call_key, 'call');
      const targetUser = targetUserForAccessLink(link, body);
      const tenant = tenantSnapshotFor(targetUser, call);
      const sessionId = callAccessSessionId(link, targetUser);
      const session = {
        id: sessionId,
        token: sessionId,
        token_type: 'session_id',
        issued_at: '2026-05-08T10:00:00.000Z',
        expires_at: '2030-01-01T00:00:00.000Z',
        expires_in_seconds: 43200,
      };
      issuedSessions.set(session.token, { session, user: targetUser, call, tenant, link });
      await fulfillJson(route, 200, {
        status: 'ok',
        result: {
          state: 'session_started',
          session,
          user: userPayload(targetUser, tenant),
          tenant,
          access_link: accessLinkPayload(link, call, targetUser),
          link_kind: link.link_kind,
          call: callPayload(call, targetUser, link.requires_admission ? 'pending' : 'allowed'),
          join_path: link.join_path,
        },
        time: '2026-05-08T10:00:00.000Z',
      });
      return;
    }

    if (url.pathname === '/api/auth/session-state' || url.pathname === '/api/auth/session') {
      const record = sessionRecordForRequest(request);
      if (!record) {
        await fulfillJson(route, 401, {
          status: 'error',
          error: { code: 'auth_failed', message: 'A valid session token is required.' },
        });
        return;
      }
      await fulfillJson(route, 200, sessionStatePayload(record));
      return;
    }

    const resolveMatch = url.pathname.match(/^\/api\/calls\/resolve\/([^/]+)$/);
    if (resolveMatch && request.method() === 'GET') {
      const record = sessionRecordForRequest(request);
      if (!record) {
        await fulfillJson(route, 401, {
          status: 'error',
          error: { code: 'auth_failed', message: 'A valid session token is required.' },
        });
        return;
      }

      const callRef = decodeURIComponent(resolveMatch[1] || '');
      const call = callByRef(callRef);
      if (!call) {
        await fulfillJson(route, 200, {
          status: 'ok',
          result: {
            state: 'not_found',
            resolved_as: '',
            reason: 'route_call_ref_not_found',
            access_link: null,
            call: null,
          },
          time: '2026-05-08T10:00:00.000Z',
        });
        return;
      }

      const decision = directJoinDecisionFor(record.user, call);
      logDirectJoinDecision({ request, user: record.user, call, decision });
      if (!decision.allowed) {
        await fulfillJson(route, 200, {
          status: 'ok',
          result: {
            state: 'forbidden',
            resolved_as: 'call_id',
            reason: 'calls_forbidden',
            access_link: null,
            call: null,
          },
          time: '2026-05-08T10:00:00.000Z',
        });
        return;
      }

      await fulfillJson(route, 200, {
        status: 'ok',
        result: {
          state: 'resolved',
          resolved_as: 'call_id',
          access_link: null,
          access_decision: decision,
          call: callPayload(call, record.user, 'allowed', { includeViewerIfMissing: false }),
        },
        time: '2026-05-08T10:00:00.000Z',
      });
      return;
    }

    const callMatch = url.pathname.match(/^\/api\/calls\/([^/]+)$/);
    if (callMatch && request.method() === 'GET') {
      const record = sessionRecordForRequest(request);
      if (!record) {
        await fulfillJson(route, 401, {
          status: 'error',
          error: { code: 'auth_failed', message: 'A valid session token is required.' },
        });
        return;
      }

      const callId = decodeURIComponent(callMatch[1] || '');
      const call = callByRef(callId);
      if (!call) {
        await fulfillJson(route, 404, {
          status: 'error',
          error: { code: 'calls_not_found', message: 'Call does not exist.' },
        });
        return;
      }

      const decision = directJoinDecisionFor(record.user, call);
      logDirectJoinDecision({ request, user: record.user, call, decision });
      if (!decision.allowed) {
        await fulfillJson(route, 403, {
          status: 'error',
          error: { code: 'calls_forbidden', message: 'You are not allowed to view this call.' },
        });
        return;
      }

      await fulfillJson(route, 200, {
        status: 'ok',
        call: callPayload(call, record.user, 'allowed', { includeViewerIfMissing: false }),
        time: '2026-05-08T10:00:00.000Z',
      });
      return;
    }

    await fulfillJson(route, 404, {
      status: 'error',
      error: { code: 'not_found', message: `Missing IAM call-access seed route: ${url.pathname}` },
    });
  });
}

export async function installCallAccessFakeRealtime(context, { linkKey = '', callKey = '', userKey = '', requiresAdmission = null }) {
  const link = String(linkKey || '').trim() !== '' ? requiredRow(accessLinkIndex, linkKey, 'access link') : null;
  const call = link ? requiredRow(callIndex, link.call_key, 'call') : requiredRow(callIndex, callKey, 'call');
  const user = String(userKey || '').trim() !== '' ? requiredRow(userIndex, userKey, 'user') : null;
  const decision = user ? directJoinDecisionFor(user, call) : null;
  const callSnapshot = callPayload(call, user, 'allowed', { includeViewerIfMissing: false });
  const resolvedRequiresAdmission = requiresAdmission === null
    ? Boolean(link && link.requires_admission !== false)
    : Boolean(requiresAdmission);

  await context.addInitScript(({ roomId, callId, admissionRequired, participants, viewer }) => {
    const listenersSymbol = Symbol('listeners');

    window.__iamCallAccessSocketFrames = [];
    window.__iamCallAccessSocketEvents = [];
    window.__iamCallAccessSockets = [];

    function snapshotPayload(reason = 'requested') {
      return {
        type: 'room/snapshot',
        room_id: roomId,
        call_id: callId,
        participant_count: participants.length,
        participants,
        viewer,
        layout: null,
        activity: [],
        reason,
        time: new Date().toISOString(),
      };
    }

    class FakeWebSocket {
      static CONNECTING = 0;
      static OPEN = 1;
      static CLOSING = 2;
      static CLOSED = 3;

      constructor(url) {
        this.url = String(url || '');
        this.readyState = FakeWebSocket.CONNECTING;
        this[listenersSymbol] = {};
        window.__iamCallAccessSockets.push(this);
        setTimeout(() => {
          if (this.readyState === FakeWebSocket.CLOSED) return;
          this.readyState = FakeWebSocket.OPEN;
          this.dispatch('open', {});
          this.emit({
            type: 'system/welcome',
            active_room_id: roomId,
            call_context: viewer,
            admission: {
              requires_admission: Boolean(admissionRequired),
              pending_room_id: roomId,
              call_id: callId,
            },
          });
        }, 0);
      }

      addEventListener(type, callback) {
        if (!this[listenersSymbol][type]) this[listenersSymbol][type] = [];
        this[listenersSymbol][type].push(callback);
        if (type === 'open' && this.readyState === FakeWebSocket.OPEN) {
          setTimeout(() => callback({}), 0);
        }
      }

      removeEventListener(type, callback) {
        this[listenersSymbol][type] = (this[listenersSymbol][type] || [])
          .filter((registered) => registered !== callback);
      }

      dispatch(type, event) {
        for (const callback of this[listenersSymbol][type] || []) callback(event);
      }

      emit(payload) {
        window.__iamCallAccessSocketEvents.push(payload);
        this.dispatch('message', { data: JSON.stringify(payload) });
      }

      send(data) {
        let payload = null;
        try {
          payload = JSON.parse(String(data || '{}'));
        } catch {
          payload = { type: 'invalid_json' };
        }
        window.__iamCallAccessSocketFrames.push(payload);
        if (payload.type === 'room/snapshot/request' || payload.type === 'room/join') {
          setTimeout(() => this.emit(snapshotPayload(payload.type)), 0);
          return;
        }
        if (payload.type === 'lobby/queue/join') {
          setTimeout(() => {
            this.emit({
              type: 'lobby/snapshot',
              room_id: roomId,
              call_id: callId,
              pending: [],
              admitted: [],
              rejected: [],
            });
          }, 0);
        }
      }

      close(code = 1000, reason = 'test_close') {
        if (this.readyState === FakeWebSocket.CLOSED) return;
        this.readyState = FakeWebSocket.CLOSED;
        this.dispatch('close', { code, reason });
      }
    }

    window.WebSocket = FakeWebSocket;
  }, {
    roomId: call.room_id,
    callId: call.id,
    admissionRequired: resolvedRequiresAdmission,
    participants: callSnapshot.participants.internal.map((participant) => ({
      user_id: participant.user_id,
      display_name: participant.display_name,
      email: participant.email,
      role: 'user',
      call_role: participant.call_role,
      effective_call_role: participant.call_role,
      invite_state: participant.invite_state,
      joined_at: participant.joined_at,
      connected_at: participant.connected_at,
    })),
    viewer: {
      user_id: user?.id || 0,
      role: user?.role || 'user',
      call_id: call.id,
      call_role: decision?.source === 'owner' ? 'owner' : 'participant',
      effective_call_role: decision?.source === 'system_admin'
        ? 'owner'
        : (decision?.source === 'organization_admin' ? 'moderator' : (decision?.source === 'owner' ? 'owner' : 'participant')),
      can_moderate: Boolean(decision?.can_manage_lobby),
      can_manage_owner: decision?.source === 'system_admin' || decision?.source === 'owner',
    },
  });
}

export async function installCallAccessMediaDeviceShim(context) {
  await context.addInitScript(() => {
    Object.defineProperty(navigator, 'mediaDevices', {
      configurable: true,
      value: {
        ...(navigator.mediaDevices || {}),
        getUserMedia: async () => new MediaStream(),
        enumerateDevices: async () => [
          { kind: 'audioinput', deviceId: 'iam-audio', label: 'IAM matrix microphone', groupId: 'iam-call-access' },
          { kind: 'videoinput', deviceId: 'iam-video', label: 'IAM matrix camera', groupId: 'iam-call-access' },
          { kind: 'audiooutput', deviceId: 'iam-speaker', label: 'IAM matrix speaker', groupId: 'iam-call-access' },
        ],
        getSupportedConstraints: () => ({ audio: true, video: true, deviceId: true }),
        addEventListener: () => {},
        removeEventListener: () => {},
      },
    });
  });
}

export async function createCallAccessMatrixPage(browser, baseURL, { scenarioKey }) {
  const scenario = requiredRow(scenarioIndex, scenarioKey, 'scenario');
  const linkKey = String(scenario.link_key || '').trim();
  if (linkKey === '') throw new Error(`Scenario ${scenarioKey} is not bound to a call-access link.`);

  const context = await browser.newContext({ baseURL, permissions: ['camera', 'microphone'] });
  await installCallAccessSeedRoutes(context);
  await installCallAccessMediaDeviceShim(context);
  await installCallAccessFakeRealtime(context, { linkKey });
  const page = await context.newPage();
  return { context, page, scenario: clone(scenario) };
}

export async function createDirectJoinMatrixPage(browser, baseURL, { scenarioKey }) {
  const scenario = requiredRow(scenarioIndex, scenarioKey, 'scenario');
  const callKey = String(scenario.call_key || '').trim();
  const userKey = String(scenario.principal_user_key || '').trim();
  if (callKey === '') throw new Error(`Scenario ${scenarioKey} is not bound to a direct-join call.`);
  if (userKey === '') throw new Error(`Scenario ${scenarioKey} is not bound to a principal user.`);

  const directJoinDecisions = [];
  const context = await browser.newContext({ baseURL, permissions: ['camera', 'microphone'] });
  await installStoredSeedSession(context, userKey, callKey, scenario.client_session_overrides || {});
  await installCallAccessSeedRoutes(context, { directJoinDecisions });
  await installCallAccessMediaDeviceShim(context);
  await installCallAccessFakeRealtime(context, { callKey, userKey, requiresAdmission: false });
  const page = await context.newPage();
  return { context, page, scenario: clone(scenario), directJoinDecisions };
}
