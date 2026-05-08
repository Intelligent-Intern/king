import { test, expect } from '@playwright/test';

import { installMediaDeviceShim } from './helpers/nativeAudioTransferHarness.js';

const sessionStorageKey = 'ii_videocall_v1_session';

async function createPublicJoinPage(browser, baseURL) {
  const context = await browser.newContext({ baseURL, permissions: ['camera', 'microphone'] });
  await installMediaDeviceShim(context);
  const page = await context.newPage();
  return { context, page };
}

async function seedStaleSession(context, session) {
  await context.addInitScript(({ key, value }) => {
    localStorage.setItem(key, JSON.stringify(value));
  }, { key: sessionStorageKey, value: session });
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
