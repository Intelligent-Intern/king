import { test, expect } from '@playwright/test';
import {
  createMatrixPage,
  matrixCallRef,
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

async function reconnectProbe(page) {
  return page.evaluate((storageKey) => {
    const setup = document.querySelector('.workspace-call-view')?.__vueParentComponent?.setupState;
    const frames = Array.isArray(window.__matrixSocketFrames) ? window.__matrixSocketFrames : [];
    const sockets = Array.isArray(window.__matrixSockets) ? window.__matrixSockets : [];
    const fetchCalls = Array.isArray(window.__matrixFetchCalls) ? window.__matrixFetchCalls : [];
    const lifecycle = Array.isArray(window.__matrixSocketLifecycle) ? window.__matrixSocketLifecycle : [];
    const pageLifecycle = Array.isArray(window.__matrixPageLifecycleEvents) ? window.__matrixPageLifecycleEvents : [];

    return {
      connectionState: String(setup?.connectionState || ''),
      connectionReason: String(setup?.connectionReason || ''),
      currentUrl: window.location.href,
      storedSessionPresent: Boolean(localStorage.getItem(storageKey)),
      socketCount: sockets.filter((socket) => String(socket?.url || '').includes('/ws?')).length,
      snapshotRequests: frames.filter((frame) => frame?.type === 'room/snapshot/request').length,
      fetchCalls,
      lifecycle,
      pageLifecycle,
    };
  }, sessionStorageKey);
}

async function waitForReconnectBackfill(page, previous) {
  await page.waitForFunction((before) => {
    const setup = document.querySelector('.workspace-call-view')?.__vueParentComponent?.setupState;
    const frames = Array.isArray(window.__matrixSocketFrames) ? window.__matrixSocketFrames : [];
    const sockets = Array.isArray(window.__matrixSockets) ? window.__matrixSockets : [];
    const snapshotRequests = frames.filter((frame) => frame?.type === 'room/snapshot/request').length;
    const socketCount = sockets.filter((socket) => String(socket?.url || '').includes('/ws?')).length;
    return setup?.connectionState === 'online'
      && socketCount > before.socketCount
      && snapshotRequests > before.snapshotRequests;
  }, previous, { timeout: 12_000 });
}

function expectNoLogoutOrReload(probe, observedNavigationUrls, logoutRequests, initialUrl) {
  expect(probe.currentUrl).toBe(initialUrl);
  expect(probe.storedSessionPresent).toBe(true);
  expect(observedNavigationUrls).toEqual([]);
  expect(logoutRequests).toEqual([]);
  expect(probe.fetchCalls.filter((call) => String(call.url || '').includes('/api/auth/logout'))).toEqual([]);
  expect(probe.pageLifecycle).toEqual([]);
  expect(probe.connectionState).toBe('online');
}

test('retryable websocket auth error keeps the browser session and requests room snapshot after reconnect', async ({ browser }) => {
  test.setTimeout(90_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const admin = await createMatrixPage(browser, baseURL, matrixUsers.admin);
  const observedNavigationUrls = [];
  const logoutRequests = [];

  try {
    await openMatrixWorkspaceWithRealtimeSocket(admin.page);
    const initialUrl = admin.page.url();
    admin.page.on('framenavigated', (frame) => {
      if (frame === admin.page.mainFrame()) observedNavigationUrls.push(frame.url());
    });
    admin.page.on('request', (request) => {
      if (request.url().includes('/api/auth/logout')) logoutRequests.push(request.url());
    });

    const before = await reconnectProbe(admin.page);
    expect(before.snapshotRequests).toBeGreaterThan(0);

    const emitted = await admin.page.evaluate(() => window.__matrixEmitRetryableAuthError());
    expect(emitted).toBe(true);
    await admin.page.waitForFunction(() => {
      const setup = document.querySelector('.workspace-call-view')?.__vueParentComponent?.setupState;
      const lifecycle = window.__matrixSocketLifecycle || [];
      return setup?.connectionState === 'retrying'
        || lifecycle.some((event) => event?.type === 'close' && event?.reason === 'client_close');
    });

    await waitForReconnectBackfill(admin.page, before);
    const after = await reconnectProbe(admin.page);
    expect(after.snapshotRequests).toBeGreaterThan(before.snapshotRequests);
    expectNoLogoutOrReload(after, observedNavigationUrls, logoutRequests, initialUrl);
  } finally {
    await Promise.allSettled([admin.context.close()]);
  }
});

test('retryable websocket backfill handshake failure retries and backfills the room snapshot without logout', async ({ browser }) => {
  test.setTimeout(90_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
  const admin = await createMatrixPage(browser, baseURL, matrixUsers.admin);
  const observedNavigationUrls = [];
  const logoutRequests = [];

  try {
    await openMatrixWorkspaceWithRealtimeSocket(admin.page);
    const initialUrl = admin.page.url();
    admin.page.on('framenavigated', (frame) => {
      if (frame === admin.page.mainFrame()) observedNavigationUrls.push(frame.url());
    });
    admin.page.on('request', (request) => {
      if (request.url().includes('/api/auth/logout')) logoutRequests.push(request.url());
    });

    const before = await reconnectProbe(admin.page);
    expect(before.snapshotRequests).toBeGreaterThan(0);

    const queued = await admin.page.evaluate(() => window.__matrixQueueSocketConnectFailure({
      code: 1011,
      reason: 'websocket_reconnect_backfill_unavailable',
    }));
    expect(queued).toBe(1);
    const dropped = await admin.page.evaluate(() => window.__matrixForceSocketClose(1006, 'network_drop'));
    expect(dropped).toBe(true);

    await admin.page.waitForFunction(() => (
      (window.__matrixSocketLifecycle || []).some((event) => (
        event?.type === 'connect-failure'
        && event?.reason === 'websocket_reconnect_backfill_unavailable'
      ))
    ), null, { timeout: 10_000 });
    await waitForReconnectBackfill(admin.page, before);

    const after = await reconnectProbe(admin.page);
    expect(after.snapshotRequests).toBeGreaterThan(before.snapshotRequests);
    expect(after.lifecycle.some((event) => (
      event?.type === 'connect-failure'
      && event?.reason === 'websocket_reconnect_backfill_unavailable'
    ))).toBe(true);
    expectNoLogoutOrReload(after, observedNavigationUrls, logoutRequests, initialUrl);
  } finally {
    await Promise.allSettled([admin.context.close()]);
  }
});
