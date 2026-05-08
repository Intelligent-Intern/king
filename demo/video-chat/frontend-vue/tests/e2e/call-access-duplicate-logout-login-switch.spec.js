import { test, expect } from '@playwright/test';

import { installMediaDeviceShim } from './helpers/nativeAudioTransferHarness.js';

const sessionStorageKey = 'ii_videocall_v1_session';

function parseJsonPostData(request) {
  const raw = request.postData();
  if (!raw) return null;
  try {
    return JSON.parse(raw);
  } catch {
    return null;
  }
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

function authSessionPayload(account) {
  return {
    status: 'ok',
    result: { state: 'authenticated' },
    session: { id: account.sessionId, token: account.sessionToken, expires_at: '2026-09-01T10:00:00Z' },
    user: { id: account.userId, email: account.email, display_name: account.displayName, role: 'user', status: 'active' },
    tenant: { id: 1, uuid: 'tenant-1', label: 'Intelligent Intern', role: 'member', permissions: { tenant_admin: false } },
  };
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

async function expectTextDoesNotContain(locator, values, label) {
  const text = await locator.innerText();
  const lowerText = String(text || '').toLowerCase();
  for (const value of values) {
    const needle = String(value || '').trim().toLowerCase();
    if (needle === '') continue;
    expect(lowerText, `${label} must not contain ${value}`).not.toContain(needle);
  }
}

test('duplicate abuse detection survives logout/login switch in the same browser', async ({ browser }) => {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const accessId = '77777777-7777-4777-8777-777777777777';
  const callId = 'duplicate-logout-login-switch-call';
  const callTitle = 'Duplicate Logout Login Switch Call';
  const accountA = {
    userId: 31,
    email: 'duplicate-switch-a@example.invalid',
    displayName: 'Duplicate Switch Account A',
    sessionId: 'sess_duplicate_switch_a',
    sessionToken: 'sess_duplicate_switch_a',
  };
  const accountB = {
    userId: 32,
    email: 'duplicate-switch-b@example.invalid',
    displayName: 'Duplicate Switch Account B',
    sessionId: 'sess_duplicate_switch_b',
    sessionToken: 'sess_duplicate_switch_b',
  };
  const foreignNeedles = [
    'original-switch-target@example.invalid',
    'Original Switch Target',
    'Private Switch Host',
    'private-switch-host@example.invalid',
    'sess_original_switch_target',
    'sess_duplicate_switch_should_not_bind',
  ];

  const context = await browser.newContext({ baseURL, permissions: ['camera', 'microphone'] });
  await installMediaDeviceShim(context);
  await context.addInitScript(({ key, session }) => {
    localStorage.setItem(key, JSON.stringify(session));
  }, { key: sessionStorageKey, session: storedSessionFor(accountA) });
  const page = await context.newPage();
  const sessionRequests = [];
  let logoutPostCount = 0;

  try {
    await page.route('**/api/auth/session-state', async (route) => {
      const authorization = route.request().headers().authorization || '';
      const account = authorization === `Bearer ${accountB.sessionToken}` ? accountB : accountA;
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(authSessionPayload(account)) });
    });
    await page.route('**/api/auth/logout', async (route) => {
      logoutPostCount += 1;
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ status: 'ok', result: { post_logout_landing_url: '' } }),
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
            access_link: { id: accessId, target_user_id: accountA.userId },
            link_kind: 'personal',
            call: { id: callId, room_id: callId, title: callTitle },
            target_hint: { participant_email: null },
            join_path: `/join/${accessId}`,
          },
        }),
      });
    });
    await page.route(`**/api/call-access/${accessId}/session`, async (route) => {
      sessionRequests.push({
        authorization: route.request().headers().authorization || '',
        body: parseJsonPostData(route.request()),
      });
      await route.fulfill({
        status: 409,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'error',
          error: {
            code: 'call_access_conflict',
            message: 'Call access cannot be used for the current call state.',
            details: {
              fields: { auth: 'session_context_changed' },
              review: {
                flag: 'duplicate_personalized_link',
                state: 'manual_review_required',
                access_fingerprint: 'sha256:duplicate-switch-link',
                subject_user_id: accountB.userId,
                raw_link_identifier_logged: false,
                account_email_logged: false,
              },
            },
          },
          result: {
            session: { id: 'sess_duplicate_switch_should_not_bind', token: 'sess_duplicate_switch_should_not_bind' },
            user: { email: 'original-switch-target@example.invalid', display_name: 'Original Switch Target' },
            call: { title: 'Private Switch Host' },
          },
        }),
      });
    });

    await page.goto(`/join/${accessId}`);
    const joinDialog = page.getByRole('dialog', { name: 'Join video call' });
    await expect(joinDialog).toContainText(callTitle, { timeout: 20_000 });

    const logoutState = await page.evaluate(async () => {
      const { logoutSession, sessionState } = await import('/src/domain/auth/session.ts');
      await logoutSession();
      return { userId: sessionState.userId, sessionId: sessionState.sessionId, sessionToken: sessionState.sessionToken };
    });
    expect(logoutState).toEqual({ userId: 0, sessionId: '', sessionToken: '' });

    await page.evaluate(({ key, account, session }) => {
      localStorage.setItem(key, JSON.stringify(session));
      window.dispatchEvent(new StorageEvent('storage', { key, newValue: JSON.stringify(session), storageArea: localStorage }));
      return import('/src/domain/auth/session.ts').then(({ sessionState }) => {
        sessionState.role = 'user';
        sessionState.displayName = account.displayName;
        sessionState.email = account.email;
        sessionState.userId = account.userId;
        sessionState.accountType = 'account';
        sessionState.isGuest = false;
        sessionState.sessionId = account.sessionId;
        sessionState.sessionToken = account.sessionToken;
        sessionState.expiresAt = '2026-09-01T10:00:00Z';
        sessionState.recovered = true;
      });
    }, { key: sessionStorageKey, account: accountB, session: storedSessionFor(accountB) });

    await joinDialog.getByRole('button', { name: /^Join call$/ }).click();
    await expect(joinDialog).toContainText('This call link cannot be used for the current call state.');
    await expectTextDoesNotContain(joinDialog, foreignNeedles, 'logout/login duplicate dialog');

    expect(logoutPostCount).toBe(1);
    expect(sessionRequests).toHaveLength(1);
    expect(sessionRequests[0]).toEqual({
      authorization: `Bearer ${accountB.sessionToken}`,
      body: {
        verified_user_id: accountA.userId,
        verified_session_id: accountA.sessionId,
      },
    });
    const storedSession = await readStoredSession(page);
    expect(storedSession.sessionToken).toBe(accountB.sessionToken);
    expect(storedSession.sessionToken).not.toBe('sess_duplicate_switch_should_not_bind');
    expect(JSON.stringify(storedSession)).not.toContain('original-switch-target');
    expect(page.url()).toContain(`/join/${accessId}`);
    expect(page.url()).not.toContain('/workspace/call');
  } finally {
    await context.close();
  }
});
