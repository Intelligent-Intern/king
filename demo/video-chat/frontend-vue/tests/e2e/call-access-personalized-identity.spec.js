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

function expectTextDoesNotContain(text, values, label) {
  const lowerText = String(text || '').toLowerCase();
  for (const value of values) {
    const needle = String(value || '').trim().toLowerCase();
    if (needle === '') continue;
    expect(lowerText, `${label} must not contain ${value}`).not.toContain(needle);
  }
}

async function createPublicJoinPage(browser, baseURL) {
  const context = await browser.newContext({ baseURL, permissions: ['camera', 'microphone'] });
  await installMediaDeviceShim(context);
  await installAdmissionSocketShim(context);
  const page = await context.newPage();
  return { context, page };
}

function isSessionStateProbe(response) {
  return response.url().includes('/api/auth/session-state')
    && response.request().method() === 'GET';
}

async function installAdmissionSocketShim(context) {
  await context.addInitScript(() => {
    const listenersSymbol = Symbol('listeners');
    window.__iamPersonalizedIdentitySocketFrames = [];
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
        window.__iamPersonalizedIdentitySocketFrames.push(payload);
        if (payload.type === 'lobby/queue/join') {
          this.emit({
            type: 'lobby/snapshot',
            room_id: 'lobby',
            pending: [], admitted: [], rejected: [],
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
    void context.close().catch(() => {});
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

test('session switch after verified personalized link fails without rebinding or leaking data', async ({ browser }) => {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const accessId = '22222222-2222-4222-8222-222222222222';
  const callId = 'call-access-login-switch-call';
  const callTitle = 'Verified Link Call';
  const foreignTitle = 'Foreign Switched Account Call';
  const foreignEmail = 'foreign-switch@example.invalid';
  const rejectedCallAccessToken = 'sess_foreign_call_access_should_not_bind';
  const verifiedSession = {
    userId: 2,
    email: 'user@intelligent-intern.com',
    displayName: 'Standard Verified User',
    sessionId: 'sess_verified_standard',
    sessionToken: 'sess_verified_standard',
    expiresAt: '2026-09-01T10:00:00Z',
  };
  const switchedSession = {
    sessionId: 'sess_current_admin',
    sessionToken: 'sess_current_admin',
    expiresAt: '2026-09-01T10:00:00Z',
  };
  const { context, page } = await createPublicJoinPage(browser, baseURL);
  let sessionStateAuthorization = '';
  let joinGetCount = 0;
  let sessionPostCount = 0;
  let sessionAuthorization = '';
  let sessionBody = null;

  try {
    await seedStoredSession(context, verifiedSession);

    await page.route('**/api/auth/session-state', async (route) => {
      sessionStateAuthorization = route.request().headers().authorization || '';
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(authSessionPayload(verifiedSession)),
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
          targetUserId: verifiedSession.userId,
        })),
      });
    });

    await page.route(`**/api/call-access/${accessId}/session`, async (route) => {
      sessionPostCount += 1;
      sessionAuthorization = route.request().headers().authorization || '';
      sessionBody = parseJsonPostData(route.request());
      await route.fulfill({
        status: 409,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'error',
          error: {
            code: 'call_access_conflict',
            message: `Rejected switched account for ${foreignEmail}`,
          },
          result: {
            session: {
              id: rejectedCallAccessToken,
              token: rejectedCallAccessToken,
              expires_at: '2026-09-01T10:05:00Z',
            },
            user: {
              id: 99,
              email: foreignEmail,
              display_name: 'Foreign Switched User',
              role: 'user',
            },
            call: {
              id: 'foreign-call-id',
              title: foreignTitle,
            },
          },
        }),
      });
    });

    const sessionStateResponsePromise = page.waitForResponse(isSessionStateProbe);
    await page.goto(`/join/${accessId}`);
    expect((await sessionStateResponsePromise).status()).toBe(200);
    expect(sessionStateAuthorization).toBe(`Bearer ${verifiedSession.sessionToken}`);

    const joinDialog = page.getByRole('dialog', { name: 'Join video call' });
    await expect(joinDialog).toBeVisible({ timeout: 20_000 });
    await expect(joinDialog).toContainText(callTitle);

    const switchedSnapshot = await page.evaluate(async ({ key, session }) => {
      const { sessionState } = await import('/src/domain/auth/session.ts');
      sessionState.role = 'admin';
      sessionState.displayName = 'Switched Admin';
      sessionState.email = 'admin@intelligent-intern.com';
      sessionState.userId = 1;
      sessionState.accountType = 'account';
      sessionState.isGuest = false;
      sessionState.sessionId = session.sessionId;
      sessionState.sessionToken = session.sessionToken;
      sessionState.expiresAt = session.expiresAt;
      sessionState.recovered = true;
      localStorage.setItem(key, JSON.stringify(session));
      return {
        userId: sessionState.userId,
        sessionId: sessionState.sessionId,
        sessionToken: sessionState.sessionToken,
      };
    }, { key: sessionStorageKey, session: switchedSession });
    expect(switchedSnapshot).toEqual({
      userId: 1,
      sessionId: switchedSession.sessionId,
      sessionToken: switchedSession.sessionToken,
    });

    await joinDialog.getByRole('button', { name: /^Join call$/ }).click();
    await expect(joinDialog).toContainText('This call link cannot be used for the current call state.');

    expect(sessionPostCount).toBe(1);
    expect(sessionAuthorization).toBe(`Bearer ${switchedSession.sessionToken}`);
    expect(sessionBody).toEqual({
      verified_user_id: verifiedSession.userId,
      verified_session_id: verifiedSession.sessionId,
    });
    await expect(joinDialog).not.toContainText(foreignTitle);
    await expect(joinDialog).not.toContainText(foreignEmail);
    await expect(joinDialog).not.toContainText(rejectedCallAccessToken);

    const storedSession = await readStoredSession(page);
    expect(storedSession.sessionId).toBe(switchedSession.sessionId);
    expect(storedSession.sessionToken).toBe(switchedSession.sessionToken);
    expect(storedSession.sessionToken).not.toBe(rejectedCallAccessToken);
    expect(page.url()).toContain(`/join/${accessId}`);
    expect(joinGetCount).toBe(1);
  } finally {
    await context.close();
  }
});

test('logout after verified personalized link fails closed before session issuance', async ({ browser }) => {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const accessId = '55555555-5555-4555-8555-555555555555';
  const callId = 'call-access-logout-verified-call';
  const safeCallTitle = 'Verified Logout Link Call';
  const foreignTitle = 'Foreign Logout Session Call';
  const foreignInviteEmail = 'foreign-logout-invitee@example.invalid';
  const foreignHostName = 'Private Logout Host';
  const foreignHostEmail = 'private-logout-host@example.invalid';
  const rejectedSessionToken = 'sess_logout_foreign_should_not_bind';
  const verifiedSession = {
    userId: 2,
    email: 'verified-logout-user@example.invalid',
    displayName: 'Verified Logout User',
    sessionId: 'sess_verified_before_logout',
    sessionToken: 'sess_verified_before_logout',
    expiresAt: '2026-09-01T10:00:00Z',
  };
  const foreignNeedles = [
    foreignTitle,
    foreignInviteEmail,
    foreignHostName,
    foreignHostEmail,
    rejectedSessionToken,
  ];
  const { context, page } = await createPublicJoinPage(browser, baseURL);
  let sessionStateAuthorization = '';
  let joinGetCount = 0;
  let sessionPostCount = 0;
  let logoutPostCount = 0;
  const navigations = [];

  try {
    await seedStoredSession(context, verifiedSession);

    page.on('framenavigated', (frame) => {
      if (frame === page.mainFrame()) navigations.push(frame.url());
    });

    await page.route('**/api/auth/session-state', async (route) => {
      sessionStateAuthorization = route.request().headers().authorization || '';
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(authSessionPayload(verifiedSession)),
      });
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
      joinGetCount += 1;
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(personalJoinPayload({
          accessId,
          callId,
          callTitle: safeCallTitle,
          targetUserId: verifiedSession.userId,
        })),
      });
    });

    await page.route(`**/api/call-access/${accessId}/session`, async (route) => {
      sessionPostCount += 1;
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'ok',
          result: {
            session: {
              id: rejectedSessionToken,
              token: rejectedSessionToken,
              expires_at: '2026-09-01T10:05:00Z',
            },
            user: {
              id: 99,
              email: foreignInviteEmail,
              display_name: 'Foreign Logout Invitee',
              role: 'user',
            },
            call: {
              id: 'foreign-logout-call-id',
              title: foreignTitle,
              owner: {
                display_name: foreignHostName,
                email: foreignHostEmail,
              },
            },
          },
        }),
      });
    });

    const sessionStateResponsePromise = page.waitForResponse(isSessionStateProbe);
    await page.goto(`/join/${accessId}`);
    expect((await sessionStateResponsePromise).status()).toBe(200);
    expect(sessionStateAuthorization).toBe(`Bearer ${verifiedSession.sessionToken}`);

    const joinDialog = page.getByRole('dialog', { name: 'Join video call' });
    await expect(joinDialog).toBeVisible({ timeout: 20_000 });
    await expect(joinDialog).toContainText(safeCallTitle);

    const logoutResult = await page.evaluate(async () => {
      const { logoutSession, sessionState } = await import('/src/domain/auth/session.ts');
      await logoutSession();
      return {
        userId: sessionState.userId,
        sessionId: sessionState.sessionId,
        sessionToken: sessionState.sessionToken,
      };
    });
    expect(logoutResult).toEqual({ userId: 0, sessionId: '', sessionToken: '' });
    expect(logoutPostCount).toBe(1);

    await joinDialog.getByRole('button', { name: /^Join call$/ }).click();
    await expect(joinDialog).toContainText('This call link cannot be used for the current call state.');
    await page.waitForTimeout(300);

    expect(sessionPostCount).toBe(0);
    expect(joinGetCount).toBe(1);
    expect(page.url()).toContain(`/join/${accessId}`);
    expect(page.url()).not.toContain('/workspace/call');
    expect(navigations.filter((url) => url.includes('/workspace/call'))).toEqual([]);
    for (const value of foreignNeedles) {
      await expect(joinDialog, `logout denial must not render ${value}`).not.toContainText(value);
    }

    const storedSession = await readStoredSession(page);
    expect(storedSession.sessionId || '').toBe('');
    expect(storedSession.sessionToken || '').toBe('');
    expect(JSON.stringify(storedSession)).not.toContain(rejectedSessionToken);
  } finally {
    await context.close();
  }
});

test('wrong-account strong personalized mismatch denies access without foreign data exposure', async ({ browser }) => {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const accessId = '33333333-3333-4333-8333-333333333333';
  const callId = 'strong-mismatch-call';
  const safeCallTitle = 'Strong Mismatch Waiting Room';
  const wrongHostName = 'Definitely Wrong Host';
  const linkInviteeName = 'Foreign Link Invitee';
  const linkInviteeEmail = 'foreign-link-invitee@example.invalid';
  const realHostName = 'Private Foreign Host';
  const realHostEmail = 'private-host@example.invalid';
  const deniedSessionToken = 'sess_denied_strong_mismatch_should_not_bind';
  const wrongAccount = {
    userId: 3,
    email: 'wrong-current-user@example.invalid',
    displayName: 'Wrong Current User',
    sessionId: 'sess_wrong_logged_in_user',
    sessionToken: 'sess_wrong_logged_in_user',
    expiresAt: '2026-09-01T10:00:00Z',
  };
  const foreignNeedles = [
    linkInviteeName,
    linkInviteeEmail,
    realHostName,
    realHostEmail,
    deniedSessionToken,
  ];
  const { context, page } = await createPublicJoinPage(browser, baseURL);
  let sessionStateAuthorization = '';
  let joinGetCount = 0;
  let sessionPostCount = 0;
  let sessionAuthorization = '';
  let sessionBody = null;

  try {
    await seedStoredSession(context, wrongAccount);

    await page.route('**/api/auth/session-state', async (route) => {
      sessionStateAuthorization = route.request().headers().authorization || '';
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(authSessionPayload(wrongAccount)),
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
          callTitle: safeCallTitle,
          targetUserId: 2,
        })),
      });
    });

    await page.route(`**/api/call-access/${accessId}/session`, async (route) => {
      sessionPostCount += 1;
      sessionAuthorization = route.request().headers().authorization || '';
      sessionBody = parseJsonPostData(route.request());
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
              fields: {
                host_name: 'wrong_host_name',
              },
            },
          },
        }),
      });
    });

    const joinResponsePromise = page.waitForResponse((response) => (
      response.url().includes(`/api/call-access/${accessId}/join`)
      && response.request().method() === 'GET'
    ));
    const sessionStateResponsePromise = page.waitForResponse(isSessionStateProbe);
    await page.goto(`/join/${accessId}`);
    expect((await sessionStateResponsePromise).status()).toBe(200);
    const joinResponse = await joinResponsePromise;
    expect(joinResponse.status()).toBe(200);
    expect(sessionStateAuthorization).toBe(`Bearer ${wrongAccount.sessionToken}`);
    expectTextDoesNotContain(await joinResponse.text(), foreignNeedles, 'strong-mismatch join response');

    const joinDialog = page.getByRole('dialog', { name: 'Join video call' });
    await expect(joinDialog).toBeVisible({ timeout: 20_000 });
    await expect(joinDialog).toContainText(safeCallTitle);
    for (const value of foreignNeedles) {
      await expect(joinDialog, `dialog must not render ${value}`).not.toContainText(value);
    }

    const sessionResponsePromise = page.waitForResponse((response) => (
      response.url().includes(`/api/call-access/${accessId}/session`)
      && response.request().method() === 'POST'
    ));
    await joinDialog.getByRole('button', { name: /^Join call$/ }).click();
    const sessionResponse = await sessionResponsePromise;
    expect(sessionResponse.status()).toBe(403);
    const sessionBodyText = await sessionResponse.text();
    expectTextDoesNotContain(sessionBodyText, foreignNeedles, 'strong-mismatch wrong-host denial response');
    const sessionPayload = JSON.parse(sessionBodyText);
    expect(sessionPayload?.error?.code).toBe('call_access_forbidden');
    expect(sessionPayload?.error?.details?.mismatch).toBe('strong_personalized_link');
    expect(sessionPayload?.error?.details?.fields?.host_name).toBe('wrong_host_name');

    expect(sessionPostCount).toBe(1);
    expect(sessionAuthorization).toBe(`Bearer ${wrongAccount.sessionToken}`);
    expect(sessionBody).toEqual({
      verified_user_id: wrongAccount.userId,
      verified_session_id: wrongAccount.sessionId,
    });

    await expect(joinDialog).toContainText('This call link is not available for your session.');
    await expect(joinDialog).not.toContainText(/Call owner has been notified|Waiting for host/i);
    for (const value of [...foreignNeedles, wrongHostName]) {
      await expect(joinDialog, `dialog denial must not render ${value}`).not.toContainText(value);
    }
    expect(page.url()).toContain(`/join/${accessId}`);
    expect(page.url()).not.toContain('/workspace/call');

    const storedSession = await readStoredSession(page);
    expect(storedSession.sessionId).toBe(wrongAccount.sessionId);
    expect(storedSession.sessionToken).toBe(wrongAccount.sessionToken);
    expect(storedSession.sessionToken).not.toBe(deniedSessionToken);
    expect(joinGetCount).toBe(1);
  } finally {
    await context.close();
  }
});
