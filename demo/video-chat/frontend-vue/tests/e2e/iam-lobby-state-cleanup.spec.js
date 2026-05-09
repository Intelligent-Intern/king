import { test, expect } from '@playwright/test';

import {
  accessIdFromJoinPath,
  getSeedAccessLink,
  getSeedCall,
  getSeedScenario,
  getSeedUser,
  installCallAccessSeedRoutes,
} from './helpers/callAccessSeedMatrix.js';
import {
  installCallAccessFakeRealtime,
  installCallAccessMediaDeviceShim,
} from './helpers/callAccessSeedRuntime.js';
import {
  createMatrixPage,
  matrixCallId,
  matrixRoomId,
  matrixUsers,
  openMatrixWorkspace,
} from './helpers/videochatMatrixHarness.js';

function escapeRegExp(input) {
  return String(input).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function hostLobbyEntry({ userId, displayName, requestedMs }) {
  return {
    user_id: userId,
    display_name: displayName,
    role: 'user',
    requested_unix_ms: requestedMs,
    requested_at: new Date(requestedMs).toISOString(),
  };
}

function hostAdmittedEntry({ userId, displayName, admittedMs }) {
  return {
    user_id: userId,
    display_name: displayName,
    role: 'user',
    admitted_unix_ms: admittedMs,
    admitted_at: new Date(admittedMs).toISOString(),
    admitted_by: {
      user_id: matrixUsers.admin.id,
      display_name: matrixUsers.admin.displayName,
      role: matrixUsers.admin.role,
    },
  };
}

function hostLobbySnapshot({ queue = [], admitted = [], reason }) {
  return {
    type: 'lobby/snapshot',
    room_id: matrixRoomId,
    call_id: matrixCallId,
    queue,
    queue_count: queue.length,
    admitted,
    admitted_count: admitted.length,
    reason,
    server_unix_ms: Date.now(),
    time: new Date().toISOString(),
  };
}

async function emitMatrixLobbySnapshot(page, payload) {
  await page.evaluate((eventPayload) => {
    window.__matrixEmit(eventPayload);
  }, payload);
}

async function openHostLobbyPanel(page) {
  await page.locator('button.tab-lobby').click();
  const lobbyPanel = page.locator('.panel-lobby.active');
  await expect(lobbyPanel).toBeVisible();
  return lobbyPanel;
}

async function hostLobbyState(page) {
  return page.evaluate(() => {
    const setup = document.querySelector('.workspace-call-view')?.__vueParentComponent?.setupState;
    if (!setup) throw new Error('Call workspace setup state is not available.');
    const queue = Array.isArray(setup.lobbyQueue) ? setup.lobbyQueue : [];
    const admitted = Array.isArray(setup.lobbyAdmitted) ? setup.lobbyAdmitted : [];
    return {
      queueUserIds: queue.map((entry) => Number(entry?.user_id || 0)).filter(Boolean),
      admittedUserIds: admitted.map((entry) => Number(entry?.user_id || 0)).filter(Boolean),
    };
  });
}

function callParticipantRow(user, callRole = 'participant') {
  return {
    user_id: user.id,
    display_name: user.display_name,
    email: user.email,
    call_role: callRole,
    invite_state: 'allowed',
    joined_at: '2026-05-08T10:00:00.000Z',
    connected_at: null,
  };
}

function admittedCallPayload(call, user) {
  const owner = getSeedUser(call.owner_user_key);
  const participants = [callParticipantRow(owner, 'owner')];
  if (Number(owner.id) !== Number(user.id)) {
    participants.push(callParticipantRow(user, 'participant'));
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

async function createCallAccessPage(browser, baseURL, scenarioKey) {
  const scenario = getSeedScenario(scenarioKey);
  const link = getSeedAccessLink(scenario.link_key);
  const context = await browser.newContext({ baseURL, permissions: ['camera', 'microphone'] });
  await installCallAccessSeedRoutes(context);
  await installCallAccessMediaDeviceShim(context);
  await installCallAccessFakeRealtime(context, {
    linkKey: link.key,
    userKey: scenario.principal_user_key,
  });
  const page = await context.newPage();
  return { context, page, scenario, link };
}

async function installWorkspaceAccessRoutes(page, { call, user }) {
  let state = 'waiting';
  const callPayload = admittedCallPayload(call, user);

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
            reason: state === 'rejected' ? 'lobby_rejected' : 'lobby_admission_required',
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
          access_decision: {
            allowed: true,
            reason: 'call_access_lobby_admitted',
            source: 'call_access_link',
            scope: 'call',
            can_manage_lobby: false,
          },
          call: callPayload,
        },
        time: '2026-05-08T10:00:00.000Z',
      }),
    });
  });

  await page.route(`**/api/calls/${call.id}*`, async (route) => {
    if (state !== 'admitted') {
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
      body: JSON.stringify({ status: 'ok', call: callPayload, time: '2026-05-08T10:00:00.000Z' }),
    });
  });

  return {
    admit() {
      state = 'admitted';
    },
    reject() {
      state = 'rejected';
    },
  };
}

async function waitForAdmissionSocket(page) {
  await page.waitForFunction(() => {
    const sockets = Array.isArray(window.__iamCallAccessSockets) ? window.__iamCallAccessSockets : [];
    return sockets.some((socket) => socket?.readyState === WebSocket.OPEN);
  }, null, { timeout: 20_000 });
}

async function emitCallAccessLobbySnapshot(page, { call, user, admitted, reason }) {
  await waitForAdmissionSocket(page);
  await page.evaluate((payload) => {
    const sockets = Array.isArray(window.__iamCallAccessSockets) ? window.__iamCallAccessSockets : [];
    for (const socket of sockets) {
      if (socket?.readyState === WebSocket.OPEN && typeof socket.emit === 'function') {
        socket.emit(payload);
      }
    }
  }, {
    type: 'lobby/snapshot',
    room_id: call.room_id,
    call_id: call.id,
    queue: admitted ? [] : [hostLobbyEntry({ userId: user.id, displayName: user.display_name, requestedMs: 1_780_800_000_000 })],
    queue_count: admitted ? 0 : 1,
    admitted: admitted ? [hostAdmittedEntry({ userId: user.id, displayName: user.display_name, admittedMs: 1_780_800_001_000 })] : [],
    admitted_count: admitted ? 1 : 0,
    reason,
    server_unix_ms: 1_780_800_001_000,
    time: '2026-05-08T10:00:01.000Z',
  });
  await page.waitForFunction((expectedReason) => (
    (window.__iamCallAccessSocketEvents || []).some((event) => event?.reason === expectedReason)
  ), reason, { timeout: 10_000 });
}

async function startLobbyJoin(page, { link, call, guestName }) {
  const accessId = accessIdFromJoinPath(link.join_path);
  const joinResponsePromise = page.waitForResponse((response) => (
    response.url().includes(`/api/call-access/${accessId}/join`)
    && response.request().method() === 'GET'
  ));
  await page.goto(link.join_path);
  const joinResponse = await joinResponsePromise;
  expect(joinResponse.status()).toBe(200);

  const joinDialog = page.getByRole('dialog', { name: 'Join video call' });
  await expect(joinDialog).toBeVisible({ timeout: 20_000 });
  await expect(joinDialog).toContainText(call.title);
  if (guestName) {
    await joinDialog.getByPlaceholder('Enter your display name').fill(guestName);
  }

  const sessionResponsePromise = page.waitForResponse((response) => (
    response.url().includes(`/api/call-access/${accessId}/session`)
    && response.request().method() === 'POST'
  ));
  await joinDialog.getByRole('button', { name: /^Join call$/ }).click();
  const sessionResponse = await sessionResponsePromise;
  expect(sessionResponse.status()).toBe(200);
  await expect(joinDialog).toContainText(/Call owner has been notified|Waiting for host/i, { timeout: 20_000 });

  const frames = await page.evaluate(() => window.__iamCallAccessSocketFrames || []);
  expect(frames.some((frame) => frame?.type === 'lobby/queue/join')).toBe(true);
  return joinDialog;
}

async function waitForWorkspace(page, call) {
  await page.waitForURL(new RegExp(`/workspace/call/${escapeRegExp(call.id)}(?:[/?#].*)?$`), { timeout: 30_000 });
  await expect(page.locator('.workspace-call-view')).toBeVisible({ timeout: 20_000 });
  await page.waitForFunction(() => (
    (window.__iamCallAccessSocketFrames || []).some((frame) => frame?.type === 'room/snapshot/request')
  ), null, { timeout: 20_000 });
}

test('e2e_lobby_012_lobby_state_updates_correctly removes admitted and aborted participants from the host lobby', async ({ browser, baseURL }) => {
  const admittedUser = { userId: 20, displayName: 'IAM Admitted Guest' };
  const abortedUser = { userId: 30, displayName: 'IAM Aborted Guest' };
  const { context, page } = await createMatrixPage(browser, baseURL, matrixUsers.admin);
  try {
    await openMatrixWorkspace(page);
    const lobbyPanel = await openHostLobbyPanel(page);

    await emitMatrixLobbySnapshot(page, hostLobbySnapshot({
      queue: [hostLobbyEntry({ ...admittedUser, requestedMs: 1_780_700_000_000 })],
      reason: 'iam_state_cleanup_waiting',
    }));
    await expect(lobbyPanel.locator('.user-row', { hasText: admittedUser.displayName })).toHaveCount(1);
    await expect(lobbyPanel.locator('.user-row', { hasText: admittedUser.displayName })).toContainText('queued');
    await expect(page.locator('.tab-lobby .tab-notice-badge')).toHaveText('1');

    await lobbyPanel.locator('button[title="Allow user"]').click();
    await expect.poll(() => page.evaluate(() => (
      (window.__matrixSocketFrames || []).filter((frame) => frame?.type === 'lobby/allow' && frame?.target_user_id === 20).length
    ))).toBe(1);

    await emitMatrixLobbySnapshot(page, hostLobbySnapshot({
      admitted: [hostAdmittedEntry({ ...admittedUser, admittedMs: 1_780_700_001_000 })],
      reason: 'iam_state_cleanup_admitted',
    }));
    await expect(lobbyPanel.locator('.user-row', { hasText: admittedUser.displayName })).toHaveCount(0);
    await expect(page.locator('.tab-lobby .tab-notice-badge')).toHaveCount(0);
    await expect(lobbyPanel.locator('.user-list-empty')).toBeVisible();
    await expect.poll(() => hostLobbyState(page)).toEqual({ queueUserIds: [], admittedUserIds: [20] });

    await emitMatrixLobbySnapshot(page, hostLobbySnapshot({
      queue: [hostLobbyEntry({ ...abortedUser, requestedMs: 1_780_700_002_000 })],
      reason: 'iam_state_cleanup_abort_waiting',
    }));
    await expect(lobbyPanel.locator('.user-row', { hasText: abortedUser.displayName })).toHaveCount(1);
    await expect(page.locator('.tab-lobby .tab-notice-badge')).toHaveText('1');

    await emitMatrixLobbySnapshot(page, hostLobbySnapshot({
      admitted: [hostAdmittedEntry({ ...admittedUser, admittedMs: 1_780_700_001_000 })],
      reason: 'iam_state_cleanup_abort_cancelled',
    }));
    await expect(lobbyPanel.locator('.user-row', { hasText: abortedUser.displayName })).toHaveCount(0);
    await expect(page.locator('.tab-lobby .tab-notice-badge')).toHaveCount(0);
    await expect.poll(() => hostLobbyState(page)).toEqual({ queueUserIds: [], admittedUserIds: [20] });
  } finally {
    await context.close();
  }
});

test('e2e_lobby_008_rejected_participant_cannot_enter and admitted participant enters call', async ({ browser }) => {
  test.setTimeout(120_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const scenarioKey = 'anonymous_open_logged_out_creates_temporary_guest_waits_for_host';
  const scenario = getSeedScenario(scenarioKey);
  const user = getSeedUser(scenario.principal_user_key);
  const call = getSeedCall(getSeedAccessLink(scenario.link_key).call_key);

  const abortSession = await createCallAccessPage(browser, baseURL, scenarioKey);
  try {
    await startLobbyJoin(abortSession.page, {
      link: abortSession.link,
      call,
      guestName: 'IAM Abort Guest',
    });
    await abortSession.page.getByLabel('Cancel join').click();
    await expect.poll(() => abortSession.page.evaluate(() => (
      (window.__iamCallAccessSocketFrames || []).filter((frame) => frame?.type === 'lobby/queue/cancel').length
    ))).toBe(1);
  } finally {
    await abortSession.context.close();
  }

  const rejectedSession = await createCallAccessPage(browser, baseURL, scenarioKey);
  const rejectedRoutes = await installWorkspaceAccessRoutes(rejectedSession.page, { call, user });
  try {
    await startLobbyJoin(rejectedSession.page, {
      link: rejectedSession.link,
      call,
      guestName: 'IAM Rejected Guest',
    });
    rejectedRoutes.reject();
    await emitCallAccessLobbySnapshot(rejectedSession.page, {
      call,
      user,
      admitted: false,
      reason: 'iam_lobby_rejected',
    });
    await expect(rejectedSession.page).toHaveURL(new RegExp(`${escapeRegExp(rejectedSession.link.join_path)}(?:[?#].*)?$`));
    await expect(rejectedSession.page.locator('.workspace-call-view')).toHaveCount(0);

    const resolveResponsePromise = rejectedSession.page.waitForResponse((response) => (
      response.url().includes(`/api/calls/resolve/${call.id}`)
      && response.request().method() === 'GET'
    ));
    await rejectedSession.page.goto(`/workspace/call/${call.id}?entry=rejected_probe`);
    const resolveResponse = await resolveResponsePromise;
    expect(resolveResponse.status()).toBe(200);
    const resolvePayload = await resolveResponse.json();
    expect(resolvePayload?.result?.state).toBe('forbidden');
    expect(resolvePayload?.result?.reason).toBe('lobby_rejected');
    expect(resolvePayload?.result?.call ?? null).toBeNull();
    await expect(rejectedSession.page.locator('.workspace-call-view')).toHaveCount(0);
  } finally {
    await rejectedSession.context.close();
  }

  const admittedSession = await createCallAccessPage(browser, baseURL, scenarioKey);
  const admittedRoutes = await installWorkspaceAccessRoutes(admittedSession.page, { call, user });
  try {
    await startLobbyJoin(admittedSession.page, {
      link: admittedSession.link,
      call,
      guestName: 'IAM Admitted Guest',
    });
    admittedRoutes.admit();
    await emitCallAccessLobbySnapshot(admittedSession.page, {
      call,
      user,
      admitted: true,
      reason: 'iam_lobby_admitted',
    });
    await waitForWorkspace(admittedSession.page, call);
    await expect(admittedSession.page.locator('button.tab-lobby')).toHaveCount(0);
  } finally {
    await admittedSession.context.close();
  }
});
