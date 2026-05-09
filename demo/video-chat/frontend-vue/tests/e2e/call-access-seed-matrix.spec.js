import { test, expect } from '@playwright/test';

import {
  accessIdFromJoinPath,
  createCallAccessMatrixPage,
  getSeedAccessLink,
  getSeedCall,
  getSeedScenario,
  getSeedUser,
  installStoredSeedSession,
  seedUserKeys,
  sessionStorageKey,
  tenantSnapshotForSeedUser,
} from './helpers/callAccessSeedMatrix.js';

const directJoinPermissionCases = [
  {
    label: 'system admin alpha',
    principalUserKey: 'system_admin',
    callKey: 'alpha_active',
    allowed: true,
  },
  {
    label: 'system admin beta',
    principalUserKey: 'system_admin',
    callKey: 'beta_active',
    allowed: true,
  },
  {
    label: 'system admin tenantless',
    principalUserKey: 'system_admin',
    callKey: 'tenantless_active',
    allowed: true,
  },
  {
    label: 'alpha org admin alpha',
    principalUserKey: 'alpha_org_admin',
    callKey: 'alpha_active',
    allowed: true,
  },
  {
    label: 'registered guest alpha',
    principalUserKey: 'registered_guest',
    callKey: 'alpha_active',
    allowed: true,
  },
  {
    label: 'alpha call owner alpha',
    principalUserKey: 'alpha_call_owner',
    callKey: 'alpha_active',
    allowed: true,
  },
  {
    label: 'alpha org admin beta',
    principalUserKey: 'alpha_org_admin',
    callKey: 'beta_active',
    allowed: false,
  },
  {
    label: 'alpha normal user alpha',
    principalUserKey: 'alpha_normal_user',
    callKey: 'alpha_active',
    allowed: false,
  },
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
    'beta_org_admin',
    'alpha_call_owner',
    'alpha_normal_user',
    'registered_guest',
    'removed_invited_member',
    'temporary_personalized_guest',
    'temporary_anonymous_guest',
  ]));

  const systemAdminScenario = getSeedScenario('system_admin_join_any_organization_call_without_guest_list');
  expect(systemAdminScenario.call_keys).toEqual(expect.arrayContaining(['alpha_active', 'beta_active', 'tenantless_active']));
  expect(systemAdminScenario.expected.guest_list_required).toBe(false);
  expect(systemAdminScenario.expected.can_manage_lobby).toBe(true);
  expect(systemAdminScenario.expected.platform_admin).toBe(true);

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

  for (const row of directJoinPermissionCases) {
    await test.step(row.label, async () => {
      const call = getSeedCall(row.callKey);
      const principal = getSeedUser(row.principalUserKey);
      const { context, page } = await createDirectJoinProbePage(browser, baseURL, {
        principalUserKey: row.principalUserKey,
        callKey: row.callKey,
      });

      try {
        const responses = await fetchDirectJoinResponses(page, {
          roomId: call.room_id,
          callId: call.id,
        });

        expect.soft(responses.storedSession.userId, `${row.label} stored user id`).toBe(principal.id);
        expect.soft(responses.storedSession.sessionToken, `${row.label} stored session token`).toMatch(/^sess_iam_seed_/);

        expect.soft(responses.resolve.status, `${row.label} resolve HTTP status`).toBe(200);
        expect.soft(responses.resolve.payload?.status, `${row.label} resolve envelope`).toBe('ok');
        if (row.allowed) {
          expect.soft(responses.resolve.payload?.result?.state, `${row.label} resolve state`).toBe('resolved');
          expect.soft(responses.resolve.payload?.result?.call?.id, `${row.label} resolved call id`).toBe(call.id);
          expect.soft(responses.call.status, `${row.label} call HTTP status`).toBe(200);
          expect.soft(responses.call.payload?.status, `${row.label} call envelope`).toBe('ok');
          expect.soft(responses.call.payload?.call?.id, `${row.label} fetched call id`).toBe(call.id);
        } else {
          expect.soft(responses.resolve.payload?.result?.state, `${row.label} resolve denied state`).toBe('forbidden');
          expect.soft(responses.resolve.payload?.result?.reason, `${row.label} resolve denied reason`).toBe('calls_forbidden');
          expect.soft(responses.resolve.payload?.result?.call ?? null, `${row.label} resolve denied call`).toBeNull();
          expect.soft(responses.call.status, `${row.label} call denied HTTP status`).toBe(403);
          expect.soft(responses.call.payload?.status, `${row.label} call denied envelope`).toBe('error');
          expect.soft(responses.call.payload?.error?.code, `${row.label} call denied code`).toBe('calls_forbidden');
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
