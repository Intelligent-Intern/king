import { test, expect } from '@playwright/test';

import { installMediaDeviceShim } from './helpers/nativeAudioTransferHarness.js';

const sessionStorageKey = 'ii_videocall_v1_session';

async function createPublicJoinPage(browser, baseURL, contextOptions = {}) {
  const context = await browser.newContext({
    baseURL,
    permissions: ['camera', 'microphone'],
    ...contextOptions,
  });
  await installMediaDeviceShim(context);
  const page = await context.newPage();
  return { context, page };
}

async function seedStaleSession(context, session) {
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

async function routeAuthenticatedSessionState(page, account) {
  await page.route('**/api/auth/session-state', async (route) => {
    const authorization = route.request().headers().authorization || '';
    if (authorization !== `Bearer ${account.sessionToken}`) {
      await route.fulfill({
        status: 401,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'error',
          error: { code: 'auth_failed', message: 'A valid session token is required.' },
          result: { state: 'unauthenticated', reason: 'invalid_session' },
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

async function expectTextDoesNotContain(locator, values, label) {
  const text = await locator.innerText();
  const lowerText = String(text || '').toLowerCase();
  for (const value of values) {
    const needle = String(value || '').trim().toLowerCase();
    if (needle === '') continue;
    expect(lowerText, `${label} must not contain ${value}`).not.toContain(needle);
  }
}

async function routeInvalidatedJoin(page, accessId, onJoin) {
  await page.route(`**/api/call-access/${accessId}/join`, async (route) => {
    onJoin(route);
    await route.fulfill({
      status: 404,
      contentType: 'application/json',
      body: JSON.stringify({
        status: 'error',
        error: {
          code: 'call_access_not_found',
          message: 'Invalidated cross-browser link for hidden-invitee@example.invalid is gone.',
        },
        result: {
          access_link: {
            id: accessId,
            target_user_id: 88,
            consumed_at: '2026-09-01T09:00:00Z',
          },
          call: {
            id: 'hidden-invalidated-cross-context-call',
            room_id: 'hidden-invalidated-cross-context-call',
            title: 'Hidden Invalidated Cross Context Call',
            owner: {
              display_name: 'Hidden Invalidated Host',
              email: 'hidden-invalidated-host@example.invalid',
            },
          },
          target_user: {
            id: 88,
            email: 'hidden-invitee@example.invalid',
            display_name: 'Hidden Invitee',
          },
        },
      }),
    });
  });
}

test('invalidated personalized link renders stale safe state without leaking invite data', async ({ browser }) => {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const accessId = '88888888-8888-4888-8888-888888888888';
  const staleSession = {
    sessionId: 'sess_stale_invalidated_invitee',
    sessionToken: 'sess_stale_invalidated_invitee',
    expiresAt: '2026-09-01T10:00:00Z',
  };
  const privateNeedles = [
    'Cancelled Personalized Secret Call',
    'cancelled-invitee@example.invalid',
    'Cancelled Invitee',
    'Private Cancelled Host',
    'sess_fresh_invalidated_should_not_bind',
    'cancelled-call-id',
  ];
  const { context, page } = await createPublicJoinPage(browser, baseURL);
  let sessionPostCount = 0;

  try {
    await seedStaleSession(context, staleSession);

    await page.route('**/api/auth/session-state', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'ok',
          result: { state: 'authenticated' },
          session: {
            id: staleSession.sessionId,
            token: staleSession.sessionToken,
            expires_at: staleSession.expiresAt,
          },
          user: {
            id: 2,
            email: 'cancelled-invitee@example.invalid',
            display_name: 'Cancelled Invitee',
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
        status: 404,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'error',
          error: {
            code: 'call_access_not_found',
            message: 'Cancelled Personalized Secret Call for cancelled-invitee@example.invalid is gone.',
          },
          result: {
            access_link: {
              id: accessId,
              target_user_id: 2,
              consumed_at: '2026-09-01T09:00:00Z',
            },
            call: {
              id: 'cancelled-call-id',
              room_id: 'cancelled-call-id',
              title: 'Cancelled Personalized Secret Call',
              owner: {
                display_name: 'Private Cancelled Host',
                email: 'host-cancelled@example.invalid',
              },
            },
            target_user: {
              id: 2,
              email: 'cancelled-invitee@example.invalid',
              display_name: 'Cancelled Invitee',
            },
          },
        }),
      });
    });

    await page.route(`**/api/call-access/${accessId}/session`, async (route) => {
      sessionPostCount += 1;
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'ok',
          result: {
            session: {
              id: 'sess_fresh_invalidated_should_not_bind',
              token: 'sess_fresh_invalidated_should_not_bind',
              expires_at: '2026-09-01T10:05:00Z',
            },
            call: {
              id: 'cancelled-call-id',
              title: 'Cancelled Personalized Secret Call',
            },
          },
        }),
      });
    });

    await page.goto(`/join/${accessId}`);

    const joinDialog = page.getByRole('dialog', { name: 'Join video call' });
    await expect(joinDialog).toBeVisible({ timeout: 20_000 });
    await expect(joinDialog).toContainText(/call link is invalid|call access id is invalid/i);
    await expectTextDoesNotContain(joinDialog, privateNeedles, 'invalidated personalized link screen');
    await expect(joinDialog.getByRole('button', { name: /^Join call$/ })).toHaveCount(0);
    expect(sessionPostCount).toBe(0);
    expect(page.url()).toContain(`/join/${accessId}`);
    expect(page.url()).not.toContain('/workspace/call');
  } finally {
    await context.close();
  }
});

test('invalidated personalized link stays denied across browser device and session contexts', async ({ browser }) => {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const accessId = '99999999-9999-4999-8999-999999999999';
  const accountA = {
    userId: 31,
    email: 'invalidated-browser-a@example.invalid',
    displayName: 'Invalidated Browser A',
    sessionId: 'sess_invalidated_browser_a',
    sessionToken: 'sess_invalidated_browser_a',
    expiresAt: '2026-09-01T10:00:00Z',
  };
  const accountB = {
    userId: 31,
    email: 'invalidated-browser-a@example.invalid',
    displayName: 'Invalidated Browser A',
    sessionId: 'sess_invalidated_device_b',
    sessionToken: 'sess_invalidated_device_b',
    expiresAt: '2026-09-01T10:00:00Z',
  };
  const privateNeedles = [
    'Hidden Invalidated Cross Context Call',
    'hidden-invalidated-cross-context-call',
    'hidden-invitee@example.invalid',
    'Hidden Invitee',
    'Hidden Invalidated Host',
    'hidden-invalidated-host@example.invalid',
    'sess_hidden_invalidated_should_not_bind',
  ];
  const browserA = await createPublicJoinPage(browser, baseURL);
  const deviceB = await createPublicJoinPage(browser, baseURL, {
    viewport: { width: 390, height: 844 },
    userAgent: 'King IAM invalidated-link mobile device B',
  });
  let joinGetCountA = 0;
  let joinGetCountB = 0;
  let sessionPostCountA = 0;
  let sessionPostCountB = 0;

  try {
    await seedStaleSession(browserA.context, {
      sessionId: accountA.sessionId,
      sessionToken: accountA.sessionToken,
      expiresAt: accountA.expiresAt,
    });
    await seedStaleSession(deviceB.context, {
      sessionId: accountB.sessionId,
      sessionToken: accountB.sessionToken,
      expiresAt: accountB.expiresAt,
    });
    await Promise.all([
      routeAuthenticatedSessionState(browserA.page, accountA),
      routeAuthenticatedSessionState(deviceB.page, accountB),
      routeInvalidatedJoin(browserA.page, accessId, () => { joinGetCountA += 1; }),
      routeInvalidatedJoin(deviceB.page, accessId, () => { joinGetCountB += 1; }),
    ]);
    await browserA.page.route(`**/api/call-access/${accessId}/session`, async (route) => {
      sessionPostCountA += 1;
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'ok',
          result: {
            session: {
              id: 'sess_hidden_invalidated_should_not_bind',
              token: 'sess_hidden_invalidated_should_not_bind',
            },
          },
        }),
      });
    });
    await deviceB.page.route(`**/api/call-access/${accessId}/session`, async (route) => {
      sessionPostCountB += 1;
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'ok',
          result: {
            session: {
              id: 'sess_hidden_invalidated_should_not_bind',
              token: 'sess_hidden_invalidated_should_not_bind',
            },
          },
        }),
      });
    });

    await Promise.all([
      browserA.page.goto(`/join/${accessId}`),
      deviceB.page.goto(`/join/${accessId}`),
    ]);
    const dialogA = browserA.page.getByRole('dialog', { name: 'Join video call' });
    const dialogB = deviceB.page.getByRole('dialog', { name: 'Join video call' });
    await Promise.all([
      expect(dialogA).toBeVisible({ timeout: 20_000 }),
      expect(dialogB).toBeVisible({ timeout: 20_000 }),
    ]);
    await Promise.all([
      expect(dialogA).toContainText(/call link is invalid|call access id is invalid/i),
      expect(dialogB).toContainText(/call link is invalid|call access id is invalid/i),
    ]);
    await Promise.all([
      expectTextDoesNotContain(dialogA, privateNeedles, 'invalidated browser A dialog'),
      expectTextDoesNotContain(dialogB, privateNeedles, 'invalidated device B dialog'),
    ]);
    await Promise.all([
      expect(dialogA.getByRole('button', { name: /^Join call$/ })).toHaveCount(0),
      expect(dialogB.getByRole('button', { name: /^Join call$/ })).toHaveCount(0),
    ]);

    const storedA = await readStoredSession(browserA.page);
    const storedB = await readStoredSession(deviceB.page);
    expect(storedA.sessionToken).toBe(accountA.sessionToken);
    expect(storedB.sessionToken).toBe(accountB.sessionToken);
    expect(storedA.sessionToken).not.toBe(storedB.sessionToken);
    expect(sessionPostCountA).toBe(0);
    expect(sessionPostCountB).toBe(0);
    expect(joinGetCountA).toBe(1);
    expect(joinGetCountB).toBe(1);
    expect(browserA.page.url()).toContain(`/join/${accessId}`);
    expect(deviceB.page.url()).toContain(`/join/${accessId}`);
    expect(browserA.page.url()).not.toContain('/workspace/call');
    expect(deviceB.page.url()).not.toContain('/workspace/call');
  } finally {
    await Promise.allSettled([browserA.context.close(), deviceB.context.close()]);
  }
});
