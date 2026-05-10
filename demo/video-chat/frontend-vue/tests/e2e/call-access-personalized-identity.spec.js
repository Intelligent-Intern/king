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

async function installAdmissionSocketShim(context) {
  await context.addInitScript(() => {
    const listenersSymbol = Symbol('listeners');
    window.__iamPersonalizedIdentitySocketFrames = [];

    class FakeWebSocket {
      static CONNECTING = 0;
      static OPEN = 1;
      static CLOSING = 2;
      static CLOSED = 3;

      constructor(url) {
        this.url = String(url || '');
        this.readyState = FakeWebSocket.CONNECTING;
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
        window.__iamPersonalizedIdentitySocketFrames.push(payload);
        if (payload.type === 'lobby/queue/join') {
          this.emit({
            type: 'lobby/snapshot',
            room_id: 'lobby',
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

async function createPublicJoinPage(browser, baseURL) {
  const context = await browser.newContext({ baseURL, permissions: ['camera', 'microphone'] });
  await installMediaDeviceShim(context);
  await installAdmissionSocketShim(context);
  const page = await context.newPage();
  return { context, page };
}

async function seedStoredSession(context, session) {
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

function authSessionPayload(account) {
  return {
    status: 'ok',
    result: { state: 'authenticated' },
    session: {
      id: account.sessionId,
      token: account.sessionToken,
      expires_at: account.expiresAt,
    },
    user: {
      id: account.userId,
      email: account.email,
      display_name: account.displayName,
      role: account.role || 'user',
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

function personalJoinPayload({ accessId, callId, callTitle, targetUserId }) {
  return {
    status: 'ok',
    result: {
      state: 'resolved',
      access_link: { id: accessId, target_user_id: targetUserId },
      link_kind: 'personal',
      call: {
        id: callId,
        room_id: 'lobby',
        title: callTitle,
      },
      target_hint: { participant_email: null },
      join_path: `/join/${accessId}`,
    },
  };
}

function sessionSuccessPayload({ sessionToken, account, callId, callTitle }) {
  return {
    status: 'ok',
    result: {
      session: {
        id: sessionToken,
        token: sessionToken,
        expires_at: '2026-09-01T10:05:00Z',
      },
      user: {
        id: account.userId,
        email: account.email,
        display_name: account.displayName,
        role: account.role || 'user',
        status: 'active',
      },
      tenant: {
        id: 1,
        uuid: 'tenant-1',
        label: 'Intelligent Intern',
        role: 'member',
        permissions: { tenant_admin: false },
      },
      call: {
        id: callId,
        room_id: 'lobby',
        title: callTitle,
      },
    },
  };
}

test('logged-out personalized link starts the linked call session without identity proof', async ({ browser }) => {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const accessId = '66666666-6666-4666-8666-666666666666';
  const callId = 'logged-out-personalized-call';
  const callTitle = 'Logged Out Personalized Link Call';
  const participant = {
    userId: 2,
    email: 'linked-logged-out@example.invalid',
    displayName: 'Linked Logged Out User',
  };
  const callAccessSessionToken = 'sess_logged_out_personalized_call_access';
  const { context, page } = await createPublicJoinPage(browser, baseURL);
  let joinGetCount = 0;
  let sessionPostCount = 0;
  let sessionAuthorization = '';
  let sessionBody = undefined;

  try {
    await page.route('**/api/auth/session-state', async (route) => {
      const authorization = route.request().headers().authorization || '';
      const authenticated = authorization === `Bearer ${callAccessSessionToken}`;
      await route.fulfill({
        status: authenticated ? 200 : 401,
        contentType: 'application/json',
        body: JSON.stringify(authenticated ? authSessionPayload({
          ...participant,
          sessionId: callAccessSessionToken,
          sessionToken: callAccessSessionToken,
          expiresAt: '2026-09-01T10:05:00Z',
        }) : {
          status: 'error',
          error: { code: 'auth_failed', message: 'A valid session token is required.' },
        }),
      });
    });

    await page.route(`**/api/call-access/${accessId}/join`, async (route) => {
      joinGetCount += 1;
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(personalJoinPayload({
          accessId,
          callId,
          callTitle,
          targetUserId: participant.userId,
        })),
      });
    });

    await page.route(`**/api/call-access/${accessId}/session`, async (route) => {
      sessionPostCount += 1;
      sessionAuthorization = route.request().headers().authorization || '';
      sessionBody = parseJsonPostData(route.request());
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(sessionSuccessPayload({
          sessionToken: callAccessSessionToken,
          account: participant,
          callId,
          callTitle,
        })),
      });
    });

    await page.goto(`/join/${accessId}`);
    const joinDialog = page.getByRole('dialog', { name: 'Join video call' });
    await expect(joinDialog).toBeVisible({ timeout: 20_000 });
    await expect(joinDialog).toContainText(callTitle);
    await expect(joinDialog).toContainText('Personalized link');

    await joinDialog.getByRole('button', { name: /^Join call$/ }).click();
    await expect(joinDialog).toContainText(/Connecting to lobby|Call owner has been notified|Waiting for host/i, { timeout: 20_000 });

    expect(sessionAuthorization).toBe('');
    expect(sessionBody).toBeNull();
    expect(joinGetCount).toBe(1);
    expect(sessionPostCount).toBe(1);

    const storedSession = await readStoredSession(page);
    expect(storedSession.sessionId).toBe(callAccessSessionToken);
    expect(storedSession.sessionToken).toBe(callAccessSessionToken);
  } finally {
    await context.close();
  }
});

test('same-account personalized link sends verified identity proof and adopts only its own session', async ({ browser }) => {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const accessId = '77777777-7777-4777-8777-777777777777';
  const callId = 'same-account-personalized-call';
  const callTitle = 'Same Account Personalized Link Call';
  const account = {
    userId: 2,
    email: 'same-account@example.invalid',
    displayName: 'Same Account User',
    sessionId: 'sess_same_account_before_join',
    sessionToken: 'sess_same_account_before_join',
    expiresAt: '2026-09-01T10:00:00Z',
  };
  const callAccessSessionToken = 'sess_same_account_call_access';
  const foreignNeedles = [
    'wrong-account@example.invalid',
    'Wrong Account User',
    'sess_wrong_account_should_not_bind',
    'Foreign Same Account Call',
  ];
  const { context, page } = await createPublicJoinPage(browser, baseURL);
  let sessionStateAuthorization = '';
  let sessionAuthorization = '';
  let sessionBody = null;

  try {
    await seedStoredSession(context, account);

    await page.route('**/api/auth/session-state', async (route) => {
      sessionStateAuthorization = route.request().headers().authorization || '';
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(authSessionPayload(account)),
      });
    });

    await page.route(`**/api/call-access/${accessId}/join`, async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(personalJoinPayload({
          accessId,
          callId,
          callTitle,
          targetUserId: account.userId,
        })),
      });
    });

    await page.route(`**/api/call-access/${accessId}/session`, async (route) => {
      sessionAuthorization = route.request().headers().authorization || '';
      sessionBody = parseJsonPostData(route.request());
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
    await expect(joinDialog).toBeVisible({ timeout: 20_000 });
    await expect(joinDialog).toContainText(callTitle);
    for (const value of foreignNeedles) {
      await expect(joinDialog, `same-account dialog must not render ${value}`).not.toContainText(value);
    }

    await joinDialog.getByRole('button', { name: /^Join call$/ }).click();
    await expect(joinDialog).toContainText(/Connecting to lobby|Call owner has been notified|Waiting for host/i, { timeout: 20_000 });

    expect(sessionStateAuthorization).toBe(`Bearer ${account.sessionToken}`);
    expect(sessionAuthorization).toBe(`Bearer ${account.sessionToken}`);
    expect(sessionBody).toEqual({
      verified_user_id: account.userId,
      verified_session_id: account.sessionId,
    });

    const storedSession = await readStoredSession(page);
    expect(storedSession.sessionId).toBe(callAccessSessionToken);
    expect(storedSession.sessionToken).toBe(callAccessSessionToken);
    expect(JSON.stringify(storedSession)).not.toContain('sess_wrong_account_should_not_bind');
  } finally {
    await context.close();
  }
});
