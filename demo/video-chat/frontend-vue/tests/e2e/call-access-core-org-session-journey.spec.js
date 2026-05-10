import { test, expect } from '@playwright/test';

import {
  accessIdFromJoinPath,
  createCallAccessMatrixPage,
  createDirectJoinMatrixPage,
  directJoinDecisionForSeedUser,
  getSeedAccessLink,
  getSeedCall,
  getSeedOrganization,
  getSeedScenario,
  getSeedUser,
  installCallAccessSeedRoutes,
  sessionStorageKey,
  storedSessionForSeedUser,
  tenantSnapshotForSeedUser,
} from './helpers/callAccessSeedMatrix.js';

async function readStoredSession(page) {
  return page.evaluate((key) => {
    try {
      return JSON.parse(localStorage.getItem(key) || '{}');
    } catch {
      return {};
    }
  }, sessionStorageKey);
}

test('e2e_org_001-004 organization fixture creates users with User and Admin organization roles', () => {
  const organization = getSeedOrganization('alpha_org');
  const normalUser = getSeedUser('alpha_normal_user');
  const organizationAdmin = getSeedUser('alpha_org_admin');

  expect(organization).toMatchObject({
    tenant_key: 'alpha',
    public_id: 'organization-alpha-e2e',
  });
  expect(normalUser.account_type).toBe('account');
  expect(normalUser.organization_memberships).toEqual([
    { organization_key: organization.key, role: 'member' },
  ]);
  expect(organizationAdmin.account_type).toBe('account');
  expect(organizationAdmin.organization_memberships).toEqual([
    { organization_key: organization.key, role: 'admin' },
  ]);

  expect(directJoinDecisionForSeedUser('alpha_normal_user', 'alpha_active')).toMatchObject({
    allowed: false,
    source: 'none',
    can_manage_lobby: false,
  });
  expect(directJoinDecisionForSeedUser('alpha_org_admin', 'alpha_active')).toMatchObject({
    allowed: true,
    source: 'organization_admin',
    can_manage_lobby: true,
  });
});

for (const userKey of ['alpha_normal_user', 'alpha_org_admin']) {
  test(`e2e_org_005-006 ${userKey} has an account session fixture for login state`, () => {
    const user = getSeedUser(userKey);
    const storedSession = storedSessionForSeedUser(userKey, 'alpha_active');
    const tenant = tenantSnapshotForSeedUser(userKey, 'alpha_active');

    expect(user.account_type).toBe('account');
    expect(user.is_guest).toBe(false);
    expect(storedSession.userId).toBe(user.id);
    expect(storedSession.email).toBe(user.email);
    expect(storedSession.sessionToken).toMatch(/^sess_iam_seed_/);
    expect(tenant?.tenant_id).toBe(5101);
    expect(tenant?.permissions?.platform_admin ?? false).toBe(false);
    expect(tenant?.permissions?.tenant_admin ?? false).toBe(false);
  });
}

test('e2e_org_007 logged-out browser has no active account session', async ({ browser }) => {
  test.setTimeout(30_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const context = await browser.newContext({ baseURL });
  await installCallAccessSeedRoutes(context);
  const page = await context.newPage();

  try {
    await page.goto('/login');
    await expect(page.getByLabel('Email')).toBeVisible();
    expect(await readStoredSession(page)).toEqual({});

    const sessionState = await page.evaluate(async () => {
      const response = await fetch('/api/auth/session-state');
      let payload = null;
      try {
        payload = await response.json();
      } catch {
        payload = null;
      }
      return { status: response.status, payload };
    });

    expect(sessionState.status).toBe(401);
    expect(sessionState.payload?.status).toBe('error');
    expect(sessionState.payload?.error?.code).toBe('auth_failed');
  } finally {
    await context.close();
  }
});

test('logged-in account remains the active account when opening an anonymous call link', async ({ browser }) => {
  test.setTimeout(60_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const scenario = getSeedScenario('anonymous_open_logged_in_uses_own_account_waits_for_host');
  const link = getSeedAccessLink(scenario.link_key);
  const call = getSeedCall(link.call_key);
  const account = getSeedUser(scenario.principal_user_key);
  const temporaryGuest = getSeedUser('temporary_anonymous_guest');
  const accessId = accessIdFromJoinPath(link.join_path);
  const initialSession = storedSessionForSeedUser(account.key, link.call_key);

  const { context, page } = await createCallAccessMatrixPage(browser, baseURL, {
    scenarioKey: scenario.key,
    storedSessionUserKey: account.key,
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
    expect(joinPayload?.result?.link_kind).toBe('open');
    expect(joinPayload?.result?.target_user).toBeNull();
    expect(joinPayload?.result?.call?.id).toBe(call.id);

    const joinDialog = page.getByRole('dialog', { name: 'Join video call' });
    await expect(joinDialog).toBeVisible({ timeout: 20_000 });
    await expect(joinDialog).toContainText('Free-for-all link');
    expect((await readStoredSession(page)).sessionToken).toBe(initialSession.sessionToken);
    await joinDialog.getByPlaceholder('Enter your display name').fill('Ignored Browser Guest');

    const sessionResponsePromise = page.waitForResponse((response) => (
      response.url().includes(`/api/call-access/${accessId}/session`)
      && response.request().method() === 'POST'
    ));
    await joinDialog.getByRole('button', { name: /^Join call$/ }).click();
    const sessionResponse = await sessionResponsePromise;
    const sessionRequest = sessionResponse.request();
    expect(sessionRequest.headers().authorization).toBe(`Bearer ${initialSession.sessionToken}`);
    expect(sessionResponse.status()).toBe(200);
    const sessionPayload = await sessionResponse.json();
    expect(sessionPayload?.result?.user?.id).toBe(account.id);
    expect(sessionPayload?.result?.user?.id).not.toBe(temporaryGuest.id);
    expect(sessionPayload?.result?.user?.account_type).toBe('account');
    expect(Boolean(sessionPayload?.result?.user?.is_guest)).toBe(false);
    expect(sessionPayload?.result?.tenant?.permissions?.platform_admin ?? false).toBe(false);
    expect(sessionPayload?.result?.tenant?.permissions?.tenant_admin ?? false).toBe(false);

    const storedAfterJoin = await readStoredSession(page);
    expect(storedAfterJoin).toMatchObject({
      sessionToken: sessionPayload?.result?.session?.token,
      sessionId: sessionPayload?.result?.session?.id,
    });
  } finally {
    await context.close();
  }
});

test('user without organization cannot receive organization-based call rights', async ({ browser }) => {
  test.setTimeout(60_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const scenarioKey = 'direct_join_user_without_organization_denied';
  const scenario = getSeedScenario(scenarioKey);
  const call = getSeedCall(scenario.call_key);
  const user = getSeedUser(scenario.principal_user_key);
  const { context, page, directJoinDecisions } = await createDirectJoinMatrixPage(browser, baseURL, { scenarioKey });

  try {
    expect(user.memberships).toEqual([{ tenant_key: 'alpha', role: 'member' }]);
    expect(user.organization_memberships).toEqual([]);
    expect(directJoinDecisionForSeedUser(user.key, call.key)).toMatchObject({
      allowed: false,
      reason: 'not_on_guest_list',
      source: 'none',
      can_manage_lobby: false,
    });

    const resolveResponsePromise = page.waitForResponse((response) => (
      response.url().includes(`/api/calls/resolve/${call.id}`)
      && response.request().method() === 'GET'
    ));
    await page.goto(`/workspace/call/${call.id}`);
    const resolveResponse = await resolveResponsePromise;
    expect(resolveResponse.status()).toBe(200);
    const resolvePayload = await resolveResponse.json();
    expect(resolvePayload?.result?.state).toBe('forbidden');
    expect(resolvePayload?.result?.call ?? null).toBe(null);

    await expect(page).toHaveURL(/\/(user\/dashboard|admin\/calls)(?:[/?#].*)?$/);
    await expect(page.locator('body')).not.toContainText(call.title);
    expect(directJoinDecisions).toContainEqual(expect.objectContaining({
      user_key: user.key,
      call_key: call.key,
      allowed: false,
      reason: 'not_on_guest_list',
      source: 'none',
      can_manage_lobby: false,
    }));
  } finally {
    await context.close();
  }
});
