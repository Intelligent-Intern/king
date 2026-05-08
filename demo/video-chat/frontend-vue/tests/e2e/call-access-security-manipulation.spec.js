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
    expiresAt: '2026-12-11T10:00:00Z',
  };
}

function authSessionPayload(account) {
  return {
    status: 'ok',
    result: { state: 'authenticated' },
    session: {
      id: account.sessionId,
      token: account.sessionToken,
      expires_at: '2026-12-11T10:00:00Z',
    },
    user: {
      id: account.userId,
      email: account.email,
      display_name: account.displayName,
      role: 'user',
      status: 'active',
    },
    tenant: {
      id: account.tenantId,
      uuid: `tenant-${account.tenantId}`,
      label: account.tenantLabel,
      role: 'member',
      permissions: { tenant_admin: false },
    },
  };
}

function personalJoinPayload({ accessId, callId, callTitle }) {
  return {
    status: 'ok',
    result: {
      state: 'resolved',
      access_link: { id: accessId },
      link_kind: 'personal',
      call: { id: callId, room_id: callId, title: callTitle },
      target_hint: { participant_email: null },
      join_path: `/join/${accessId}`,
    },
  };
}

function sessionSuccessPayload({ account, callId, callTitle }) {
  return {
    status: 'ok',
    result: {
      session: {
        id: account.issuedSessionToken,
        token: account.issuedSessionToken,
        expires_at: '2026-12-11T10:05:00Z',
      },
      user: {
        id: account.userId,
        email: account.email,
        display_name: account.displayName,
        role: 'user',
        status: 'active',
      },
      tenant: {
        id: account.tenantId,
        uuid: `tenant-${account.tenantId}`,
        label: account.tenantLabel,
        role: 'member',
        permissions: { tenant_admin: false },
      },
      call: { id: callId, room_id: callId, title: callTitle },
    },
  };
}

async function installAdmissionSocketShim(context) {
  await context.addInitScript(() => {
    const listenersSymbol = Symbol('listeners');
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
        this[listenersSymbol][type] = (this[listenersSymbol][type] || []).filter((item) => item !== callback);
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
          this.emit({ type: 'lobby/snapshot', room_id: payload.room_id || 'lobby', pending: [], admitted: [], rejected: [] });
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

async function createJoinPage(browser, baseURL, account) {
  const context = await browser.newContext({ baseURL, permissions: ['camera', 'microphone'] });
  await installMediaDeviceShim(context);
  await installAdmissionSocketShim(context);
  await context.addInitScript(({ key, session }) => {
    localStorage.setItem(key, JSON.stringify(session));
  }, { key: sessionStorageKey, session: storedSessionFor(account) });
  const page = await context.newPage();
  return { context, page };
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
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(authSessionPayload(account)) });
  });
}

async function routePersonalJoin(page, { accessId, callId, callTitle }) {
  await page.route(`**/api/call-access/${accessId}/join`, async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(personalJoinPayload({ accessId, callId, callTitle })),
    });
  });
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

test('parallel tabs in different authenticated contexts keep call-access accounts isolated', async ({ browser }) => {
  test.setTimeout(90_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const accountA = {
    userId: 41,
    email: 'parallel-alpha@example.invalid',
    displayName: 'Parallel Alpha',
    sessionId: 'sess_parallel_alpha_account',
    sessionToken: 'sess_parallel_alpha_account',
    issuedSessionToken: 'sess_parallel_alpha_call_access',
    tenantId: 101,
    tenantLabel: 'Parallel Tenant Alpha',
  };
  const accountB = {
    userId: 42,
    email: 'parallel-beta@example.invalid',
    displayName: 'Parallel Beta',
    sessionId: 'sess_parallel_beta_account',
    sessionToken: 'sess_parallel_beta_account',
    issuedSessionToken: 'sess_parallel_beta_call_access',
    tenantId: 102,
    tenantLabel: 'Parallel Tenant Beta',
  };
  const flowA = {
    accessId: '77777777-7777-4777-8777-777777777777',
    callId: 'parallel-alpha-call',
    callTitle: 'Parallel Alpha Call',
  };
  const flowB = {
    accessId: '88888888-8888-4888-8888-888888888888',
    callId: 'parallel-beta-call',
    callTitle: 'Parallel Beta Call',
  };
  const tabA = await createJoinPage(browser, baseURL, accountA);
  const tabB = await createJoinPage(browser, baseURL, accountB);
  const sessionRequests = [];
  let releaseBothRequests = () => {};
  const bothSessionRequests = new Promise((resolve) => {
    releaseBothRequests = resolve;
  });

  async function routeSessionStart(page, flow, account, label) {
    await page.route(`**/api/call-access/${flow.accessId}/session`, async (route) => {
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
          account,
          callId: flow.callId,
          callTitle: flow.callTitle,
        })),
      });
    });
  }

  try {
    await Promise.all([
      routeAuthenticatedSessionState(tabA.page, accountA),
      routeAuthenticatedSessionState(tabB.page, accountB),
      routePersonalJoin(tabA.page, flowA),
      routePersonalJoin(tabB.page, flowB),
      routeSessionStart(tabA.page, flowA, accountA, 'tab-a'),
      routeSessionStart(tabB.page, flowB, accountB, 'tab-b'),
    ]);

    await Promise.all([
      tabA.page.goto(`/join/${flowA.accessId}`),
      tabB.page.goto(`/join/${flowB.accessId}`),
    ]);
    const dialogA = tabA.page.getByRole('dialog', { name: 'Join video call' });
    const dialogB = tabB.page.getByRole('dialog', { name: 'Join video call' });
    await Promise.all([
      expect(dialogA).toContainText(flowA.callTitle, { timeout: 20_000 }),
      expect(dialogB).toContainText(flowB.callTitle, { timeout: 20_000 }),
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
    expect(sessionRequests.find((request) => request.label === 'tab-a')).toMatchObject({
      authorization: `Bearer ${accountA.sessionToken}`,
      body: {
        verified_user_id: accountA.userId,
        verified_session_id: accountA.sessionId,
      },
    });
    expect(sessionRequests.find((request) => request.label === 'tab-b')).toMatchObject({
      authorization: `Bearer ${accountB.sessionToken}`,
      body: {
        verified_user_id: accountB.userId,
        verified_session_id: accountB.sessionId,
      },
    });

    const storedA = await readStoredSession(tabA.page);
    const storedB = await readStoredSession(tabB.page);
    expect(storedA.sessionToken).toBe(accountA.issuedSessionToken);
    expect(storedB.sessionToken).toBe(accountB.issuedSessionToken);
    expect(JSON.stringify(storedA)).not.toContain(accountB.issuedSessionToken);
    expect(JSON.stringify(storedB)).not.toContain(accountA.issuedSessionToken);
    expect(tabA.page.url()).toContain(`/join/${flowA.accessId}`);
    expect(tabB.page.url()).toContain(`/join/${flowB.accessId}`);
    expect(tabA.page.url()).not.toContain('/workspace/call');
    expect(tabB.page.url()).not.toContain('/workspace/call');
  } finally {
    await Promise.allSettled([tabA.context.close(), tabB.context.close()]);
  }
});
