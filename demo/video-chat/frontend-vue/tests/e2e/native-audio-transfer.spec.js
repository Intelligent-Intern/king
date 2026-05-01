import { test, expect } from '@playwright/test';

import {
  adminCredentials,
  userCredentials,
  admitFirstLobbyUser,
  createAuthenticatedPage,
  createInvitedCallViaApi,
  createPersonalAccessJoinPath,
  enterOwnerWorkspaceCall,
  escapeRegExp,
  measureNativeAudioBridgeEnergy,
  nativeAudioBridgeSnapshot,
  nativeMediaSignalCount,
  queueUserAdmission,
} from './helpers/nativeAudioTransferHarness.js';

test('admitted participants receive encrypted remote audio over the native audio bridge', async ({ browser }) => {
  test.setTimeout(180_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';

  const { context: adminContext, page: adminPage, storedSession: adminSession } = await createAuthenticatedPage(
    browser,
    baseURL,
    adminCredentials,
    { audioFrequency: 440 },
  );
  const { context: userContext, page: userPage, storedSession: userSession } = await createAuthenticatedPage(
    browser,
    baseURL,
    userCredentials,
    { audioFrequency: 660 },
  );

  const participantUserId = userSession.userId || 2;
  const callTitle = `E2E Native Audio ${Date.now()}`;

  try {
    const callId = await createInvitedCallViaApi({
      sessionToken: adminSession.sessionToken,
      title: callTitle,
      participantUserId,
    });
    const userJoinPath = await createPersonalAccessJoinPath({
      callId,
      sessionToken: adminSession.sessionToken,
      participantUserId,
    });

    await enterOwnerWorkspaceCall(adminPage, callId);
    await queueUserAdmission(userPage, userJoinPath);
    await admitFirstLobbyUser(adminPage);

    await userPage.waitForURL(new RegExp(`/workspace/call/${escapeRegExp(callId)}(?:[/?#].*)?$`), { timeout: 30_000 });
    await expect(userPage.locator('.workspace-main-video')).toBeVisible({ timeout: 20_000 });

    await Promise.all([
      expect.poll(() => nativeMediaSignalCount(adminPage), {
        timeout: 45_000,
        message: 'admin browser should exchange media-security and native WebRTC audio bridge signals',
      }).toBeGreaterThan(3),
      expect.poll(() => nativeMediaSignalCount(userPage), {
        timeout: 45_000,
        message: 'user browser should exchange media-security and native WebRTC audio bridge signals',
      }).toBeGreaterThan(3),
    ]);

    await Promise.all([
      expect.poll(() => nativeAudioBridgeSnapshot(adminPage), {
        timeout: 60_000,
        message: 'admin browser should receive a live encrypted remote audio bridge track',
      }).toMatchObject({ hasLiveTrack: true }),
      expect.poll(() => nativeAudioBridgeSnapshot(userPage), {
        timeout: 60_000,
        message: 'user browser should receive a live encrypted remote audio bridge track',
      }).toMatchObject({ hasLiveTrack: true }),
    ]);

    await Promise.all([
      expect.poll(async () => (await measureNativeAudioBridgeEnergy(adminPage)).maxRms, {
        timeout: 45_000,
        message: 'admin browser should measure non-silent remote audio energy',
      }).toBeGreaterThan(0.003),
      expect.poll(async () => (await measureNativeAudioBridgeEnergy(userPage)).maxRms, {
        timeout: 45_000,
        message: 'user browser should measure non-silent remote audio energy',
      }).toBeGreaterThan(0.003),
    ]);
  } finally {
    await Promise.allSettled([
      adminContext.close(),
      userContext.close(),
    ]);
  }
});
