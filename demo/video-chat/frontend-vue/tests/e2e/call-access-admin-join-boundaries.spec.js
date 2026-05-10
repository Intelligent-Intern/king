import { test, expect } from '@playwright/test';

import {
  getSeedCall,
  getSeedTenant,
  getSeedUser,
  sessionStorageKey,
} from './helpers/callAccessSeedMatrix.js';

const alphaTenant = getSeedTenant('alpha');
const betaTenant = getSeedTenant('beta');
const alphaCall = getSeedCall('alpha_active');
const betaCall = getSeedCall('beta_active');
const alphaOwner = getSeedUser(alphaCall.owner_user_key);
const betaOwner = getSeedUser(betaCall.owner_user_key);
const systemAdmin = getSeedUser('system_admin');
const alphaOrgAdmin = getSeedUser('alpha_org_admin');
const alphaMember = getSeedUser('alpha_normal_user');
const moderatorUser = {
  key: 'iam2_boundary_moderator',
  id: 2912,
  email: 'iam2-boundary-moderator@example.test',
  display_name: 'IAM2 Boundary Moderator',
  role: 'user',
  account_type: 'account',
  is_guest: false,
  system_admin: false,
  memberships: [
    { tenant_key: 'alpha', role: 'member' },
  ],
};
const moderatorCall = {
  key: 'iam2_moderator_call',
  id: 'iam2-moderator-call',
  room_id: 'iam2-moderator-room',
  tenant_key: 'alpha',
  title: 'IAM2 Moderator Boundary Call',
  status: 'active',
  starts_at: '2026-05-10T15:00:00.000Z',
  ends_at: '2026-05-10T15:30:00.000Z',
  owner_user_key: 'alpha_call_owner',
  guest_list_user_keys: [],
};

const users = new Map([
  systemAdmin,
  alphaOrgAdmin,
  alphaOwner,
  alphaMember,
  moderatorUser,
].map((user) => [user.key, user]));
const calls = new Map([
  alphaCall,
  betaCall,
  moderatorCall,
].map((call) => [call.key, call]));
const tenants = new Map([
  ['alpha', alphaTenant],
  ['beta', betaTenant],
]);
const participantRolesByCall = new Map([
  [
    moderatorCall.key,
    new Map([
      [alphaOwner.id, 'owner'],
      [moderatorUser.id, 'moderator'],
    ]),
  ],
]);

const boundaryCases = [
  {
    label: 'system admin joins an active organization call without guest-list membership',
    userKey: 'system_admin',
    callKey: 'alpha_active',
    expected: {
      allowed: true,
      source: 'system_admin',
      platformAdmin: true,
      tenantAdmin: true,
      tenantPermissionManageLobby: true,
      canManageLobby: true,
      effectiveCallRole: 'owner',
      resolveStatus: 200,
      callStatus: 200,
    },
  },
  {
    label: 'org admin joins only their own organization call',
    userKey: 'alpha_org_admin',
    callKey: 'alpha_active',
    expected: {
      allowed: true,
      source: 'organization_admin',
      platformAdmin: false,
      tenantAdmin: true,
      tenantPermissionManageLobby: true,
      canManageLobby: true,
      effectiveCallRole: 'moderator',
      resolveStatus: 200,
      callStatus: 200,
    },
  },
  {
    label: 'foreign org admin cannot cross organization boundaries',
    userKey: 'alpha_org_admin',
    callKey: 'beta_active',
    expected: {
      allowed: false,
      source: 'none',
      platformAdmin: false,
      tenantAdmin: false,
      tenantPermissionManageLobby: false,
      canManageLobby: false,
      resolveStatus: 200,
      callStatus: 403,
      reason: 'calls_forbidden',
    },
  },
  {
    label: 'call-scoped moderator joins assigned call without organization admin rights',
    userKey: 'iam2_boundary_moderator',
    callKey: 'iam2_moderator_call',
    expected: {
      allowed: true,
      source: 'moderator',
      platformAdmin: false,
      tenantAdmin: false,
      tenantPermissionManageLobby: false,
      canManageLobby: true,
      effectiveCallRole: 'moderator',
      resolveStatus: 200,
      callStatus: 200,
    },
  },
  {
    label: 'call owner joins their own call without organization admin rights',
    userKey: 'alpha_call_owner',
    callKey: 'alpha_active',
    expected: {
      allowed: true,
      source: 'owner',
      platformAdmin: false,
      tenantAdmin: false,
      tenantPermissionManageLobby: false,
      canManageLobby: true,
      effectiveCallRole: 'owner',
      resolveStatus: 200,
      callStatus: 200,
    },
  },
  {
    label: 'plain organization member cannot bypass invitation boundaries',
    userKey: 'alpha_normal_user',
    callKey: 'alpha_active',
    expected: {
      allowed: false,
      source: 'none',
      platformAdmin: false,
      tenantAdmin: false,
      tenantPermissionManageLobby: false,
      canManageLobby: false,
      resolveStatus: 200,
      callStatus: 403,
      reason: 'calls_forbidden',
    },
  },
];

function jsonHeaders() {
  return {
    'access-control-allow-origin': '*',
    'access-control-allow-credentials': 'true',
    'access-control-allow-headers': 'content-type, authorization, x-session-id',
    'access-control-allow-methods': 'GET, POST, PATCH, DELETE, OPTIONS',
    'content-type': 'application/json; charset=utf-8',
  };
}

async function fulfillJson(route, status, payload) {
  await route.fulfill({
    status,
    headers: jsonHeaders(),
    body: JSON.stringify(payload),
  });
}

function sessionTokenFor(userKey) {
  return `sess_iam2_admin_join_boundary_${userKey}`;
}

function tenantForCall(call) {
  const tenantKey = String(call?.tenant_key || '').trim();
  return tenantKey === '' ? null : tenants.get(tenantKey) || null;
}

function membershipForTenant(user, tenantKey) {
  return (Array.isArray(user?.memberships) ? user.memberships : [])
    .find((membership) => String(membership?.tenant_key || '') === tenantKey) || null;
}

function isPlatformAdmin(user) {
  return user?.system_admin === true || String(user?.role || '').toLowerCase() === 'admin';
}

function tenantPermissionsFor(user, membershipRole) {
  const role = String(membershipRole || 'member').trim().toLowerCase();
  const tenantAdmin = role === 'admin' || role === 'owner';
  const elevated = tenantAdmin || isPlatformAdmin(user);
  return {
    platform_admin: isPlatformAdmin(user),
    tenant_admin: elevated,
    manage_lobby: elevated,
    admit_participants: elevated,
    reject_participants: elevated,
    kick_participants: elevated,
  };
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
    permissions: tenantPermissionsFor(user, role),
  };
}

function ownerForCall(call) {
  if (call.key === betaCall.key) return betaOwner;
  return alphaOwner;
}

function callRoleFor(user, call) {
  if (Number(ownerForCall(call).id) === Number(user.id)) return 'owner';
  return participantRolesByCall.get(call.key)?.get(Number(user.id)) || 'participant';
}

function directJoinDecision(user, call) {
  if (!user || !call || String(call.status || '').toLowerCase() !== 'active') {
    return { allowed: false, source: 'none', reason: 'calls_forbidden', can_manage_lobby: false };
  }
  if (isPlatformAdmin(user)) {
    return { allowed: true, source: 'system_admin', can_manage_lobby: true };
  }
  const tenantKey = String(call.tenant_key || '').trim();
  const membershipRole = String(membershipForTenant(user, tenantKey)?.role || '').trim().toLowerCase();
  if (membershipRole === 'admin' || membershipRole === 'owner') {
    return { allowed: true, source: 'organization_admin', can_manage_lobby: true };
  }
  if (callRoleFor(user, call) === 'owner') {
    return { allowed: true, source: 'owner', can_manage_lobby: true };
  }
  if (callRoleFor(user, call) === 'moderator') {
    return { allowed: true, source: 'moderator', can_manage_lobby: true };
  }
  return { allowed: false, source: 'none', reason: 'calls_forbidden', can_manage_lobby: false };
}

function effectiveCallRoleFor(decision, user, call) {
  if (decision.source === 'system_admin' || decision.source === 'owner') return 'owner';
  if (decision.source === 'organization_admin' || decision.source === 'moderator') return 'moderator';
  return callRoleFor(user, call);
}

function userPayload(user, tenant) {
  return {
    id: user.id,
    email: user.email,
    display_name: user.display_name,
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
    account_type: user.account_type || 'account',
    is_guest: Boolean(user.is_guest),
    tenant,
  };
}

function participantPayload(user, callRole = 'participant') {
  return {
    user_id: user.id,
    display_name: user.display_name,
    email: user.email,
    role: user.role,
    call_role: callRole,
    effective_call_role: callRole,
    invite_state: 'allowed',
    joined_at: null,
    connected_at: null,
  };
}

function callPayload(call, viewerUser, decision) {
  const owner = ownerForCall(call);
  const participants = [participantPayload(owner, 'owner')];
  if (call.key === moderatorCall.key) {
    participants.push(participantPayload(moderatorUser, 'moderator'));
  }
  const existingViewer = participants.find((participant) => Number(participant.user_id) === Number(viewerUser.id));
  if (!existingViewer) {
    participants.push(participantPayload(viewerUser, callRoleFor(viewerUser, call)));
  }
  const effectiveRole = effectiveCallRoleFor(decision, viewerUser, call);
  return {
    id: call.id,
    room_id: call.room_id,
    title: call.title,
    status: call.status,
    starts_at: call.starts_at,
    ends_at: call.ends_at,
    owner: {
      user_id: owner.id,
      display_name: owner.display_name,
      email: owner.email,
    },
    participants: {
      total: participants.length,
      internal: participants,
      external: [],
    },
    my_participation: {
      call_role: callRoleFor(viewerUser, call),
      effective_call_role: effectiveRole,
      invite_state: 'allowed',
      can_manage_lobby: Boolean(decision.can_manage_lobby),
    },
    viewer: {
      user_id: viewerUser.id,
      role: viewerUser.role,
      call_id: call.id,
      call_role: callRoleFor(viewerUser, call),
      effective_call_role: effectiveRole,
      can_moderate: Boolean(decision.can_manage_lobby),
    },
    access_decision: {
      source: decision.source,
      can_manage_lobby: Boolean(decision.can_manage_lobby),
    },
  };
}

function resolveCall(ref) {
  return [...calls.values()].find((call) => call.id === ref || call.room_id === ref || call.key === ref) || null;
}

function authRecordFromRequest(request) {
  const authorization = String(request.headers().authorization || '').trim();
  const token = authorization.replace(/^Bearer\s+/i, '').trim();
  const user = [...users.values()].find((candidate) => sessionTokenFor(candidate.key) === token) || null;
  if (!user) return null;
  return {
    session: {
      id: token,
      token,
      token_type: 'session_id',
      issued_at: '2026-05-10T16:00:00.000Z',
      expires_at: '2030-01-01T00:00:00.000Z',
    },
    user,
  };
}

async function installBoundaryRoutes(context) {
  await context.route('**/api/**', async (route) => {
    const request = route.request();
    if (request.method() === 'OPTIONS') {
      await route.fulfill({ status: 204, headers: jsonHeaders() });
      return;
    }

    const url = new URL(request.url());
    if (url.pathname === '/api/auth/session-state') {
      const record = authRecordFromRequest(request);
      if (!record) {
        await fulfillJson(route, 401, {
          status: 'error',
          error: { code: 'auth_failed', message: 'A valid session token is required.' },
        });
        return;
      }
      await fulfillJson(route, 200, {
        status: 'ok',
        result: { state: 'authenticated' },
        session: record.session,
        user: userPayload(record.user, null),
        tenant: null,
        time: '2026-05-10T16:00:00.000Z',
      });
      return;
    }

    const resolveMatch = url.pathname.match(/^\/api\/calls\/resolve\/([^/]+)$/);
    if (resolveMatch && request.method() === 'GET') {
      const record = authRecordFromRequest(request);
      const call = resolveCall(decodeURIComponent(resolveMatch[1] || ''));
      if (!record || !call) {
        await fulfillJson(route, record ? 404 : 401, {
          status: 'error',
          error: { code: record ? 'calls_not_found' : 'auth_failed', message: 'Direct join boundary probe failed.' },
        });
        return;
      }
      const decision = directJoinDecision(record.user, call);
      if (!decision.allowed) {
        await fulfillJson(route, 200, {
          status: 'ok',
          result: {
            state: 'forbidden',
            resolved_as: 'call_id',
            reason: decision.reason,
            access_link: null,
            call: null,
            access_decision: {
              source: decision.source,
              can_manage_lobby: false,
            },
          },
          time: '2026-05-10T16:00:00.000Z',
        });
        return;
      }
      await fulfillJson(route, 200, {
        status: 'ok',
        result: {
          state: 'resolved',
          resolved_as: 'call_id',
          access_link: null,
          call: callPayload(call, record.user, decision),
          access_decision: {
            source: decision.source,
            can_manage_lobby: Boolean(decision.can_manage_lobby),
          },
        },
        time: '2026-05-10T16:00:00.000Z',
      });
      return;
    }

    const callMatch = url.pathname.match(/^\/api\/calls\/([^/]+)$/);
    if (callMatch && request.method() === 'GET') {
      const record = authRecordFromRequest(request);
      const call = resolveCall(decodeURIComponent(callMatch[1] || ''));
      if (!record || !call) {
        await fulfillJson(route, record ? 404 : 401, {
          status: 'error',
          error: { code: record ? 'calls_not_found' : 'auth_failed', message: 'Direct join boundary probe failed.' },
        });
        return;
      }
      const decision = directJoinDecision(record.user, call);
      if (!decision.allowed) {
        await fulfillJson(route, 403, {
          status: 'error',
          error: { code: decision.reason, message: 'You are not allowed to view this call.' },
        });
        return;
      }
      const tenant = tenantSnapshotFor(record.user, call);
      await fulfillJson(route, 200, {
        status: 'ok',
        call: callPayload(call, record.user, decision),
        user: userPayload(record.user, tenant),
        tenant,
        time: '2026-05-10T16:00:00.000Z',
      });
      return;
    }

    await fulfillJson(route, 404, {
      status: 'error',
      error: { code: 'not_found', message: `Unexpected IAM2 admin boundary route: ${url.pathname}` },
    });
  });
}

async function probeDirectJoinBoundary(page, { userKey, callKey }) {
  const user = users.get(userKey);
  const call = calls.get(callKey);
  if (!user || !call) throw new Error(`Missing boundary fixture for ${userKey}/${callKey}`);
  return page.evaluate(async ({ storageKey, sessionToken, callId, roomId, user }) => {
    localStorage.setItem(storageKey, JSON.stringify({
      role: user.role,
      displayName: user.display_name,
      email: user.email,
      userId: user.id,
      sessionId: sessionToken,
      sessionToken,
      expiresAt: '2030-01-01T00:00:00.000Z',
    }));
    const headers = {
      accept: 'application/json',
      authorization: `Bearer ${sessionToken}`,
    };
    const readJson = async (path) => {
      const response = await fetch(path, { headers });
      let payload = null;
      try {
        payload = await response.json();
      } catch {
        payload = null;
      }
      return { status: response.status, payload };
    };
    return {
      resolve: await readJson(`/api/calls/resolve/${encodeURIComponent(roomId)}`),
      call: await readJson(`/api/calls/${encodeURIComponent(callId)}`),
    };
  }, {
    storageKey: sessionStorageKey,
    sessionToken: sessionTokenFor(user.key),
    callId: call.id,
    roomId: call.room_id,
    user,
  });
}

test('admin direct-join boundaries are enforced for system admin, org-admin, foreign org-admin, moderator, owner, and member', async ({ browser }) => {
  test.setTimeout(60_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const context = await browser.newContext({ baseURL });
  await installBoundaryRoutes(context);
  const page = await context.newPage();

  try {
    await page.goto('/');
    for (const boundaryCase of boundaryCases) {
      await test.step(boundaryCase.label, async () => {
        const { expected } = boundaryCase;
        const responses = await probeDirectJoinBoundary(page, boundaryCase);
        expect.soft(responses.resolve.status, `${boundaryCase.label} resolve status`).toBe(expected.resolveStatus);
        expect.soft(responses.call.status, `${boundaryCase.label} call status`).toBe(expected.callStatus);

        if (expected.allowed) {
          const resolveResult = responses.resolve.payload?.result || {};
          const fetchedCall = responses.call.payload?.call || {};
          expect.soft(responses.resolve.payload?.status, `${boundaryCase.label} resolve envelope`).toBe('ok');
          expect.soft(resolveResult.state, `${boundaryCase.label} resolve state`).toBe('resolved');
          expect.soft(resolveResult.access_decision?.source, `${boundaryCase.label} decision source`).toBe(expected.source);
          expect.soft(resolveResult.access_decision?.can_manage_lobby, `${boundaryCase.label} can manage lobby`).toBe(expected.canManageLobby);
          expect.soft(fetchedCall.id, `${boundaryCase.label} fetched call id`).toBe(calls.get(boundaryCase.callKey).id);
          expect.soft(fetchedCall.access_decision?.source, `${boundaryCase.label} fetched decision source`).toBe(expected.source);
          expect.soft(fetchedCall.my_participation?.effective_call_role, `${boundaryCase.label} effective call role`).toBe(expected.effectiveCallRole);
          expect.soft(fetchedCall.my_participation?.can_manage_lobby, `${boundaryCase.label} participation lobby right`).toBe(expected.canManageLobby);
          expect.soft(responses.call.payload?.tenant?.permissions?.platform_admin ?? false, `${boundaryCase.label} platform admin tenant permission`).toBe(expected.platformAdmin);
          expect.soft(responses.call.payload?.tenant?.permissions?.tenant_admin ?? false, `${boundaryCase.label} tenant admin permission`).toBe(expected.tenantAdmin);
          expect.soft(responses.call.payload?.tenant?.permissions?.manage_lobby ?? false, `${boundaryCase.label} tenant manage_lobby permission`).toBe(expected.tenantPermissionManageLobby);
        } else {
          const resolveResult = responses.resolve.payload?.result || {};
          expect.soft(responses.resolve.payload?.status, `${boundaryCase.label} denied resolve envelope`).toBe('ok');
          expect.soft(resolveResult.state, `${boundaryCase.label} denied resolve state`).toBe('forbidden');
          expect.soft(resolveResult.reason, `${boundaryCase.label} denied reason`).toBe(expected.reason);
          expect.soft(resolveResult.call ?? null, `${boundaryCase.label} denied call payload`).toBeNull();
          expect.soft(resolveResult.access_decision?.source, `${boundaryCase.label} denied decision source`).toBe(expected.source);
          expect.soft(resolveResult.access_decision?.can_manage_lobby, `${boundaryCase.label} denied lobby right`).toBe(false);
          expect.soft(responses.call.payload?.status, `${boundaryCase.label} denied call envelope`).toBe('error');
          expect.soft(responses.call.payload?.error?.code, `${boundaryCase.label} denied call error`).toBe(expected.reason);
          expect.soft(responses.call.payload?.call ?? null, `${boundaryCase.label} denied fetched private call`).toBeNull();
        }
      });
    }
  } finally {
    await context.close();
  }
});
