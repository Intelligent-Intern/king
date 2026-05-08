import { test, expect } from '@playwright/test';

import {
  accessIdFromJoinPath,
  getSeedAccessLink,
  getSeedCall,
  getSeedScenario,
  getSeedUser,
  sessionStorageKey,
} from './helpers/callAccessSeedMatrix.js';
import { createCallAccessMatrixPage } from './helpers/callAccessSeedRuntime.js';

function expectNoForbiddenNeedles(value, needles, label) {
  const text = String(value || '').toLowerCase();
  for (const needle of needles) {
    const normalized = String(needle || '').trim().toLowerCase();
    if (normalized === '') continue;
    expect(text, `${label} must not expose ${needle}`).not.toContain(normalized);
  }
}

function noMediaSecretPayload(value, label) {
  expect(JSON.stringify(value), label).not.toMatch(/\b(?:sdp|ice|candidate|media_token|turn_credential|authorization|password|secret)\b/i);
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

test('e2e_anon_logged_out_011_disabled_anonymous_link_allows_no_lobby_entry', async ({ browser }) => {
  test.setTimeout(60_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const scenario = getSeedScenario('anonymous_open_disabled_logged_out_no_lobby_entry');
  const link = getSeedAccessLink(scenario.link_key);
  const call = getSeedCall(link.call_key);
  const owner = getSeedUser(call.owner_user_key);
  const accessId = accessIdFromJoinPath(link.join_path);
  const forbiddenNeedles = [
    call.id,
    call.title,
    owner.email,
    owner.display_name,
  ];

  const { context, page } = await createCallAccessMatrixPage(browser, baseURL, {
    scenarioKey: scenario.key,
  });
  let sessionPostCount = 0;
  page.on('request', (request) => {
    if (
      request.method() === 'POST'
      && request.url().includes(`/api/call-access/${accessId}/session`)
    ) {
      sessionPostCount += 1;
    }
  });
  await page.route(`**/api/call-access/${accessId}/join`, async (route) => {
    await route.fulfill({
      status: 404,
      contentType: 'application/json',
      body: JSON.stringify({
        status: 'error',
        error: { code: 'call_access_not_found', message: 'Call access link does not exist.' },
      }),
    });
  });
  await page.route(`**/api/call-access/${accessId}/session`, async (route) => {
    await route.fulfill({
      status: 404,
      contentType: 'application/json',
      body: JSON.stringify({
        status: 'error',
        error: { code: 'call_access_not_found', message: 'Call access link does not exist.' },
      }),
    });
  });

  try {
    const joinResponsePromise = page.waitForResponse((response) => (
      response.url().includes(`/api/call-access/${accessId}/join`)
      && response.request().method() === 'GET'
    ));
    await page.goto(link.join_path);
    const joinResponse = await joinResponsePromise;
    expect(joinResponse.status()).toBe(404);
    const joinPayload = await joinResponse.json();
    expect(joinPayload?.error?.code).toBe('call_access_not_found');
    expect(joinPayload?.result ?? null).toBeNull();
    noMediaSecretPayload(joinPayload, 'disabled anonymous join payload must not expose media/auth secrets');
    expectNoForbiddenNeedles(JSON.stringify(joinPayload), forbiddenNeedles, 'disabled anonymous join payload');

    const joinDialog = page.getByRole('dialog', { name: 'Join video call' });
    await expect(joinDialog).toBeVisible({ timeout: 20_000 });
    await expect(joinDialog).toContainText(/call link is invalid|call access id is invalid/i);
    await expect(joinDialog.getByRole('button', { name: /^Join call$/ })).toHaveCount(0);
    for (const needle of forbiddenNeedles) {
      await expect(joinDialog, `disabled anonymous dialog must not render ${needle}`).not.toContainText(needle);
    }

    expect(sessionPostCount).toBe(0);
    const realtimeProbe = await page.evaluate(() => ({
      lobbyFrames: (window.__iamCallAccessSocketFrames || []).filter((frame) => frame?.type === 'lobby/queue/join').length,
    }));
    expect(realtimeProbe.lobbyFrames).toBe(0);

    const storedSession = await readStoredSession(page);
    expect(storedSession.sessionToken || '').toBe('');
    expect(storedSession.sessionId || '').toBe('');
    expect(page.url()).toContain(`/join/${accessId}`);
    expect(page.url()).not.toContain('/workspace/call');
  } finally {
    await context.close();
  }
});
