import { test, expect } from '@playwright/test';

import {
  getSeedCall,
  getSeedScenario,
  getSeedUser,
  sessionStorageKey,
} from './helpers/callAccessSeedMatrix.js';
import { createDirectJoinMatrixPage } from './helpers/callAccessSeedRuntime.js';

function escapeRegExp(input) {
  return String(input).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function countAllowedDirectJoinDecisions(directJoinDecisions, callId, source) {
  return directJoinDecisions.filter((decision) => (
    decision.call_id === callId
    && decision.allowed === true
    && decision.source === source
  )).length;
}

async function socketProbe(page) {
  return page.evaluate((storageKey) => {
    const frames = Array.isArray(window.__iamCallAccessSocketFrames) ? window.__iamCallAccessSocketFrames : [];
    const sockets = Array.isArray(window.__iamCallAccessSockets) ? window.__iamCallAccessSockets : [];
    const setup = document.querySelector('.workspace-call-view')?.__vueParentComponent?.setupState;
    let storedSession = {};
    try {
      storedSession = JSON.parse(localStorage.getItem(storageKey) || '{}');
    } catch {
      storedSession = {};
    }

    return {
      connectionState: String(setup?.connectionState || ''),
      storedSessionPresent: Boolean(storedSession.sessionToken || storedSession.sessionId),
      storedSessionToken: String(storedSession.sessionToken || ''),
      socketCount: sockets.filter((socket) => String(socket?.url || '').includes('/ws?')).length,
      roomJoins: frames.filter((frame) => frame?.type === 'room/join').length,
      snapshotRequests: frames.filter((frame) => frame?.type === 'room/snapshot/request').length,
      roomLeaves: frames.filter((frame) => frame?.type === 'room/leave').length,
      currentUrl: window.location.href,
      canModerate: Boolean(setup?.canModerate),
      viewerCanModerateCall: Boolean(setup?.viewerCanModerateCall),
    };
  }, sessionStorageKey);
}

async function openAuthorizedWorkspace(page, call, expectedSource) {
  const resolveResponsePromise = page.waitForResponse((response) => (
    response.url().includes(`/api/calls/resolve/${call.id}`)
    && response.request().method() === 'GET'
  ));

  await page.goto(`/workspace/call/${call.id}`);
  const resolveResponse = await resolveResponsePromise;
  expect(resolveResponse.status()).toBe(200);
  const resolvePayload = await resolveResponse.json();
  expect(resolvePayload?.result?.state).toBe('resolved');
  expect(resolvePayload?.result?.call?.id).toBe(call.id);
  expect(resolvePayload?.result?.access_decision?.source).toBe(expectedSource);

  await expect(page).toHaveURL(new RegExp(`/workspace/call/${escapeRegExp(call.id)}(?:[/?#].*)?$`));
  await expect(page.locator('.workspace-call-view')).toBeVisible({ timeout: 20_000 });
  await page.waitForFunction(() => {
    const setup = document.querySelector('.workspace-call-view')?.__vueParentComponent?.setupState;
    const frames = Array.isArray(window.__iamCallAccessSocketFrames) ? window.__iamCallAccessSocketFrames : [];
    return setup?.connectionState === 'online'
      && frames.some((frame) => frame?.type === 'room/snapshot/request' || frame?.type === 'room/join');
  }, null, { timeout: 15_000 });
}

async function leaveCall(page, call) {
  const beforeLeave = await socketProbe(page);
  await page.getByTitle('Hang up').click();
  await expect(page).toHaveURL(/\/(?:admin\/calls|user\/dashboard)(?:[/?#].*)?$/, { timeout: 15_000 });
  await page.waitForFunction((previous) => {
    const frames = Array.isArray(window.__iamCallAccessSocketFrames) ? window.__iamCallAccessSocketFrames : [];
    return frames.filter((frame) => frame?.type === 'room/leave').length > previous.roomLeaves
      || !window.location.pathname.includes(`/workspace/call/${previous.callId}`);
  }, { ...beforeLeave, callId: call.id }, { timeout: 10_000 });

  const afterLeave = await socketProbe(page);
  expect(afterLeave.storedSessionPresent).toBe(true);
  expect(afterLeave.storedSessionToken).toBe(beforeLeave.storedSessionToken);
  return beforeLeave;
}

const authorizedRejoinCases = [
  {
    title: 'e2e_rejoin_006_registered_guest_can_rejoin: registered guest-list user can leave and rejoin with the same session',
    scenarioKey: 'direct_join_guest_list_user_allowed',
    expectedSource: 'guest_list',
    expectedModeration: false,
  },
  {
    title: 'system admin can leave and rejoin without guest-list entry',
    scenarioKey: 'direct_join_system_admin_without_guest_list',
    expectedSource: 'system_admin',
    expectedModeration: true,
  },
  {
    title: 'organization admin can leave and rejoin own organization call without guest-list entry',
    scenarioKey: 'direct_join_org_admin_own_organization_without_guest_list',
    expectedSource: 'organization_admin',
    expectedModeration: true,
  },
];

for (const rejoinCase of authorizedRejoinCases) {
  test(rejoinCase.title, async ({ browser }) => {
    test.setTimeout(90_000);
    const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';
    const scenario = getSeedScenario(rejoinCase.scenarioKey);
    const call = getSeedCall(scenario.call_key);
    const user = getSeedUser(scenario.principal_user_key);
    const { context, page, directJoinDecisions } = await createDirectJoinMatrixPage(browser, baseURL, {
      scenarioKey: rejoinCase.scenarioKey,
    });

    try {
      await openAuthorizedWorkspace(page, call, rejoinCase.expectedSource);
      const beforeLeave = await socketProbe(page);
      expect(beforeLeave.connectionState).toBe('online');
      expect(beforeLeave.storedSessionPresent).toBe(true);
      expect(beforeLeave.snapshotRequests).toBeGreaterThan(0);
      expect(beforeLeave.canModerate || beforeLeave.viewerCanModerateCall).toBe(rejoinCase.expectedModeration);

      const allowedBeforeLeave = countAllowedDirectJoinDecisions(
        directJoinDecisions,
        call.id,
        rejoinCase.expectedSource,
      );
      expect(allowedBeforeLeave).toBeGreaterThan(0);

      await leaveCall(page, call);
      await openAuthorizedWorkspace(page, call, rejoinCase.expectedSource);

      const afterRejoin = await socketProbe(page);
      expect(afterRejoin.connectionState).toBe('online');
      expect(afterRejoin.storedSessionPresent).toBe(true);
      expect(afterRejoin.storedSessionToken).toBe(beforeLeave.storedSessionToken);
      expect(afterRejoin.snapshotRequests).toBeGreaterThan(0);
      expect(afterRejoin.roomLeaves).toBe(0);
      expect(afterRejoin.canModerate || afterRejoin.viewerCanModerateCall).toBe(rejoinCase.expectedModeration);
      expect(page.url()).toContain(`/workspace/call/${call.id}`);
      if (rejoinCase.expectedSource === 'guest_list') {
        await expect(page.locator('.workspace-call-view')).toContainText(user.display_name);
      }

      expect(countAllowedDirectJoinDecisions(
        directJoinDecisions,
        call.id,
        rejoinCase.expectedSource,
      )).toBeGreaterThan(allowedBeforeLeave);
    } finally {
      await context.close();
    }
  });
}
