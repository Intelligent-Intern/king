import { test, expect } from '@playwright/test';

import {
  createMatrixPage,
  matrixCallRef,
  matrixRoomId,
  matrixUsers,
  sessionStorageKey,
} from './helpers/videochatMatrixHarness.js';

async function openMatrixWorkspaceWithRealtimeSocket(page) {
  await page.goto(`/workspace/call/${matrixCallRef}`);
  await page.waitForSelector('.workspace-call-view');
  await page.waitForFunction(() => {
    const setup = document.querySelector('.workspace-call-view')?.__vueParentComponent?.setupState;
    return setup?.connectionState === 'online';
  });
  await page.waitForFunction(() => (
    (window.__matrixSocketFrames || []).some((frame) => frame?.type === 'room/snapshot/request')
  ));
}

async function socketProbe(page) {
  return page.evaluate((storageKey) => {
    const frames = Array.isArray(window.__matrixSocketFrames) ? window.__matrixSocketFrames : [];
    const sockets = Array.isArray(window.__matrixSockets) ? window.__matrixSockets : [];
    const lifecycle = Array.isArray(window.__matrixSocketLifecycle) ? window.__matrixSocketLifecycle : [];
    const setup = document.querySelector('.workspace-call-view')?.__vueParentComponent?.setupState;

    return {
      connectionState: String(setup?.connectionState || ''),
      storedSessionPresent: Boolean(localStorage.getItem(storageKey)),
      socketCount: sockets.filter((socket) => String(socket?.url || '').includes('/ws?')).length,
      roomJoins: frames.filter((frame) => frame?.type === 'room/join').length,
      snapshotRequests: frames.filter((frame) => frame?.type === 'room/snapshot/request').length,
      roomLeaves: frames.filter((frame) => frame?.type === 'room/leave').length,
      clientLeaveCloses: lifecycle.filter((event) => event?.type === 'close' && event?.reason === 'client_leave').length,
      networkDropCloses: lifecycle.filter((event) => event?.type === 'close' && event?.reason === 'network_drop').length,
      currentUrl: window.location.href,
    };
  }, sessionStorageKey);
}

async function waitForReconnectBackfill(page, before) {
  await page.waitForFunction((previous) => {
    const setup = document.querySelector('.workspace-call-view')?.__vueParentComponent?.setupState;
    const frames = Array.isArray(window.__matrixSocketFrames) ? window.__matrixSocketFrames : [];
    const sockets = Array.isArray(window.__matrixSockets) ? window.__matrixSockets : [];
    const snapshotRequests = frames.filter((frame) => frame?.type === 'room/snapshot/request').length;
    const socketCount = sockets.filter((socket) => String(socket?.url || '').includes('/ws?')).length;
    return setup?.connectionState === 'online'
      && socketCount > previous.socketCount
      && snapshotRequests > previous.snapshotRequests;
  }, before, { timeout: 12_000 });
}

test('network reconnect backfills the call room without sending a leave frame', async ({ browser }) => {
  test.setTimeout(90_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const admin = await createMatrixPage(browser, baseURL, matrixUsers.admin);

  try {
    await openMatrixWorkspaceWithRealtimeSocket(admin.page);
    const before = await socketProbe(admin.page);
    expect(before.connectionState).toBe('online');
    expect(before.snapshotRequests).toBeGreaterThan(0);

    const dropped = await admin.page.evaluate(() => window.__matrixForceSocketClose(1006, 'network_drop'));
    expect(dropped).toBe(true);
    await admin.page.waitForFunction(() => {
      const setup = document.querySelector('.workspace-call-view')?.__vueParentComponent?.setupState;
      return setup?.connectionState === 'retrying';
    });
    await waitForReconnectBackfill(admin.page, before);

    const after = await socketProbe(admin.page);
    expect(after.connectionState).toBe('online');
    expect(after.storedSessionPresent).toBe(true);
    expect(after.snapshotRequests).toBeGreaterThan(before.snapshotRequests);
    expect(after.socketCount).toBeGreaterThan(before.socketCount);
    expect(after.roomLeaves).toBe(before.roomLeaves);
    expect(after.clientLeaveCloses).toBe(before.clientLeaveCloses);
    expect(after.networkDropCloses).toBeGreaterThan(before.networkDropCloses);
    expect(after.currentUrl).toContain(`/workspace/call/${matrixCallRef}`);
  } finally {
    await Promise.allSettled([admin.context.close()]);
  }
});

test('explicit hangup leaves the room and the same session can rejoin with a fresh snapshot', async ({ browser }) => {
  test.setTimeout(90_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const admin = await createMatrixPage(browser, baseURL, matrixUsers.admin);

  try {
    await openMatrixWorkspaceWithRealtimeSocket(admin.page);
    const beforeLeave = await socketProbe(admin.page);

    await admin.page.getByTitle('Hang up').click();
    await expect(admin.page).toHaveURL(/\/admin\/calls(?:[/?#].*)?$/, { timeout: 15_000 });
    await admin.page.waitForFunction((previous) => {
      const frames = Array.isArray(window.__matrixSocketFrames) ? window.__matrixSocketFrames : [];
      const lifecycle = Array.isArray(window.__matrixSocketLifecycle) ? window.__matrixSocketLifecycle : [];
      return frames.filter((frame) => frame?.type === 'room/leave').length > previous.roomLeaves
        && lifecycle.filter((event) => event?.type === 'close' && event?.reason === 'client_leave').length > previous.clientLeaveCloses;
    }, beforeLeave, { timeout: 10_000 });

    const afterLeave = await socketProbe(admin.page);
    expect(afterLeave.roomLeaves).toBe(beforeLeave.roomLeaves + 1);
    expect(afterLeave.clientLeaveCloses).toBeGreaterThan(beforeLeave.clientLeaveCloses);
    expect(afterLeave.storedSessionPresent).toBe(true);

    await openMatrixWorkspaceWithRealtimeSocket(admin.page);
    const afterRejoin = await socketProbe(admin.page);
    expect(afterRejoin.connectionState).toBe('online');
    expect(afterRejoin.storedSessionPresent).toBe(true);
    expect(afterRejoin.socketCount).toBeGreaterThan(0);
    expect(afterRejoin.snapshotRequests).toBeGreaterThan(0);
    expect(afterRejoin.roomLeaves).toBe(0);
    await expect(admin.page.locator('.user-row', { hasText: matrixUsers.user.displayName })).toBeVisible();
  } finally {
    await Promise.allSettled([admin.context.close()]);
  }
});

test('participant browser never exposes stale lobby kick controls', async ({ browser }) => {
  test.setTimeout(90_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const user = await createMatrixPage(browser, baseURL, matrixUsers.user);

  try {
    await openMatrixWorkspaceWithRealtimeSocket(user.page);
    await expect(user.page.locator('button.tab-lobby')).toHaveCount(0);
    await expect(user.page.locator('button[title="Remove from lobby"]:not([disabled])')).toHaveCount(0);
    await expect(user.page.locator('button[title="Remove user"]:not([disabled])')).toHaveCount(0);

    await user.page.evaluate((roomId) => {
      window.__matrixEmit({
        type: 'lobby/snapshot',
        room_id: roomId,
        queue: [
          {
            user_id: 77,
            display_name: 'Queued Kick Target',
            role: 'user',
            requested_unix_ms: Date.now(),
            requested_at: new Date().toISOString(),
          },
        ],
        admitted: [],
        reason: 'stale_kick_denial_probe',
      });
    }, matrixRoomId);

    await user.page.waitForTimeout(200);
    await expect(user.page.locator('button.tab-lobby')).toHaveCount(0);
    await expect(user.page.locator('button[title="Allow user"]:not([disabled])')).toHaveCount(0);
    await expect(user.page.locator('button[title="Remove user"]:not([disabled])')).toHaveCount(0);
    const moderationState = await user.page.evaluate(() => {
      const setup = document.querySelector('.workspace-call-view')?.__vueParentComponent?.setupState;
      return {
        canModerate: Boolean(setup?.canModerate),
        viewerCanModerateCall: Boolean(setup?.viewerCanModerateCall),
      };
    });
    expect(moderationState).toEqual({ canModerate: false, viewerCanModerateCall: false });
  } finally {
    await Promise.allSettled([user.context.close()]);
  }
});

test('e2e_security_009_kick_during_active_call_removes_user', async ({ browser }) => {
  test.setTimeout(90_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const admin = await createMatrixPage(browser, baseURL, matrixUsers.admin);

  try {
    await openMatrixWorkspaceWithRealtimeSocket(admin.page);
    const activeUserRow = admin.page.locator('.panel-users .user-row', { hasText: matrixUsers.user.displayName });
    await expect(activeUserRow).toBeVisible({ timeout: 10_000 });
    const removeActiveUserButton = activeUserRow.locator('button[title="Remove user"]');
    await expect(removeActiveUserButton).toBeEnabled();

    await removeActiveUserButton.click();
    await admin.page.waitForFunction((targetUserId) => {
      const frames = Array.isArray(window.__matrixSocketFrames) ? window.__matrixSocketFrames : [];
      const participants = Array.isArray(window.__matrixParticipants) ? window.__matrixParticipants : [];
      return frames.some((frame) => frame?.type === 'lobby/remove' && Number(frame?.target_user_id || 0) === targetUserId)
        && !participants.some((participant) => Number(participant?.user?.id || 0) === targetUserId);
    }, matrixUsers.user.id, { timeout: 10_000 });

    await expect(activeUserRow).toHaveCount(0);
    const kickProbe = await admin.page.evaluate((targetUserId) => {
      const frames = Array.isArray(window.__matrixSocketFrames) ? window.__matrixSocketFrames : [];
      const events = Array.isArray(window.__matrixSocketEvents) ? window.__matrixSocketEvents : [];
      return {
        removeCommandCount: frames.filter((frame) => frame?.type === 'lobby/remove' && Number(frame?.target_user_id || 0) === targetUserId).length,
        kickedSnapshotCount: events.filter((event) => event?.type === 'room/snapshot' && event?.reason === 'participant_kicked').length,
        remainingTargetRows: (Array.isArray(window.__matrixParticipants) ? window.__matrixParticipants : [])
          .filter((participant) => Number(participant?.user?.id || 0) === targetUserId).length,
      };
    }, matrixUsers.user.id);
    expect(kickProbe).toEqual({
      removeCommandCount: 1,
      kickedSnapshotCount: 1,
      remainingTargetRows: 0,
    });
  } finally {
    await Promise.allSettled([admin.context.close()]);
  }
});
