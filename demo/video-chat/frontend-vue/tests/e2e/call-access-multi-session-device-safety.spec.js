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
    role: account.role || 'user',
    displayName: account.displayName,
    email: account.email,
    userId: account.userId,
    accountType: 'account',
    isGuest: false,
    sessionId: account.sessionId,
    sessionToken: account.sessionToken,
    expiresAt: account.expiresAt || '2026-09-01T10:00:00Z',
  };
}

function authSessionPayload(account) {
  return {
    status: 'ok',
    result: { state: 'authenticated' },
    session: { id: account.sessionId, token: account.sessionToken, expires_at: account.expiresAt || '2026-09-01T10:00:00Z' },
    user: { id: account.userId, email: account.email, display_name: account.displayName, role: account.role || 'user', status: 'active' },
    tenant: { id: 1, uuid: 'tenant-1', label: 'Intelligent Intern', role: 'member', permissions: { tenant_admin: false } },
  };
}

function authFailedPayload(reason = 'expired_session') {
  return {
    status: 'error',
    error: { code: 'auth_failed', message: 'A valid session token is required.' },
    result: { state: 'unauthenticated', reason },
  };
}

function personalJoinPayload({ accessId, callId, callTitle, targetUserId }) {
  return {
    status: 'ok',
    result: {
      state: 'resolved',
      access_link: { id: accessId, target_user_id: targetUserId },
      link_kind: 'personal',
      call: { id: callId, room_id: callId, title: callTitle },
      target_hint: { participant_email: null },
      join_path: `/join/${accessId}`,
    },
  };
}

function sessionSuccessPayload({ sessionToken, account, callId, callTitle }) {
  return {
    status: 'ok',
    result: {
      session: { id: sessionToken, token: sessionToken, expires_at: '2026-09-01T10:05:00Z' },
      user: { id: account.userId, email: account.email, display_name: account.displayName, role: account.role || 'user', status: 'active' },
      tenant: { id: 1, uuid: 'tenant-1', label: 'Intelligent Intern', role: 'member', permissions: { tenant_admin: false } },
      call: { id: callId, room_id: callId, title: callTitle },
    },
  };
}

async function installAdmissionSocketShim(context) {
  await context.addInitScript(() => {
    const listenersSymbol = Symbol('listeners');
    window.__iamMultiSessionSocketFrames = [];
    class FakeWebSocket {
      static OPEN = 1;
      static CLOSED = 3;
      constructor(url) {
        this.url = String(url || '');
        this.readyState = 0;
        this[listenersSymbol] = {};
        setTimeout(() => {
          if (this.readyState === FakeWebSocket.CLOSED) return;
          this.readyState = FakeWebSocket.OPEN;
          this.dispatch('open', {});
          this.emit({
            type: 'system/welcome',
            admission: { requires_admission: true, pending_room_id: 'lobby' },
          });
        }, 0);
      }
      addEventListener(type, callback) {
        if (!this[listenersSymbol][type]) this[listenersSymbol][type] = [];
        this[listenersSymbol][type].push(callback);
      }
      removeEventListener(type, callback) {
        this[listenersSymbol][type] = (this[listenersSymbol][type] || []).filter((registered) => registered !== callback);
      }
      dispatch(type, event) {
        for (const callback of this[listenersSymbol][type] || []) callback(event);
      }
      emit(payload) {
        this.dispatch('message', { data: JSON.stringify(payload) });
      }
      send(data) {
        let payload = {};
        try {
          payload = JSON.parse(String(data || '{}'));
        } catch {
          payload = {};
        }
        window.__iamMultiSessionSocketFrames.push(payload);
        if (payload.type === 'lobby/queue/join') {
          this.emit({
            type: 'lobby/snapshot',
            room_id: payload.room_id || 'lobby',
            pending: [],
            admitted: [],
            rejected: [],
          });
        }
      }
      close(code = 1000, reason = 'test_close') {
        if (this.readyState === FakeWebSocket.CLOSED) return;
        this.readyState = FakeWebSocket.CLOSED;
        this.dispatch('close', { code, reason });
      }
    }
    window.WebSocket = FakeWebSocket;
  });
}

async function createJoinPage(browser, baseURL, account = null, contextOptions = {}) {
  const context = await browser.newContext({
    baseURL,
    permissions: ['camera', 'microphone'],
    ...contextOptions,
  });
  await installMediaDeviceShim(context);
  await installAdmissionSocketShim(context);
  if (account) {
    await context.addInitScript(({ key, session }) => {
      localStorage.setItem(key, JSON.stringify(session));
    }, { key: sessionStorageKey, session: storedSessionFor(account) });
  }
  const page = await context.newPage();
  return { context, page };
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

async function routeAuthenticatedSessionState(page, account, options = {}) {
  const authorizations = [];
  await page.route('**/api/auth/session-state', async (route) => {
    const authorization = route.request().headers().authorization || '';
    authorizations.push(authorization);
    const expired = typeof options.expired === 'function' ? options.expired(authorization) : Boolean(options.expired);
    if (expired) {
      await route.fulfill({
        status: 401,
        contentType: 'application/json',
        body: JSON.stringify(authFailedPayload()),
      });
      return;
    }
    if (authorization !== `Bearer ${account.sessionToken}`) {
      await route.fulfill({
        status: 401,
        contentType: 'application/json',
        body: JSON.stringify(authFailedPayload('invalid_session')),
      });
      return;
    }
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(authSessionPayload(account)),
    });
  });
  return authorizations;
}

async function routePersonalJoin(page, { accessId, callId, callTitle, targetUserId }, onJoin = null) {
  await page.route(`**/api/call-access/${accessId}/join`, async (route) => {
    if (typeof onJoin === 'function') onJoin(route);
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(personalJoinPayload({ accessId, callId, callTitle, targetUserId })),
    });
  });
}

test('same user can open the same personalized link in two browser/device contexts without cross-session data', async ({ browser }) => {
  test.setTimeout(90_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const accessId = '11111111-1111-4111-8111-111111111111';
  const callId = 'same-user-multi-device-call';
  const callTitle = 'Same User Multi Device Call';
  const accountA = {
    userId: 21,
    email: 'same-user@example.invalid',
    displayName: 'Same Device User',
    sessionId: 'sess_same_user_browser_a',
    sessionToken: 'sess_same_user_browser_a',
    issuedSessionToken: 'sess_same_user_call_access_a',
  };
  const accountB = {
    ...accountA,
    sessionId: 'sess_same_user_browser_b',
    sessionToken: 'sess_same_user_browser_b',
    issuedSessionToken: 'sess_same_user_call_access_b',
  };
  const browserA = await createJoinPage(browser, baseURL, accountA);
  const browserB = await createJoinPage(browser, baseURL, accountB, {
    viewport: { width: 390, height: 844 },
    userAgent: 'King IAM E2E Device B',
  });
  const sessionRequests = [];
  let releaseBothRequests = () => {};
  const bothSessionRequests = new Promise((resolve) => {
    releaseBothRequests = resolve;
  });

  async function routeSessionStart(page, account, label) {
    await page.route(`**/api/call-access/${accessId}/session`, async (route) => {
      const request = route.request();
      sessionRequests.push({
        label,
        authorization: request.headers().authorization || '',
        body: parseJsonPostData(request),
      });
      if (sessionRequests.length >= 2) releaseBothRequests();
      await bothSessionRequests;
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(sessionSuccessPayload({
          sessionToken: account.issuedSessionToken,
          account,
          callId,
          callTitle,
        })),
      });
    });
  }

  try {
    await Promise.all([
      routeAuthenticatedSessionState(browserA.page, accountA),
      routeAuthenticatedSessionState(browserB.page, accountB),
      routePersonalJoin(browserA.page, { accessId, callId, callTitle, targetUserId: accountA.userId }),
      routePersonalJoin(browserB.page, { accessId, callId, callTitle, targetUserId: accountB.userId }),
      routeSessionStart(browserA.page, accountA, 'browser-a'),
      routeSessionStart(browserB.page, accountB, 'browser-b'),
    ]);

    await Promise.all([
      browserA.page.goto(`/join/${accessId}`),
      browserB.page.goto(`/join/${accessId}`),
    ]);
    const dialogA = browserA.page.getByRole('dialog', { name: 'Join video call' });
    const dialogB = browserB.page.getByRole('dialog', { name: 'Join video call' });
    await Promise.all([
      expect(dialogA).toContainText(callTitle, { timeout: 20_000 }),
      expect(dialogB).toContainText(callTitle, { timeout: 20_000 }),
    ]);

    await Promise.all([
      dialogA.getByRole('button', { name: /^Join call$/ }).click(),
      dialogB.getByRole('button', { name: /^Join call$/ }).click(),
    ]);
    await bothSessionRequests;
    await Promise.all([
      expect(dialogA).toContainText(/Call owner has been notified|Waiting for host/i, { timeout: 20_000 }),
      expect(dialogB).toContainText(/Call owner has been notified|Waiting for host/i, { timeout: 20_000 }),
    ]);

    expect(sessionRequests).toHaveLength(2);
    expect(sessionRequests.find((request) => request.label === 'browser-a')).toMatchObject({
      authorization: `Bearer ${accountA.sessionToken}`,
      body: {
        verified_user_id: accountA.userId,
        verified_session_id: accountA.sessionId,
      },
    });
    expect(sessionRequests.find((request) => request.label === 'browser-b')).toMatchObject({
      authorization: `Bearer ${accountB.sessionToken}`,
      body: {
        verified_user_id: accountB.userId,
        verified_session_id: accountB.sessionId,
      },
    });

    const storedA = await readStoredSession(browserA.page);
    const storedB = await readStoredSession(browserB.page);
    expect(storedA.sessionToken).toBe(accountA.issuedSessionToken);
    expect(storedB.sessionToken).toBe(accountB.issuedSessionToken);
    expect(storedA.sessionToken).not.toBe(storedB.sessionToken);
  } finally {
    await Promise.allSettled([browserA.context.close(), browserB.context.close()]);
  }
});

test('different user opening the same personalized link is review-flagged without rebinding or leaks', async ({ browser }) => {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const accessId = '22222222-2222-4222-8222-222222222222';
  const currentAccount = {
    userId: 22,
    email: 'current-duplicate-device@example.invalid',
    displayName: 'Current Duplicate Device',
    sessionId: 'sess_duplicate_device_current',
    sessionToken: 'sess_duplicate_device_current',
  };
  const foreignNeedles = [
    'original-device-target@example.invalid',
    'Original Device Target',
    'Private Device Host',
    'private-device-host@example.invalid',
    'sess_original_device_target',
  ];
  const { context, page } = await createJoinPage(browser, baseURL, currentAccount);
  let joinGetCount = 0;
  let sessionPostCount = 0;

  try {
    await routeAuthenticatedSessionState(page, currentAccount);
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
              fields: { auth: 'not_bound_to_current_user', host_name: 'not_verified' },
              review: {
                flag: 'duplicate_personalized_link',
                state: 'manual_review_required',
                access_fingerprint: 'sha256:same-link-other-device',
                subject_user_id: currentAccount.userId,
                raw_link_identifier_logged: false,
                account_email_logged: false,
              },
            },
          },
        }),
      });
    });
    await page.route(`**/api/call-access/${accessId}/session`, async (route) => {
      sessionPostCount += 1;
      await route.fulfill({ status: 500, contentType: 'application/json', body: '{}' });
    });

    const joinResponsePromise = page.waitForResponse((response) => (
      response.url().includes(`/api/call-access/${accessId}/join`)
      && response.request().method() === 'GET'
    ));
    await page.goto(`/join/${accessId}`);
    const joinResponse = await joinResponsePromise;
    const joinBody = await joinResponse.text();
    expect(joinResponse.status()).toBe(403);
    expect(joinBody).toContain('duplicate_personalized_link');
    expect(joinBody).toContain('access_fingerprint');
    for (const value of foreignNeedles) {
      expect(joinBody.toLowerCase()).not.toContain(value.toLowerCase());
    }

    const joinDialog = page.getByRole('dialog', { name: 'Join video call' });
    await expect(joinDialog).toBeVisible({ timeout: 20_000 });
    await expect(joinDialog.getByRole('button', { name: /^Join call$/ })).toHaveCount(0);
    await expectTextDoesNotContain(joinDialog, foreignNeedles, 'duplicate device dialog');

    const storedSession = await readStoredSession(page);
    expect(storedSession.sessionId).toBe(currentAccount.sessionId);
    expect(storedSession.sessionToken).toBe(currentAccount.sessionToken);
    expect(sessionPostCount).toBe(0);
    expect(joinGetCount).toBe(1);
    expect(page.url()).toContain(`/join/${accessId}`);
    expect(page.url()).not.toContain('/workspace/call');
  } finally {
    await context.close();
  }
});

test('login switch while host-verification warning state is pending fails closed', async ({ browser }) => {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const accessId = '33333333-3333-4333-8333-333333333333';
  const callId = 'warning-login-switch-call';
  const callTitle = 'Warning Login Switch Call';
  const verifiedAccount = {
    userId: 23,
    email: 'verified-warning@example.invalid',
    displayName: 'Verified Warning User',
    sessionId: 'sess_warning_verified',
    sessionToken: 'sess_warning_verified',
  };
  const switchedAccount = {
    userId: 24,
    email: 'switched-warning@example.invalid',
    displayName: 'Switched Warning User',
    sessionId: 'sess_warning_switched',
    sessionToken: 'sess_warning_switched',
  };
  const foreignNeedles = [
    'Hidden Warning Invitee',
    'hidden-warning-invitee@example.invalid',
    'Hidden Warning Host',
    'sess_hidden_warning_should_not_bind',
  ];
  const { context, page } = await createJoinPage(browser, baseURL, verifiedAccount);
  let sessionAuthorization = '';
  let sessionBody = null;

  try {
    await routeAuthenticatedSessionState(page, verifiedAccount);
    await routePersonalJoin(page, { accessId, callId, callTitle, targetUserId: verifiedAccount.userId });
    await page.route(`**/api/call-access/${accessId}/session`, async (route) => {
      sessionAuthorization = route.request().headers().authorization || '';
      sessionBody = parseJsonPostData(route.request());
      await route.fulfill({
        status: 409,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'error',
          error: {
            code: 'call_access_conflict',
            message: 'Call access session context changed.',
            details: { fields: { auth: 'session_context_changed', host_name: 'not_verified' } },
          },
          result: {
            session: {
              id: 'sess_hidden_warning_should_not_bind',
              token: 'sess_hidden_warning_should_not_bind',
            },
            user: {
              id: 99,
              email: 'hidden-warning-invitee@example.invalid',
              display_name: 'Hidden Warning Invitee',
            },
            call: {
              id: 'hidden-warning-call',
              title: 'Hidden Warning Host',
            },
          },
        }),
      });
    });

    await page.goto(`/join/${accessId}`);
    const joinDialog = page.getByRole('dialog', { name: 'Join video call' });
    await expect(joinDialog).toContainText(callTitle, { timeout: 20_000 });

    const switchedSnapshot = await page.evaluate(async ({ key, account, session }) => {
      const { sessionState } = await import('/src/domain/auth/session.ts');
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
      localStorage.setItem(key, JSON.stringify(session));
      return {
        userId: sessionState.userId,
        sessionId: sessionState.sessionId,
        sessionToken: sessionState.sessionToken,
      };
    }, { key: sessionStorageKey, account: switchedAccount, session: storedSessionFor(switchedAccount) });
    expect(switchedSnapshot).toEqual({
      userId: switchedAccount.userId,
      sessionId: switchedAccount.sessionId,
      sessionToken: switchedAccount.sessionToken,
    });

    await joinDialog.getByRole('button', { name: /^Join call$/ }).click();
    await expect(joinDialog).toContainText('This call link cannot be used for the current call state.');

    expect(sessionAuthorization).toBe(`Bearer ${switchedAccount.sessionToken}`);
    expect(sessionBody).toEqual({
      verified_user_id: verifiedAccount.userId,
      verified_session_id: verifiedAccount.sessionId,
    });
    await expectTextDoesNotContain(joinDialog, foreignNeedles, 'login-switch warning dialog');
    const storedSession = await readStoredSession(page);
    expect(storedSession.sessionToken).toBe(switchedAccount.sessionToken);
    expect(storedSession.sessionToken).not.toBe('sess_hidden_warning_should_not_bind');
    expect(page.url()).not.toContain('/workspace/call');
  } finally {
    await context.close();
  }
});

test('session expiry while waiting in lobby clears the stale session without entering the call', async ({ browser }) => {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const accessId = '44444444-4444-4444-8444-444444444444';
  const callId = 'lobby-expiry-call';
  const callTitle = 'Lobby Expiry Call';
  const account = {
    userId: 25,
    email: 'lobby-expiry@example.invalid',
    displayName: 'Lobby Expiry User',
    sessionId: 'sess_lobby_expiry_before_join',
    sessionToken: 'sess_lobby_expiry_before_join',
  };
  const callAccessSessionToken = 'sess_lobby_expiry_call_access';
  const { context, page } = await createJoinPage(browser, baseURL, account);
  let expireOnRecovery = false;
  let joinGetCount = 0;
  let sessionPostCount = 0;

  try {
    await routeAuthenticatedSessionState(page, account, { expired: () => expireOnRecovery });
    await routePersonalJoin(page, { accessId, callId, callTitle, targetUserId: account.userId }, () => {
      joinGetCount += 1;
    });
    await page.route(`**/api/call-access/${accessId}/session`, async (route) => {
      sessionPostCount += 1;
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(sessionSuccessPayload({
          sessionToken: callAccessSessionToken,
          account,
          callId,
          callTitle,
        })),
      });
    });

    await page.goto(`/join/${accessId}`);
    const joinDialog = page.getByRole('dialog', { name: 'Join video call' });
    await expect(joinDialog).toContainText(callTitle, { timeout: 20_000 });
    await joinDialog.getByRole('button', { name: /^Join call$/ }).click();
    await expect(joinDialog).toContainText(/Call owner has been notified|Waiting for host/i, { timeout: 20_000 });
    expect((await readStoredSession(page)).sessionToken).toBe(callAccessSessionToken);

    expireOnRecovery = true;
    await page.reload({ waitUntil: 'domcontentloaded' });
    const reloadedDialog = page.getByRole('dialog', { name: 'Join video call' });
    await expect(reloadedDialog).toBeVisible({ timeout: 20_000 });
    await expect(reloadedDialog).toContainText(callTitle);

    const storedAfterExpiry = await readStoredSession(page);
    expect(storedAfterExpiry.sessionToken || '').toBe('');
    expect(sessionPostCount).toBe(1);
    expect(joinGetCount).toBe(2);
    expect(page.url()).toContain(`/join/${accessId}`);
    expect(page.url()).not.toContain('/workspace/call');
  } finally {
    await context.close();
  }
});

test('session expiry in call workspace redirects to login and clears the stale call session', async ({ browser }) => {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const expiredAccount = {
    userId: 26,
    email: 'call-expiry@example.invalid',
    displayName: 'Call Expiry User',
    sessionId: 'sess_call_expired',
    sessionToken: 'sess_call_expired',
    expiresAt: '2026-01-01T00:00:00Z',
  };
  const { context, page } = await createJoinPage(browser, baseURL, expiredAccount);

  try {
    await page.route('**/api/auth/session-state', async (route) => {
      await route.fulfill({
        status: 401,
        contentType: 'application/json',
        body: JSON.stringify(authFailedPayload('expired_session')),
      });
    });

    await page.goto('/workspace/call/session-expiry-call');
    await expect(page).toHaveURL(/\/login\?redirect=/, { timeout: 20_000 });
    await expect(page.locator('.workspace-call-view')).toHaveCount(0);
    const storedAfterExpiry = await readStoredSession(page);
    expect(storedAfterExpiry.sessionToken || '').toBe('');
  } finally {
    await context.close();
  }
});

test('refresh after failed host verification preserves the current account and refetches safe state', async ({ browser }) => {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const accessId = '55555555-5555-4555-8555-555555555555';
  const callId = 'host-refresh-call';
  const callTitle = 'Host Refresh Call';
  const wrongAccount = {
    userId: 27,
    email: 'host-refresh-current@example.invalid',
    displayName: 'Host Refresh Current',
    sessionId: 'sess_host_refresh_current',
    sessionToken: 'sess_host_refresh_current',
  };
  const foreignNeedles = [
    'host-refresh-target@example.invalid',
    'Host Refresh Target',
    'Private Host Refresh Host',
    'sess_host_refresh_should_not_bind',
  ];
  const { context, page } = await createJoinPage(browser, baseURL, wrongAccount);
  let joinGetCount = 0;
  let sessionPostCount = 0;

  try {
    await routeAuthenticatedSessionState(page, wrongAccount);
    await routePersonalJoin(page, { accessId, callId, callTitle, targetUserId: 99 }, () => {
      joinGetCount += 1;
    });
    await page.route(`**/api/call-access/${accessId}/session`, async (route) => {
      sessionPostCount += 1;
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
              fields: { host_name: 'wrong_host_name' },
            },
          },
          result: {
            session: { id: 'sess_host_refresh_should_not_bind', token: 'sess_host_refresh_should_not_bind' },
            user: { email: 'host-refresh-target@example.invalid', display_name: 'Host Refresh Target' },
            call: { title: 'Private Host Refresh Host' },
          },
        }),
      });
    });

    await page.goto(`/join/${accessId}`);
    const joinDialog = page.getByRole('dialog', { name: 'Join video call' });
    await expect(joinDialog).toContainText(callTitle, { timeout: 20_000 });
    await joinDialog.getByRole('button', { name: /^Join call$/ }).click();
    await expect(joinDialog).toContainText('This call link is not available for your session.');
    await expectTextDoesNotContain(joinDialog, foreignNeedles, 'failed host verification dialog');

    await page.reload({ waitUntil: 'domcontentloaded' });
    const reloadedDialog = page.getByRole('dialog', { name: 'Join video call' });
    await expect(reloadedDialog).toContainText(callTitle, { timeout: 20_000 });
    await expectTextDoesNotContain(reloadedDialog, foreignNeedles, 'host verification refresh dialog');
    const storedSession = await readStoredSession(page);
    expect(storedSession.sessionToken).toBe(wrongAccount.sessionToken);
    expect(storedSession.sessionToken).not.toBe('sess_host_refresh_should_not_bind');
    expect(joinGetCount).toBe(2);
    expect(sessionPostCount).toBe(1);
    expect(page.url()).toContain(`/join/${accessId}`);
    expect(page.url()).not.toContain('/workspace/call');
  } finally {
    await context.close();
  }
});

test('refresh while account-update email confirmation is pending keeps confirmation account-bound', async ({ browser }) => {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const accessId = '66666666-6666-4666-8666-666666666666';
  const callId = 'email-refresh-call';
  const callTitle = 'Email Refresh Call';
  const currentAccount = {
    userId: 28,
    email: 'email-refresh-current@example.invalid',
    displayName: 'Email Refresh Current',
    sessionId: 'sess_email_refresh_current',
    sessionToken: 'sess_email_refresh_current',
  };
  const foreignNeedles = [
    'email-refresh-link-target@example.invalid',
    'Email Refresh Link Target',
    'sess_email_refresh_link_target',
  ];
  const { context, page } = await createJoinPage(browser, baseURL, currentAccount);
  const confirmationRequests = [];
  let joinGetCount = 0;
  let sessionPostCount = 0;

  try {
    await routeAuthenticatedSessionState(page, currentAccount);
    await routePersonalJoin(page, { accessId, callId, callTitle, targetUserId: currentAccount.userId }, () => {
      joinGetCount += 1;
    });
    await page.route(`**/api/call-access/${accessId}/session`, async (route) => {
      sessionPostCount += 1;
      await route.fulfill({ status: 500, contentType: 'application/json', body: '{}' });
    });
    await page.route(`**/api/call-access/${accessId}/account-update-confirmation`, async (route) => {
      confirmationRequests.push({
        authorization: route.request().headers().authorization || '',
        body: parseJsonPostData(route.request()),
      });
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
            debug_confirmation_token: 'cau_email_refresh_pending_token',
          },
        }),
      });
    });

    await page.goto(`/join/${accessId}`);
    const joinDialog = page.getByRole('dialog', { name: 'Join video call' });
    await expect(joinDialog).toContainText(callTitle, { timeout: 20_000 });

    const confirmationResult = await page.evaluate(async ({ id, token }) => {
      const response = await fetch(`/api/call-access/${id}/account-update-confirmation`, {
        method: 'POST',
        headers: { accept: 'application/json', 'content-type': 'application/json', authorization: `Bearer ${token}` },
        body: JSON.stringify({ display_name: 'Manually Confirmed Refresh Name' }),
      });
      return { status: response.status, payload: await response.json() };
    }, { id: accessId, token: currentAccount.sessionToken });
    expect(confirmationResult.status).toBe(200);
    expect(confirmationResult.payload.result.recipient_user_id).toBe(currentAccount.userId);
    expect(confirmationResult.payload.result.sent_to_logged_in_account).toBe(true);
    expect(confirmationResult.payload.result.sent_to_link_account).toBe(false);

    await page.reload({ waitUntil: 'domcontentloaded' });
    const reloadedDialog = page.getByRole('dialog', { name: 'Join video call' });
    await expect(reloadedDialog).toContainText(callTitle, { timeout: 20_000 });
    await expectTextDoesNotContain(reloadedDialog, foreignNeedles, 'pending email confirmation refresh dialog');

    expect(confirmationRequests).toHaveLength(1);
    expect(confirmationRequests[0].authorization).toBe(`Bearer ${currentAccount.sessionToken}`);
    expect(confirmationRequests[0].body).toEqual({ display_name: 'Manually Confirmed Refresh Name' });
    const storedSession = await readStoredSession(page);
    expect(storedSession.sessionToken).toBe(currentAccount.sessionToken);
    expect(JSON.stringify(storedSession)).not.toContain('email-refresh-link-target');
    expect(sessionPostCount).toBe(0);
    expect(joinGetCount).toBe(2);
    expect(page.url()).toContain(`/join/${accessId}`);
    expect(page.url()).not.toContain('/workspace/call');
  } finally {
    await context.close();
  }
});
