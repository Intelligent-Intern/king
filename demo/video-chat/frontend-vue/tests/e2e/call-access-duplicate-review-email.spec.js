import { test, expect } from '@playwright/test';

import { installMediaDeviceShim } from './helpers/nativeAudioTransferHarness.js';

const sessionStorageKey = 'ii_videocall_v1_session';

async function createJoinPage(browser, baseURL) {
  const context = await browser.newContext({ baseURL, permissions: ['camera', 'microphone'] });
  await installMediaDeviceShim(context);
  const page = await context.newPage();
  return { context, page };
}

function storedSessionFor(account) {
  return {
    role: 'user',
    displayName: account.displayName,
    email: account.email,
    userId: account.userId,
    accountType: 'account',
    isGuest: false,
    sessionId: account.sessionId,
    sessionToken: account.sessionToken,
    expiresAt: '2026-09-01T10:00:00Z',
  };
}

function expectTextDoesNotContain(text, values, label) {
  const lowerText = String(text || '').toLowerCase();
  for (const value of values) {
    const needle = String(value || '').trim().toLowerCase();
    if (needle === '') continue;
    expect(lowerText, `${label} must not contain ${value}`).not.toContain(needle);
  }
}

async function routeSessionState(page, account, options = {}) {
  await page.route('**/api/auth/session-state', async (route) => {
    if (options.expired) {
      await route.fulfill({
        status: 401,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'error',
          error: {
            code: 'auth_failed',
            message: 'Session is no longer valid.',
            details: { reason: 'expired_session' },
          },
        }),
      });
      return;
    }

    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        status: 'ok',
        result: { state: 'authenticated' },
        session: {
          id: account.sessionId,
          token: account.sessionToken,
          expires_at: account.expiresAt || '2026-09-01T10:00:00Z',
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
      }),
    });
  });
}

async function routeResolvedJoin(page, accessId, callTitle, onJoin = null) {
  await page.route(`**/api/call-access/${accessId}/join`, async (route) => {
    if (typeof onJoin === 'function') onJoin(route.request());
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        status: 'ok',
        result: {
          state: 'resolved',
          access_link: { id: accessId },
          link_kind: 'personal',
          call: {
            id: 'pending-confirmation-call',
            room_id: 'lobby',
            title: callTitle,
          },
          target_hint: { participant_email: null },
          join_path: `/join/${accessId}`,
        },
      }),
    });
  });
}

test('duplicate personalized-link review flag stays private and keeps the current account session', async ({ browser }) => {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const accessId = '66666666-6666-4666-8666-666666666666';
  const currentAccount = {
    userId: 7,
    email: 'duplicate-current@example.invalid',
    displayName: 'Duplicate Current Account',
    sessionId: 'sess_duplicate_current',
    sessionToken: 'sess_duplicate_current',
  };
  const foreignNeedles = [
    'original-link-target@example.invalid',
    'Original Link Target',
    'private-host@example.invalid',
    'Private Link Host',
    'sess_original_link_target',
    accessId,
  ];

  const { context, page } = await createJoinPage(browser, baseURL);
  let sessionStateAuthorization = '';
  let joinGetCount = 0;

  try {
    await context.addInitScript(({ key, session }) => {
      localStorage.setItem(key, JSON.stringify(session));
    }, { key: sessionStorageKey, session: storedSessionFor(currentAccount) });

    await page.route('**/api/auth/session-state', async (route) => {
      sessionStateAuthorization = route.request().headers().authorization || '';
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'ok',
          result: { state: 'authenticated' },
          session: {
            id: currentAccount.sessionId,
            token: currentAccount.sessionToken,
            expires_at: '2026-09-01T10:00:00Z',
          },
          user: {
            id: currentAccount.userId,
            email: currentAccount.email,
            display_name: currentAccount.displayName,
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
        }),
      });
    });

    await page.route(`**/api/call-access/${accessId}/join`, async (route) => {
      joinGetCount += 1;
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
                auth: 'not_bound_to_current_user',
                host_name: 'not_verified',
              },
              review: {
                flag: 'duplicate_personalized_link',
                state: 'manual_review_required',
                call_id: 'duplicate-review-call',
                access_fingerprint: 'sha256:duplicate-access-fingerprint',
                subject_user_id: currentAccount.userId,
                affected_user_ref: 'sha256:original-link-target',
                raw_link_identifier_logged: false,
                account_email_logged: false,
              },
            },
          },
        }),
      });
    });

    const joinResponsePromise = page.waitForResponse((response) => (
      response.url().includes(`/api/call-access/${accessId}/join`)
      && response.request().method() === 'GET'
    ));
    await page.goto(`/join/${accessId}`);
    const joinResponse = await joinResponsePromise;
    expect(joinResponse.status()).toBe(403);
    expect(sessionStateAuthorization).toBe(`Bearer ${currentAccount.sessionToken}`);

    const joinBody = await joinResponse.text();
    expect(joinBody).toContain('duplicate_personalized_link');
    expect(joinBody).toContain('access_fingerprint');
    expect(joinBody).not.toContain('"access_id"');
    expectTextDoesNotContain(joinBody, foreignNeedles, 'duplicate review response');

    const joinDialog = page.getByRole('dialog', { name: 'Join video call' });
    await expect(joinDialog).toBeVisible({ timeout: 20_000 });
    await expect(joinDialog.getByRole('button', { name: /^Join call$/ })).toHaveCount(0);
    for (const value of foreignNeedles) {
      await expect(joinDialog, `duplicate review dialog must not render ${value}`).not.toContainText(value);
    }

    const storedSession = await page.evaluate((key) => JSON.parse(localStorage.getItem(key) || '{}'), sessionStorageKey);
    expect(storedSession.sessionId).toBe(currentAccount.sessionId);
    expect(storedSession.sessionToken).toBe(currentAccount.sessionToken);
    expect(page.url()).toContain(`/join/${accessId}`);
    expect(page.url()).not.toContain('/workspace/call');
    expect(joinGetCount).toBe(1);
  } finally {
    await context.close();
  }
});

test('pending email confirmation confirms in another browser and rejects expired session safely', async ({ browser }) => {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const accessId = '88888888-8888-4888-8888-888888888888';
  const currentAccountA = {
    userId: 9,
    email: 'pending-current@example.invalid',
    displayName: 'Pending Current Account',
    sessionId: 'sess_pending_current_a',
    sessionToken: 'sess_pending_current_a',
  };
  const currentAccountB = {
    ...currentAccountA,
    sessionId: 'sess_pending_current_b',
    sessionToken: 'sess_pending_current_b',
  };
  const expiredAccount = {
    ...currentAccountA,
    sessionId: 'sess_pending_current_expired',
    sessionToken: 'sess_pending_current_expired',
    expiresAt: '2020-01-01T00:00:00Z',
  };
  const wrongAccount = {
    userId: 10,
    email: 'pending-wrong@example.invalid',
    displayName: 'Pending Wrong Account',
    sessionId: 'sess_pending_wrong_account',
    sessionToken: 'sess_pending_wrong_account',
  };
  const confirmationToken = 'cau_pending_cross_browser_token';
  const foreignNeedles = [
    'pending-link-target@example.invalid',
    'Pending Link Target',
    'pending-host@example.invalid',
    'Pending Private Host',
    'sess_pending_link_target',
    accessId,
  ];
  const contexts = [];
  let joinGetCount = 0;
  let sessionPostCount = 0;
  let tokenConsumed = false;
  const confirmationRequests = [];
  const confirmRequests = [];

  async function accountPage(account, options = {}) {
    const entry = await createJoinPage(browser, baseURL);
    contexts.push(entry.context);
    await entry.context.addInitScript(({ key, session }) => {
      localStorage.setItem(key, JSON.stringify(session));
    }, { key: sessionStorageKey, session: storedSessionFor(account) });
    await routeSessionState(entry.page, account, options);
    await routeResolvedJoin(entry.page, accessId, 'Pending Confirmation Call', () => {
      joinGetCount += 1;
    });
    await entry.page.route(`**/api/call-access/${accessId}/session`, async (route) => {
      sessionPostCount += 1;
      await route.fulfill({
        status: 500,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'error',
          error: { code: 'unexpected_session_start', message: 'Session start is not part of this pending-confirmation flow.' },
        }),
      });
    });
    await entry.page.route(`**/api/call-access/${accessId}/account-update-confirmation`, async (route) => {
      const request = route.request();
      confirmationRequests.push({
        authorization: request.headers().authorization || '',
        body: JSON.parse(request.postData() || '{}'),
      });
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'ok',
          result: {
            state: 'pending_confirmation',
            recipient_email: currentAccountA.email,
            recipient_user_id: currentAccountA.userId,
            sent_to_logged_in_account: true,
            sent_to_link_account: false,
            debug_confirmation_token: confirmationToken,
          },
        }),
      });
    });
    await entry.page.route('**/api/call-access/account-update-confirmations/*/confirm', async (route) => {
      const authorization = route.request().headers().authorization || '';
      confirmRequests.push({ authorization });
      if (authorization === `Bearer ${expiredAccount.sessionToken}`) {
        await route.fulfill({
          status: 401,
          contentType: 'application/json',
          body: JSON.stringify({
            status: 'error',
            error: {
              code: 'auth_failed',
              message: 'A valid session token is required.',
              details: { reason: 'expired_session' },
            },
          }),
        });
        return;
      }
      if (authorization === `Bearer ${wrongAccount.sessionToken}`) {
        await route.fulfill({
          status: 403,
          contentType: 'application/json',
          body: JSON.stringify({
            status: 'error',
            error: {
              code: 'call_access_forbidden',
              message: 'Confirmation token is not available for your account.',
              details: { fields: { token: 'account_bound' } },
            },
          }),
        });
        return;
      }
      if (tokenConsumed) {
        await route.fulfill({
          status: 409,
          contentType: 'application/json',
          body: JSON.stringify({
            status: 'error',
            error: {
              code: 'call_access_conflict',
              message: 'Confirmation token has already been used.',
              details: { fields: { token: 'already_consumed' } },
            },
          }),
        });
        return;
      }
      expect(authorization).toBe(`Bearer ${currentAccountB.sessionToken}`);
      tokenConsumed = true;
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'ok',
          result: {
            state: 'confirmed',
            user: {
              id: currentAccountA.userId,
              email: currentAccountA.email,
              display_name: 'Pending Browser Confirmed Name',
              role: 'user',
              status: 'active',
            },
          },
        }),
      });
    });
    return entry;
  }

  try {
    const browserA = await accountPage(currentAccountA);
    await browserA.page.goto(`/join/${accessId}`);
    const joinDialog = browserA.page.getByRole('dialog', { name: 'Join video call' });
    await expect(joinDialog).toBeVisible({ timeout: 20_000 });
    await expect(joinDialog).toContainText('Pending Confirmation Call');

    const pendingResult = await browserA.page.evaluate(async ({ id, token }) => {
      const response = await fetch(`/api/call-access/${id}/account-update-confirmation`, {
        method: 'POST',
        headers: { accept: 'application/json', 'content-type': 'application/json', authorization: `Bearer ${token}` },
        body: JSON.stringify({ display_name: 'Pending Browser Confirmed Name' }),
      });
      return { status: response.status, payload: await response.json() };
    }, { id: accessId, token: currentAccountA.sessionToken });
    expect(pendingResult.status).toBe(200);
    expect(pendingResult.payload.result.state).toBe('pending_confirmation');
    expect(pendingResult.payload.result.sent_to_logged_in_account).toBe(true);
    expect(pendingResult.payload.result.sent_to_link_account).toBe(false);
    expect(confirmationRequests).toEqual([{
      authorization: `Bearer ${currentAccountA.sessionToken}`,
      body: { display_name: 'Pending Browser Confirmed Name' },
    }]);
    expectTextDoesNotContain(JSON.stringify(pendingResult), foreignNeedles, 'pending confirmation response');

    const storedAfterPending = await browserA.page.evaluate((key) => JSON.parse(localStorage.getItem(key) || '{}'), sessionStorageKey);
    expect(storedAfterPending.sessionId).toBe(currentAccountA.sessionId);
    expect(storedAfterPending.sessionToken).toBe(currentAccountA.sessionToken);
    expect(sessionPostCount).toBe(0);

    await browserA.page.reload();
    await expect(joinDialog).toBeVisible({ timeout: 20_000 });
    expect(joinGetCount).toBe(2);

    const expired = await accountPage(expiredAccount, { expired: true });
    await expired.page.goto(`/join/${accessId}`);
    const expiredResult = await expired.page.evaluate(async ({ token }) => {
      const response = await fetch('/api/call-access/account-update-confirmations/cau_pending_cross_browser_token/confirm', {
        method: 'POST',
        headers: { accept: 'application/json', authorization: `Bearer ${token}` },
      });
      return { status: response.status, payload: await response.json() };
    }, { token: expiredAccount.sessionToken });
    expect(expiredResult.status).toBe(401);
    expect(expiredResult.payload.error.details.reason).toBe('expired_session');
    expectTextDoesNotContain(JSON.stringify(expiredResult), foreignNeedles, 'expired pending confirmation response');

    const wrong = await accountPage(wrongAccount);
    await wrong.page.goto(`/join/${accessId}`);
    const wrongResult = await wrong.page.evaluate(async ({ token }) => {
      const response = await fetch('/api/call-access/account-update-confirmations/cau_pending_cross_browser_token/confirm', {
        method: 'POST',
        headers: { accept: 'application/json', authorization: `Bearer ${token}` },
      });
      return { status: response.status, payload: await response.json() };
    }, { token: wrongAccount.sessionToken });
    expect(wrongResult.status).toBe(403);
    expect(wrongResult.payload.error.details.fields.token).toBe('account_bound');
    expectTextDoesNotContain(JSON.stringify(wrongResult), foreignNeedles, 'wrong-account confirmation response');

    const browserB = await accountPage(currentAccountB);
    await browserB.page.goto(`/join/${accessId}`);
    const confirmResult = await browserB.page.evaluate(async ({ token }) => {
      const response = await fetch('/api/call-access/account-update-confirmations/cau_pending_cross_browser_token/confirm', {
        method: 'POST',
        headers: { accept: 'application/json', authorization: `Bearer ${token}` },
      });
      return { status: response.status, payload: await response.json() };
    }, { token: currentAccountB.sessionToken });
    expect(confirmResult.status).toBe(200);
    expect(confirmResult.payload.result.user.id).toBe(currentAccountA.userId);
    expect(confirmResult.payload.result.user.email).toBe(currentAccountA.email);
    expectTextDoesNotContain(JSON.stringify(confirmResult), foreignNeedles, 'browser-b confirmation response');

    const storedBrowserB = await browserB.page.evaluate((key) => JSON.parse(localStorage.getItem(key) || '{}'), sessionStorageKey);
    expect(storedBrowserB.sessionId).toBe(currentAccountB.sessionId);
    expect(storedBrowserB.sessionToken).toBe(currentAccountB.sessionToken);
    expect(storedBrowserB.userId).toBe(currentAccountA.userId);
    expect(JSON.stringify(storedBrowserB)).not.toContain('pending-link-target');

    const replayResult = await browserB.page.evaluate(async ({ token }) => {
      const response = await fetch('/api/call-access/account-update-confirmations/cau_pending_cross_browser_token/confirm', {
        method: 'POST',
        headers: { accept: 'application/json', authorization: `Bearer ${token}` },
      });
      return { status: response.status, payload: await response.json() };
    }, { token: currentAccountB.sessionToken });
    expect(replayResult.status).toBe(409);
    expect(replayResult.payload.error.details.fields.token).toBe('already_consumed');
    expect(confirmRequests.map((entry) => entry.authorization)).toEqual([
      `Bearer ${expiredAccount.sessionToken}`,
      `Bearer ${wrongAccount.sessionToken}`,
      `Bearer ${currentAccountB.sessionToken}`,
      `Bearer ${currentAccountB.sessionToken}`,
    ]);
    expect(sessionPostCount).toBe(0);
    expect(browserA.page.url()).toContain(`/join/${accessId}`);
    expect(browserB.page.url()).toContain(`/join/${accessId}`);
  } finally {
    await Promise.all(contexts.map((context) => context.close()));
  }
});

test('email confirmation request is rate-limited and does not rebind before confirmation', async ({ browser }) => {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const accessId = '77777777-7777-4777-8777-777777777777';
  const currentAccount = {
    userId: 8,
    email: 'confirmation-current@example.invalid',
    displayName: 'Confirmation Current Account',
    sessionId: 'sess_confirmation_current_e2e',
    sessionToken: 'sess_confirmation_current_e2e',
  };
  const foreignNeedles = [
    'confirmation-link-target@example.invalid',
    'Confirmation Link Target',
    'confirmation-host@example.invalid',
    'Confirmation Host',
    'sess_confirmation_link_target_e2e',
  ];

  const { context, page } = await createJoinPage(browser, baseURL);
  const confirmationRequests = [];
  const confirmationResponses = [
    { status: 200, token: 'cau_first_confirmation_token' },
    { status: 200, token: 'cau_second_confirmation_token' },
    { status: 429, token: '' },
  ];

  try {
    await context.addInitScript(({ key, session }) => {
      localStorage.setItem(key, JSON.stringify(session));
    }, { key: sessionStorageKey, session: storedSessionFor(currentAccount) });

    await page.route('**/api/auth/session-state', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'ok',
          result: { state: 'authenticated' },
          session: {
            id: currentAccount.sessionId,
            token: currentAccount.sessionToken,
            expires_at: '2026-09-01T10:00:00Z',
          },
          user: {
            id: currentAccount.userId,
            email: currentAccount.email,
            display_name: currentAccount.displayName,
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
        }),
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
            access_link: { id: accessId },
            link_kind: 'personal',
            call: {
              id: 'confirmation-review-call',
              room_id: 'lobby',
              title: 'Confirmation Review Call',
            },
            target_hint: { participant_email: null },
            join_path: `/join/${accessId}`,
          },
        }),
      });
    });

    await page.route(`**/api/call-access/${accessId}/account-update-confirmation`, async (route) => {
      const request = route.request();
      const requestBody = JSON.parse(request.postData() || '{}');
      confirmationRequests.push({
        authorization: request.headers().authorization || '',
        body: requestBody,
      });
      const response = confirmationResponses[Math.min(confirmationRequests.length - 1, confirmationResponses.length - 1)];
      if (response.status === 429) {
        await route.fulfill({
          status: 429,
          contentType: 'application/json',
          body: JSON.stringify({
            status: 'error',
            error: {
              code: 'call_access_rate_limited',
              message: 'Account update confirmation is rate-limited.',
              details: {
                fields: { confirmation: 'rate_limited' },
                retry_after_seconds: 900,
              },
            },
          }),
        });
        return;
      }

      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'ok',
          result: {
            state: 'pending_confirmation',
            recipient_email: currentAccount.email,
            recipient_user_id: currentAccount.userId,
            sent_to_logged_in_account: true,
            sent_to_link_account: false,
            debug_confirmation_token: response.token,
          },
        }),
      });
    });

    await page.route('**/api/call-access/account-update-confirmations/*/confirm', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'ok',
          result: {
            state: 'confirmed',
            user: {
              id: currentAccount.userId,
              email: currentAccount.email,
              display_name: 'Manually Re Entered Name',
              role: 'user',
              status: 'active',
            },
          },
        }),
      });
    });

    await page.goto(`/join/${accessId}`);
    const joinDialog = page.getByRole('dialog', { name: 'Join video call' });
    await expect(joinDialog).toBeVisible({ timeout: 20_000 });
    await expect(joinDialog).toContainText('Confirmation Review Call');

    const requestResults = await page.evaluate(async ({ id, token }) => {
      const first = await fetch(`/api/call-access/${id}/account-update-confirmation`, {
        method: 'POST',
        headers: { accept: 'application/json', 'content-type': 'application/json', authorization: `Bearer ${token}` },
        body: JSON.stringify({ display_name: 'Manually Re Entered Name' }),
      });
      const firstPayload = await first.json();
      const second = await fetch(`/api/call-access/${id}/account-update-confirmation`, {
        method: 'POST',
        headers: { accept: 'application/json', 'content-type': 'application/json', authorization: `Bearer ${token}` },
        body: JSON.stringify({ first_name: 'Second', last_name: 'Manual Entry' }),
      });
      const secondPayload = await second.json();
      const third = await fetch(`/api/call-access/${id}/account-update-confirmation`, {
        method: 'POST',
        headers: { accept: 'application/json', 'content-type': 'application/json', authorization: `Bearer ${token}` },
        body: JSON.stringify({ display_name: 'Should Be Rate Limited' }),
      });
      const thirdPayload = await third.json();
      const confirm = await fetch(`/api/call-access/account-update-confirmations/${firstPayload.result.debug_confirmation_token}/confirm`, {
        method: 'POST',
        headers: { accept: 'application/json', authorization: `Bearer ${token}` },
      });
      const confirmPayload = await confirm.json();
      return {
        firstStatus: first.status,
        firstPayload,
        secondStatus: second.status,
        secondPayload,
        thirdStatus: third.status,
        thirdPayload,
        confirmStatus: confirm.status,
        confirmPayload,
      };
    }, { id: accessId, token: currentAccount.sessionToken });

    expect(requestResults.firstStatus).toBe(200);
    expect(requestResults.firstPayload.result.recipient_email).toBe(currentAccount.email);
    expect(requestResults.firstPayload.result.sent_to_logged_in_account).toBe(true);
    expect(requestResults.firstPayload.result.sent_to_link_account).toBe(false);
    expect(requestResults.secondStatus).toBe(200);
    expect(requestResults.thirdStatus).toBe(429);
    expect(requestResults.thirdPayload.error.details.fields.confirmation).toBe('rate_limited');
    expect(requestResults.confirmStatus).toBe(200);
    expect(requestResults.confirmPayload.result.user.id).toBe(currentAccount.userId);

    expect(confirmationRequests).toHaveLength(3);
    for (const request of confirmationRequests) {
      expect(request.authorization).toBe(`Bearer ${currentAccount.sessionToken}`);
      expect(JSON.stringify(request.body)).not.toContain('confirmation-link-target');
      expect(JSON.stringify(request.body)).not.toContain('confirmation-host');
    }
    expect(confirmationRequests[0].body).toEqual({ display_name: 'Manually Re Entered Name' });
    expect(confirmationRequests[1].body).toEqual({ first_name: 'Second', last_name: 'Manual Entry' });

    const allResponseText = JSON.stringify(requestResults);
    expectTextDoesNotContain(allResponseText, foreignNeedles, 'email confirmation responses');

    const storedSession = await page.evaluate((key) => JSON.parse(localStorage.getItem(key) || '{}'), sessionStorageKey);
    expect(storedSession.sessionId).toBe(currentAccount.sessionId);
    expect(storedSession.sessionToken).toBe(currentAccount.sessionToken);
    expect(JSON.stringify(storedSession)).not.toContain('confirmation-link-target');
    expect(storedSession.sessionToken).not.toBe('sess_confirmation_link_target_e2e');
  } finally {
    await context.close();
  }
});
