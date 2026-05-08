import { test, expect } from '@playwright/test';

import {
  accessIdFromJoinPath,
  getSeedAccessLink,
  getSeedCall,
  getSeedScenario,
  getSeedUser,
  sessionStorageKey,
} from './helpers/callAccessSeedMatrix.js';
import {
  createCallAccessMatrixPage,
  createDirectJoinMatrixPage,
  installCallAccessMediaDeviceShim,
} from './helpers/callAccessSeedRuntime.js';

function escapeRegExp(input) {
  return String(input).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function expectNoNeedles(value, needles, label) {
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
    role: user.role,
    call_role: callRole,
    effective_call_role: callRole,
    invite_state: inviteState,
    joined_at: '2026-05-08T10:00:00.000Z',
    connected_at: '2026-05-08T10:00:00.000Z',
  };
}

function callPayload(call, viewer) {
  const owner = getSeedUser(call.owner_user_key);
  const participants = [participantRow(owner, 'owner', 'allowed')];
  if (Number(viewer.id) !== Number(owner.id)) {
    participants.push(participantRow(viewer, 'participant', 'allowed'));
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
      call_role: Number(viewer.id) === Number(owner.id) ? 'owner' : 'participant',
      invite_state: 'allowed',
    },
  };
}

function accessDecision() {
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

async function installWorkspaceAccessStateRoutes(page, { call, user }) {
  let state = 'waiting';
  const payload = callPayload(call, user);

  await page.route(`**/api/calls/resolve/${call.id}*`, async (route) => {
    if (state !== 'admitted') {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'ok',
          result: {
            state: 'forbidden',
            resolved_as: 'call_id',
            reason: state === 'deleted' ? 'call_deleted' : 'call_not_joinable_from_status',
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
          access_decision: accessDecision(),
          call: payload,
        },
        time: '2026-05-08T10:00:00.000Z',
      }),
    });
  });

  await page.route(`**/api/calls/${call.id}*`, async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path !== `/api/calls/${call.id}`) {
      await route.fallback();
      return;
    }

    if (state !== 'admitted') {
      await route.fulfill({
        status: 403,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'error',
          error: { code: 'calls_forbidden', message: 'This call is no longer joinable.' },
        }),
      });
      return;
    }

    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        status: 'ok',
        call: payload,
        time: '2026-05-08T10:00:00.000Z',
      }),
    });
  });

  return {
    admit() {
      state = 'admitted';
    },
    delete() {
      state = 'deleted';
    },
    end() {
      state = 'ended';
    },
  };
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
    const payload = {
      type: 'lobby/snapshot',
      room_id: roomId,
      call_id: callId,
      pending: [],
      admitted: [{
        user_id: userId,
        display_name: displayName,
        role: 'user',
        admitted_at: '2026-05-08T10:00:01.000Z',
      }],
      rejected: [],
      reason: 'iam_main_journey_terminal_admit',
    };
    for (const socket of sockets) {
      if (socket?.readyState === WebSocket.OPEN && typeof socket.emit === 'function') {
        socket.emit(payload);
      }
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

async function readStoredSession(page) {
  return page.evaluate((key) => {
    try {
      return JSON.parse(localStorage.getItem(key) || '{}');
    } catch {
      return {};
    }
  }, sessionStorageKey);
}

async function startPersonalizedLinkSession(page, { link, call, user }) {
  const accessId = accessIdFromJoinPath(link.join_path);
  const joinResponsePromise = page.waitForResponse((response) => (
    response.url().includes(`/api/call-access/${accessId}/join`)
    && response.request().method() === 'GET'
  ));
  await page.goto(link.join_path);
  const joinResponse = await joinResponsePromise;
  expect(joinResponse.status()).toBe(200);
  const joinPayload = await joinResponse.json();
  expect(joinPayload?.result?.link_kind).toBe('personal');
  expect(joinPayload?.result?.call?.id).toBe(call.id);
  noMediaSecretPayload(joinPayload, 'current personalized join payload');

  const joinDialog = page.getByRole('dialog', { name: 'Join video call' });
  await expect(joinDialog).toBeVisible({ timeout: 20_000 });
  await expect(joinDialog).toContainText(call.title);
  await expect(joinDialog).toContainText('Personalized link');

  const sessionResponsePromise = page.waitForResponse((response) => (
    response.url().includes(`/api/call-access/${accessId}/session`)
    && response.request().method() === 'POST'
  ));
  await joinDialog.getByRole('button', { name: /^Join call$/ }).click();
  const sessionResponse = await sessionResponsePromise;
  expect(sessionResponse.status()).toBe(200);
  const sessionPayload = await sessionResponse.json();
  expect(sessionPayload?.result?.user?.id).toBe(user.id);
  expect(sessionPayload?.result?.call?.id).toBe(call.id);
  expect(sessionPayload?.result?.tenant?.permissions?.tenant_admin ?? false).toBe(false);
  expect(sessionPayload?.result?.tenant?.permissions?.manage_lobby ?? false).toBe(false);
  expect(sessionPayload?.result?.tenant?.permissions?.admit_participants ?? false).toBe(false);
  noMediaSecretPayload(sessionPayload, 'current personalized session payload');

  await expect(joinDialog).toContainText(/Call owner has been notified|Waiting for host/i, { timeout: 20_000 });
  return sessionPayload;
}

async function assertSafeDeniedJoin(page, { accessId, status, code, needles, label }) {
  const joinResponsePromise = page.waitForResponse((response) => (
    response.url().includes(`/api/call-access/${accessId}/join`)
    && response.request().method() === 'GET'
  ));
  await page.goto(`/join/${accessId}`);
  const joinResponse = await joinResponsePromise;
  expect(joinResponse.status()).toBe(status);
  const joinPayload = await joinResponse.json();
  expect(joinPayload?.status).toBe('error');
  expect(joinPayload?.error?.code).toBe(code);
  expect(joinPayload?.result ?? null).toBeNull();
  noMediaSecretPayload(joinPayload, `${label} denied payload`);

  const joinDialog = page.getByRole('dialog', { name: 'Join video call' });
  await expect(joinDialog).toBeVisible({ timeout: 20_000 });
  await expect(joinDialog).toContainText(/call link is invalid|call access id is invalid|current call state|does not exist/i);
  await expect(joinDialog.getByRole('button', { name: /^Join call$/ })).toHaveCount(0);
  expectNoNeedles(await joinDialog.innerText(), [...needles, accessId], `${label} denied dialog`);
  expect(page.url()).toContain(`/join/${accessId}`);
  expect(page.url()).not.toContain('/workspace/call');
}

async function routeDeniedLink(page, { accessId, status = 404, code = 'call_access_not_found', message = 'Call access link does not exist.' }) {
  await page.route(`**/api/call-access/${accessId}/join`, async (route) => {
    await route.fulfill({
      status,
      contentType: 'application/json',
      body: JSON.stringify({
        status: 'error',
        error: { code, message },
      }),
    });
  });
  await page.route(`**/api/call-access/${accessId}/session`, async (route) => {
    await route.fulfill({
      status,
      contentType: 'application/json',
      body: JSON.stringify({
        status: 'error',
        error: { code, message },
      }),
    });
  });
}

async function openDirectWorkspace(browser, baseURL, scenarioKey) {
  const session = await createDirectJoinMatrixPage(browser, baseURL, { scenarioKey });
  const call = getSeedCall(session.scenario.call_key);
  await session.page.goto(`/workspace/call/${call.id}`);
  await expect(session.page.locator('.workspace-call-view')).toBeVisible({ timeout: 20_000 });
  await session.page.waitForFunction(() => (
    (window.__iamCallAccessSocketFrames || []).some((frame) => frame?.type === 'room/snapshot/request')
    && typeof window.__iamCallAccessEmitRoomSnapshot === 'function'
  ), null, { timeout: 20_000 });
  return { ...session, call };
}

async function emitExplicitEndedState(page, call) {
  const sent = await page.evaluate((payload) => window.__iamCallAccessEmitRoomSnapshot(payload), {
    participants: [],
    participant_count: 0,
    call_lifecycle: {
      status: 'ended',
      owner_absence: {
        enabled: false,
        status: 'call_inactive',
        call_id: call.id,
        room_id: call.room_id,
        call_status: 'ended',
        owner_present: false,
        active_participant_count: 0,
        active_non_owner_count: 0,
        timer_ms: 15 * 60 * 1000,
        countdown_ms: 5 * 60 * 1000,
        ended_reason: 'owner_explicit_end',
        transitioned: true,
      },
    },
  });
  expect(sent).toBeGreaterThan(0);
}

async function callLifecycleState(page) {
  return page.evaluate(() => {
    const setup = document.querySelector('.workspace-call-view')?.__vueParentComponent?.setupState;
    return JSON.parse(JSON.stringify({
      ownerAbsenceState: setup?.ownerAbsenceState || null,
      connectionState: setup?.connectionState || '',
    }));
  });
}

test('e2e_journey_020_invalidated_invite_link_denied', async ({ browser }) => {
  test.setTimeout(60_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const accessId = '88888888-8888-4888-8888-888888888888';
  const privateNeedles = [
    'Invalidated Private Call',
    'invalidated-invitee@example.invalid',
    'Invalidated Invitee',
    'Private Invalidated Host',
    'invalidated-call-id',
  ];
  const context = await browser.newContext({ baseURL, permissions: ['camera', 'microphone'] });
  await installCallAccessMediaDeviceShim(context);
  const page = await context.newPage();
  let sessionPostCount = 0;

  try {
    page.on('request', (request) => {
      if (request.method() === 'POST' && request.url().includes(`/api/call-access/${accessId}/session`)) {
        sessionPostCount += 1;
      }
    });
    await routeDeniedLink(page, { accessId });
    await assertSafeDeniedJoin(page, {
      accessId,
      status: 404,
      code: 'call_access_not_found',
      needles: privateNeedles,
      label: 'invalidated personalized invite main journey',
    });
    expect(sessionPostCount).toBe(0);
  } finally {
    await context.close();
  }
});

test('e2e_journey_021_rescheduled_call_old_link_invalid_new_link_valid', async ({ browser }) => {
  test.setTimeout(90_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const oldAccessId = '33333333-3333-4333-8333-333333333333';
  const scenario = getSeedScenario('temporary_personalized_guest_has_no_system_admin_rights');
  const newLink = getSeedAccessLink(scenario.link_key);
  const call = getSeedCall(newLink.call_key);
  const user = getSeedUser(scenario.principal_user_key);
  const owner = getSeedUser(call.owner_user_key);
  const { context, page } = await createCallAccessMatrixPage(browser, baseURL, {
    scenarioKey: scenario.key,
  });
  const workspaceAccess = await installWorkspaceAccessStateRoutes(page, { call, user });
  let oldSessionPostCount = 0;

  try {
    page.on('request', (request) => {
      if (request.method() === 'POST' && request.url().includes(`/api/call-access/${oldAccessId}/session`)) {
        oldSessionPostCount += 1;
      }
    });
    await routeDeniedLink(page, { accessId: oldAccessId });
    await assertSafeDeniedJoin(page, {
      accessId: oldAccessId,
      status: 404,
      code: 'call_access_not_found',
      needles: [call.id, call.title, owner.email, owner.display_name],
      label: 'rescheduled stale link main journey',
    });
    expect(oldSessionPostCount).toBe(0);

    await startPersonalizedLinkSession(page, { link: newLink, call, user });
    workspaceAccess.admit();
    await emitAdmission(page, { call, user });
    await waitForWorkspace(page, call);
    const workspaceText = await page.locator('body').innerText();
    expect(workspaceText).toContain(user.display_name);
    expectNoNeedles(workspaceText, [owner.email], 'rescheduled current-link workspace');
    const setup = await page.evaluate(() => {
      const state = document.querySelector('.workspace-call-view')?.__vueParentComponent?.setupState;
      return {
        canModerate: Boolean(state?.canModerate),
        viewerCanModerateCall: Boolean(state?.viewerCanModerateCall),
      };
    });
    expect(setup.canModerate).toBe(false);
    expect(setup.viewerCanModerateCall).toBe(false);
  } finally {
    await context.close();
  }
});

test('e2e_journey_022_deleted_call_revokes_all_temp_access', async ({ browser }) => {
  test.setTimeout(90_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const scenario = getSeedScenario('temporary_personalized_guest_has_no_system_admin_rights');
  const link = getSeedAccessLink(scenario.link_key);
  const call = getSeedCall(link.call_key);
  const user = getSeedUser(scenario.principal_user_key);
  const owner = getSeedUser(call.owner_user_key);
  const accessId = accessIdFromJoinPath(link.join_path);
  const { context, page } = await createCallAccessMatrixPage(browser, baseURL, {
    scenarioKey: scenario.key,
  });
  const workspaceAccess = await installWorkspaceAccessStateRoutes(page, { call, user });
  let deletedSessionPostCount = 0;

  try {
    await startPersonalizedLinkSession(page, { link, call, user });
    workspaceAccess.admit();
    await emitAdmission(page, { call, user });
    await waitForWorkspace(page, call);
    const storedBeforeDelete = await readStoredSession(page);
    expect(storedBeforeDelete.sessionToken || '').not.toBe('');

    workspaceAccess.delete();
    const resolveResponsePromise = page.waitForResponse((response) => (
      response.url().includes(`/api/calls/resolve/${call.id}`)
      && response.request().method() === 'GET'
    ));
    await page.goto(`/workspace/call/${call.id}?entry=deleted-rejoin`);
    const resolveResponse = await resolveResponsePromise;
    expect(resolveResponse.status()).toBe(200);
    const resolvePayload = await resolveResponse.json();
    expect(resolvePayload?.result?.state).toBe('forbidden');
    expect(resolvePayload?.result?.call ?? null).toBeNull();
    noMediaSecretPayload(resolvePayload, 'deleted temp rejoin denial payload');
    await expect(page).toHaveURL(/\/(user\/dashboard|admin\/calls)(?:[/?#].*)?$/, { timeout: 20_000 });
    await expect(page.locator('.workspace-call-view')).toHaveCount(0);
    expectNoNeedles(await page.locator('body').innerText(), [call.id, call.title, owner.email, owner.display_name], 'deleted temp rejoin safe screen');

    page.on('request', (request) => {
      if (request.method() === 'POST' && request.url().includes(`/api/call-access/${accessId}/session`)) {
        deletedSessionPostCount += 1;
      }
    });
    await routeDeniedLink(page, { accessId });
    await assertSafeDeniedJoin(page, {
      accessId,
      status: 404,
      code: 'call_access_not_found',
      needles: [call.id, call.title, owner.email, owner.display_name, user.email, user.display_name],
      label: 'deleted call stale temp link main journey',
    });
    expect(deletedSessionPostCount).toBe(0);
  } finally {
    await context.close();
  }
});

test('e2e_journey_023_explicit_call_end_revokes_all_join_paths', async ({ browser }) => {
  test.setTimeout(90_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const participant = await openDirectWorkspace(browser, baseURL, 'direct_join_guest_list_user_allowed');
  const admin = await openDirectWorkspace(browser, baseURL, 'direct_join_org_admin_own_organization_without_guest_list');

  try {
    await emitExplicitEndedState(participant.page, participant.call);
    await emitExplicitEndedState(admin.page, admin.call);

    const participantState = await callLifecycleState(participant.page);
    const adminState = await callLifecycleState(admin.page);
    expect(participantState.ownerAbsenceState?.callStatus).toBe('ended');
    expect(participantState.ownerAbsenceState?.status).toBe('call_inactive');
    expect(participantState.ownerAbsenceState?.ended_reason).toBe('owner_explicit_end');
    expect(adminState.ownerAbsenceState?.callStatus).toBe('ended');
    expect(adminState.ownerAbsenceState?.status).toBe('call_inactive');
    expect(adminState.ownerAbsenceState?.ended_reason).toBe('owner_explicit_end');
  } finally {
    await participant.context.close();
    await admin.context.close();
  }
});
