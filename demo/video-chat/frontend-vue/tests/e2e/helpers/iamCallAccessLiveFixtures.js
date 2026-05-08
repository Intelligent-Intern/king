import { createHash } from 'node:crypto';

import {
  accessIdFromJoinPath,
  backendOrigin as defaultBackendOrigin,
  getSeedAccessLink,
  getSeedCall,
  getSeedScenario,
  getSeedTenant,
  getSeedUser,
} from './callAccessSeedMatrix.js';

export const iamCallAccessLiveFixtureContractVersion = 'iam-call-access-live-fixtures.v1';
export const iamCallAccessFixtureClock = '2026-05-08T10:00:00.000Z';
export const iamCallAccessFixtureScopes = Object.freeze([
  'tenant_probe',
  'organization',
  'user',
  'role',
  'call',
  'access_link',
  'lobby_probe',
  'session_probe',
  'audit_probe',
]);

export const iamCallAccessCredentialEnv = Object.freeze({
  admin: Object.freeze({
    email: ['VIDEOCHAT_IAM_E2E_ADMIN_EMAIL', 'VIDEOCHAT_E2E_ADMIN_EMAIL', 'VIDEOCHAT_DEPLOY_ADMIN_EMAIL'],
    password: ['VIDEOCHAT_IAM_E2E_ADMIN_PASSWORD', 'VIDEOCHAT_E2E_ADMIN_PASSWORD', 'VIDEOCHAT_DEPLOY_ADMIN_PASSWORD'],
    fallback: Object.freeze({ email: 'admin@intelligent-intern.com', password: 'admin123' }),
  }),
  user: Object.freeze({
    email: ['VIDEOCHAT_IAM_E2E_USER_EMAIL', 'VIDEOCHAT_E2E_USER_EMAIL', 'VIDEOCHAT_DEPLOY_USER_EMAIL'],
    password: ['VIDEOCHAT_IAM_E2E_USER_PASSWORD', 'VIDEOCHAT_E2E_USER_PASSWORD', 'VIDEOCHAT_DEPLOY_USER_PASSWORD'],
    fallback: Object.freeze({ email: 'user@intelligent-intern.com', password: 'user123' }),
  }),
});

function envFirst(names) {
  for (const name of names) {
    const value = String(process.env[name] || '').trim();
    if (value !== '') return value;
  }
  return '';
}

function slug(value, fallback = 'fixture') {
  const normalized = String(value || '')
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 72);
  return normalized || fallback;
}

function pascalWords(value, fallback = 'Fixture') {
  const words = slug(value, fallback)
    .split('-')
    .filter(Boolean)
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1));
  return words.join(' ') || fallback;
}

export function normalizeIamFixtureRunId(runId = process.env.VIDEOCHAT_IAM_E2E_RUN_ID || 'foundation') {
  return slug(runId, 'foundation').slice(0, 48);
}

export function iamFixtureKey(scope, key, runId = process.env.VIDEOCHAT_IAM_E2E_RUN_ID || 'foundation') {
  return [
    'iam',
    normalizeIamFixtureRunId(runId),
    slug(scope, 'scope'),
    slug(key, 'case'),
  ].join('-');
}

export function iamFixtureEmail(key, runId = process.env.VIDEOCHAT_IAM_E2E_RUN_ID || 'foundation') {
  return `${iamFixtureKey('user', key, runId)}@example.test`;
}

export function iamFixturePassword(key, runId = process.env.VIDEOCHAT_IAM_E2E_RUN_ID || 'foundation') {
  return `${iamFixtureKey('password', key, runId)}-Password-1`;
}

export function iamFixtureRoomId(key, runId = process.env.VIDEOCHAT_IAM_E2E_RUN_ID || 'foundation') {
  return iamFixtureKey('room', key, runId).slice(0, 80);
}

export function iamFixtureTitle(scope, key, runId = process.env.VIDEOCHAT_IAM_E2E_RUN_ID || 'foundation') {
  return `IAM ${pascalWords(runId)} ${pascalWords(scope)} ${pascalWords(key)}`;
}

export function iamFixtureFingerprint(value) {
  const normalized = String(value || '').trim();
  if (normalized === '') return '';
  return `sha256:${createHash('sha256').update(normalized).digest('hex')}`;
}

function credentialFor(role, overrides = {}) {
  const normalizedRole = String(role || 'admin').trim().toLowerCase() === 'user' ? 'user' : 'admin';
  const override = overrides[normalizedRole] || overrides[role] || {};
  const env = iamCallAccessCredentialEnv[normalizedRole];
  const email = String(override.email || envFirst(env.email) || env.fallback.email).trim();
  const password = String(override.password || envFirst(env.password) || env.fallback.password).trim();
  if (email === '' || password === '') {
    throw new Error(`Missing IAM E2E credentials for ${normalizedRole}.`);
  }
  return { role: normalizedRole, email, password };
}

function storedSessionFromAuthPayload(payload) {
  const session = payload?.session || payload?.result?.session || {};
  const user = payload?.user || payload?.result?.user || {};
  return {
    role: String(user.role || '').trim(),
    displayName: String(user.display_name || '').trim(),
    email: String(user.email || '').trim(),
    userId: Number.isInteger(user.id) ? user.id : Number(user.id || 0),
    avatarPath: typeof user.avatar_path === 'string' && user.avatar_path.trim() !== '' ? user.avatar_path.trim() : null,
    timeFormat: typeof user.time_format === 'string' && user.time_format.trim() !== '' ? user.time_format.trim() : '24h',
    theme: typeof user.theme === 'string' && user.theme.trim() !== '' ? user.theme.trim() : 'dark',
    status: typeof user.status === 'string' ? user.status.trim() : '',
    sessionId: String(session.id || session.token || '').trim(),
    sessionToken: String(session.token || session.id || '').trim(),
    expiresAt: typeof session.expires_at === 'string' ? session.expires_at.trim() : '',
    tenant: payload?.tenant || payload?.result?.tenant || user.tenant || null,
  };
}

function authToken(auth) {
  if (typeof auth === 'string') return auth.trim();
  return String(
    auth?.token
    || auth?.sessionToken
    || auth?.session?.sessionToken
    || auth?.session?.token
    || auth?.session?.id
    || '',
  ).trim();
}

function withSearchParams(url, search = {}) {
  for (const [key, value] of Object.entries(search || {})) {
    if (value === undefined || value === null || String(value).trim() === '') continue;
    url.searchParams.set(key, String(value));
  }
  return url;
}

function parseRows(payload, collectionKey) {
  const result = payload?.result || {};
  const candidates = [
    result.rows,
    result[collectionKey],
    payload?.[collectionKey],
    payload?.rows,
  ];
  for (const candidate of candidates) {
    if (Array.isArray(candidate)) return candidate;
  }
  return [];
}

function rowName(row) {
  return String(row?.name || row?.label || row?.display_name || '').trim();
}

function optionalSeed(getter, key) {
  const normalizedKey = String(key || '').trim();
  if (normalizedKey === '') return null;
  try {
    return getter(normalizedKey);
  } catch {
    return null;
  }
}

function callPayload({ key = 'alpha_active', runId, participantUserIds = [], externalParticipants = [], overrides = {} }) {
  const seedCall = optionalSeed(getSeedCall, key);
  const startsAt = String(overrides.starts_at || seedCall?.starts_at || iamCallAccessFixtureClock);
  const endsAt = String(overrides.ends_at || seedCall?.ends_at || '2026-05-08T11:00:00.000Z');
  return {
    title: overrides.title || iamFixtureTitle('call', key, runId),
    access_mode: overrides.access_mode || 'invite_only',
    room_id: overrides.room_id || iamFixtureRoomId(key, runId),
    starts_at: startsAt,
    ends_at: endsAt,
    internal_participant_user_ids: participantUserIds,
    external_participants: externalParticipants,
    ...overrides,
  };
}

function userCreatePayload({ key, runId, role = 'user', relationships = {}, overrides = {} }) {
  const seedUser = optionalSeed(getSeedUser, key);
  const email = String(overrides.email || iamFixtureEmail(key || 'user', runId)).trim().toLowerCase();
  return {
    email,
    display_name: overrides.display_name || overrides.displayName || seedUser?.display_name || iamFixtureTitle('user', key, runId),
    password: overrides.password || iamFixturePassword(key || 'user', runId),
    role: overrides.role || role,
    status: overrides.status || 'active',
    relationships,
    ...overrides,
    email,
  };
}

function organizationPayload({ key, runId, users = [], roles = [], overrides = {} }) {
  return {
    name: overrides.name || iamFixtureTitle('organization', key, runId),
    status: overrides.status || 'active',
    relationships: {
      users,
      roles,
      ...(overrides.relationships || {}),
    },
    ...overrides,
  };
}

function rolePayload({ key, runId, permissions = [], overrides = {} }) {
  return {
    key: overrides.key || iamFixtureKey('role', key, runId),
    name: overrides.name || iamFixtureTitle('role', key, runId),
    status: overrides.status || 'active',
    description: overrides.description || `Deterministic IAM fixture role for ${key}.`,
    permissions,
    ...overrides,
  };
}

function buildWebSocketOrigin(origin) {
  const url = new URL(origin);
  url.protocol = url.protocol === 'https:' ? 'wss:' : 'ws:';
  return url;
}

export function buildIamCallAccessWebSocketUrl({
  origin = defaultBackendOrigin,
  sessionToken,
  roomId = 'lobby',
  callId = '',
}) {
  const url = buildWebSocketOrigin(origin);
  url.pathname = '/ws';
  url.search = '';
  url.searchParams.set('room', roomId);
  if (String(callId || '').trim() !== '') url.searchParams.set('call_id', String(callId).trim());
  if (String(sessionToken || '').trim() !== '') url.searchParams.set('session', String(sessionToken).trim());
  return url.toString();
}

export function callAccessLobbyProbe({ origin = defaultBackendOrigin, session, call, targetUserId }) {
  const sessionToken = authToken(session);
  const callId = String(call?.id || call?.call_id || '').trim();
  const roomId = String(call?.room_id || call?.roomId || 'lobby').trim() || 'lobby';
  const userId = Number(targetUserId || session?.userId || session?.user?.id || 0);
  return {
    websocket_url: buildIamCallAccessWebSocketUrl({ origin, sessionToken, roomId, callId }),
    room_id: roomId,
    call_id: callId,
    target_user_id: userId,
    frames: {
      queue_join: { type: 'lobby/queue/join', room_id: roomId, call_id: callId },
      queue_cancel: { type: 'lobby/queue/cancel', room_id: roomId, call_id: callId },
      allow: { type: 'lobby/allow', room_id: roomId, call_id: callId, target_user_id: userId },
      reject: { type: 'lobby/reject', room_id: roomId, call_id: callId, target_user_id: userId },
      remove: { type: 'lobby/remove', room_id: roomId, call_id: callId, target_user_id: userId },
      kick: { type: 'lobby/kick', room_id: roomId, call_id: callId, target_user_id: userId },
    },
  };
}

export function callAccessAuditProbe({ tenant = null, call = null, accessLink = null, session = null, targetUser = null }) {
  const accessId = String(accessLink?.id || accessLink?.access_link?.id || accessIdFromJoinPath(accessLink?.join_path) || '').trim();
  const callId = String(call?.id || accessLink?.call_id || accessLink?.access_link?.call_id || '').trim();
  const sessionId = authToken(session);
  return {
    filters: {
      tenant_id: tenant?.id || tenant?.tenant_id || accessLink?.tenant_id || null,
      call_id: callId,
      target_user_id: targetUser?.id || targetUser?.user_id || null,
      limit: 50,
    },
    expected_event_types: [
      'call_created',
      'call_access_invitation_created',
      'call_access_link_opened',
      'temporary_account_created',
      'call_access_account_compared',
      'call_scoped_access_continued',
      'call_access_duplicate_personalized_link_review',
      'call_access_strong_mismatch_denied',
      'call_access_host_verification_failed',
      'call_access_host_name_rejected',
      'call_access_host_name_verified',
      'call_access_host_name_verification_failed',
      'call_access_account_update_confirmation_requested',
      'call_access_invitation_invalidated',
      'call_participant_joined',
      'call_participant_left',
      'call_participant_rejoined',
      'call_participant_kicked',
      'call_owner_transferred',
      'membership_removed',
    ],
    fingerprints: {
      access_id: iamFixtureFingerprint(accessId),
      call_id: iamFixtureFingerprint(callId),
      session_id: iamFixtureFingerprint(sessionId),
    },
    forbidden_payload_keys: [
      'authorization',
      'password',
      'secret',
      'session_id',
      'token',
      'sdp',
      'ice',
      'candidate',
      'media_token',
    ],
  };
}

export function createIamCallAccessFixtureFactory(options = {}) {
  const origin = String(options.backendOrigin || defaultBackendOrigin).replace(/\/+$/, '');
  const fetchImpl = options.fetchImpl || globalThis.fetch;
  const runId = normalizeIamFixtureRunId(options.runId);
  const credentials = options.credentials || {};
  const created = {
    calls: [],
    users: [],
    organizations: [],
    roles: [],
  };

  if (typeof fetchImpl !== 'function') {
    throw new Error('IAM live fixture factory requires a fetch implementation.');
  }

  async function api(pathname, requestOptions = {}) {
    const method = String(requestOptions.method || 'GET').toUpperCase();
    const url = withSearchParams(new URL(pathname, `${origin}/`), requestOptions.search);
    const token = authToken(requestOptions.auth || requestOptions.token || '');
    const headers = {
      accept: 'application/json',
      ...(requestOptions.headers || {}),
    };
    const init = { method, headers };
    if (token !== '') headers.authorization = `Bearer ${token}`;
    if (requestOptions.body !== undefined) {
      headers['content-type'] = headers['content-type'] || 'application/json';
      init.body = typeof requestOptions.body === 'string'
        ? requestOptions.body
        : JSON.stringify(requestOptions.body);
    }

    const response = await fetchImpl(url, init);
    const text = await response.text();
    let payload = null;
    if (text.trim() !== '') {
      try {
        payload = JSON.parse(text);
      } catch {
        payload = null;
      }
    }

    const expectedStatuses = requestOptions.expectedStatuses || [200];
    if (!expectedStatuses.includes(response.status)) {
      const message = payload?.error?.message || payload?.message || text || `HTTP ${response.status}`;
      throw new Error(`${method} ${url.pathname} failed: ${message}`);
    }
    return { status: response.status, headers: response.headers, payload, text, url: url.toString() };
  }

  async function login(role = 'admin') {
    const loginCredentials = credentialFor(role, credentials);
    const result = await api('/api/auth/login', {
      method: 'POST',
      body: { email: loginCredentials.email, password: loginCredentials.password },
      expectedStatuses: [200],
    });
    const session = storedSessionFromAuthPayload(result.payload || {});
    if (session.sessionToken === '') {
      throw new Error(`Login for ${loginCredentials.email} did not return a session token.`);
    }
    return {
      role: loginCredentials.role,
      email: loginCredentials.email,
      token: session.sessionToken,
      session,
      payload: result.payload,
    };
  }

  async function sessionProbe(auth) {
    const result = await api('/api/auth/session-state', { auth, expectedStatuses: [200] });
    return {
      session: storedSessionFromAuthPayload(result.payload || {}),
      user: result.payload?.user || result.payload?.result?.user || null,
      tenant: result.payload?.tenant || result.payload?.result?.tenant || null,
      payload: result.payload,
    };
  }

  async function tenantProbe(auth) {
    const [sessionState, tenantsResult] = await Promise.all([
      sessionProbe(auth),
      api('/api/tenants', { auth, expectedStatuses: [200] }),
    ]);
    return {
      active_tenant: tenantsResult.payload?.tenant || sessionState.tenant || null,
      tenants: Array.isArray(tenantsResult.payload?.tenants) ? tenantsResult.payload.tenants : [],
      session: sessionState,
      payload: tenantsResult.payload,
    };
  }

  async function switchTenant(auth, tenant) {
    const tenantId = tenant?.id || tenant?.tenant_id || tenant;
    const result = await api('/api/auth/tenant', {
      method: 'POST',
      auth,
      body: Number.isFinite(Number(tenantId)) ? { tenant_id: Number(tenantId) } : { tenant_public_id: String(tenantId) },
      expectedStatuses: [200],
    });
    return { tenant: result.payload?.tenant || result.payload?.result?.tenant || null, payload: result.payload };
  }

  async function listGovernance(auth, entity, search = {}) {
    const result = await api(`/api/governance/${entity}`, { auth, search, expectedStatuses: [200] });
    return parseRows(result.payload, entity);
  }

  async function ensureOrganization(auth, organizationOptions = {}) {
    const payload = organizationPayload({ ...organizationOptions, runId });
    const existing = (await listGovernance(auth, 'organizations'))
      .find((row) => rowName(row).toLowerCase() === String(payload.name).toLowerCase());
    if (existing) return { state: 'reused', organization: existing, payload: { result: { row: existing } } };
    const result = await api('/api/governance/organizations', {
      method: 'POST',
      auth,
      body: payload,
      expectedStatuses: [201],
    });
    const organization = result.payload?.result?.row || null;
    if (organization) created.organizations.push(organization);
    return { state: 'created', organization, payload: result.payload };
  }

  async function createRole(auth, roleOptions = {}) {
    const result = await api('/api/governance/roles', {
      method: 'POST',
      auth,
      body: rolePayload({ ...roleOptions, runId }),
      expectedStatuses: [201],
    });
    const role = result.payload?.result?.row || null;
    if (role) created.roles.push(role);
    return { role, payload: result.payload };
  }

  async function listUsers(auth, search = {}) {
    const result = await api('/api/admin/users', {
      auth,
      search: { page_size: 50, ...search },
      expectedStatuses: [200],
    });
    return parseRows(result.payload, 'users');
  }

  async function findUserByEmail(auth, email) {
    const normalizedEmail = String(email || '').trim().toLowerCase();
    if (normalizedEmail === '') return null;
    return (await listUsers(auth, { query: normalizedEmail }))
      .find((row) => String(row?.email || '').trim().toLowerCase() === normalizedEmail) || null;
  }

  async function createOrReuseUser(auth, userOptions = {}) {
    const payload = userCreatePayload({ ...userOptions, runId });
    const result = await api('/api/admin/users', {
      method: 'POST',
      auth,
      body: payload,
      expectedStatuses: [201, 409],
    });
    if (result.status === 409) {
      const existing = await findUserByEmail(auth, payload.email);
      if (!existing) throw new Error(`User ${payload.email} already exists but could not be loaded.`);
      return { state: 'reused', user: existing, email: payload.email, password: payload.password, payload: result.payload };
    }
    const user = result.payload?.result?.user || null;
    if (user) created.users.push(user);
    return { state: 'created', user, email: payload.email, password: payload.password, payload: result.payload };
  }

  async function updateUser(auth, userId, payload) {
    const result = await api(`/api/admin/users/${encodeURIComponent(String(userId))}`, {
      method: 'PATCH',
      auth,
      body: payload,
      expectedStatuses: [200],
    });
    return { user: result.payload?.result?.user || null, payload: result.payload };
  }

  async function createCall(auth, fixtureOptions = {}) {
    const result = await api('/api/calls', {
      method: 'POST',
      auth,
      body: callPayload({ ...fixtureOptions, runId }),
      expectedStatuses: [201],
    });
    const call = result.payload?.result?.call || null;
    if (call) created.calls.push(call);
    return { call, payload: result.payload };
  }

  async function listCalls(auth, search = {}) {
    const result = await api('/api/calls', {
      auth,
      search: { page_size: 50, ...search },
      expectedStatuses: [200],
    });
    return parseRows(result.payload, 'calls');
  }

  async function findCallByRoomId(auth, roomId) {
    const normalizedRoomId = String(roomId || '').trim();
    if (normalizedRoomId === '') return null;
    return (await listCalls(auth, { query: normalizedRoomId }))
      .find((row) => String(row?.room_id || '').trim() === normalizedRoomId) || null;
  }

  async function createOrReuseCall(auth, fixtureOptions = {}) {
    const payload = callPayload({ ...fixtureOptions, runId });
    const existing = await findCallByRoomId(auth, payload.room_id);
    if (existing) return { state: 'reused', call: existing, payload: { result: { call: existing } } };
    const createdCall = await createCall(auth, { ...fixtureOptions, overrides: payload });
    return { state: 'created', ...createdCall };
  }

  async function deleteCall(auth, callId) {
    const result = await api(`/api/calls/${encodeURIComponent(String(callId))}`, {
      method: 'DELETE',
      auth,
      expectedStatuses: [200, 404],
    });
    return { state: result.status === 404 ? 'missing' : 'deleted', payload: result.payload };
  }

  async function createAccessLink(auth, callId, linkOptions = {}) {
    const body = {
      link_kind: linkOptions.linkKind || linkOptions.link_kind || 'personal',
    };
    if (linkOptions.participantUserId || linkOptions.participant_user_id) {
      body.participant_user_id = linkOptions.participantUserId || linkOptions.participant_user_id;
    }
    if (linkOptions.participantEmail || linkOptions.participant_email) {
      body.participant_email = linkOptions.participantEmail || linkOptions.participant_email;
    }
    const result = await api(`/api/calls/${encodeURIComponent(String(callId))}/access-link`, {
      method: 'POST',
      auth,
      body,
      expectedStatuses: [200],
    });
    return {
      access_link: result.payload?.result?.access_link || null,
      join_path: String(result.payload?.result?.join_path || '').trim(),
      payload: result.payload,
    };
  }

  async function resolveAccessLink(accessIdOrJoinPath) {
    const accessId = accessIdFromJoinPath(accessIdOrJoinPath) || String(accessIdOrJoinPath || '').trim();
    const result = await api(`/api/call-access/${encodeURIComponent(accessId)}/join`, { expectedStatuses: [200, 404] });
    return { state: result.payload?.result?.state || 'unknown', payload: result.payload };
  }

  async function startAccessSession(accessIdOrJoinPath, body = {}) {
    const accessId = accessIdFromJoinPath(accessIdOrJoinPath) || String(accessIdOrJoinPath || '').trim();
    const result = await api(`/api/call-access/${encodeURIComponent(accessId)}/session`, {
      method: 'POST',
      body,
      expectedStatuses: [200, 403, 404, 409, 422],
    });
    return {
      session: storedSessionFromAuthPayload(result.payload || {}),
      payload: result.payload,
      status: result.status,
    };
  }

  async function deactivateUser(auth, userId) {
    const result = await api(`/api/admin/users/${encodeURIComponent(String(userId))}/deactivate`, {
      method: 'POST',
      auth,
      expectedStatuses: [200, 404],
    });
    return { state: result.status === 404 ? 'missing' : 'deactivated', payload: result.payload };
  }

  async function cleanupCreated(auth) {
    const deletedCalls = [];
    for (const call of [...created.calls].reverse()) {
      deletedCalls.push(await deleteCall(auth, call.id || call.call_id));
    }
    const deactivatedUsers = [];
    for (const user of [...created.users].reverse()) {
      deactivatedUsers.push(await deactivateUser(auth, user.id));
    }
    return { deleted_calls: deletedCalls, deactivated_users: deactivatedUsers };
  }

  return {
    origin,
    runId,
    created,
    api,
    login,
    sessionProbe,
    tenantProbe,
    switchTenant,
    listGovernance,
    ensureOrganization,
    createRole,
    listUsers,
    findUserByEmail,
    createOrReuseUser,
    updateUser,
    createCall,
    listCalls,
    findCallByRoomId,
    createOrReuseCall,
    deleteCall,
    createAccessLink,
    resolveAccessLink,
    startAccessSession,
    deactivateUser,
    cleanupCreated,
    lobbyProbe: (probeOptions) => callAccessLobbyProbe({ origin, ...probeOptions }),
    auditProbe: callAccessAuditProbe,
  };
}

export async function seedIamCallAccessFoundation(options = {}) {
  const factory = createIamCallAccessFixtureFactory(options);
  const admin = await factory.login('admin');
  const tenant = await factory.tenantProbe(admin);
  const activeTenant = tenant.active_tenant || getSeedTenant('alpha');
  const organization = await factory.ensureOrganization(admin, {
    key: 'foundation',
    users: [],
    roles: [],
  });
  const invitedUser = await factory.createOrReuseUser(admin, {
    key: 'registered_guest',
    role: 'user',
  });
  const call = await factory.createOrReuseCall(admin, {
    key: 'alpha_active',
    participantUserIds: [Number(invitedUser.user?.id || 0)].filter((id) => id > 0),
  });
  const personalLink = await factory.createAccessLink(admin, call.call.id, {
    linkKind: 'personal',
    participantUserId: invitedUser.user?.id,
  });
  const openLink = await factory.createAccessLink(admin, call.call.id, { linkKind: 'open' });
  const personalSeed = getSeedAccessLink('removed_member_personal');
  const scenario = getSeedScenario('call_scoped_removed_member_personal_waits_for_host');
  const removedMemberSeed = getSeedUser('removed_invited_member');
  const alphaCallSeed = getSeedCall('alpha_active');

  return {
    factory,
    admin,
    tenant,
    organization,
    users: { invited: invitedUser, removed_member_seed: removedMemberSeed },
    calls: { primary: call, alpha_seed: alphaCallSeed },
    links: { personal: personalLink, open: openLink, personal_seed: personalSeed },
    scenarios: { call_scoped_removed_member_personal_waits_for_host: scenario },
    probes: {
      lobby: factory.lobbyProbe({
        session: personalLink.payload?.result?.session || admin,
        call: call.call,
        targetUserId: invitedUser.user?.id,
      }),
      session: await factory.sessionProbe(admin),
      audit: factory.auditProbe({
        tenant: activeTenant,
        call: call.call,
        accessLink: personalLink.access_link,
        session: admin,
        targetUser: invitedUser.user,
      }),
    },
    cleanup: () => factory.cleanupCreated(admin),
  };
}
