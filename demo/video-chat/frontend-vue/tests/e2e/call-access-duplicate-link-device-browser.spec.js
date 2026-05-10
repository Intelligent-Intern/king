import { test, expect } from '@playwright/test';

import { installMediaDeviceShim } from './helpers/nativeAudioTransferHarness.js';

const sessionStorageKey = 'ii_videocall_v1_session';

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
    expiresAt: account.expiresAt || '2026-09-01T10:00:00Z',
  };
}

function authSessionPayload(account) {
  return {
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
  };
}

function resolvedJoinPayload({ accessId, callTitle }) {
  return {
    status: 'ok',
    result: {
      state: 'resolved',
      access_link: { id: accessId },
      link_kind: 'personal',
      call: { id: 'duplicate-device-browser-call', room_id: 'lobby', title: callTitle },
      target_hint: { participant_email: null },
      join_path: `/join/${accessId}`,
    },
  };
}

function sessionStartedPayload({ account, sessionToken, callTitle }) {
  return {
    status: 'ok',
    result: {
      state: 'session_started',
      session: { id: sessionToken, token: sessionToken, expires_at: '2026-09-01T10:05:00Z' },
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
      call: { id: 'duplicate-device-browser-call', room_id: 'lobby', title: callTitle },
    },
  };
}

function duplicateReviewPayload({ account, accessFingerprint, stage }) {
  return {
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
          stage,
          access_fingerprint: accessFingerprint,
          subject_user_id: account.userId,
          raw_link_identifier_logged: false,
          account_email_logged: false,
          host_name_logged: false,
        },
      },
    },
  };
}

function readPostJson(request) {
  try {
    return JSON.parse(request.postData() || '{}');
  } catch {
    return null;
  }
}

async function installAdmissionSocketShim(context) {
  await context.addInitScript(() => {
    const listenersSymbol = Symbol('listeners');
    window.__iamDuplicateDeviceBrowserSocketFrames = [];

    class FakeWebSocket {
      static OPEN = 1;
      static CLOSED = 3;

      constructor() {
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
        this[listenersSymbol][type] = (this[listenersSymbol][type] || [])
          .filter((registered) => registered !== callback);
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
        window.__iamDuplicateDeviceBrowserSocketFrames.push(payload);
        if (payload.type === 'lobby/queue/join') {
          this.emit({ type: 'lobby/snapshot', room_id: 'lobby', pending: [], admitted: [], rejected: [] });
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

async function createAccountPage(browser, baseURL, account, contextOptions = {}) {
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

async function routeAuthenticatedSessionState(page, accountsByToken, records = []) {
  await page.route('**/api/auth/session-state', async (route) => {
    const authorization = route.request().headers().authorization || '';
    const token = authorization.replace(/^Bearer\s+/i, '');
    const account = accountsByToken.get(token);
    records.push({ authorization, token, matched: Boolean(account) });
    if (!account) {
      await route.fulfill({
        status: 401,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'error',
          error: { code: 'auth_failed', message: 'A valid session token is required.' },
        }),
      });
      return;
    }

    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(authSessionPayload(account)),
    });
  });
}

async function readStoredSession(page) {
  return page.evaluate((key) => JSON.parse(localStorage.getItem(key) || '{}'), sessionStorageKey);
}

async function readRuntimeSession(page) {
  return page.evaluate(async () => {
    const { sessionState } = await import('/src/domain/auth/session.ts');
    return {
      userId: sessionState.userId,
      sessionId: sessionState.sessionId,
      sessionToken: sessionState.sessionToken,
      email: sessionState.email,
    };
  });
}

async function setRuntimeSession(page, account) {
  return page.evaluate(async ({ key, session }) => {
    const { sessionState } = await import('/src/domain/auth/session.ts');
    sessionState.role = session.role;
    sessionState.displayName = session.displayName;
    sessionState.email = session.email;
    sessionState.userId = session.userId;
    sessionState.accountType = session.accountType;
    sessionState.isGuest = session.isGuest;
    sessionState.sessionId = session.sessionId;
    sessionState.sessionToken = session.sessionToken;
    sessionState.expiresAt = session.expiresAt;
    sessionState.recovered = true;
    localStorage.setItem(key, JSON.stringify(session));
    return {
      userId: sessionState.userId,
      sessionId: sessionState.sessionId,
      sessionToken: sessionState.sessionToken,
      email: sessionState.email,
    };
  }, { key: sessionStorageKey, session: storedSessionFor(account) });
}

async function expectDialogOmits(dialog, values) {
  for (const value of values) {
    await expect(dialog, `duplicate denial must not render ${value}`).not.toContainText(value);
  }
}

test('duplicate abuse detection works after logout/login switch in the same browser', async ({ browser }) => {
  test.setTimeout(60_000);

  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const accessId = '12121212-1212-4212-8212-121212121212';
  const linkedAccount = {
    userId: 31,
    email: 'same-browser-linked@example.invalid',
    displayName: 'Same Browser Linked',
    sessionId: 'sess_same_browser_linked_before',
    sessionToken: 'sess_same_browser_linked_before',
  };
  const switchedAccount = {
    userId: 32,
    email: 'same-browser-switched@example.invalid',
    displayName: 'Same Browser Switched',
    sessionId: 'sess_same_browser_switched_after_login',
    sessionToken: 'sess_same_browser_switched_after_login',
  };
  const callTitle = 'Same Browser Switch Call';
  const linkedCallAccessSession = 'sess_same_browser_linked_call_access';
  const deniedCallAccessSession = 'sess_same_browser_switched_should_not_bind';
  const accountsByToken = new Map([
    [linkedAccount.sessionToken, linkedAccount],
    [linkedCallAccessSession, { ...linkedAccount, sessionId: linkedCallAccessSession, sessionToken: linkedCallAccessSession }],
    [switchedAccount.sessionToken, switchedAccount],
  ]);
  const privateNeedles = [
    accessId,
    linkedAccount.email,
    linkedAccount.displayName,
    'Same Browser Private Host',
    'same-browser-host@example.invalid',
    deniedCallAccessSession,
  ];
  const { context, page } = await createAccountPage(browser, baseURL, null);
  let activeAccount = linkedAccount;
  const routeHits = { logout: 0, sessions: [] };

  try {
    await routeAuthenticatedSessionState(page, accountsByToken);
    await page.route('**/api/auth/logout', async (route) => {
      routeHits.logout += 1;
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ status: 'ok', result: { post_logout_landing_url: '' } }),
      });
    });
    await page.route(`**/api/call-access/${accessId}/join`, async (route) => {
      if (activeAccount.userId === linkedAccount.userId) {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify(resolvedJoinPayload({ accessId, callTitle })),
        });
        return;
      }

      await route.fulfill({
        status: 403,
        contentType: 'application/json',
        body: JSON.stringify(duplicateReviewPayload({
          account: switchedAccount,
          accessFingerprint: 'sha256:same-browser-logout-login-switch',
          stage: 'same_browser_logout_login_switch',
        })),
      });
    });
    await page.route(`**/api/call-access/${accessId}/session`, async (route) => {
      routeHits.sessions.push({ label: activeAccount.email, body: readPostJson(route.request()) });
      if (activeAccount.userId === linkedAccount.userId) {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify(sessionStartedPayload({ account: linkedAccount, sessionToken: linkedCallAccessSession, callTitle })),
        });
        return;
      }

      await route.fulfill({
        status: 409,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'error',
          error: { code: 'call_access_conflict', message: 'Unexpected switched account session start.' },
          result: { session: { id: deniedCallAccessSession, token: deniedCallAccessSession } },
        }),
      });
    });

    await page.goto('/');
    await expect(setRuntimeSession(page, linkedAccount)).resolves.toMatchObject({
      userId: linkedAccount.userId,
      sessionId: linkedAccount.sessionId,
      sessionToken: linkedAccount.sessionToken,
      email: linkedAccount.email,
    });
    await page.goto(`/join/${accessId}`);
    const linkedDialog = page.getByRole('dialog', { name: 'Join video call' });
    await expect(linkedDialog).toContainText(callTitle, { timeout: 20_000 });
    await linkedDialog.getByRole('button', { name: /^Join call$/ }).click();
    await expect.poll(async () => (await readStoredSession(page)).sessionToken).toBe(linkedCallAccessSession);

    const logoutResult = await page.evaluate(async () => {
      const { logoutSession, sessionState } = await import('/src/domain/auth/session.ts');
      await logoutSession();
      return { userId: sessionState.userId, sessionId: sessionState.sessionId, sessionToken: sessionState.sessionToken };
    });
    expect(logoutResult).toEqual({ userId: 0, sessionId: '', sessionToken: '' });
    expect(routeHits.logout).toBe(1);

    activeAccount = switchedAccount;
    await expect(setRuntimeSession(page, switchedAccount)).resolves.toMatchObject({
      userId: switchedAccount.userId,
      sessionId: switchedAccount.sessionId,
      sessionToken: switchedAccount.sessionToken,
      email: switchedAccount.email,
    });

    const duplicateResponsePromise = page.waitForResponse((response) => (
      response.url().includes(`/api/call-access/${accessId}/join`)
      && response.request().method() === 'GET'
    ));
    await page.goto(`/join/${accessId}`);
    const duplicateBody = await (await duplicateResponsePromise).text();
    expect(duplicateBody).toContain('duplicate_personalized_link');
    expect(duplicateBody).toContain('same_browser_logout_login_switch');
    for (const value of privateNeedles) {
      expect(duplicateBody.toLowerCase()).not.toContain(value.toLowerCase());
    }

    const switchedDialog = page.getByRole('dialog', { name: 'Join video call' });
    await expect(switchedDialog).toBeVisible({ timeout: 20_000 });
    await expect(switchedDialog.getByRole('button', { name: /^Join call$/ })).toHaveCount(0);
    await expectDialogOmits(switchedDialog, privateNeedles);
    expect(routeHits.sessions.filter((entry) => entry.label === switchedAccount.email)).toHaveLength(0);
    expect(await readRuntimeSession(page)).toMatchObject({
      userId: switchedAccount.userId,
      sessionId: switchedAccount.sessionId,
      sessionToken: switchedAccount.sessionToken,
      email: switchedAccount.email,
    });
    expect(page.url()).toContain(`/join/${accessId}`);
    expect(page.url()).not.toContain('/workspace/call');
  } finally {
    await context.close();
  }
});

test('e2e_duplicate_link_007/e2e_duplicate_link_008 cross-device and cross-browser duplicate use is review-flagged', async ({ browser }) => {
  test.setTimeout(60_000);

  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const accessId = '23232323-2323-4232-8232-232323232323';
  const callTitle = 'Cross Device Duplicate Call';
  const linkedAccount = {
    label: 'linked-device-a',
    userId: 41,
    email: 'linked-device-a@example.invalid',
    displayName: 'Linked Device A',
    sessionId: 'sess_linked_device_a_before',
    sessionToken: 'sess_linked_device_a_before',
  };
  const deviceAccount = {
    label: 'foreign-device-b',
    userId: 42,
    email: 'foreign-device-b@example.invalid',
    displayName: 'Foreign Device B',
    sessionId: 'sess_foreign_device_b',
    sessionToken: 'sess_foreign_device_b',
  };
  const browserAccount = {
    label: 'foreign-browser-c',
    userId: 43,
    email: 'foreign-browser-c@example.invalid',
    displayName: 'Foreign Browser C',
    sessionId: 'sess_foreign_browser_c',
    sessionToken: 'sess_foreign_browser_c',
  };
  const linkedCallAccessSession = 'sess_linked_device_a_call_access';
  const deniedDeviceSession = 'sess_foreign_device_should_not_bind';
  const deniedBrowserSession = 'sess_foreign_browser_should_not_bind';
  const privateNeedles = [
    accessId,
    linkedAccount.email,
    linkedAccount.displayName,
    'Cross Device Private Host',
    'cross-device-host@example.invalid',
    deniedDeviceSession,
    deniedBrowserSession,
  ];
  const contexts = [];
  const routes = { join: [], sessions: [] };

  async function createDuplicateContext(account, options, stage, fingerprint) {
    const entry = await createAccountPage(browser, baseURL, account, options);
    contexts.push(entry.context);
    await routeAuthenticatedSessionState(entry.page, new Map([[account.sessionToken, account]]));
    await entry.page.route(`**/api/call-access/${accessId}/join`, async (route) => {
      routes.join.push({
        label: account.label,
        userAgent: route.request().headers()['user-agent'] || '',
      });
      await route.fulfill({
        status: 403,
        contentType: 'application/json',
        body: JSON.stringify(duplicateReviewPayload({ account, accessFingerprint: fingerprint, stage })),
      });
    });
    await entry.page.route(`**/api/call-access/${accessId}/session`, async (route) => {
      routes.sessions.push({ label: account.label, body: readPostJson(route.request()) });
      await route.fulfill({ status: 500, contentType: 'application/json', body: '{}' });
    });
    return entry;
  }

  try {
    const linked = await createAccountPage(browser, baseURL, linkedAccount, {
      userAgent: 'King IAM E2E Linked Device A Chromium',
    });
    contexts.push(linked.context);
    await routeAuthenticatedSessionState(linked.page, new Map([[linkedAccount.sessionToken, linkedAccount]]));
    await linked.page.route(`**/api/call-access/${accessId}/join`, async (route) => {
      routes.join.push({
        label: linkedAccount.label,
        userAgent: route.request().headers()['user-agent'] || '',
      });
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(resolvedJoinPayload({ accessId, callTitle })),
      });
    });
    await linked.page.route(`**/api/call-access/${accessId}/session`, async (route) => {
      routes.sessions.push({ label: linkedAccount.label, body: readPostJson(route.request()) });
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(sessionStartedPayload({ account: linkedAccount, sessionToken: linkedCallAccessSession, callTitle })),
      });
    });

    await linked.page.goto(`/join/${accessId}`);
    const linkedDialog = linked.page.getByRole('dialog', { name: 'Join video call' });
    await expect(linkedDialog).toContainText(callTitle, { timeout: 20_000 });
    await linkedDialog.getByRole('button', { name: /^Join call$/ }).click();
    await expect.poll(async () => (await readStoredSession(linked.page)).sessionToken).toBe(linkedCallAccessSession);

    const device = await createDuplicateContext(
      deviceAccount,
      { viewport: { width: 390, height: 844 }, userAgent: 'King IAM E2E Mobile Device B' },
      'cross_device_duplicate',
      'sha256:cross-device-duplicate',
    );
    const otherBrowser = await createDuplicateContext(
      browserAccount,
      { userAgent: 'Mozilla/5.0 King IAM E2E Firefox Browser C' },
      'cross_browser_duplicate',
      'sha256:cross-browser-duplicate',
    );

    const deviceResponsePromise = device.page.waitForResponse((response) => (
      response.url().includes(`/api/call-access/${accessId}/join`)
      && response.request().method() === 'GET'
    ));
    const browserResponsePromise = otherBrowser.page.waitForResponse((response) => (
      response.url().includes(`/api/call-access/${accessId}/join`)
      && response.request().method() === 'GET'
    ));
    await Promise.all([
      device.page.goto(`/join/${accessId}`),
      otherBrowser.page.goto(`/join/${accessId}`),
    ]);
    const [deviceBody, browserBody] = await Promise.all([
      (await deviceResponsePromise).text(),
      (await browserResponsePromise).text(),
    ]);
    expect(deviceBody).toContain('duplicate_personalized_link');
    expect(deviceBody).toContain('cross_device_duplicate');
    expect(browserBody).toContain('duplicate_personalized_link');
    expect(browserBody).toContain('cross_browser_duplicate');
    for (const value of privateNeedles) {
      expect(deviceBody.toLowerCase()).not.toContain(value.toLowerCase());
      expect(browserBody.toLowerCase()).not.toContain(value.toLowerCase());
    }

    const deviceDialog = device.page.getByRole('dialog', { name: 'Join video call' });
    const browserDialog = otherBrowser.page.getByRole('dialog', { name: 'Join video call' });
    await expect(deviceDialog.getByRole('button', { name: /^Join call$/ })).toHaveCount(0);
    await expect(browserDialog.getByRole('button', { name: /^Join call$/ })).toHaveCount(0);
    await expectDialogOmits(deviceDialog, privateNeedles);
    await expectDialogOmits(browserDialog, privateNeedles);
    expect(routes.sessions.filter((entry) => entry.label === deviceAccount.label)).toHaveLength(0);
    expect(routes.sessions.filter((entry) => entry.label === browserAccount.label)).toHaveLength(0);
    expect(await readRuntimeSession(device.page)).toMatchObject({
      userId: deviceAccount.userId,
      sessionId: deviceAccount.sessionId,
      sessionToken: deviceAccount.sessionToken,
    });
    expect(await readRuntimeSession(otherBrowser.page)).toMatchObject({
      userId: browserAccount.userId,
      sessionId: browserAccount.sessionId,
      sessionToken: browserAccount.sessionToken,
    });
    expect(routes.join).toEqual(expect.arrayContaining([
      expect.objectContaining({ label: deviceAccount.label, userAgent: 'King IAM E2E Mobile Device B' }),
      expect.objectContaining({ label: browserAccount.label, userAgent: 'Mozilla/5.0 King IAM E2E Firefox Browser C' }),
    ]));
  } finally {
    await Promise.allSettled(contexts.map((context) => context.close()));
  }
});
