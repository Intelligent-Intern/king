import { test, expect } from '@playwright/test';

import {
  accessIdFromJoinPath,
  getSeedAccessLink,
  getSeedCall,
  getSeedScenario,
  getSeedUser,
  installCallAccessSeedRoutes,
  installStoredSeedSession,
  sessionStorageKey,
} from './helpers/callAccessSeedMatrix.js';
import {
  installCallAccessFakeRealtime,
  installCallAccessMediaDeviceShim,
} from './helpers/callAccessSeedRuntime.js';

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

function postDataJsonOrNull(request) {
  const raw = request.postData();
  if (!raw) return null;
  try {
    return JSON.parse(raw);
  } catch {
    return null;
  }
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
  let admissionState = 'waiting';
  const callPayload = admittedCallPayload(call, user);

  await page.route(`**/api/calls/resolve/${call.id}*`, async (route) => {
    if (admissionState !== 'admitted') {
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
    if (admissionState !== 'admitted') {
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
      admissionState = 'admitted';
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

async function readSocketFrames(page) {
  return page.evaluate(() => (
    Array.isArray(window.__iamCallAccessSocketFrames) ? window.__iamCallAccessSocketFrames : []
  ));
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
    const openSockets = sockets.filter((socket) => (
      socket?.readyState === WebSocket.OPEN && typeof socket.emit === 'function'
    ));
    if (openSockets.length === 0) {
      throw new Error('IAM call-access fake realtime socket is not ready.');
    }

    for (const socket of openSockets) {
      socket.emit({
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
      });
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
      snapshotRequests: frames.filter((frame) => frame?.type === 'room/snapshot/request').length,
    };
  });
}

test('e2e_journey_001_unregistered_calendar_guest_lobby_admit_join_leave_rejoin unregistered calendar guest receives personalized link, waits in lobby, admits, leaves, and rejoins', async ({ browser }) => {
  test.setTimeout(120_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const scenarioKey = 'unregistered_calendar_guest_personalized_waits_for_host';
  const scenario = getSeedScenario(scenarioKey);
  const link = getSeedAccessLink(scenario.link_key);
  const call = getSeedCall(link.call_key);
  const temporaryUser = getSeedUser(scenario.principal_user_key);
  const accessId = accessIdFromJoinPath(link.join_path);
  const forbiddenNeedles = forbiddenMainJourneyNeedles();

  expect(scenario.journey_key).toBe('e2e_journey_001_unregistered_calendar_guest_lobby_admit_join_leave_rejoin');
  expect(scenario.expected?.booking_origin).toBe('calendar');
  expect(scenario.expected?.rejoin_without_approval_after_admission).toBe(true);

  const { context, page } = await createJourneyPage(browser, baseURL, { scenarioKey });
  const workspaceAdmission = await installAdmittedWorkspaceRoutes(page, { call, user: temporaryUser });

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
    expect(joinPayload?.result?.target_user?.id).toBe(temporaryUser.id);
    expect(joinPayload?.result?.target_user?.account_type).toBe('guest');
    expect(Boolean(joinPayload?.result?.target_user?.is_guest)).toBe(true);
    expect(joinPayload?.result?.call?.id).toBe(call.id);
    noMediaSecretPayload(joinPayload, 'calendar personalized join payload must not expose media/auth secrets');
    expectNoForbiddenNeedles(JSON.stringify(joinPayload), forbiddenNeedles, 'calendar personalized join payload');

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
    expect(sessionRequest.headers().authorization || '').toBe('');
    expect(postDataJsonOrNull(sessionRequest)).toBeNull();

    const sessionPayload = await sessionResponse.json();
    expect(sessionPayload?.result?.user?.id).toBe(temporaryUser.id);
    expect(sessionPayload?.result?.user?.email).toBe(temporaryUser.email);
    expect(sessionPayload?.result?.user?.account_type).toBe('guest');
    expect(Boolean(sessionPayload?.result?.user?.is_guest)).toBe(true);
    expect(sessionPayload?.result?.user?.role).toBe('user');
    expect(sessionPayload?.result?.access_link?.participant_user_id).toBe(temporaryUser.id);
    expect(sessionPayload?.result?.tenant?.permissions?.platform_admin ?? false).toBe(false);
    expect(sessionPayload?.result?.tenant?.permissions?.tenant_admin ?? false).toBe(false);
    expect(sessionPayload?.result?.tenant?.permissions?.manage_lobby ?? false).toBe(false);
    expect(sessionPayload?.result?.tenant?.permissions?.admit_participants ?? false).toBe(false);
    noMediaSecretPayload(sessionPayload, 'calendar personalized session payload must not expose media/auth secrets');
    expectNoForbiddenNeedles(JSON.stringify(sessionPayload), forbiddenNeedles, 'calendar personalized session payload');

    await expect(joinDialog).toContainText(/Call owner has been notified|Waiting for host/i, { timeout: 20_000 });
    expect((await readSocketFrames(page)).some((frame) => frame?.type === 'lobby/queue/join')).toBe(true);

    const storedDuringLobby = await readStoredSession(page);
    expect(storedDuringLobby.sessionToken).toBe(sessionPayload?.result?.session?.token);
    expect(storedDuringLobby.sessionId).toBe(sessionPayload?.result?.session?.id);

    workspaceAdmission.admit();
    await emitAdmission(page, { call, user: temporaryUser });
    await waitForWorkspace(page, call);
    await expect(page.locator('button.tab-lobby')).toHaveCount(0);
    await expect(page.locator('button[title="Allow user"]:not([disabled])')).toHaveCount(0);

    const firstWorkspaceSecurity = await workspaceSecurityProbe(page);
    expect(firstWorkspaceSecurity.canModerate).toBe(false);
    expect(firstWorkspaceSecurity.viewerCanModerateCall).toBe(false);
    expectNoForbiddenNeedles(await page.locator('body').innerText(), forbiddenNeedles, 'calendar personalized workspace');

    await page.getByTitle('Hang up').click();
    await expect(page).toHaveURL(/\/call-goodbye(?:[/?#].*)?$/, { timeout: 20_000 });
    const afterLeaveSession = await readStoredSession(page);
    expect(afterLeaveSession.sessionToken).toBe(storedDuringLobby.sessionToken);

    await page.goto(`/workspace/call/${call.id}?entry=invite`);
    await waitForWorkspace(page, call);
    expect((await readSocketFrames(page)).some((frame) => frame?.type === 'lobby/queue/join')).toBe(false);
    const rejoinedSecurity = await workspaceSecurityProbe(page);
    expect(rejoinedSecurity.canModerate).toBe(false);
    expect(rejoinedSecurity.viewerCanModerateCall).toBe(false);
    expect(rejoinedSecurity.snapshotRequests).toBeGreaterThan(0);
    await expect(page.locator('button.tab-lobby')).toHaveCount(0);
  } finally {
    await context.close();
  }
});
