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

function resolvedJoinPayload({ accessId, callId, callTitle, targetUserId }) {
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

function sessionStartedPayload({ sessionId, account, callId, callTitle }) {
  return {
    status: 'ok',
    result: {
      state: 'session_started',
      session: { id: sessionId, token: sessionId, expires_at: '2026-09-01T10:05:00Z' },
      user: { id: account.userId, email: account.email, display_name: account.displayName, role: 'user', status: 'active' },
      call: { id: callId, room_id: callId, title: callTitle },
      link_kind: 'personal',
    },
  };
}

function duplicateReviewPayload({ subjectUserId, targetUserRef = 'sha256:linked-account', stage = 'join_opened_parallel' }) {
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
          access_fingerprint: 'sha256:duplicate-race-access',
          subject_user_id: subjectUserId,
          affected_user_ref: targetUserRef,
          first_seen_user_ref: targetUserRef,
          active_call_session: stage === 'join_opened_after_active_call_session',
          raw_link_identifier_logged: false,
          account_email_logged: false,
          host_name_logged: false,
        },
      },
    },
  };
}

async function installSocketShim(context) {
  await context.addInitScript(() => {
    window.WebSocket = class FakeWebSocket {
      static OPEN = 1;
      static CLOSED = 3;
      constructor() {
        this.readyState = FakeWebSocket.OPEN;
        this.listeners = {};
        setTimeout(() => this.dispatch('open', {}), 0);
      }
      addEventListener(type, callback) {
        this.listeners[type] = [...(this.listeners[type] || []), callback];
      }
      removeEventListener(type, callback) {
        this.listeners[type] = (this.listeners[type] || []).filter((entry) => entry !== callback);
      }
      dispatch(type, event) {
        for (const callback of this.listeners[type] || []) callback(event);
      }
      send() {}
      close() {
        this.readyState = FakeWebSocket.CLOSED;
        this.dispatch('close', { code: 1000, reason: 'test_close' });
      }
    };
  });
}

async function createJoinPage(browser, baseURL, account) {
  const context = await browser.newContext({ baseURL, permissions: ['camera', 'microphone'] });
  await installMediaDeviceShim(context);
  await installSocketShim(context);
  await context.addInitScript(({ key, session }) => {
    localStorage.setItem(key, JSON.stringify(session));
  }, { key: sessionStorageKey, session: storedSessionFor(account) });
  const page = await context.newPage();
  return { context, page };
}

async function routeSessionState(page, account) {
  await page.route('**/api/auth/session-state', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(authSessionPayload(account)) });
  });
}

function jsonPostData(request) {
  try {
    return JSON.parse(request.postData() || '{}');
  } catch {
    return {};
  }
}

async function readStoredSession(page) {
  return page.evaluate((key) => JSON.parse(localStorage.getItem(key) || '{}'), sessionStorageKey);
}

function expectBodyOmitsSecrets(body, secrets, label) {
  const lowerBody = String(body || '').toLowerCase();
  for (const secret of secrets) {
    expect(lowerBody, `${label} must not contain ${secret}`).not.toContain(String(secret).toLowerCase());
  }
}

test('security duplicate group detects concurrent personalized-link use by two accounts without inconsistent assignment', async ({ browser }) => {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const accessId = '77777777-7777-4777-8777-777777777777';
  const callId = 'duplicate-race-call';
  const callTitle = 'Duplicate Race Call';
  const linkedAccount = {
    userId: 31,
    email: 'linked-race@example.invalid',
    displayName: 'Linked Race Account',
    sessionId: 'sess_linked_race_auth',
    sessionToken: 'sess_linked_race_auth',
  };
  const foreignAccount = {
    userId: 32,
    email: 'foreign-race@example.invalid',
    displayName: 'Foreign Race Account',
    sessionId: 'sess_foreign_race_auth',
    sessionToken: 'sess_foreign_race_auth',
  };
  const linked = await createJoinPage(browser, baseURL, linkedAccount);
  const foreign = await createJoinPage(browser, baseURL, foreignAccount);
  const sessionRequests = [];
  const joinRequests = [];
  let releaseJoinRequests = () => {};
  const bothJoinRequests = new Promise((resolve) => { releaseJoinRequests = resolve; });

  try {
    await Promise.all([routeSessionState(linked.page, linkedAccount), routeSessionState(foreign.page, foreignAccount)]);
    await linked.page.route(`**/api/call-access/${accessId}/join`, async (route) => {
      joinRequests.push('linked');
      if (joinRequests.length >= 2) releaseJoinRequests();
      await bothJoinRequests;
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(resolvedJoinPayload({ accessId, callId, callTitle, targetUserId: linkedAccount.userId })),
      });
    });
    await foreign.page.route(`**/api/call-access/${accessId}/join`, async (route) => {
      joinRequests.push('foreign');
      if (joinRequests.length >= 2) releaseJoinRequests();
      await bothJoinRequests;
      await route.fulfill({
        status: 403,
        contentType: 'application/json',
        body: JSON.stringify(duplicateReviewPayload({ subjectUserId: foreignAccount.userId })),
      });
    });
    await linked.page.route(`**/api/call-access/${accessId}/session`, async (route) => {
      sessionRequests.push({ authorization: route.request().headers().authorization || '', body: jsonPostData(route.request()) });
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(sessionStartedPayload({
          sessionId: 'sess_linked_race_call_access',
          account: linkedAccount,
          callId,
          callTitle,
        })),
      });
    });
    await foreign.page.route(`**/api/call-access/${accessId}/session`, async (route) => {
      sessionRequests.push({ foreignUnexpected: true, body: jsonPostData(route.request()) });
      await route.fulfill({ status: 500, contentType: 'application/json', body: '{}' });
    });

    const foreignJoinResponsePromise = foreign.page.waitForResponse((response) => response.url().includes(`/api/call-access/${accessId}/join`));
    await Promise.all([linked.page.goto(`/join/${accessId}`), foreign.page.goto(`/join/${accessId}`)]);
    const foreignJoinBody = await (await foreignJoinResponsePromise).text();
    expect(foreignJoinBody).toContain('duplicate_personalized_link');
    expect(foreignJoinBody).toContain('manual_review_required');
    expect(foreignJoinBody).not.toContain('"access_id"');
    expectBodyOmitsSecrets(foreignJoinBody, [accessId, linkedAccount.email, linkedAccount.displayName], 'parallel duplicate response');

    const linkedDialog = linked.page.getByRole('dialog', { name: 'Join video call' });
    const foreignDialog = foreign.page.getByRole('dialog', { name: 'Join video call' });
    await expect(linkedDialog.getByRole('button', { name: /^Join call$/ })).toBeVisible({ timeout: 20_000 });
    await expect(foreignDialog.getByRole('button', { name: /^Join call$/ })).toHaveCount(0);

    await linkedDialog.getByRole('button', { name: /^Join call$/ }).click();
    await expect.poll(async () => (await readStoredSession(linked.page)).sessionToken).toBe('sess_linked_race_call_access');
    const foreignSession = await readStoredSession(foreign.page);
    expect(foreignSession.sessionToken).toBe(foreignAccount.sessionToken);
    expect(sessionRequests).toHaveLength(1);
    expect(sessionRequests[0]).toMatchObject({
      authorization: `Bearer ${linkedAccount.sessionToken}`,
      body: { verified_user_id: linkedAccount.userId, verified_session_id: linkedAccount.sessionId },
    });
  } finally {
    await Promise.allSettled([linked.context.close(), foreign.context.close()]);
  }
});

test('security duplicate group marks later foreign use after the linked account is already in the call as suspicious', async ({ browser }) => {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const accessId = '99999999-9999-4999-8999-999999999999';
  const callId = 'duplicate-used-in-call';
  const callTitle = 'Duplicate Used In Call';
  const linkedAccount = {
    userId: 41,
    email: 'linked-used@example.invalid',
    displayName: 'Linked Used Account',
    sessionId: 'sess_linked_used_auth',
    sessionToken: 'sess_linked_used_auth',
  };
  const foreignAccount = {
    userId: 42,
    email: 'foreign-used@example.invalid',
    displayName: 'Foreign Used Account',
    sessionId: 'sess_foreign_used_auth',
    sessionToken: 'sess_foreign_used_auth',
  };
  const linked = await createJoinPage(browser, baseURL, linkedAccount);
  const foreign = await createJoinPage(browser, baseURL, foreignAccount);
  let foreignSessionPosts = 0;

  try {
    await Promise.all([routeSessionState(linked.page, linkedAccount), routeSessionState(foreign.page, foreignAccount)]);
    await linked.page.route(`**/api/call-access/${accessId}/join`, async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(resolvedJoinPayload({ accessId, callId, callTitle, targetUserId: linkedAccount.userId })),
      });
    });
    await linked.page.route(`**/api/call-access/${accessId}/session`, async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(sessionStartedPayload({
          sessionId: 'sess_linked_used_call_access',
          account: linkedAccount,
          callId,
          callTitle,
        })),
      });
    });
    await linked.page.goto(`/join/${accessId}`);
    await linked.page.getByRole('dialog', { name: 'Join video call' }).getByRole('button', { name: /^Join call$/ }).click();
    await expect.poll(async () => (await readStoredSession(linked.page)).sessionToken).toBe('sess_linked_used_call_access');

    await foreign.page.route(`**/api/call-access/${accessId}/join`, async (route) => {
      await route.fulfill({
        status: 403,
        contentType: 'application/json',
        body: JSON.stringify(duplicateReviewPayload({
          subjectUserId: foreignAccount.userId,
          stage: 'join_opened_after_active_call_session',
        })),
      });
    });
    await foreign.page.route(`**/api/call-access/${accessId}/session`, async (route) => {
      foreignSessionPosts += 1;
      await route.fulfill({ status: 500, contentType: 'application/json', body: '{}' });
    });

    const foreignJoinResponsePromise = foreign.page.waitForResponse((response) => response.url().includes(`/api/call-access/${accessId}/join`));
    await foreign.page.goto(`/join/${accessId}`);
    const foreignJoinBody = await (await foreignJoinResponsePromise).text();
    expect(foreignJoinBody).toContain('duplicate_personalized_link');
    expect(foreignJoinBody).toContain('join_opened_after_active_call_session');
    expect(foreignJoinBody).toContain('active_call_session');
    expectBodyOmitsSecrets(foreignJoinBody, [accessId, linkedAccount.email, linkedAccount.displayName], 'after-call duplicate response');
    await expect(foreign.page.getByRole('dialog', { name: 'Join video call' }).getByRole('button', { name: /^Join call$/ })).toHaveCount(0);
    expect(foreignSessionPosts).toBe(0);
    expect((await readStoredSession(foreign.page)).sessionToken).toBe(foreignAccount.sessionToken);
  } finally {
    await Promise.allSettled([linked.context.close(), foreign.context.close()]);
  }
});
