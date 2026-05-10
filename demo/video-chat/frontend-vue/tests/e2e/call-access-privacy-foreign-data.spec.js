import { test, expect } from '@playwright/test';

import { installMediaDeviceShim } from './helpers/nativeAudioTransferHarness.js';

const sessionStorageKey = 'ii_videocall_v1_session';

function expectNoForeignData(text, values, label) {
  const lowerText = String(text || '').toLowerCase();
  for (const value of values) {
    const needle = String(value || '').trim().toLowerCase();
    if (needle === '') continue;
    expect(lowerText, `${label} must not contain ${value}`).not.toContain(needle);
  }
}

function parseJsonPostData(request) {
  const raw = request.postData();
  if (!raw) return null;
  try {
    return JSON.parse(raw);
  } catch {
    return null;
  }
}

async function createJoinPage(browser, baseURL) {
  const context = await browser.newContext({ baseURL, permissions: ['camera', 'microphone'] });
  await installMediaDeviceShim(context);
  const page = await context.newPage();
  return { context, page };
}

async function seedStoredSession(context, session) {
  await context.addInitScript(({ key, value }) => {
    localStorage.setItem(key, JSON.stringify(value));
  }, { key: sessionStorageKey, value: session });
}

async function readStoredSession(page) {
  return page.evaluate((key) => {
    try {
      return JSON.parse(localStorage.getItem(key) || '{}');
    } catch {
      return {};
    }
  }, sessionStorageKey);
}

function authSessionPayload(account) {
  return {
    status: 'ok',
    result: { state: 'authenticated' },
    session: {
      id: account.sessionId,
      token: account.sessionToken,
      expires_at: account.expiresAt,
    },
    user: {
      id: account.userId,
      email: account.email,
      display_name: account.displayName,
      role: 'user',
      status: 'active',
    },
    tenant: {
      id: 1,
      uuid: 'tenant-1',
      label: 'Intelligent Intern',
      role: 'member',
      permissions: { tenant_admin: false },
    },
  };
}

test('e2e_privacy_001_foreign_link_data_not_rendered and e2e_privacy_003_invalid_link_no_personal_data_leak', async ({ browser }) => {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const accessId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
  const foreignNeedles = [
    'Private Foreign Acquisition Call',
    'foreign-call-raw-id',
    'foreign-host@example.invalid',
    'Foreign Host Person',
    'foreign-invitee@example.invalid',
    'Foreign Invitee Person',
    'sess_invalid_foreign_should_not_bind',
  ];
  const { context, page } = await createJoinPage(browser, baseURL);
  let sessionPostCount = 0;

  try {
    await page.route('**/api/auth/session-state', async (route) => {
      await route.fulfill({
        status: 401,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'error',
          error: { code: 'auth_failed', message: 'A valid session token is required.' },
        }),
      });
    });

    await page.route(`**/api/call-access/${accessId}/join`, async (route) => {
      await route.fulfill({
        status: 404,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'error',
          error: {
            code: 'call_access_not_found',
            message: 'Call access link does not exist.',
          },
        }),
      });
    });

    await page.route(`**/api/call-access/${accessId}/session`, async (route) => {
      sessionPostCount += 1;
      await route.fulfill({
        status: 404,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'error',
          error: { code: 'call_access_not_found', message: 'Call access link does not exist.' },
        }),
      });
    });

    const joinResponsePromise = page.waitForResponse((response) => (
      response.url().includes(`/api/call-access/${accessId}/join`)
      && response.request().method() === 'GET'
    ));
    await page.goto(`/join/${accessId}`);
    const joinResponse = await joinResponsePromise;
    expect(joinResponse.status()).toBe(404);
    const joinBody = await joinResponse.text();
    expectNoForeignData(joinBody, foreignNeedles, 'invalid-link API response');
    expect(JSON.parse(joinBody)?.result).toBeUndefined();

    const joinDialog = page.getByRole('dialog', { name: 'Join video call' });
    await expect(joinDialog).toBeVisible({ timeout: 20_000 });
    await expect(joinDialog).toContainText(/call link is invalid|call access id is invalid/i);
    expectNoForeignData(await joinDialog.innerText(), foreignNeedles, 'invalid-link dialog');
    await expect(joinDialog.getByRole('button', { name: /^Join call$/ })).toHaveCount(0);
    expect(sessionPostCount).toBe(0);
    expect(page.url()).not.toContain('/workspace/call');
  } finally {
    await context.close();
  }
});

test('e2e_privacy_002_foreign_link_data_not_in_api_response, e2e_privacy_004_wrong_host_name_no_personal_data_leak, and e2e_privacy_005_browser_network_response_no_foreign_data', async ({ browser }) => {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const accessId = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
  const callId = 'privacy-safe-call';
  const safeCallTitle = 'Call Access Privacy Checkpoint';
  const wrongAccount = {
    userId: 33,
    email: 'current-user@example.invalid',
    displayName: 'Current Browser User',
    sessionId: 'sess_current_browser_user',
    sessionToken: 'sess_current_browser_user',
    expiresAt: '2026-09-01T10:00:00Z',
  };
  const foreignNeedles = [
    'Private Foreign Board Review',
    'foreign-board-review-call-id',
    'foreign-link-invitee@example.invalid',
    'Foreign Link Invitee',
    'private-foreign-host@example.invalid',
    'Private Foreign Host',
    'Definitely Wrong Host',
    'sess_foreign_denied_should_not_bind',
  ];
  const { context, page } = await createJoinPage(browser, baseURL);
  let sessionStateAuthorization = '';
  let sessionAuthorization = '';
  let sessionBody = null;
  let sessionPostCount = 0;

  try {
    await seedStoredSession(context, wrongAccount);

    await page.route('**/api/auth/session-state', async (route) => {
      sessionStateAuthorization = route.request().headers().authorization || '';
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(authSessionPayload(wrongAccount)),
      });
    });

    await page.route(`**/api/call-access/${accessId}/join`, async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'ok',
          result: {
            state: 'resolved',
            access_link: { id: accessId, target_user_id: 22 },
            link_kind: 'personal',
            call: {
              id: callId,
              room_id: 'lobby',
              title: safeCallTitle,
            },
            target_hint: { participant_email: null },
            join_path: `/join/${accessId}`,
          },
        }),
      });
    });

    await page.route(`**/api/call-access/${accessId}/session`, async (route) => {
      sessionPostCount += 1;
      sessionAuthorization = route.request().headers().authorization || '';
      sessionBody = parseJsonPostData(route.request());
      await route.fulfill({
        status: 403,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'error',
          error: {
            code: 'call_access_forbidden',
            message: 'Call access link is not available for your session.',
            details: {
              mismatch: 'strong_personalized_link',
              fields: {
                host_name: 'wrong_host_name',
              },
            },
          },
        }),
      });
    });

    const sessionStateResponsePromise = page.waitForResponse((response) => (
      response.url().includes('/api/auth/session-state')
      && response.request().method() === 'GET'
    ));
    const joinResponsePromise = page.waitForResponse((response) => (
      response.url().includes(`/api/call-access/${accessId}/join`)
      && response.request().method() === 'GET'
    ));
    await page.goto(`/join/${accessId}`);
    expect((await sessionStateResponsePromise).status()).toBe(200);
    expect(sessionStateAuthorization).toBe(`Bearer ${wrongAccount.sessionToken}`);

    const joinResponse = await joinResponsePromise;
    expect(joinResponse.status()).toBe(200);
    const joinBody = await joinResponse.text();
    expectNoForeignData(joinBody, foreignNeedles, 'strong-mismatch join API response');
    expect(JSON.parse(joinBody)?.result?.target_hint?.participant_email).toBeNull();

    const joinDialog = page.getByRole('dialog', { name: 'Join video call' });
    await expect(joinDialog).toBeVisible({ timeout: 20_000 });
    await expect(joinDialog).toContainText(safeCallTitle);
    expectNoForeignData(await joinDialog.innerText(), foreignNeedles, 'strong-mismatch join dialog');

    const sessionResponsePromise = page.waitForResponse((response) => (
      response.url().includes(`/api/call-access/${accessId}/session`)
      && response.request().method() === 'POST'
    ));
    await joinDialog.getByRole('button', { name: /^Join call$/ }).click();
    const sessionResponse = await sessionResponsePromise;
    expect(sessionResponse.status()).toBe(403);
    const sessionResponseBody = await sessionResponse.text();
    expectNoForeignData(sessionResponseBody, foreignNeedles, 'strong-mismatch browser network response');
    const sessionPayload = JSON.parse(sessionResponseBody);
    expect(sessionPayload?.error?.code).toBe('call_access_forbidden');
    expect(sessionPayload?.error?.details?.mismatch).toBe('strong_personalized_link');
    expect(sessionPayload?.error?.details?.fields?.host_name).toBe('wrong_host_name');

    expect(sessionPostCount).toBe(1);
    expect(sessionAuthorization).toBe(`Bearer ${wrongAccount.sessionToken}`);
    expect(sessionBody).toEqual({
      verified_user_id: wrongAccount.userId,
      verified_session_id: wrongAccount.sessionId,
    });
    expect(JSON.stringify(sessionBody || {})).not.toContain('host_name');

    await expect(joinDialog).toContainText('This call link is not available for your session.');
    await expect(joinDialog).not.toContainText(/Call owner has been notified|Waiting for host/i);
    expectNoForeignData(await joinDialog.innerText(), foreignNeedles, 'strong-mismatch denial dialog');
    expect(page.url()).toContain(`/join/${accessId}`);
    expect(page.url()).not.toContain('/workspace/call');

    const storedSession = await readStoredSession(page);
    expect(storedSession.sessionId).toBe(wrongAccount.sessionId);
    expect(storedSession.sessionToken).toBe(wrongAccount.sessionToken);
    expect(storedSession.sessionToken).not.toBe('sess_foreign_denied_should_not_bind');
  } finally {
    await context.close();
  }
});
