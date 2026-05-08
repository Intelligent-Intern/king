import { test, expect } from '@playwright/test';

import {
  accessIdFromJoinPath,
  createCallAccessMatrixPage,
  getSeedAccessLink,
  getSeedCall,
  getSeedScenario,
  getSeedUser,
  seedUserKeys,
  sessionStorageKey,
  tenantSnapshotForSeedUser,
} from './helpers/callAccessSeedMatrix.js';

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

async function expectOpenLinkWaitsForHost({ browser, scenarioKey, storedSessionUserKey = '', guestName }) {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const scenario = getSeedScenario(scenarioKey);
  const link = getSeedAccessLink(scenario.link_key);
  const call = getSeedCall(link.call_key);
  const expectedUser = getSeedUser(scenario.principal_user_key);
  const temporaryGuest = getSeedUser('temporary_anonymous_guest');
  const accessId = accessIdFromJoinPath(link.join_path);

  expect(accessId, 'join path must contain the backend-issued access id').not.toBe('');

  const { context, page } = await createCallAccessMatrixPage(browser, baseURL, {
    scenarioKey: scenario.key,
    storedSessionUserKey,
    storedSessionCallKey: link.call_key,
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
    expect(joinPayload?.result?.link_kind).toBe('open');
    expect(joinPayload?.result?.call?.id).toBe(call.id);
    expect(joinPayload?.result?.target_user).toBeNull();

    const joinDialog = page.getByRole('dialog', { name: 'Join video call' });
    await expect(joinDialog).toBeVisible({ timeout: 20_000 });
    await expect(joinDialog).toContainText(call.title);
    await expect(joinDialog).toContainText('Free-for-all link');
    await joinDialog.getByPlaceholder('Enter your display name').fill(guestName);

    const sessionResponsePromise = page.waitForResponse((response) => (
      response.url().includes(`/api/call-access/${accessId}/session`)
      && response.request().method() === 'POST'
    ));
    await joinDialog.getByRole('button', { name: /^Join call$/ }).click();
    const sessionResponse = await sessionResponsePromise;
    expect(sessionResponse.status()).toBe(200);
    const sessionPayload = await sessionResponse.json();
    expect(sessionPayload?.status).toBe('ok');
    expect(sessionPayload?.result?.link_kind).toBe('open');
    expect(sessionPayload?.result?.user?.id).toBe(expectedUser.id);
    expect(sessionPayload?.result?.user?.account_type).toBe(expectedUser.account_type);
    expect(Boolean(sessionPayload?.result?.user?.is_guest)).toBe(Boolean(expectedUser.is_guest));
    if (scenario.expected?.must_not_create_temporary_identity) {
      expect(sessionPayload?.result?.user?.id).not.toBe(temporaryGuest.id);
    }
    expect(sessionPayload?.result?.call?.id).toBe(call.id);
    expect(sessionPayload?.result?.call?.my_participation?.invite_state).toBe('pending');
    expect(sessionPayload?.result?.tenant?.permissions?.platform_admin ?? false).toBe(false);
    expect(sessionPayload?.result?.tenant?.permissions?.tenant_admin ?? false).toBe(false);
    expect(sessionPayload?.result?.tenant?.permissions?.manage_lobby ?? false).toBe(false);
    expect(sessionPayload?.result?.tenant?.permissions?.admit_participants ?? false).toBe(false);
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
}

test('anonymous open link keeps a logged-in user on their own account and waits for host admission', async ({ browser }) => {
  test.setTimeout(60_000);
  await expectOpenLinkWaitsForHost({
    browser,
    scenarioKey: 'anonymous_open_logged_in_uses_own_account_waits_for_host',
    storedSessionUserKey: 'alpha_normal_user',
    guestName: 'Ignored Guest Name',
  });
});

test('anonymous open link creates a temporary guest for logged-out users and waits for host admission', async ({ browser }) => {
  test.setTimeout(60_000);
  await expectOpenLinkWaitsForHost({
    browser,
    scenarioKey: 'anonymous_open_logged_out_creates_temporary_guest_waits_for_host',
    guestName: 'Anonymous Lobby Guest',
  });
});
