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

async function expectTextDoesNotContain(locator, values, label) {
  const lowerText = String(await locator.innerText()).toLowerCase();
  for (const value of values) {
    const needle = String(value || '').trim().toLowerCase();
    if (needle === '') continue;
    expect(lowerText, `${label} must not contain ${value}`).not.toContain(needle);
  }
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

async function installAdmissionSocketShim(context) {
  await context.addInitScript(() => {
    const listenersSymbol = Symbol('listeners');
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
      call: {
        id: callId,
        room_id: 'lobby',
        title: callTitle,
      },
    },
  };
}

test('foreign personalized strong mismatch verifies host, declines update, and keeps logged-in account', async ({ browser }) => {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const accessId = '12121212-1212-4121-8121-121212121212';
  const callId = 'strong-mismatch-host-verification-call';
  const callTitle = 'Verified Host Flow Call';
  const realHostName = 'Private Verified Host';
  const acceptedSessionToken = 'sess_strong_mismatch_host_verified';
  const loggedInAccount = {
    userId: 44,
    email: 'current-account@example.invalid',
    displayName: 'Current Logged In Account',
    sessionId: 'sess_current_account_before_host_verification',
    sessionToken: 'sess_current_account_before_host_verification',
    expiresAt: '2026-09-01T10:00:00Z',
  };
  const foreignNeedles = [
    'Foreign Link Invitee',
    'foreign-invitee@example.invalid',
    'foreign-target-session',
    realHostName,
    'host@example.invalid',
  ];

  const context = await browser.newContext({ baseURL, permissions: ['camera', 'microphone'] });
  await installMediaDeviceShim(context);
  await installAdmissionSocketShim(context);
  await seedStoredSession(context, loggedInAccount);
  const page = await context.newPage();

  let sessionPostCount = 0;
  let accountUpdateRequests = 0;
  const sessionBodies = [];

  try {
    await page.route('**/api/auth/session-state', async (route) => {
      const authorization = route.request().headers().authorization || '';
      const token = authorization.replace(/^Bearer\s+/i, '');
      const account = token === acceptedSessionToken
        ? { ...loggedInAccount, sessionId: acceptedSessionToken, sessionToken: acceptedSessionToken }
        : loggedInAccount;
      await route.fulfill({
        status: authorization ? 200 : 401,
        contentType: 'application/json',
        body: JSON.stringify(authorization ? authSessionPayload(account) : {
          status: 'error',
          error: { code: 'auth_failed', message: 'A valid session token is required.' },
        }),
      });
    });

    await page.route(`**/api/call-access/${accessId}/join`, async (route) => {
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
            },
          },
        }),
      });
    });

    await page.route(`**/api/call-access/${accessId}/session`, async (route) => {
      sessionPostCount += 1;
      const body = parseJsonPostData(route.request());
      sessionBodies.push(body);
      const hostName = String(body?.host_name || '');
      if (hostName !== realHostName) {
        await route.fulfill({
          status: 403,
          contentType: 'application/json',
          body: JSON.stringify({
            status: 'error',
            error: {
              code: 'call_access_forbidden',
              message: 'Call access link is not allowed for this call participant.',
              details: {
                mismatch: 'strong_personalized_link',
                review: 'manual_review_required',
                fields: { auth: 'not_bound_to_current_user', host_name: 'wrong_host_name' },
              },
            },
          }),
        });
        return;
      }

      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(sessionSuccessPayload({
          sessionToken: acceptedSessionToken,
          account: { ...loggedInAccount, sessionId: acceptedSessionToken, sessionToken: acceptedSessionToken },
          callId,
          callTitle,
        })),
      });
    });

    await page.route(`**/api/call-access/${accessId}/account-update-confirmation`, async (route) => {
      accountUpdateRequests += 1;
      await route.fulfill({
        status: 500,
        contentType: 'application/json',
        body: JSON.stringify({ status: 'error', error: { code: 'unexpected_account_update_request' } }),
      });
    });

    await page.goto(`/join/${accessId}`);

    const joinDialog = page.getByRole('dialog', { name: 'Join video call' });
    await expect(joinDialog).toBeVisible({ timeout: 20_000 });
    await expect(joinDialog).toContainText('This link may have been issued for someone else.');
    await expect(joinDialog).toContainText('The link details differ from the account you are currently using.');
    await expect(joinDialog.getByLabel('Host name')).toBeVisible();
    await expectTextDoesNotContain(joinDialog, foreignNeedles, 'host verification warning');

    const wrongHostResponse = page.waitForResponse((response) => (
      response.url().includes(`/api/call-access/${accessId}/session`)
      && response.request().method() === 'POST'
    ));
    await joinDialog.getByLabel('Host name').fill('Definitely Wrong Host');
    await joinDialog.getByRole('button', { name: 'Verify host' }).click();
    expect((await wrongHostResponse).status()).toBe(403);
    await expect(joinDialog).toContainText('Access was not granted. This attempt may be reviewed manually.');
    await expect(joinDialog).not.toContainText(/Call owner has been notified|Waiting for host/i);
    expect(await readStoredSession(page)).toMatchObject({
      sessionToken: loggedInAccount.sessionToken,
    });

    const correctHostResponse = page.waitForResponse((response) => (
      response.url().includes(`/api/call-access/${accessId}/session`)
      && response.request().method() === 'POST'
    ));
    await joinDialog.getByLabel('Host name').fill(realHostName);
    await joinDialog.getByRole('button', { name: 'Verify host' }).click();
    const correctResponse = await correctHostResponse;
    expect(correctResponse.status()).toBe(200);
    const correctPayload = await correctResponse.json();
    expect(correctPayload?.result?.user).toMatchObject({
      id: loggedInAccount.userId,
      email: loggedInAccount.email,
      display_name: loggedInAccount.displayName,
    });

    await expect(joinDialog).toContainText('Host name confirmed');
    await expect(joinDialog).toContainText('Do you want to update your account data before joining?');
    await expect(joinDialog.getByRole('button', { name: 'Continue without updating' })).toBeVisible();
    await expect(joinDialog.getByRole('button', { name: 'Update account data' })).toBeVisible();
    await expectTextDoesNotContain(joinDialog, foreignNeedles, 'host verification success confirmation');

    await joinDialog.getByRole('button', { name: 'Continue without updating' }).click();
    await expect(joinDialog).toContainText(/Call owner has been notified|Waiting for host/i);

    const storedSession = await readStoredSession(page);
    expect(storedSession.sessionToken).toBe(acceptedSessionToken);
    expect(storedSession.sessionToken).not.toBe('foreign-target-session');
    expect(accountUpdateRequests).toBe(0);
    expect(sessionPostCount).toBe(2);
    expect(sessionBodies).toEqual([
      {
        verified_user_id: loggedInAccount.userId,
        verified_session_id: loggedInAccount.sessionId,
        host_name: 'Definitely Wrong Host',
      },
      {
        verified_user_id: loggedInAccount.userId,
        verified_session_id: loggedInAccount.sessionId,
        host_name: realHostName,
      },
    ]);
  } finally {
    await context.close();
  }
});
