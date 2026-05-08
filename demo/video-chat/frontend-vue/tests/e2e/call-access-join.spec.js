import { test, expect } from '@playwright/test';

import {
  adminCredentials,
  createAuthenticatedPage,
  createInvitedCallViaApi,
  createPersonalAccessJoinPath,
  installMediaDeviceShim,
} from './helpers/nativeAudioTransferHarness.js';

const sessionStorageKey = 'ii_videocall_v1_session';

function accessIdFromJoinPath(joinPath) {
  const match = String(joinPath || '').match(/\/join\/([a-f0-9-]{36})(?:[/?#].*)?$/i);
  return match ? match[1].toLowerCase() : '';
}

function parseJsonPostData(request) {
  try {
    return JSON.parse(request.postData() || '{}');
  } catch {
    return null;
  }
}

function expectTextDoesNotContain(text, values, label) {
  const lowerText = String(text || '').toLowerCase();
  for (const value of values) {
    const needle = String(value || '').trim().toLowerCase();
    if (needle === '') continue;
    expect(lowerText, `${label} must not contain ${value}`).not.toContain(needle);
  }
}

async function createPublicJoinPage(browser, baseURL) {
  const context = await browser.newContext({ baseURL, permissions: ['camera', 'microphone'] });
  await installMediaDeviceShim(context);
  const page = await context.newPage();
  return { context, page };
}

test('personal call-access link starts a call-scoped session and waits for host admission', async ({ browser }) => {
  test.setTimeout(90_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const participantUserId = 2;
  const callTitle = `E2E Call Access ${Date.now()}`;

  const { context: adminContext, storedSession: adminSession } = await createAuthenticatedPage(
    browser,
    baseURL,
    adminCredentials,
  );
  const { context: publicContext, page } = await createPublicJoinPage(browser, baseURL);

  try {
    const callId = await createInvitedCallViaApi({
      sessionToken: adminSession.sessionToken,
      title: callTitle,
      participantUserId,
    });
    const joinPath = await createPersonalAccessJoinPath({
      callId,
      sessionToken: adminSession.sessionToken,
      participantUserId,
    });
    const accessId = accessIdFromJoinPath(joinPath);
    expect(accessId, 'join path must contain the backend-issued access id').not.toBe('');

    const joinResponsePromise = page.waitForResponse((response) => (
      response.url().includes(`/api/call-access/${accessId}/join`)
      && response.request().method() === 'GET'
    ));
    await page.goto(joinPath);
    const joinResponse = await joinResponsePromise;
    expect(joinResponse.status()).toBe(200);
    const joinPayload = await joinResponse.json();
    expect(joinPayload?.status).toBe('ok');
    expect(joinPayload?.result?.link_kind).toBe('personal');
    expect(joinPayload?.result?.call?.id).toBe(callId);

    const joinDialog = page.getByRole('dialog', { name: 'Join video call' });
    await expect(joinDialog).toBeVisible({ timeout: 20_000 });
    await expect(joinDialog).toContainText(callTitle);
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
    expect(sessionPayload?.result?.user?.id).toBe(participantUserId);
    expect(sessionPayload?.result?.call?.id).toBe(callId);
    expect(sessionPayload?.result?.tenant?.permissions?.tenant_admin ?? false).toBe(false);

    await expect(joinDialog).toContainText(/Call owner has been notified|Waiting for host/i, { timeout: 20_000 });

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
    await Promise.allSettled([
      adminContext.close(),
      publicContext.close(),
    ]);
  }
});

test('invalid call-access link renders safe state without foreign call data', async ({ browser }) => {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const foreignTitle = `Private Foreign Call ${Date.now()}`;
  const foreignEmail = `private-${Date.now()}@example.invalid`;
  const guessedAccessId = '11111111-1111-4111-8111-111111111111';

  const { context, page } = await createPublicJoinPage(browser, baseURL);

  try {
    await page.route('**/api/call-access/*/join', async (route) => {
      await route.fulfill({
        status: 404,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'error',
          error: {
            code: 'call_access_not_found',
            message: `No access for ${foreignEmail}`,
          },
          result: {
            call: {
              id: 'foreign-call-id',
              title: foreignTitle,
              owner: {
                email: foreignEmail,
                name: 'Private Owner',
              },
            },
          },
        }),
      });
    });

    await page.goto(`/join/${guessedAccessId}`);

    const joinDialog = page.getByRole('dialog', { name: 'Join video call' });
    await expect(joinDialog).toBeVisible();
    await expect(joinDialog).toContainText(/call link is invalid|call access id is invalid/i);
    await expect(joinDialog).not.toContainText(foreignTitle);
    await expect(joinDialog).not.toContainText(foreignEmail);
    await expect(joinDialog.getByRole('button', { name: /^Join call$/ })).toHaveCount(0);
  } finally {
    await context.close();
  }
});

test('login switch after verified call-access link fails without rebinding or leaking foreign data', async ({ browser }) => {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const accessId = '22222222-2222-4222-8222-222222222222';
  const callId = 'call-access-login-switch-call';
  const callTitle = 'Verified Link Call';
  const foreignTitle = 'Foreign Switched Account Call';
  const foreignEmail = 'foreign-switch@example.invalid';
  const rejectedCallAccessToken = 'sess_foreign_call_access_should_not_bind';
  const verifiedSession = {
    sessionId: 'sess_verified_standard',
    sessionToken: 'sess_verified_standard',
    expiresAt: '2026-09-01T10:00:00Z',
  };
  const switchedSession = {
    sessionId: 'sess_current_admin',
    sessionToken: 'sess_current_admin',
    expiresAt: '2026-09-01T10:00:00Z',
  };

  const { context, page } = await createPublicJoinPage(browser, baseURL);
  let sessionStateRequestAuthorization = '';
  let joinGetCount = 0;
  let sessionPostCount = 0;
  let sessionRequestAuthorization = '';
  let sessionRequestBody = null;

  try {
    await context.addInitScript(({ key, session }) => {
      localStorage.setItem(key, JSON.stringify(session));
    }, { key: sessionStorageKey, session: verifiedSession });

    await page.route('**/api/auth/session-state', async (route) => {
      sessionStateRequestAuthorization = route.request().headers().authorization || '';
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'ok',
          result: { state: 'authenticated' },
          session: {
            id: verifiedSession.sessionId,
            token: verifiedSession.sessionToken,
            expires_at: verifiedSession.expiresAt,
          },
          user: {
            id: 2,
            email: 'user@intelligent-intern.com',
            display_name: 'Standard Verified User',
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
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'ok',
          result: {
            link_kind: 'personal',
            call: {
              id: callId,
              room_id: 'lobby',
              title: callTitle,
            },
            access_link: {
              id: accessId,
              target_user_id: 2,
            },
          },
        }),
      });
    });

    await page.route(`**/api/call-access/${accessId}/session`, async (route) => {
      sessionPostCount += 1;
      sessionRequestAuthorization = route.request().headers().authorization || '';
      sessionRequestBody = parseJsonPostData(route.request());
      await route.fulfill({
        status: 409,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'error',
          error: {
            code: 'call_access_conflict',
            message: `Rejected switched account for ${foreignEmail}`,
          },
          result: {
            session: {
              id: rejectedCallAccessToken,
              token: rejectedCallAccessToken,
              expires_at: '2026-09-01T10:05:00Z',
            },
            user: {
              id: 99,
              email: foreignEmail,
              display_name: 'Foreign Switched User',
              role: 'user',
            },
            call: {
              id: 'foreign-call-id',
              title: foreignTitle,
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
    expect(joinResponse.status()).toBe(200);
    expect(sessionStateRequestAuthorization).toBe(`Bearer ${verifiedSession.sessionToken}`);

    const joinDialog = page.getByRole('dialog', { name: 'Join video call' });
    await expect(joinDialog).toBeVisible({ timeout: 20_000 });
    await expect(joinDialog).toContainText(callTitle);
    await expect(joinDialog).toContainText('Personalized link');

    const switchedSnapshot = await page.evaluate(async ({ key, session }) => {
      const { sessionState } = await import('/src/domain/auth/session.ts');
      sessionState.role = 'admin';
      sessionState.displayName = 'Switched Admin';
      sessionState.email = 'admin@intelligent-intern.com';
      sessionState.userId = 1;
      sessionState.accountType = 'account';
      sessionState.isGuest = false;
      sessionState.sessionId = session.sessionId;
      sessionState.sessionToken = session.sessionToken;
      sessionState.expiresAt = session.expiresAt;
      sessionState.recovered = true;
      localStorage.setItem(key, JSON.stringify(session));
      return {
        userId: sessionState.userId,
        sessionId: sessionState.sessionId,
        sessionToken: sessionState.sessionToken,
      };
    }, { key: sessionStorageKey, session: switchedSession });
    expect(switchedSnapshot).toEqual({
      userId: 1,
      sessionId: switchedSession.sessionId,
      sessionToken: switchedSession.sessionToken,
    });

    const sessionResponsePromise = page.waitForResponse((response) => (
      response.url().includes(`/api/call-access/${accessId}/session`)
      && response.request().method() === 'POST'
    ));
    await joinDialog.getByRole('button', { name: /^Join call$/ }).click();
    const sessionResponse = await sessionResponsePromise;
    expect(sessionResponse.status()).toBe(409);
    const sessionPayload = await sessionResponse.json();
    expect(sessionPayload?.error?.code).toBe('call_access_conflict');

    expect(sessionPostCount).toBe(1);
    expect(sessionRequestAuthorization).toBe(`Bearer ${switchedSession.sessionToken}`);
    expect(sessionRequestBody).toEqual({
      verified_user_id: 2,
      verified_session_id: verifiedSession.sessionId,
    });

    await expect(joinDialog).toContainText('This call link cannot be used for the current call state.');
    await expect(joinDialog).not.toContainText(foreignTitle);
    await expect(joinDialog).not.toContainText(foreignEmail);
    await expect(joinDialog).not.toContainText(rejectedCallAccessToken);

    const storedSession = await page.evaluate((key) => {
      try {
        return JSON.parse(localStorage.getItem(key) || '{}');
      } catch {
        return {};
      }
    }, sessionStorageKey);
    expect(storedSession.sessionId).toBe(switchedSession.sessionId);
    expect(storedSession.sessionToken).toBe(switchedSession.sessionToken);
    expect(storedSession.sessionToken).not.toBe(rejectedCallAccessToken);
    expect(page.url()).toContain(`/join/${accessId}`);

    await page.waitForTimeout(300);
    expect(joinGetCount).toBe(1);
    expect(sessionPostCount).toBe(1);
  } finally {
    await context.close();
  }
});

test('strong personalized-link mismatch wrong host denial gives no access and leaks no foreign person data', async ({ browser }) => {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const accessId = '33333333-3333-4333-8333-333333333333';
  const callId = 'strong-mismatch-call';
  const safeCallTitle = 'Strong Mismatch Waiting Room';
  const wrongHostName = 'Definitely Wrong Host';
  const linkInviteeName = 'Foreign Link Invitee';
  const linkInviteeEmail = 'foreign-link-invitee@example.invalid';
  const realHostName = 'Private Foreign Host';
  const realHostEmail = 'private-host@example.invalid';
  const deniedSessionToken = 'sess_denied_strong_mismatch_should_not_bind';
  const wrongLoggedInUserId = 3;
  const wrongLoggedInSession = {
    sessionId: 'sess_wrong_logged_in_user',
    sessionToken: 'sess_wrong_logged_in_user',
    expiresAt: '2026-09-01T10:00:00Z',
  };
  const foreignNeedles = [
    linkInviteeName,
    linkInviteeEmail,
    realHostName,
    realHostEmail,
    deniedSessionToken,
  ];

  const { context, page } = await createPublicJoinPage(browser, baseURL);
  let sessionStateRequestAuthorization = '';
  let joinGetCount = 0;
  let sessionPostCount = 0;
  let sessionRequestAuthorization = '';
  let sessionRequestBody = null;

  try {
    await context.addInitScript(({ key, session }) => {
      localStorage.setItem(key, JSON.stringify(session));
    }, { key: sessionStorageKey, session: wrongLoggedInSession });

    await page.route('**/api/auth/session-state', async (route) => {
      sessionStateRequestAuthorization = route.request().headers().authorization || '';
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'ok',
          result: { state: 'authenticated' },
          session: {
            id: wrongLoggedInSession.sessionId,
            token: wrongLoggedInSession.sessionToken,
            expires_at: wrongLoggedInSession.expiresAt,
          },
          user: {
            id: wrongLoggedInUserId,
            email: 'wrong-current-user@example.invalid',
            display_name: 'Wrong Current User',
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
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'ok',
          result: {
            state: 'resolved',
            access_link: { id: accessId },
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
      sessionRequestAuthorization = route.request().headers().authorization || '';
      sessionRequestBody = parseJsonPostData(route.request());
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

    const joinResponsePromise = page.waitForResponse((response) => (
      response.url().includes(`/api/call-access/${accessId}/join`)
      && response.request().method() === 'GET'
    ));
    await page.goto(`/join/${accessId}`);
    const joinResponse = await joinResponsePromise;
    expect(joinResponse.status()).toBe(200);
    expect(sessionStateRequestAuthorization).toBe(`Bearer ${wrongLoggedInSession.sessionToken}`);
    const joinBody = await joinResponse.text();
    expectTextDoesNotContain(joinBody, foreignNeedles, 'strong-mismatch join response');

    const joinDialog = page.getByRole('dialog', { name: 'Join video call' });
    await expect(joinDialog).toBeVisible({ timeout: 20_000 });
    await expect(joinDialog).toContainText(safeCallTitle);
    await expect(joinDialog).toContainText('Personalized link');
    for (const value of foreignNeedles) {
      await expect(joinDialog, `dialog must not render ${value}`).not.toContainText(value);
    }

    const sessionResponsePromise = page.waitForResponse((response) => (
      response.url().includes(`/api/call-access/${accessId}/session`)
      && response.request().method() === 'POST'
    ));
    await joinDialog.getByRole('button', { name: /^Join call$/ }).click();
    const sessionResponse = await sessionResponsePromise;
    expect(sessionResponse.status()).toBe(403);
    const sessionBody = await sessionResponse.text();
    expectTextDoesNotContain(sessionBody, foreignNeedles, 'strong-mismatch wrong-host denial response');
    const sessionPayload = JSON.parse(sessionBody);
    expect(sessionPayload?.error?.code).toBe('call_access_forbidden');
    expect(sessionPayload?.error?.details?.mismatch).toBe('strong_personalized_link');
    expect(sessionPayload?.error?.details?.fields?.host_name).toBe('wrong_host_name');

    expect(sessionPostCount).toBe(1);
    expect(sessionRequestAuthorization).toBe(`Bearer ${wrongLoggedInSession.sessionToken}`);
    expect(sessionRequestBody).toEqual({
      verified_user_id: wrongLoggedInUserId,
      verified_session_id: wrongLoggedInSession.sessionId,
    });

    await expect(joinDialog).toContainText('This call link is not available for your session.');
    await expect(joinDialog).not.toContainText(/Call owner has been notified|Waiting for host/i);
    for (const value of [...foreignNeedles, wrongHostName]) {
      await expect(joinDialog, `dialog denial must not render ${value}`).not.toContainText(value);
    }
    expect(page.url()).toContain(`/join/${accessId}`);
    expect(page.url()).not.toContain('/workspace/call');

    const storedSession = await page.evaluate((key) => {
      try {
        return JSON.parse(localStorage.getItem(key) || '{}');
      } catch {
        return {};
      }
    }, sessionStorageKey);
    expect(storedSession.sessionId).toBe(wrongLoggedInSession.sessionId);
    expect(storedSession.sessionToken).toBe(wrongLoggedInSession.sessionToken);
    expect(storedSession.sessionToken).not.toBe(deniedSessionToken);

    await page.waitForTimeout(300);
    expect(joinGetCount).toBe(1);
    expect(sessionPostCount).toBe(1);
  } finally {
    await context.close();
  }
});
