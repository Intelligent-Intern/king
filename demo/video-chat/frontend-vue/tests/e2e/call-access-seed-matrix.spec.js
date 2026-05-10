import { test, expect } from '@playwright/test';

import {
  accessIdFromJoinPath,
  createCallAccessMatrixPage,
  getSeedAccessLink,
  getSeedCall,
  getSeedOrganization,
  getSeedScenario,
  getSeedUser,
  installStoredSeedSession,
  seedCallKeys,
  seedUserKeys,
  sessionStorageKey,
  tenantSnapshotForSeedUser,
} from './helpers/callAccessSeedMatrix.js';

const directJoinPermissionCases = [
  'direct_join_system_admin_alpha_active_allowed',
  'direct_join_system_admin_beta_active_allowed',
  'direct_join_system_admin_tenantless_active_allowed',
  'direct_join_alpha_org_admin_alpha_active_allowed',
  'direct_join_beta_org_admin_beta_active_allowed',
  'direct_join_registered_guest_alpha_active_allowed',
  'direct_join_alpha_call_owner_alpha_active_allowed',
  'direct_join_alpha_org_admin_beta_active_denied',
  'direct_join_alpha_org_admin_beta_cross_org_private_denied',
  'direct_join_alpha_admin_beta_member_active_switch_denied',
  'direct_join_alpha_normal_user_alpha_active_denied',
  'direct_join_user_without_organization_denied',
  'direct_join_beta_normal_user_beta_active_denied',
  'direct_join_system_admin_alpha_ended_denied',
  'direct_join_alpha_owner_alpha_disabled_denied',
  'direct_join_alpha_owner_alpha_deleted_hidden',
];

async function createDirectJoinProbePage(browser, baseURL, { principalUserKey, callKey }) {
  const { context, page } = await createCallAccessMatrixPage(browser, baseURL, {
    scenarioKey: 'call_scoped_removed_member_personal_waits_for_host',
  });
  await page.close();
  await installStoredSeedSession(context, principalUserKey, callKey);
  return { context, page: await context.newPage() };
}

async function fetchDirectJoinResponses(page, { roomId, callId }) {
  await page.goto('/');
  return page.evaluate(async ({ storageKey, roomId: targetRoomId, callId: targetCallId }) => {
    let storedSession = {};
    try {
      storedSession = JSON.parse(localStorage.getItem(storageKey) || '{}');
    } catch {
      storedSession = {};
    }
    const headers = {
      accept: 'application/json',
      authorization: `Bearer ${String(storedSession.sessionToken || '')}`,
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
      storedSession,
      resolve: await readJson(`/api/calls/resolve/${encodeURIComponent(targetRoomId)}`),
      call: await readJson(`/api/calls/${encodeURIComponent(targetCallId)}`),
    };
  }, { storageKey: sessionStorageKey, roomId, callId });
}

test('IAM call-access seed matrix covers required principals without temporary admin elevation', () => {
  expect(seedUserKeys()).toEqual(expect.arrayContaining([
    'system_admin',
    'alpha_org_admin',
    'alpha_admin_beta_member',
    'beta_org_admin',
    'alpha_call_owner',
    'alpha_normal_user',
    'beta_normal_user',
    'alpha_tenant_member_without_organization',
    'registered_guest',
    'removed_invited_member',
    'temporary_personalized_guest',
    'temporary_anonymous_guest',
  ]));

  expect(seedCallKeys()).toEqual(expect.arrayContaining([
    'alpha_active',
    'beta_active',
    'tenantless_active',
    'beta_cross_org_private',
    'alpha_ended',
    'alpha_disabled',
    'alpha_deleted',
  ]));

  const systemAdminScenario = getSeedScenario('system_admin_join_any_organization_call_without_guest_list');
  expect(systemAdminScenario.call_keys).toEqual(expect.arrayContaining([
    'alpha_active',
    'beta_active',
    'beta_cross_org_private',
    'tenantless_active',
  ]));
  expect(systemAdminScenario.expected.guest_list_required).toBe(false);
  expect(systemAdminScenario.expected.can_manage_lobby).toBe(true);
  expect(systemAdminScenario.expected.platform_admin).toBe(true);

  expect(getSeedOrganization('alpha_org')).toMatchObject({
    tenant_key: 'alpha',
    public_id: 'organization-alpha-e2e',
    status: 'active',
  });
  expect(getSeedUser('alpha_normal_user').organization_memberships).toEqual([
    { organization_key: 'alpha_org', role: 'member' },
  ]);
  expect(getSeedUser('alpha_org_admin').organization_memberships).toEqual([
    { organization_key: 'alpha_org', role: 'admin' },
  ]);
  const tenantOnlyUser = getSeedUser('alpha_tenant_member_without_organization');
  expect(tenantOnlyUser.memberships).toEqual([{ tenant_key: 'alpha', role: 'member' }]);
  expect(tenantOnlyUser.organization_memberships).toEqual([]);
  expect(getSeedScenario('direct_join_user_without_organization_denied').expected).toMatchObject({
    direct_join_allowed: false,
    expected_resolve_state: 'forbidden',
    expected_resolve_reason: 'calls_forbidden',
    tenant_admin: false,
    platform_admin: false,
  });

  for (const [callKey, status] of [
    ['alpha_ended', 'ended'],
    ['alpha_disabled', 'disabled'],
    ['alpha_deleted', 'deleted'],
  ]) {
    const call = getSeedCall(callKey);
    expect(call.status).toBe(status);
  }

  for (const userKey of ['temporary_personalized_guest', 'temporary_anonymous_guest']) {
    const user = getSeedUser(userKey);
    const tenant = tenantSnapshotForSeedUser(userKey, 'alpha_active');
    expect(user.temporary).toBe(true);
    expect(user.role).toBe('user');
    expect(user.system_admin).toBe(false);
    expect(tenant?.permissions?.platform_admin ?? false).toBe(false);
    expect(tenant?.permissions?.tenant_admin ?? false).toBe(false);
  }
});

test('Direct Join Permissions seed matrix enforces direct call-ref API access', async ({ browser }) => {
  test.setTimeout(120_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';

  for (const scenarioKey of directJoinPermissionCases) {
    await test.step(scenarioKey, async () => {
      const scenario = getSeedScenario(scenarioKey);
      const call = getSeedCall(scenario.call_key);
      const principal = getSeedUser(scenario.principal_user_key);
      const expected = scenario.expected || {};
      const { context, page } = await createDirectJoinProbePage(browser, baseURL, {
        principalUserKey: scenario.principal_user_key,
        callKey: scenario.call_key,
      });

      try {
        const responses = await fetchDirectJoinResponses(page, {
          roomId: call.room_id,
          callId: call.id,
        });

        expect.soft(responses.storedSession.userId, `${scenarioKey} stored user id`).toBe(principal.id);
        expect.soft(responses.storedSession.sessionToken, `${scenarioKey} stored session token`).toMatch(/^sess_iam_seed_/);

        expect.soft(responses.resolve.status, `${scenarioKey} resolve HTTP status`).toBe(expected.expected_resolve_status);
        if (expected.direct_join_allowed === true) {
          expect.soft(responses.resolve.payload?.status, `${scenarioKey} resolve envelope`).toBe('ok');
          expect.soft(responses.resolve.payload?.result?.state, `${scenarioKey} resolve state`).toBe(expected.expected_resolve_state);
          expect.soft(responses.resolve.payload?.result?.call?.id, `${scenarioKey} resolved call id`).toBe(call.id);
          expect.soft(responses.call.status, `${scenarioKey} call HTTP status`).toBe(expected.expected_call_status);
          expect.soft(responses.call.payload?.status, `${scenarioKey} call envelope`).toBe('ok');
          expect.soft(responses.call.payload?.call?.id, `${scenarioKey} fetched call id`).toBe(call.id);
        } else if (expected.expected_resolve_status === 200) {
          expect.soft(responses.resolve.payload?.status, `${scenarioKey} resolve denied envelope`).toBe('ok');
          expect.soft(responses.resolve.payload?.result?.state, `${scenarioKey} resolve denied state`).toBe(expected.expected_resolve_state);
          expect.soft(responses.resolve.payload?.result?.reason, `${scenarioKey} resolve denied reason`).toBe(expected.expected_resolve_reason);
          expect.soft(responses.resolve.payload?.result?.call ?? null, `${scenarioKey} resolve denied call`).toBeNull();
          expect.soft(responses.call.status, `${scenarioKey} call denied HTTP status`).toBe(expected.expected_call_status);
          expect.soft(responses.call.payload?.status, `${scenarioKey} call denied envelope`).toBe('error');
          expect.soft(responses.call.payload?.error?.code, `${scenarioKey} call denied code`).toBe(expected.expected_call_error_code);
        } else {
          expect.soft(responses.resolve.payload?.status, `${scenarioKey} resolve hidden envelope`).toBe('error');
          expect.soft(responses.resolve.payload?.error?.code, `${scenarioKey} resolve hidden code`).toBe(expected.expected_resolve_error_code);
          expect.soft(responses.call.status, `${scenarioKey} call denied HTTP status`).toBe(expected.expected_call_status);
          expect.soft(responses.call.payload?.status, `${scenarioKey} call denied envelope`).toBe('error');
          expect.soft(responses.call.payload?.error?.code, `${scenarioKey} call denied code`).toBe(expected.expected_call_error_code);
        }
        if (expected.private_call_payload_forbidden === true) {
          expect.soft(responses.resolve.payload?.result?.call ?? null, `${scenarioKey} terminal resolve private call`).toBeNull();
          expect.soft(responses.call.payload?.call ?? null, `${scenarioKey} terminal GET private call`).toBeNull();
        }
      } finally {
        await context.close();
      }
    });
  }
});

test('personal call-access matrix seed starts a call-scoped session and waits for host admission', async ({ browser }) => {
  test.setTimeout(60_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const scenario = getSeedScenario('call_scoped_removed_member_personal_waits_for_host');
  const link = getSeedAccessLink(scenario.link_key);
  const call = getSeedCall(link.call_key);
  const participant = getSeedUser(link.target_user_key);
  const accessId = accessIdFromJoinPath(link.join_path);

  expect(accessId, 'join path must contain the backend-issued access id').not.toBe('');

  const { context, page } = await createCallAccessMatrixPage(browser, baseURL, {
    scenarioKey: scenario.key,
  });
  try {
    const joinResponsePromise = page.waitForResponse((response) => (
      response.url().includes(`/api/call-access/${accessId}/join`)
      && response.request().method() === 'GET'
    ));
    await page.goto(link.join_path);
    const joinResponse = await joinResponsePromise;
    expect(joinResponse.status()).toBe(200);
    const joinPayload = await joinResponse.json();
    expect(joinPayload?.status).toBe('ok');
    expect(joinPayload?.result?.link_kind).toBe('personal');
    expect(joinPayload?.result?.call?.id).toBe(call.id);
    expect(joinPayload?.result?.target_user?.id).toBe(participant.id);

    const joinDialog = page.getByRole('dialog', { name: 'Join video call' });
    await expect(joinDialog).toBeVisible({ timeout: 20_000 });
    await expect(joinDialog).toContainText(call.title);
    await expect(joinDialog).toContainText('Personalized link');

    const sessionResponsePromise = page.waitForResponse((response) => (
      response.url().includes(`/api/call-access/${accessId}/session`)
      && response.request().method() === 'POST'
    ));
    await joinDialog.getByRole('button', { name: /^Join call$/ }).click();
    const sessionResponse = await sessionResponsePromise;
    expect(sessionResponse.status()).toBe(200);
    const sessionPayload = await sessionResponse.json();
    expect(sessionPayload?.status).toBe('ok');
    expect(sessionPayload?.result?.user?.id).toBe(participant.id);
    expect(sessionPayload?.result?.call?.id).toBe(call.id);
    expect(sessionPayload?.result?.tenant?.permissions?.tenant_admin ?? false).toBe(false);
    expect(JSON.stringify(sessionPayload)).not.toMatch(/\b(?:sdp|ice|candidate|media_token|turn_credential)\b/i);

    await expect(joinDialog).toContainText(/Call owner has been notified|Waiting for host/i, { timeout: 20_000 });
    const socketFrames = await page.evaluate(() => window.__iamCallAccessSocketFrames || []);
    expect(socketFrames.some((frame) => frame?.type === 'lobby/queue/join')).toBe(true);

    const storedSession = await page.evaluate((key) => {
      try {
        return JSON.parse(localStorage.getItem(key) || '{}');
      } catch {
        return {};
      }
    }, sessionStorageKey);
    expect(storedSession.sessionToken).toBe(sessionPayload?.result?.session?.token);
    expect(storedSession.sessionId).toBe(sessionPayload?.result?.session?.id);
  } finally {
    await context.close();
  }
});
