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
} from './helpers/callAccessSeedRuntime.js';

function escapeRegExp(input) {
  return String(input).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function lobbySnapshotFor(call, waitingUser, reason) {
  return {
    type: 'lobby/snapshot',
    room_id: call.room_id,
    call_id: call.id,
    queue: [
      {
        user_id: waitingUser.id,
        display_name: waitingUser.display_name,
        role: waitingUser.role,
        requested_unix_ms: 1_780_600_000_000,
        requested_at: '2026-05-08T10:00:00.000Z',
      },
    ],
    queue_count: 1,
    admitted: [],
    admitted_count: 0,
    reason,
    server_unix_ms: 1_780_600_000_000,
    server_time: '2026-05-08T10:00:00.000Z',
    time: '2026-05-08T10:00:00.000Z',
  };
}

async function emitLobbySnapshot(page, payload) {
  await expect.poll(
    async () => page.evaluate(() => (
      (window.__iamCallAccessSockets || []).some((socket) => socket?.readyState === WebSocket.OPEN)
    )),
    { timeout: 20_000 },
  ).toBe(true);

  const sentCount = await page.evaluate((snapshot) => {
    let sent = 0;
    for (const socket of window.__iamCallAccessSockets || []) {
      if (socket?.readyState !== WebSocket.OPEN || typeof socket.emit !== 'function') continue;
      socket.emit(snapshot);
      sent += 1;
    }
    return sent;
  }, payload);

  expect(sentCount).toBeGreaterThan(0);
}

async function expectJoinLinkQueuesAdmission({
  browser,
  scenarioKey,
  storedSessionUserKey = '',
  guestName = '',
}) {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const scenario = getSeedScenario(scenarioKey);
  const link = getSeedAccessLink(scenario.link_key);
  const call = getSeedCall(link.call_key);
  const expectedUser = getSeedUser(scenario.principal_user_key);
  const accessId = accessIdFromJoinPath(link.join_path);

  expect(accessId).not.toBe('');

  const { context, page } = await createCallAccessMatrixPage(browser, baseURL, {
    scenarioKey,
    storedSessionUserKey,
    storedSessionCallKey: link.call_key,
  });

  try {
    const joinResponsePromise = page.waitForResponse((response) => (
      response.url().includes(`/api/call-access/${accessId}/join`)
      && response.request().method() === 'GET'
    ));
    await page.goto(link.join_path);
    const joinResponse = await joinResponsePromise;
    expect(joinResponse.status()).toBe(200);
    const joinPayload = await joinResponse.json();
    expect(joinPayload?.result?.call?.id).toBe(call.id);

    const joinDialog = page.getByRole('dialog', { name: 'Join video call' });
    await expect(joinDialog).toBeVisible({ timeout: 20_000 });
    await expect(joinDialog).toContainText(call.title);

    const guestNameInput = joinDialog.getByPlaceholder('Enter your display name');
    if (await guestNameInput.count()) {
      await guestNameInput.fill(guestName || expectedUser.display_name);
    }

    const sessionResponsePromise = page.waitForResponse((response) => (
      response.url().includes(`/api/call-access/${accessId}/session`)
      && response.request().method() === 'POST'
    ));
    await joinDialog.getByRole('button', { name: /^Join call$/ }).click();
    const sessionResponse = await sessionResponsePromise;
    expect(sessionResponse.status()).toBe(200);
    const sessionPayload = await sessionResponse.json();
    expect(sessionPayload?.result?.user?.id).toBe(expectedUser.id);
    expect(sessionPayload?.result?.call?.id).toBe(call.id);
    expect(sessionPayload?.result?.call?.my_participation?.invite_state).toBe('pending');
    expect(sessionPayload?.result?.tenant?.permissions?.manage_lobby ?? false).toBe(false);
    expect(sessionPayload?.result?.tenant?.permissions?.admit_participants ?? false).toBe(false);

    await expect(joinDialog).toContainText(/Call owner has been notified|Waiting for host/i, { timeout: 20_000 });
    await expect(page).toHaveURL(new RegExp(`/join/${escapeRegExp(accessId)}(?:[/?#].*)?$`));

    const socketFrames = await page.evaluate(() => window.__iamCallAccessSocketFrames || []);
    expect(socketFrames.some((frame) => (
      frame?.type === 'lobby/queue/join'
      && frame?.room_id === call.room_id
    ))).toBe(true);

    const storedSession = await page.evaluate((key) => {
      try {
        return JSON.parse(localStorage.getItem(key) || '{}');
      } catch {
        return {};
      }
    }, sessionStorageKey);
    expect(storedSession.sessionToken).toBe(sessionPayload?.result?.session?.token);
  } finally {
    await context.close();
  }
}

async function openDirectWorkspace(browser, scenarioKey) {
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const scenario = getSeedScenario(scenarioKey);
  const call = getSeedCall(scenario.call_key);
  const { context, page, directJoinDecisions } = await createDirectJoinMatrixPage(browser, baseURL, { scenarioKey });

  await page.goto(`/workspace/call/${call.id}`);
  await expect(page).toHaveURL(new RegExp(`/workspace/call/${escapeRegExp(call.id)}(?:[/?#].*)?$`), { timeout: 20_000 });
  await expect(page.locator('.workspace-call-view')).toBeVisible({ timeout: 20_000 });
  expect(directJoinDecisions.some((decision) => (
    decision.call_id === call.id
    && decision.allowed === true
    && decision.source === scenario.expected.decision_source
  ))).toBe(true);

  return { context, page, call };
}

async function expectModeratorSeesWaitingParticipant({ browser, scenarioKey, waitingUserKey }) {
  const waitingUser = getSeedUser(waitingUserKey);
  const { context, page, call } = await openDirectWorkspace(browser, scenarioKey);

  try {
    await expect(page.locator('button.tab-lobby')).toBeVisible({ timeout: 20_000 });
    await emitLobbySnapshot(page, lobbySnapshotFor(call, waitingUser, scenarioKey));

    const lobbyBadge = page.locator('.tab-lobby .tab-notice-badge');
    await expect(lobbyBadge).toBeVisible({ timeout: 20_000 });
    await expect(lobbyBadge).toHaveText('1');

    await page.locator('button.tab-lobby').click();
    const lobbyPanel = page.locator('.panel-lobby.active');
    await expect(lobbyPanel).toBeVisible({ timeout: 10_000 });
    await expect(lobbyPanel.locator('.user-row', { hasText: waitingUser.display_name })).toBeVisible({ timeout: 20_000 });
    await expect(lobbyPanel.locator('button[title="Allow user"]')).toBeEnabled();
    await expect(lobbyPanel.locator('button[title="Remove user"]')).toBeEnabled();
  } finally {
    await context.close();
  }
}

test('e2e_lobby_001_unauthorized_user_lands_in_lobby: no-direct-access identities wait for host admission', async ({ browser }) => {
  test.setTimeout(120_000);

  for (const lobbyCase of [
    {
      scenarioKey: 'call_scoped_removed_member_personal_waits_for_host',
      label: 'user without direct permission',
    },
    {
      scenarioKey: 'anonymous_open_logged_out_creates_temporary_guest_waits_for_host',
      label: 'anonymous not logged-in user',
      guestName: 'Anonymous Not Logged In Lobby Guest',
    },
    {
      scenarioKey: 'registered_logged_out_personalized_uses_temporary_account',
      label: 'personalized temporary user without direct permission',
    },
    {
      scenarioKey: 'anonymous_open_logged_in_uses_own_account_waits_for_host',
      label: 'logged-in user without direct permission',
      storedSessionUserKey: 'alpha_normal_user',
      guestName: 'Ignored Logged In Lobby Guest',
    },
  ]) {
    await test.step(lobbyCase.label, async () => {
      await expectJoinLinkQueuesAdmission({
        browser,
        scenarioKey: lobbyCase.scenarioKey,
        storedSessionUserKey: lobbyCase.storedSessionUserKey || '',
        guestName: lobbyCase.guestName || '',
      });
    });
  }
});

test('e2e_lobby_002_host_sees_waiting_participant: host receives lobby entry and management controls', async ({ browser }) => {
  test.setTimeout(60_000);
  await expectModeratorSeesWaitingParticipant({
    browser,
    scenarioKey: 'direct_join_normal_owner_without_guest_list',
    waitingUserKey: 'alpha_normal_user',
  });
});

test('e2e_lobby_004_org_admin_sees_waiting_participant_for_own_org: organization admin receives own-call lobby entry', async ({ browser }) => {
  test.setTimeout(60_000);
  await expectModeratorSeesWaitingParticipant({
    browser,
    scenarioKey: 'direct_join_org_admin_own_organization_without_guest_list',
    waitingUserKey: 'alpha_normal_user',
  });
});

test('e2e_lobby_005_unauthorized_user_no_lobby_controls: participant cannot see lobby management controls', async ({ browser }) => {
  test.setTimeout(60_000);
  const waitingUser = getSeedUser('alpha_normal_user');
  const { context, page, call } = await openDirectWorkspace(browser, 'direct_join_guest_list_user_allowed');

  try {
    await emitLobbySnapshot(page, lobbySnapshotFor(call, waitingUser, 'unauthorized_user_controls_hidden'));
    await expect(page.locator('button.tab-lobby')).toHaveCount(0);
    await expect(page.locator('.panel-lobby button[title="Allow user"]')).toHaveCount(0);
    await expect(page.locator('.panel-lobby button[title="Remove user"]')).toHaveCount(0);
    await expect(page.locator('.tab-lobby .tab-notice-badge')).toHaveCount(0);
  } finally {
    await context.close();
  }
});
