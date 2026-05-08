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

function resolvedJoinPayload({ accessId, callTitle = 'Parallel Account Tabs Call' }) {
  return {
    status: 'ok',
    result: {
      state: 'resolved',
      access_link: { id: accessId },
      link_kind: 'personal',
      call: {
        id: 'parallel-account-call',
        room_id: 'lobby',
        title: callTitle,
      },
      target_hint: { participant_email: null },
      join_path: `/join/${accessId}`,
    },
  };
}

function sessionStartedPayload({ account, sessionToken, callTitle = 'Parallel Account Tabs Call' }) {
  return {
    status: 'ok',
    result: {
      state: 'session_started',
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
        id: 'parallel-account-call',
        room_id: 'lobby',
        title: callTitle,
      },
    },
  };
}

function readPostJson(request) {
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
    window.__iamParallelAccountSocketFrames = [];

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
        window.__iamParallelAccountSocketFrames.push(payload);
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
  await context.addInitScript(({ key, session }) => {
    localStorage.setItem(key, JSON.stringify(session));
  }, { key: sessionStorageKey, session: storedSessionFor(account) });

  const page = await context.newPage();
  return { context, page };
}

async function createAccountTab(browser, baseURL, account, accessId, routes) {
  const { context, page } = await createAccountPage(browser, baseURL, account);
  await page.route('**/api/auth/session-state', async (route) => {
    const authorization = route.request().headers().authorization || '';
    routes.sessionState.push({ label: account.label, authorization });
    if (authorization !== `Bearer ${account.sessionToken}`) {
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

  await page.route(`**/api/call-access/${accessId}/join`, async (route) => {
    routes.join.push({
      label: account.label,
      authorization: route.request().headers().authorization || '',
    });
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(resolvedJoinPayload({ accessId })),
    });
  });

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

async function expectDialogOmits(dialog, values) {
  for (const value of values) {
    await expect(dialog, `duplicate denial must not render ${value}`).not.toContainText(value);
  }
}

test('e2e_duplicate_link_005/e2e_duplicate_link_006 parallel account tabs detect duplicate use without merging sessions', async ({ browser }) => {
  test.setTimeout(60_000);

  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const accessId = '99999999-9999-4999-8999-999999999999';
  const linkedAccount = {
    label: 'linked',
    userId: 21,
    email: 'parallel-linked@example.invalid',
    displayName: 'Parallel Linked Account',
    sessionId: 'sess_parallel_linked_before',
    sessionToken: 'sess_parallel_linked_before',
  };
  const otherAccount = {
    label: 'other',
    userId: 22,
    email: 'parallel-other@example.invalid',
    displayName: 'Parallel Other Account',
    sessionId: 'sess_parallel_other_before',
    sessionToken: 'sess_parallel_other_before',
  };
  const linkedCallAccessSession = 'sess_parallel_linked_call_access';
  const deniedCallAccessSession = 'sess_parallel_other_should_not_bind';
  const foreignNeedles = [
    linkedAccount.email,
    linkedAccount.displayName,
    'Parallel Private Host',
    'parallel-host@example.invalid',
    deniedCallAccessSession,
  ];
  const routes = { sessionState: [], join: [], sessions: [] };
  const tabs = [];

  try {
    const linkedTab = await createAccountTab(browser, baseURL, linkedAccount, accessId, routes);
    tabs.push(linkedTab);
    await linkedTab.page.route(`**/api/call-access/${accessId}/session`, async (route) => {
      routes.sessions.push({
        label: linkedAccount.label,
        authorization: route.request().headers().authorization || '',
        body: readPostJson(route.request()),
      });
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(sessionStartedPayload({ account: linkedAccount, sessionToken: linkedCallAccessSession })),
      });
    });

    await linkedTab.page.goto(`/join/${accessId}`);
    const linkedDialog = linkedTab.page.getByRole('dialog', { name: 'Join video call' });
    await expect(linkedDialog).toBeVisible({ timeout: 20_000 });
    await expect(linkedDialog).toContainText('Parallel Account Tabs Call');

    const otherTab = await createAccountTab(browser, baseURL, otherAccount, accessId, routes);
    tabs.push(otherTab);
    await otherTab.page.route(`**/api/call-access/${accessId}/session`, async (route) => {
      routes.sessions.push({
        label: otherAccount.label,
        authorization: route.request().headers().authorization || '',
        body: readPostJson(route.request()),
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
              fields: {
                auth: 'not_bound_to_current_user',
                host_name: 'not_verified',
              },
              mismatch: 'strong_personalized_link',
              review: {
                flag: 'duplicate_personalized_link',
                state: 'manual_review_required',
                access_fingerprint: 'sha256:parallel-account-access',
                raw_link_identifier_logged: false,
                account_email_logged: false,
                host_name_logged: false,
              },
            },
          },
          result: {
            session: { id: deniedCallAccessSession, token: deniedCallAccessSession },
            user: {
              id: linkedAccount.userId,
              email: linkedAccount.email,
              display_name: linkedAccount.displayName,
            },
            call: {
              title: 'Parallel Private Host Call',
              owner: {
                display_name: 'Parallel Private Host',
                email: 'parallel-host@example.invalid',
              },
            },
          },
        }),
      });
    });

    await otherTab.page.goto(`/join/${accessId}`);
    const otherDialog = otherTab.page.getByRole('dialog', { name: 'Join video call' });
    await expect(otherDialog).toBeVisible({ timeout: 20_000 });
    await expect(otherDialog).toContainText('Parallel Account Tabs Call');

    const linkedSessionResponse = linkedTab.page.waitForResponse((response) => (
      response.url().includes(`/api/call-access/${accessId}/session`)
      && response.request().method() === 'POST'
    ));
    const otherSessionResponse = otherTab.page.waitForResponse((response) => (
      response.url().includes(`/api/call-access/${accessId}/session`)
      && response.request().method() === 'POST'
    ));

    await Promise.all([
      linkedDialog.getByRole('button', { name: /^Join call$/ }).click(),
      otherDialog.getByRole('button', { name: /^Join call$/ }).click(),
    ]);

    const [linkedResponse, otherResponse] = await Promise.all([linkedSessionResponse, otherSessionResponse]);
    expect(linkedResponse.status()).toBe(200);
    expect(otherResponse.status()).toBe(409);

    expect(routes.sessionState).toEqual(expect.arrayContaining([
      { label: 'linked', authorization: `Bearer ${linkedAccount.sessionToken}` },
      { label: 'other', authorization: `Bearer ${otherAccount.sessionToken}` },
    ]));
    expect(routes.join).toEqual(expect.arrayContaining([
      { label: 'linked', authorization: `Bearer ${linkedAccount.sessionToken}` },
      { label: 'other', authorization: `Bearer ${otherAccount.sessionToken}` },
    ]));
    expect(routes.sessions).toEqual(expect.arrayContaining([
      {
        label: 'linked',
        authorization: `Bearer ${linkedAccount.sessionToken}`,
        body: {
          verified_user_id: linkedAccount.userId,
          verified_session_id: linkedAccount.sessionId,
        },
      },
      {
        label: 'other',
        authorization: `Bearer ${otherAccount.sessionToken}`,
        body: {
          verified_user_id: otherAccount.userId,
          verified_session_id: otherAccount.sessionId,
        },
      },
    ]));

    await expect(linkedDialog).toContainText(/Call owner has been notified|Waiting for host/i, { timeout: 20_000 });
    await expect(otherDialog).toContainText(/cannot be used for the current call state/i);
    await expect(otherDialog).not.toContainText(/Call owner has been notified|Waiting for host/i);
    await expectDialogOmits(otherDialog, foreignNeedles);

    const linkedStoredSession = await readStoredSession(linkedTab.page);
    expect(linkedStoredSession.sessionId).toBe(linkedCallAccessSession);
    expect(linkedStoredSession.sessionToken).toBe(linkedCallAccessSession);

    const otherRuntimeSession = await readRuntimeSession(otherTab.page);
    expect(otherRuntimeSession).toMatchObject({
      userId: otherAccount.userId,
      sessionId: otherAccount.sessionId,
      sessionToken: otherAccount.sessionToken,
      email: otherAccount.email,
    });
    expect(otherRuntimeSession.sessionToken).not.toBe(deniedCallAccessSession);
    expect(otherRuntimeSession.sessionToken).not.toBe(linkedCallAccessSession);
    expect(otherTab.page.url()).toContain(`/join/${accessId}`);
    expect(otherTab.page.url()).not.toContain('/workspace/call');
  } finally {
    await Promise.allSettled(tabs.map(({ context }) => context.close()));
  }
});
