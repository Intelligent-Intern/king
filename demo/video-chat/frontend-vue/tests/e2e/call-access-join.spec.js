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

async function createPublicJoinPage(browser, baseURL) {
  const context = await browser.newContext({ baseURL, permissions: ['camera', 'microphone'] });
  await installMediaDeviceShim(context);
  const page = await context.newPage();
  return { context, page };
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
    await expect(joinDialog).toContainText(/call link is invalid|call access id is invalid|current call state|does not exist/i);
    await expect(joinDialog).not.toContainText(foreignTitle);
    await expect(joinDialog).not.toContainText(foreignEmail);
    await expect(joinDialog.getByRole('button', { name: /^Join call$/ })).toHaveCount(0);
  } finally {
    await context.close();
  }
});

test('stale deleted ended and inactive-user call links render safe state without foreign data', async ({ browser }) => {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const cases = [
    {
      label: 'ended call',
      accessId: '22222222-2222-4222-8222-222222222222',
      status: 409,
      code: 'call_access_conflict',
      privateNeedles: [
        'Ended Private Strategy Call',
        'ended-host@example.invalid',
        'Ended Invitee',
        'sess_ended_should_not_bind',
        'ended-private-call-id',
      ],
      payload: {
        status: 'error',
        error: {
          code: 'call_access_conflict',
          message: 'Ended Private Strategy Call cannot be joined by Ended Invitee.',
        },
        result: {
          call: {
            id: 'ended-private-call-id',
            title: 'Ended Private Strategy Call',
            owner: { email: 'ended-host@example.invalid', display_name: 'Ended Host' },
          },
          target_user: { display_name: 'Ended Invitee' },
        },
      },
    },
    {
      label: 'deleted call',
      accessId: '33333333-3333-4333-8333-333333333333',
      status: 404,
      code: 'call_access_not_found',
      privateNeedles: [
        'Deleted Private Strategy Call',
        'deleted-host@example.invalid',
        'Deleted Invitee',
        'sess_deleted_should_not_bind',
        'deleted-private-call-id',
      ],
      payload: {
        status: 'error',
        error: {
          code: 'call_access_not_found',
          message: 'Deleted Private Strategy Call no longer exists.',
        },
        result: {
          call: {
            id: 'deleted-private-call-id',
            title: 'Deleted Private Strategy Call',
            owner: { email: 'deleted-host@example.invalid', display_name: 'Deleted Host' },
          },
          target_user: { display_name: 'Deleted Invitee' },
        },
      },
    },
    {
      label: 'disabled user',
      accessId: '44444444-4444-4444-8444-444444444444',
      status: 404,
      code: 'call_access_not_found',
      privateNeedles: [
        'Disabled User Private Call',
        'disabled-user@example.invalid',
        'Disabled Target User',
        'sess_disabled_should_not_bind',
        'disabled-user-private-call-id',
      ],
      payload: {
        status: 'error',
        error: {
          code: 'call_access_not_found',
          message: 'Disabled User Private Call is unavailable for disabled-user@example.invalid.',
        },
        result: {
          call: {
            id: 'disabled-user-private-call-id',
            title: 'Disabled User Private Call',
          },
          target_user: {
            email: 'disabled-user@example.invalid',
            display_name: 'Disabled Target User',
          },
        },
      },
    },
    {
      label: 'deleted user',
      accessId: '55555555-5555-4555-8555-555555555555',
      status: 404,
      code: 'call_access_not_found',
      privateNeedles: [
        'Deleted User Private Call',
        'deleted-user@example.invalid',
        'Deleted Target User',
        'sess_deleted_user_should_not_bind',
        'deleted-user-private-call-id',
      ],
      payload: {
        status: 'error',
        error: {
          code: 'call_access_not_found',
          message: 'Deleted User Private Call is unavailable for deleted-user@example.invalid.',
        },
        result: {
          call: {
            id: 'deleted-user-private-call-id',
            title: 'Deleted User Private Call',
          },
          target_user: {
            email: 'deleted-user@example.invalid',
            display_name: 'Deleted Target User',
          },
        },
      },
    },
  ];

  for (const item of cases) {
    const { context, page } = await createPublicJoinPage(browser, baseURL);
    let sessionPostCount = 0;

    try {
      await page.route(`**/api/call-access/${item.accessId}/join`, async (route) => {
        await route.fulfill({
          status: item.status,
          contentType: 'application/json',
          body: JSON.stringify(item.payload),
        });
      });

      await page.route(`**/api/call-access/${item.accessId}/session`, async (route) => {
        sessionPostCount += 1;
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            status: 'ok',
            result: {
              session: {
                id: item.privateNeedles.find((value) => String(value).startsWith('sess_')),
                token: item.privateNeedles.find((value) => String(value).startsWith('sess_')),
              },
            },
          }),
        });
      });

      await page.goto(`/join/${item.accessId}`);

      const joinDialog = page.getByRole('dialog', { name: 'Join video call' });
      await expect(joinDialog, item.label).toBeVisible();
      await expect(joinDialog, item.label).toContainText(/call link is invalid|call access id is invalid|current call state|does not exist/i);
      await expectTextDoesNotContain(joinDialog, item.privateNeedles, item.label);
      await expect(joinDialog.getByRole('button', { name: /^Join call$/ }), item.label).toHaveCount(0);
      expect(sessionPostCount, `${item.label} must not start a session`).toBe(0);
      expect(page.url()).toContain(`/join/${item.accessId}`);
      expect(page.url()).not.toContain('/workspace/call');
    } finally {
      await context.close();
    }
  }
});
