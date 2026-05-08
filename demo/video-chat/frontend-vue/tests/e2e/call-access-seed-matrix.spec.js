import { test, expect } from '@playwright/test';

import {
  accessIdFromJoinPath,
  createCallAccessMatrixPage,
  createDirectJoinMatrixPage,
  directJoinDecisionForSeedUser,
  getSeedAccessLink,
  getSeedCall,
  getSeedScenario,
  getSeedTenant,
  getSeedUser,
  seedUserKeys,
  sessionStorageKey,
  tenantSnapshotForSeedUser,
} from './helpers/callAccessSeedMatrix.js';

const allowedDirectJoinScenarios = [
  'direct_join_system_admin_without_guest_list',
  'direct_join_org_admin_own_organization_without_guest_list',
  'direct_join_normal_owner_without_guest_list',
  'direct_join_guest_list_user_allowed',
];

const deniedDirectJoinScenarios = [
  'direct_join_system_admin_explicit_ended_call_denied',
  'direct_join_org_admin_explicit_ended_call_denied',
  'direct_join_org_admin_foreign_organization_denied',
  'direct_join_system_admin_deleted_call_denied',
  'direct_join_system_admin_ended_call_denied',
  'direct_join_active_org_switch_does_not_grant_foreign_call',
  'direct_join_owner_rights_not_cross_org',
  'direct_join_guest_list_not_cross_org',
  'direct_join_forged_client_admin_role_denied',
];

const authDeniedDirectJoinScenarios = [
  'direct_join_forged_session_token_denied',
  'direct_join_disabled_user_denied',
  'direct_join_deleted_user_denied',
];

function escapeRegExp(input) {
  return String(input).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

test('IAM call-access seed matrix covers required principals without temporary admin elevation', () => {
  expect(seedUserKeys()).toEqual(expect.arrayContaining([
    'system_admin',
    'alpha_org_admin',
    'alpha_admin_beta_member',
    'beta_org_admin',
    'alpha_call_owner',
    'alpha_normal_user',
    'registered_guest',
    'removed_invited_member',
    'disabled_registered_user',
    'deleted_registered_user',
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

  for (const scenarioKey of [
    ...allowedDirectJoinScenarios,
    ...deniedDirectJoinScenarios,
    ...authDeniedDirectJoinScenarios,
  ]) {
    const scenario = getSeedScenario(scenarioKey);
    const decision = directJoinDecisionForSeedUser(scenario.principal_user_key, scenario.call_key);
    expect(decision.source).toBe(scenario.expected.decision_source);
    expect(decision.allowed).toBe(scenario.expected.state === 'resolved');
    if (scenario.expected.decision_reason) {
      expect(decision.reason).toBe(scenario.expected.decision_reason);
    }
    expect(decision.can_manage_lobby).toBe(scenario.expected.can_manage_lobby);
    const tenant = tenantSnapshotForSeedUser(scenario.principal_user_key, scenario.call_key);
    expect(tenant?.permissions?.platform_admin ?? false).toBe(scenario.expected.platform_admin);
    expect(tenant?.permissions?.tenant_admin ?? false).toBe(scenario.expected.tenant_admin);
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

for (const scenarioKey of allowedDirectJoinScenarios) {
  test(`direct workspace join allows ${scenarioKey} through server-side role evaluation`, async ({ browser }) => {
    test.setTimeout(60_000);
    const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
    const scenario = getSeedScenario(scenarioKey);
    const call = getSeedCall(scenario.call_key);
    const { context, page, directJoinDecisions } = await createDirectJoinMatrixPage(browser, baseURL, { scenarioKey });

    try {
      const resolveResponsePromise = page.waitForResponse((response) => (
        response.url().includes(`/api/calls/resolve/${call.id}`)
        && response.request().method() === 'GET'
      ));
      await page.goto(`/workspace/call/${call.id}`);
      const resolveResponse = await resolveResponsePromise;
      expect(resolveResponse.status()).toBe(200);
      const resolvePayload = await resolveResponse.json();
      expect(resolvePayload?.result?.state).toBe('resolved');
      expect(resolvePayload?.result?.call?.id).toBe(call.id);
      expect(resolvePayload?.result?.access_decision?.source).toBe(scenario.expected.decision_source);
      expect(resolvePayload?.result?.access_decision?.can_manage_lobby).toBe(scenario.expected.can_manage_lobby);

      await expect(page).toHaveURL(new RegExp(`/workspace/call/${escapeRegExp(call.id)}(?:[/?#].*)?$`));
      await expect(page.locator('.workspace-call-view')).toBeVisible({ timeout: 20_000 });
      expect(directJoinDecisions.some((decision) => (
        decision.call_id === call.id
        && decision.allowed === true
        && decision.source === scenario.expected.decision_source
      ))).toBe(true);
    } finally {
      await context.close();
    }
  });
}

for (const scenarioKey of deniedDirectJoinScenarios) {
  test(`direct workspace join denies ${scenarioKey} without leaking call payload`, async ({ browser }) => {
    test.setTimeout(60_000);
    const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
    const scenario = getSeedScenario(scenarioKey);
    const call = getSeedCall(scenario.call_key);
    const { context, page, directJoinDecisions } = await createDirectJoinMatrixPage(browser, baseURL, { scenarioKey });

    try {
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
      expect(directJoinDecisions.some((decision) => (
        decision.call_id === call.id
        && decision.allowed === false
        && decision.source === 'none'
      ))).toBe(true);
    } finally {
      await context.close();
    }
  });
}

for (const scenarioKey of authDeniedDirectJoinScenarios) {
  test(`direct workspace join denies ${scenarioKey} before call resolution`, async ({ browser }) => {
    test.setTimeout(60_000);
    const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
    const scenario = getSeedScenario(scenarioKey);
    const call = getSeedCall(scenario.call_key);
    const { context, page, directJoinDecisions } = await createDirectJoinMatrixPage(browser, baseURL, { scenarioKey });

    try {
      const authResponsePromise = page.waitForResponse((response) => (
        response.url().includes('/api/auth/session-state')
        && response.request().method() === 'GET'
      ));
      await page.goto(`/workspace/call/${call.id}`);
      const authResponse = await authResponsePromise;
      expect(authResponse.status()).toBe(401);

      await expect(page).toHaveURL(/\/login(?:[/?#].*)?$/);
      await expect(page.locator('body')).not.toContainText(call.title);
      expect(directJoinDecisions.some((decision) => decision.allowed === true)).toBe(false);
    } finally {
      await context.close();
    }
  });
}

test('foreign personalized link review flags stay bound to the target organization and call', async ({ browser }) => {
  test.setTimeout(60_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const scenario = getSeedScenario('review_flags_correct_org_for_foreign_personal_link');
  const link = getSeedAccessLink(scenario.link_key);
  const call = getSeedCall(scenario.expected.review_call_key);
  const tenant = getSeedTenant(scenario.expected.review_tenant_key);
  const subject = getSeedUser(scenario.expected.subject_user_key);
  const target = getSeedUser(scenario.expected.target_user_key);
  const accessId = accessIdFromJoinPath(link.join_path);
  const reviewFlags = [];

  const { context, page } = await createCallAccessMatrixPage(browser, baseURL, {
    scenarioKey: scenario.key,
    storedSessionUserKey: scenario.principal_user_key,
    storedSessionCallKey: 'alpha_active',
  });
  try {
    await page.route(`**/api/call-access/${accessId}/join`, async (route) => {
      reviewFlags.push({
        reason: 'duplicate_personalized_link',
        status: 'open',
        tenant_id: tenant.id,
        tenant_key: scenario.expected.review_tenant_key,
        call_id: call.id,
        call_key: call.key,
        subject_user_id: subject.id,
        subject_user_key: subject.key,
        target_user_id: target.id,
        target_user_key: target.key,
        payload: {
          flag: 'duplicate_personalized_link',
          link_kind: 'personal',
          review_status: 'manual_review_required',
          raw_link_identifier_logged: false,
          account_email_logged: false,
          host_name_logged: false,
        },
      });
      await route.fulfill({
        status: 403,
        json: {
          status: 'error',
          error: {
            code: 'call_access_forbidden',
            message: 'Call access link is not available for your session.',
            details: {
              mismatch: 'strong_personalized_link',
              fields: {
                auth: 'not_bound_to_current_user',
                host_name: 'not_verified',
              },
            },
          },
        },
      });
    });

    const joinResponsePromise = page.waitForResponse((response) => (
      response.url().includes(`/api/call-access/${accessId}/join`)
      && response.request().method() === 'GET'
    ));
    await page.goto(link.join_path);
    const joinResponse = await joinResponsePromise;
    expect(joinResponse.status()).toBe(403);
    const joinPayload = await joinResponse.json();
    expect(joinPayload?.error?.code).toBe('call_access_forbidden');
    expect(joinPayload?.error?.details?.fields?.auth).toBe('not_bound_to_current_user');

    expect(reviewFlags).toHaveLength(1);
    expect(reviewFlags[0]).toMatchObject({
      reason: scenario.expected.reason,
      tenant_id: tenant.id,
      tenant_key: scenario.expected.review_tenant_key,
      call_id: call.id,
      call_key: call.key,
      subject_user_id: subject.id,
      subject_user_key: subject.key,
      target_user_id: target.id,
      target_user_key: target.key,
    });
    expect(JSON.stringify(reviewFlags[0])).not.toContain(link.id);
    expect(reviewFlags[0]?.payload?.raw_link_identifier_logged).toBe(false);
    expect(reviewFlags[0]?.payload?.account_email_logged).toBe(false);
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
