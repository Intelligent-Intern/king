import { test, expect } from '@playwright/test';

import {
  accessIdFromJoinPath,
  getSeedAccessLink,
  getSeedCall,
  getSeedScenario,
  getSeedUser,
  installCallAccessFakeRealtime,
  installCallAccessMediaDeviceShim,
  installCallAccessSeedRoutes,
  installStoredSeedSession,
  sessionStorageKey,
  storedSessionForSeedUser,
} from './helpers/callAccessSeedMatrix.js';

function escapeRegExp(input) {
  return String(input).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function forbiddenMainJourneyNeedles() {
  const betaCall = getSeedCall('beta_active');
  const betaAdmin = getSeedUser('beta_org_admin');
  const systemAdmin = getSeedUser('system_admin');
  return [
    betaCall.id,
    betaCall.title,
    betaAdmin.email,
    betaAdmin.display_name,
    systemAdmin.email,
  ];
}

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

function participantRow(user, callRole = 'participant', inviteState = 'allowed') {
  return {
    user_id: user.id,
    display_name: user.display_name,
    email: user.email,
    call_role: callRole,
    invite_state: inviteState,
    joined_at: null,
    connected_at: null,
  };
}

function admittedCallPayload(call, user) {
  const owner = getSeedUser(call.owner_user_key);
  const participants = [participantRow(owner, 'owner', 'allowed')];
  if (Number(owner.id) !== Number(user.id)) {
    participants.push(participantRow(user, 'participant', 'allowed'));
  }

  return {
    id: call.id,
    room_id: call.room_id,
    title: call.title,
    status: call.status,
    starts_at: call.starts_at,
    ends_at: call.ends_at,
    owner: {
      user_id: owner.id,
      display_name: owner.display_name,
      email: owner.email,
    },
    participants: {
      total: participants.length,
      internal: participants,
      external: [],
    },
    my_participation: {
      call_role: Number(owner.id) === Number(user.id) ? 'owner' : 'participant',
      invite_state: 'allowed',
    },
  };
}

function admittedAccessDecision() {
  return {
    allowed: true,
    reason: 'call_access_lobby_admitted',
    source: 'call_access_link',
    scope: 'call',
    can_manage_lobby: false,
    can_admit: false,
    can_reject: false,
    can_kick: false,
  };
}

async function installAdmittedWorkspaceRoutes(page, { call, user }) {
  let admitted = false;
  const callPayload = admittedCallPayload(call, user);

  await page.route(`**/api/calls/resolve/${call.id}*`, async (route) => {
    if (!admitted) {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'ok',
          result: {
            state: 'forbidden',
            resolved_as: 'call_id',
            reason: 'lobby_admission_required',
            access_link: null,
            call: null,
          },
          time: '2026-05-08T10:00:00.000Z',
        }),
      });
      return;
    }

    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        status: 'ok',
        result: {
          state: 'resolved',
          resolved_as: 'call_id',
          access_link: null,
          access_decision: admittedAccessDecision(),
          call: callPayload,
        },
        time: '2026-05-08T10:00:00.000Z',
      }),
    });
  });

  await page.route(`**/api/calls/${call.id}*`, async (route) => {
    if (!admitted) {
      await route.fulfill({
        status: 403,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'error',
          error: { code: 'calls_forbidden', message: 'Lobby admission is required.' },
        }),
      });
      return;
    }

    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        status: 'ok',
        call: callPayload,
        time: '2026-05-08T10:00:00.000Z',
      }),
    });
  });

  return {
    admit() {
      admitted = true;
    },
  };
}

async function createJourneyPage(browser, baseURL, {
  scenarioKey,
  storedSessionUserKey = '',
  storedSessionCallKey = 'alpha_active',
}) {
  const scenario = getSeedScenario(scenarioKey);
  const link = getSeedAccessLink(scenario.link_key);
  const context = await browser.newContext({ baseURL, permissions: ['camera', 'microphone'] });
  if (storedSessionUserKey !== '') {
    await installStoredSeedSession(context, storedSessionUserKey, storedSessionCallKey);
  }
  await installCallAccessSeedRoutes(context);
  await installCallAccessMediaDeviceShim(context);
  await installCallAccessFakeRealtime(context, {
    linkKey: link.key,
    userKey: scenario.principal_user_key,
  });
  const page = await context.newPage();
  return { context, page, scenario, link };
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

async function waitForAdmissionSocket(page) {
  await page.waitForFunction(() => {
    const sockets = Array.isArray(window.__iamCallAccessSockets) ? window.__iamCallAccessSockets : [];
    return sockets.some((socket) => socket?.readyState === WebSocket.OPEN);
  }, null, { timeout: 20_000 });
}

async function emitAdmission(page, { call, user }) {
  await waitForAdmissionSocket(page);
  await page.evaluate(({ roomId, callId, userId, displayName }) => {
    const sockets = Array.isArray(window.__iamCallAccessSockets) ? window.__iamCallAccessSockets : [];
    const openSockets = sockets.filter((candidate) => (
      candidate?.readyState === WebSocket.OPEN && typeof candidate.emit === 'function'
    ));
    if (openSockets.length === 0) {
      throw new Error('IAM call-access fake realtime socket is not ready.');
    }
    const payload = {
      type: 'lobby/snapshot',
      room_id: roomId,
      call_id: callId,
      pending: [],
      admitted: [{
        user_id: userId,
        display_name: displayName,
        role: 'user',
        admitted_unix_ms: 1_778_000_001_000,
        admitted_at: '2026-05-08T10:00:01.000Z',
        admitted_by: {
          user_id: 0,
          display_name: 'IAM Smoke Host',
          role: 'user',
        },
      }],
      rejected: [],
      reason: 'iam_main_journey_smoke_admit',
    };
    for (const socket of openSockets) {
      socket.emit(payload);
    }
  }, {
    roomId: call.room_id,
    callId: call.id,
    userId: user.id,
    displayName: user.display_name,
  });
}

async function waitForWorkspace(page, call) {
  await page.waitForURL(new RegExp(`/workspace/call/${escapeRegExp(call.id)}(?:[/?#].*)?$`), { timeout: 30_000 });
  await expect(page.locator('.workspace-call-view')).toBeVisible({ timeout: 20_000 });
  await page.waitForFunction(() => (
    (window.__iamCallAccessSocketFrames || []).some((frame) => frame?.type === 'room/snapshot/request')
  ), null, { timeout: 20_000 });
}

async function workspaceSecurityProbe(page) {
  return page.evaluate(() => {
    const setup = document.querySelector('.workspace-call-view')?.__vueParentComponent?.setupState;
    const frames = Array.isArray(window.__iamCallAccessSocketFrames) ? window.__iamCallAccessSocketFrames : [];
    return {
      canModerate: Boolean(setup?.canModerate),
      viewerCanModerateCall: Boolean(setup?.viewerCanModerateCall),
      roomLeaves: frames.filter((frame) => frame?.type === 'room/leave').length,
      snapshotRequests: frames.filter((frame) => frame?.type === 'room/snapshot/request').length,
    };
  });
}

test('e2e_journey_003 logged-in own personalized link keeps the account through lobby admission', async ({ browser }) => {
  test.setTimeout(90_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const scenarioKey = 'call_scoped_removed_member_personal_waits_for_host';
  const scenario = getSeedScenario(scenarioKey);
  const link = getSeedAccessLink(scenario.link_key);
  const call = getSeedCall(link.call_key);
  const user = getSeedUser(scenario.principal_user_key);
  const accessId = accessIdFromJoinPath(link.join_path);
  const storedBeforeJoin = storedSessionForSeedUser(user.key, link.call_key);
  const forbiddenNeedles = forbiddenMainJourneyNeedles();

  const { context, page } = await createJourneyPage(browser, baseURL, {
    scenarioKey,
    storedSessionUserKey: user.key,
    storedSessionCallKey: link.call_key,
  });
  const workspaceAdmission = await installAdmittedWorkspaceRoutes(page, { call, user });

  try {
    const joinResponsePromise = page.waitForResponse((response) => (
      response.url().includes(`/api/call-access/${accessId}/join`)
      && response.request().method() === 'GET'
    ));
    await page.goto(link.join_path);
    const joinResponse = await joinResponsePromise;
    expect(joinResponse.status()).toBe(200);
    const joinPayload = await joinResponse.json();
    expect(joinPayload?.result?.link_kind).toBe('personal');
    expect(joinPayload?.result?.target_user?.id).toBe(user.id);
    expect(joinPayload?.result?.call?.id).toBe(call.id);
    noMediaSecretPayload(joinPayload, 'personalized join payload must not expose media/auth secrets');
    expectNoForbiddenNeedles(JSON.stringify(joinPayload), forbiddenNeedles, 'personalized join payload');

    const joinDialog = page.getByRole('dialog', { name: 'Join video call' });
    await expect(joinDialog).toBeVisible({ timeout: 20_000 });
    await expect(joinDialog).toContainText(call.title);
    await expect(joinDialog).toContainText('Personalized link');
    for (const needle of forbiddenNeedles) {
      await expect(joinDialog, `dialog must not render ${needle}`).not.toContainText(needle);
    }

    const sessionResponsePromise = page.waitForResponse((response) => (
      response.url().includes(`/api/call-access/${accessId}/session`)
      && response.request().method() === 'POST'
    ));
    await joinDialog.getByRole('button', { name: /^Join call$/ }).click();
    const sessionResponse = await sessionResponsePromise;
    expect(sessionResponse.status()).toBe(200);
    const sessionRequest = sessionResponse.request();
    expect(sessionRequest.headers().authorization).toBe(`Bearer ${storedBeforeJoin.sessionToken}`);
    expect(sessionRequest.postDataJSON()).toEqual({
      verified_user_id: user.id,
      verified_session_id: storedBeforeJoin.sessionId,
    });

    const sessionPayload = await sessionResponse.json();
    expect(sessionPayload?.result?.user?.id).toBe(user.id);
    expect(sessionPayload?.result?.user?.account_type).toBe('account');
    expect(Boolean(sessionPayload?.result?.user?.is_guest)).toBe(false);
    expect(sessionPayload?.result?.tenant?.permissions?.platform_admin ?? false).toBe(false);
    expect(sessionPayload?.result?.tenant?.permissions?.tenant_admin ?? false).toBe(false);
    expect(sessionPayload?.result?.tenant?.permissions?.manage_lobby ?? false).toBe(false);
    expect(sessionPayload?.result?.tenant?.permissions?.admit_participants ?? false).toBe(false);
    noMediaSecretPayload(sessionPayload, 'personalized session payload must not expose media/auth secrets');
    expectNoForbiddenNeedles(JSON.stringify(sessionPayload), forbiddenNeedles, 'personalized session payload');

    await expect(joinDialog).toContainText(/Call owner has been notified|Waiting for host/i, { timeout: 20_000 });
    const queuedFrames = await page.evaluate(() => window.__iamCallAccessSocketFrames || []);
    expect(queuedFrames.some((frame) => frame?.type === 'lobby/queue/join')).toBe(true);

    const storedDuringLobby = await readStoredSession(page);
    expect(storedDuringLobby.sessionToken).toBe(sessionPayload?.result?.session?.token);
    expect(storedDuringLobby.sessionId).toBe(sessionPayload?.result?.session?.id);
    expect(storedDuringLobby.sessionToken).not.toBe(storedBeforeJoin.sessionToken);

    workspaceAdmission.admit();
    await emitAdmission(page, { call, user });
    await waitForWorkspace(page, call);
    await expect(page.locator('button.tab-lobby')).toHaveCount(0);
    await expect(page.locator('button[title="Allow user"]:not([disabled])')).toHaveCount(0);

    const security = await workspaceSecurityProbe(page);
    expect(security.canModerate).toBe(false);
    expect(security.viewerCanModerateCall).toBe(false);
    expect(security.snapshotRequests).toBeGreaterThan(0);
    expectNoForbiddenNeedles(await page.locator('body').innerText(), forbiddenNeedles, 'personalized workspace');
  } finally {
    await context.close();
  }
});

test('e2e_journey_010 logged-out anonymous link creates a least-privilege guest, admits, leaves, and rejoins', async ({ browser }) => {
  test.setTimeout(120_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const scenarioKey = 'anonymous_open_logged_out_creates_temporary_guest_waits_for_host';
  const scenario = getSeedScenario(scenarioKey);
  const link = getSeedAccessLink(scenario.link_key);
  const call = getSeedCall(link.call_key);
  const user = getSeedUser(scenario.principal_user_key);
  const accessId = accessIdFromJoinPath(link.join_path);
  const guestName = 'Anonymous Main Journey Guest';
  const forbiddenNeedles = forbiddenMainJourneyNeedles();

  const { context, page } = await createJourneyPage(browser, baseURL, { scenarioKey });
  const workspaceAdmission = await installAdmittedWorkspaceRoutes(page, { call, user });

  try {
    const joinResponsePromise = page.waitForResponse((response) => (
      response.url().includes(`/api/call-access/${accessId}/join`)
      && response.request().method() === 'GET'
    ));
    await page.goto(link.join_path);
    const joinResponse = await joinResponsePromise;
    expect(joinResponse.status()).toBe(200);
    const joinPayload = await joinResponse.json();
    expect(joinPayload?.result?.link_kind).toBe('open');
    expect(joinPayload?.result?.target_user).toBeNull();
    expect(joinPayload?.result?.call?.id).toBe(call.id);
    noMediaSecretPayload(joinPayload, 'anonymous join payload must not expose media/auth secrets');
    expectNoForbiddenNeedles(JSON.stringify(joinPayload), forbiddenNeedles, 'anonymous join payload');

    const joinDialog = page.getByRole('dialog', { name: 'Join video call' });
    await expect(joinDialog).toBeVisible({ timeout: 20_000 });
    await expect(joinDialog).toContainText(call.title);
    await expect(joinDialog).toContainText('Free-for-all link');
    await joinDialog.getByPlaceholder('Enter your display name').fill(guestName);

    const sessionResponsePromise = page.waitForResponse((response) => (
      response.url().includes(`/api/call-access/${accessId}/session`)
      && response.request().method() === 'POST'
    ));
    await joinDialog.getByRole('button', { name: /^Join call$/ }).click();
    const sessionResponse = await sessionResponsePromise;
    expect(sessionResponse.status()).toBe(200);
    const sessionRequest = sessionResponse.request();
    expect(sessionRequest.headers().authorization || '').toBe('');
    expect(sessionRequest.postDataJSON()).toEqual({ guest_name: guestName });

    const sessionPayload = await sessionResponse.json();
    expect(sessionPayload?.result?.user?.id).toBe(user.id);
    expect(sessionPayload?.result?.user?.account_type).toBe('guest');
    expect(Boolean(sessionPayload?.result?.user?.is_guest)).toBe(true);
    expect(sessionPayload?.result?.user?.role).toBe('user');
    expect(sessionPayload?.result?.tenant?.permissions?.platform_admin ?? false).toBe(false);
    expect(sessionPayload?.result?.tenant?.permissions?.tenant_admin ?? false).toBe(false);
    expect(sessionPayload?.result?.tenant?.permissions?.manage_lobby ?? false).toBe(false);
    expect(sessionPayload?.result?.tenant?.permissions?.admit_participants ?? false).toBe(false);
    noMediaSecretPayload(sessionPayload, 'anonymous session payload must not expose media/auth secrets');
    expectNoForbiddenNeedles(JSON.stringify(sessionPayload), forbiddenNeedles, 'anonymous session payload');

    await expect(joinDialog).toContainText(/Call owner has been notified|Waiting for host/i, { timeout: 20_000 });
    const queuedFrames = await page.evaluate(() => window.__iamCallAccessSocketFrames || []);
    expect(queuedFrames.some((frame) => frame?.type === 'lobby/queue/join')).toBe(true);

    const storedDuringLobby = await readStoredSession(page);
    expect(storedDuringLobby.sessionToken).toBe(sessionPayload?.result?.session?.token);
    expect(storedDuringLobby.sessionId).toBe(sessionPayload?.result?.session?.id);

    workspaceAdmission.admit();
    await emitAdmission(page, { call, user });
    await waitForWorkspace(page, call);
    await expect(page.locator('button.tab-lobby')).toHaveCount(0);
    await expect(page.locator('button[title="Allow user"]:not([disabled])')).toHaveCount(0);

    const firstWorkspaceSecurity = await workspaceSecurityProbe(page);
    expect(firstWorkspaceSecurity.canModerate).toBe(false);
    expect(firstWorkspaceSecurity.viewerCanModerateCall).toBe(false);
    expectNoForbiddenNeedles(await page.locator('body').innerText(), forbiddenNeedles, 'anonymous workspace');

    await page.getByTitle('Hang up').click();
    await expect(page).toHaveURL(/\/call-goodbye(?:[/?#].*)?$/, { timeout: 20_000 });
    const afterLeaveSession = await readStoredSession(page);
    expect(afterLeaveSession.sessionToken).toBe(storedDuringLobby.sessionToken);

    await page.goto(`/workspace/call/${call.id}?entry=invite`);
    await waitForWorkspace(page, call);
    const rejoinedSecurity = await workspaceSecurityProbe(page);
    expect(rejoinedSecurity.canModerate).toBe(false);
    expect(rejoinedSecurity.viewerCanModerateCall).toBe(false);
    expect(rejoinedSecurity.snapshotRequests).toBeGreaterThan(0);
    await expect(page.locator('button.tab-lobby')).toHaveCount(0);
  } finally {
    await context.close();
  }
});
