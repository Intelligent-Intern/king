import { test, expect } from '@playwright/test';
import {
  createMatrixPage,
  matrixCallId,
  matrixRoomId,
  matrixUsers,
  openMatrixWorkspace,
} from './helpers/videochatMatrixHarness.js';

const waitingUserId = 20;
const waitingUserName = 'Waiting User';

function lobbyEntry(overrides = {}) {
  return {
    user_id: waitingUserId,
    display_name: waitingUserName,
    role: 'user',
    requested_unix_ms: 1_780_600_000_000,
    requested_at: '2026-05-08T10:00:00.000Z',
    ...overrides,
  };
}

function admittedEntry(overrides = {}) {
  return {
    user_id: waitingUserId,
    display_name: waitingUserName,
    role: 'user',
    admitted_unix_ms: 1_780_600_001_000,
    admitted_at: '2026-05-08T10:00:01.000Z',
    admitted_by: {
      user_id: matrixUsers.admin.id,
      display_name: matrixUsers.admin.displayName,
      role: matrixUsers.admin.role,
    },
    ...overrides,
  };
}

function lobbySnapshot({ queue = [], admitted = [], reason = 'test_lobby_concurrency' }) {
  return {
    type: 'lobby/snapshot',
    room_id: matrixRoomId,
    queue,
    queue_count: queue.length,
    admitted,
    admitted_count: admitted.length,
    reason,
    server_unix_ms: Date.now(),
    time: new Date().toISOString(),
  };
}

function participantRow({ connectionId, userId, displayName, role = 'user', callRole = 'participant' }) {
  return {
    connection_id: connectionId,
    room_id: matrixRoomId,
    user: {
      id: userId,
      display_name: displayName,
      role,
      call_role: callRole,
    },
    connected_at: '2026-05-08T10:00:02.000Z',
  };
}

async function emitMatrixEvent(page, payload) {
  await page.evaluate((eventPayload) => {
    window.__matrixEmit(eventPayload);
  }, payload);
}

async function openLobbyPanel(page) {
  await page.locator('button.tab-lobby').click();
  const lobbyPanel = page.locator('.panel-lobby.active');
  await expect(lobbyPanel).toBeVisible();
  return lobbyPanel;
}

async function openUsersPanel(page) {
  await page.getByRole('tab', { name: 'Users' }).click();
  const usersPanel = page.locator('.panel-users.active');
  await expect(usersPanel).toBeVisible();
  return usersPanel;
}

async function setWorkspaceParticipants(page, participants) {
  await page.evaluate((rows) => {
    const setup = document.querySelector('.workspace-call-view')?.__vueParentComponent?.setupState;
    if (!setup) throw new Error('Call workspace setup state is not available.');
    setup.participantsRaw = rows;
  }, participants);
}

test('e2e_lobby_010_concurrent_admission_idempotent e2e_lobby_011_concurrent_admit_reject_deterministic e2e_lobby_012_lobby_state_updates_correctly', async ({ browser, baseURL }) => {
  const { context, page } = await createMatrixPage(browser, baseURL, matrixUsers.admin);
  try {
    await openMatrixWorkspace(page);

    await emitMatrixEvent(page, lobbySnapshot({
      reason: 'concurrent_duplicate_queue',
      queue: [
        lobbyEntry({ requested_unix_ms: 1_780_600_000_000 }),
        lobbyEntry({ requested_unix_ms: 1_780_600_000_100 }),
      ],
    }));

    const lobbyPanel = await openLobbyPanel(page);
    await expect(lobbyPanel.locator('.user-row', { hasText: waitingUserName })).toHaveCount(1);
    await expect(page.locator('.tab-lobby .tab-notice-badge')).toHaveText('1');

    const allowButton = lobbyPanel.locator('button[title="Allow user"]');
    const removeButton = lobbyPanel.locator('button[title="Remove user"]');
    await expect(allowButton).toHaveCount(1);
    await expect(removeButton).toHaveCount(1);
    await expect(allowButton).toBeEnabled();
    await expect(removeButton).toBeEnabled();

    await allowButton.click();
    await expect.poll(() => page.evaluate(() => (
      (window.__matrixSocketFrames || []).filter((frame) => frame?.type === 'lobby/allow').length
    ))).toBe(1);
    await expect(allowButton).toBeDisabled();

    await emitMatrixEvent(page, lobbySnapshot({
      reason: 'concurrent_admitted_wins_over_stale_queue',
      queue: [
        lobbyEntry({ requested_unix_ms: 1_780_600_000_200 }),
        lobbyEntry({ requested_unix_ms: 1_780_600_000_300 }),
      ],
      admitted: [
        admittedEntry({ admitted_unix_ms: 1_780_600_001_000 }),
        admittedEntry({ admitted_unix_ms: 1_780_600_001_050 }),
      ],
    }));

    await expect(lobbyPanel.locator('.user-row', { hasText: waitingUserName })).toHaveCount(0);
    await expect(lobbyPanel.locator('button[title="Allow user"]')).toHaveCount(0);
    await expect(lobbyPanel.locator('.user-list-empty')).toBeVisible();
    await expect(page.locator('.tab-lobby .tab-notice-badge')).toHaveCount(0);

    const usersPanel = await openUsersPanel(page);
    await setWorkspaceParticipants(page, [
      participantRow({
        connectionId: 'conn-admin',
        userId: matrixUsers.admin.id,
        displayName: matrixUsers.admin.displayName,
        role: matrixUsers.admin.role,
        callRole: matrixUsers.admin.callRole,
      }),
      participantRow({
        connectionId: 'conn-user',
        userId: matrixUsers.user.id,
        displayName: matrixUsers.user.displayName,
        role: matrixUsers.user.role,
        callRole: matrixUsers.user.callRole,
      }),
      participantRow({ connectionId: 'conn-waiting-a', userId: waitingUserId, displayName: waitingUserName }),
      participantRow({ connectionId: 'conn-waiting-b', userId: waitingUserId, displayName: waitingUserName }),
    ]);

    await expect(usersPanel.locator('.user-row', { hasText: waitingUserName })).toHaveCount(1);
    await expect(usersPanel.locator('.user-row', { hasText: waitingUserName }).locator('button[title="Remove from lobby"]')).toBeDisabled();

    await openLobbyPanel(page);
    await emitMatrixEvent(page, lobbySnapshot({ reason: 'reject_final_empty' }));
    await expect(lobbyPanel.locator('.user-row', { hasText: waitingUserName })).toHaveCount(0);
    await expect(lobbyPanel.locator('button[title="Allow user"]')).toHaveCount(0);
    await expect(lobbyPanel.locator('.user-list-empty')).toBeVisible();
  } finally {
    await context.close();
  }
});
