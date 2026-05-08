import { test, expect } from '@playwright/test';

import { installMediaDeviceShim } from './helpers/nativeAudioTransferHarness.js';

async function createPublicJoinPage(browser, baseURL) {
  const context = await browser.newContext({ baseURL, permissions: ['camera', 'microphone'] });
  await installMediaDeviceShim(context);
  const page = await context.newPage();
  return { context, page };
}

function expectTextDoesNotContain(text, values, label) {
  const lowerText = String(text || '').toLowerCase();
  for (const value of values) {
    const needle = String(value || '').trim().toLowerCase();
    if (needle === '') continue;
    expect(lowerText, `${label} must not contain ${value}`).not.toContain(needle);
  }
}

const staleLifecycleCases = [
  {
    name: 'rescheduled',
    accessId: '33333333-3333-4333-8333-333333333333',
    status: 404,
    code: 'call_access_not_found',
  },
  {
    name: 'deleted',
    accessId: '44444444-4444-4444-8444-444444444444',
    status: 404,
    code: 'call_access_not_found',
  },
  {
    name: 'ended',
    accessId: '55555555-5555-4555-8555-555555555555',
    status: 409,
    code: 'call_access_conflict',
  },
];

for (const lifecycleCase of staleLifecycleCases) {
  test(`stale ${lifecycleCase.name} call-access link renders safe invalid-link screen`, async ({ browser }) => {
    const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
    const secretCallId = `private-${lifecycleCase.name}-call-id`;
    const secretTitle = `Private ${lifecycleCase.name} lifecycle title`;
    const secretEmail = `private-${lifecycleCase.name}@example.invalid`;
    const secretOwner = `Private ${lifecycleCase.name} Owner`;

    const { context, page } = await createPublicJoinPage(browser, baseURL);

    try {
      await page.route('**/api/call-access/*/join', async (route) => {
        await route.fulfill({
          status: lifecycleCase.status,
          contentType: 'application/json',
          body: JSON.stringify({
            status: 'error',
            error: {
              code: lifecycleCase.code,
              message: `Leaked ${secretTitle} ${secretEmail} ${secretCallId}`,
            },
            result: {
              access_link: {
                id: lifecycleCase.accessId,
                call_id: secretCallId,
              },
              call: {
                id: secretCallId,
                title: secretTitle,
                owner: {
                  email: secretEmail,
                  display_name: secretOwner,
                },
              },
              target_user: {
                email: secretEmail,
                display_name: secretOwner,
              },
            },
          }),
        });
      });

      await page.goto(`/join/${lifecycleCase.accessId}`);

      const joinDialog = page.getByRole('dialog', { name: 'Join video call' });
      await expect(joinDialog).toBeVisible();
      await expect(joinDialog).toContainText(/call link is invalid|call access id is invalid|current call state|does not exist/i);
      await expect(joinDialog.getByRole('button', { name: /^Join call$/ })).toHaveCount(0);

      const dialogText = await joinDialog.innerText();
      expectTextDoesNotContain(
        dialogText,
        [secretCallId, secretTitle, secretEmail, secretOwner, lifecycleCase.accessId],
        `${lifecycleCase.name} stale-link dialog`,
      );
    } finally {
      await context.close();
    }
  });
}
