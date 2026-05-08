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
