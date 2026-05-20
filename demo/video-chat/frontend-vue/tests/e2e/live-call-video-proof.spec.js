import { mkdir, writeFile } from 'node:fs/promises';
import path, { dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

import { test, expect } from '@playwright/test';

import {
  admitFirstLobbyUser,
  createAuthenticatedPage,
  enterOwnerWorkspaceCall,
  escapeRegExp,
  queueUserAdmission,
  remoteVideoPixelSnapshot,
  waitForDeterministicRemoteVideo,
} from './helpers/nativeAudioTransferHarness.js';

const REQUIRED_FLAG = 'KINGRT_LIVE_CALL_PROOF';
const DEFAULT_CALL_ID = '39c5b3ea-855b-40fd-b030-c8af1d512605';
const DEFAULT_DURATION_MS = 30 * 60 * 1000;
const DEFAULT_SAMPLE_INTERVAL_MS = 30 * 1000;
const __dirname = dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');

function envValue(...keys) {
  for (const key of keys) {
    const value = String(process.env[key] || '').trim();
    if (value !== '') return value;
  }
  return '';
}

function isEnabled(value) {
  return /^(?:1|true|yes|on)$/i.test(String(value || '').trim());
}

function positiveEnvInteger(key, fallback) {
  const value = Number.parseInt(String(process.env[key] || ''), 10);
  return Number.isFinite(value) && value > 0 ? value : fallback;
}

function productionBaseUrl(testInfo) {
  return String(
    testInfo.project.use.baseURL
      || process.env.PLAYWRIGHT_PRODUCTION_BASE_URL
      || process.env.VIDEOCHAT_ONLINE_BASE_URL
      || 'https://app.kingrt.com',
  ).replace(/\/+$/, '');
}

function credentialsFor(role) {
  const upperRole = String(role || '').trim().toUpperCase();
  return {
    email: envValue(
      `VIDEOCHAT_PRODUCTION_${upperRole}_EMAIL`,
      `VIDEOCHAT_E2E_${upperRole}_EMAIL`,
      `VIDEOCHAT_DEPLOY_${upperRole}_EMAIL`,
    ),
    password: envValue(
      `VIDEOCHAT_PRODUCTION_${upperRole}_PASSWORD`,
      `VIDEOCHAT_E2E_${upperRole}_PASSWORD`,
      `VIDEOCHAT_DEPLOY_${upperRole}_PASSWORD`,
    ),
  };
}

function sanitizedConsoleMessage(message) {
  return String(message || '')
    .replace(/\/join\/[^/?#\s]+/gi, '/join/[REDACTED]')
    .replace(/\/workspace\/call\/[^/?#\s]+/gi, '/workspace/call/[REDACTED]')
    .replace(/\b(?:https?|wss?):\/\/[^\s"'<>\\]+/gi, '[REDACTED_URL]')
    .slice(0, 320);
}

function attachConsoleCapture(page, label, events) {
  page.on('console', (message) => {
    events.push({
      at: new Date().toISOString(),
      label,
      text: sanitizedConsoleMessage(message.text()),
      type: message.type(),
    });
  });
  page.on('pageerror', (error) => {
    events.push({
      at: new Date().toISOString(),
      label,
      text: sanitizedConsoleMessage(error instanceof Error ? error.message : String(error || '')),
      type: 'pageerror',
    });
  });
}

async function installLiveProofPageLoadCounter(context, key) {
  await context.addInitScript(({ storageKey }) => {
    if (window.top !== window) return;
    try {
      const nextCount = Number.parseInt(window.localStorage.getItem(storageKey) || '0', 10) + 1;
      window.localStorage.setItem(storageKey, String(nextCount));
    } catch {
      // Sandboxed Call App iframes can have opaque origins; only the top-level page is counted.
    }
  }, { storageKey: key });
}

async function pageLoadCount(page, key) {
  return page.evaluate((storageKey) => Number.parseInt(window.localStorage.getItem(storageKey) || '0', 10), key);
}

async function socketSnapshot(page) {
  return page.evaluate(() => {
    const events = Array.isArray(window.__kingNativeAudioSocketEvents) ? window.__kingNativeAudioSocketEvents : [];
    const websocketEvents = events.filter((event) => {
      const url = String(event?.url || '');
      return url.includes('/ws') || url.startsWith('ws:') || url.startsWith('wss:');
    });
    const binaryIn = websocketEvents.filter((event) => event?.direction === 'in' && event?.frame?.type === '__binary__');
    const binaryOut = websocketEvents.filter((event) => event?.direction === 'out' && event?.frame?.type === '__binary__');
    return {
      binaryInCount: binaryIn.length,
      binaryOutCount: binaryOut.length,
      closeCount: websocketEvents.filter((event) => event?.frame?.type === '__socket_close__').length,
      errorCount: websocketEvents.filter((event) => event?.frame?.type === '__socket_error__').length,
      inCount: websocketEvents.filter((event) => event?.direction === 'in').length,
      maxBinaryInBytes: binaryIn.reduce((max, event) => Math.max(max, Number(event?.frame?.bytes || 0)), 0),
      maxBinaryOutBytes: binaryOut.reduce((max, event) => Math.max(max, Number(event?.frame?.bytes || 0)), 0),
      outCount: websocketEvents.filter((event) => event?.direction === 'out').length,
    };
  });
}

async function openLiveParticipantFromJoinLink(page, joinUrl, callId) {
  await page.goto(joinUrl);
  const joinDialog = page.locator('.call-access-join-modal').first();
  await expect(joinDialog).toBeVisible({ timeout: 30_000 });

  const guestName = envValue('KINGRT_LIVE_PROOF_GUEST_NAME') || 'KingRT Live Proof Sender';
  const guestNameInput = joinDialog.locator('.call-access-join-guest-name input, input[placeholder*="display name" i], input[type="text"]').first();
  await expect(guestNameInput).toBeVisible({ timeout: 30_000 });
  await guestNameInput.click();
  await guestNameInput.fill(guestName);
  await guestNameInput.evaluate((element, value) => {
    if (!(element instanceof HTMLInputElement)) return;
    element.value = value;
    element.dispatchEvent(new Event('input', { bubbles: true }));
    element.dispatchEvent(new Event('change', { bubbles: true }));
  }, guestName);
  await expect(guestNameInput).toHaveValue(guestName, { timeout: 2_000 });

  const joinButton = joinDialog.getByRole('button', { name: /^Join call$/i });
  await expect(joinButton).toBeVisible({ timeout: 30_000 });
  await joinButton.click();

  const workspacePattern = new RegExp(`/workspace/call/${escapeRegExp(callId)}(?:[/?#].*)?$`);
  await Promise.race([
    page.waitForURL(workspacePattern, { timeout: 30_000 }).catch(() => null),
    expect(joinDialog).toContainText(/Call owner has been notified|Waiting for host/i, { timeout: 30_000 }).catch(() => null),
  ]);
}

async function writeArtifact(testInfo, payload) {
  const artifactPath = testInfo.outputPath('live-call-video-proof.json');
  const repoArtifactPath = path.join(
    repoRoot,
    'analysis',
    'live-call-video-proof',
    `${String(payload.callId || 'unknown-call').replace(/[^a-z0-9-]+/gi, '_')}-${String(payload.startedAt || Date.now()).replace(/[^0-9a-z]+/gi, '-')}.json`,
  );
  await mkdir(dirname(artifactPath), { recursive: true });
  await mkdir(dirname(repoArtifactPath), { recursive: true });
  const serialized = `${JSON.stringify(payload, null, 2)}\n`;
  await Promise.all([
    writeFile(artifactPath, serialized, 'utf8'),
    writeFile(repoArtifactPath, serialized, 'utf8'),
  ]);
}

test('live call deterministic video remains visible without reload or participant kick', async ({ browser }, testInfo) => {
  const durationMs = positiveEnvInteger('KINGRT_LIVE_PROOF_DURATION_MS', DEFAULT_DURATION_MS);
  const sampleIntervalMs = positiveEnvInteger('KINGRT_LIVE_PROOF_SAMPLE_INTERVAL_MS', DEFAULT_SAMPLE_INTERVAL_MS);
  const initialVideoTimeoutMs = positiveEnvInteger('KINGRT_LIVE_INITIAL_VIDEO_TIMEOUT_MS', 120_000);
  test.setTimeout(durationMs + 180_000);
  test.skip(!isEnabled(process.env[REQUIRED_FLAG]), `${REQUIRED_FLAG}=1 is required for the live call proof.`);

  const callId = envValue('KINGRT_LIVE_CALL_ID') || DEFAULT_CALL_ID;
  const joinUrl = envValue('KINGRT_LIVE_JOIN_URL');
  if (joinUrl === '') throw new Error('KINGRT_LIVE_JOIN_URL is required for the live participant.');

  const adminCredentials = credentialsFor('admin');
  const userCredentials = credentialsFor('user');
  if (!adminCredentials.email || !adminCredentials.password || !userCredentials.email || !userCredentials.password) {
    throw new Error('Production live proof requires admin and user credentials in VIDEOCHAT_PRODUCTION_* or accepted aliases.');
  }

  const baseURL = productionBaseUrl(testInfo);
  const consoleEvents = [];
  const artifact = {
    callId,
    durationMs,
    samples: [],
    startedAt: new Date().toISOString(),
  };
  let admin = null;
  let sender = null;

  try {
    admin = await createAuthenticatedPage(browser, baseURL, adminCredentials, {
      audioFrequency: 440,
      deterministicVideoPattern: false,
      videoFrameRate: 30,
      videoHeight: 720,
      videoWidth: 1280,
    });
    sender = await createAuthenticatedPage(browser, baseURL, userCredentials, {
      audioFrequency: 660,
      deterministicVideoPattern: true,
      outgoingVideoQualityProfile: 'strict_720p30',
      videoFrameRate: 30,
      videoHeight: 720,
      videoPatternLabel: 'KINGRT LIVE PROOF',
      videoWidth: 1280,
    });

    await Promise.all([
      installLiveProofPageLoadCounter(admin.context, 'kingrt_live_proof_admin_loads'),
      installLiveProofPageLoadCounter(sender.context, 'kingrt_live_proof_sender_loads'),
    ]);
    attachConsoleCapture(admin.page, 'admin', consoleEvents);
    attachConsoleCapture(sender.page, 'sender', consoleEvents);

    await enterOwnerWorkspaceCall(admin.page, callId);
    await openLiveParticipantFromJoinLink(sender.page, joinUrl, callId);
    await admitFirstLobbyUser(admin.page, sender.storedSession.userId);
    await sender.page.waitForURL(new RegExp(`/workspace/call/${escapeRegExp(callId)}(?:[/?#].*)?$`), { timeout: 60_000 });
    await expect(sender.page.locator('.workspace-main-video')).toBeVisible({ timeout: 30_000 });
    const [adminLoadBaseline, senderLoadBaseline] = await Promise.all([
      pageLoadCount(admin.page, 'kingrt_live_proof_admin_loads'),
      pageLoadCount(sender.page, 'kingrt_live_proof_sender_loads'),
    ]);

    artifact.preInitialPixels = await remoteVideoPixelSnapshot(admin.page);
    const initialPixels = await waitForDeterministicRemoteVideo(admin.page, { timeout: initialVideoTimeoutMs });
    artifact.initialPixels = initialPixels;

    const startMs = Date.now();
    const deadlineMs = startMs + durationMs;
    while (Date.now() < deadlineMs) {
      await new Promise((resolve) => setTimeout(resolve, Math.min(sampleIntervalMs, Math.max(1, deadlineMs - Date.now()))));
      const [pixels, adminSockets, senderSockets, adminLoads, senderLoads] = await Promise.all([
        remoteVideoPixelSnapshot(admin.page),
        socketSnapshot(admin.page),
        socketSnapshot(sender.page),
        pageLoadCount(admin.page, 'kingrt_live_proof_admin_loads'),
        pageLoadCount(sender.page, 'kingrt_live_proof_sender_loads'),
      ]);
      const maxPatternScore = pixels.reduce((max, entry) => Math.max(max, Number(entry.patternScore || 0)), 0);
      const sample = {
        adminLoads,
        adminSockets,
        at: new Date().toISOString(),
        maxPatternScore,
        pixelSurfaces: pixels,
        senderLoads,
        senderSockets,
      };
      artifact.samples.push(sample);
      expect(maxPatternScore, `deterministic remote video pattern must remain visible at ${sample.at}`).toBeGreaterThanOrEqual(4);
      expect(adminLoads, 'admin page must not reload during proof').toBe(adminLoadBaseline);
      expect(senderLoads, 'sender page must not reload during proof').toBe(senderLoadBaseline);
      expect(adminSockets.closeCount, 'admin websocket must not close during proof').toBe(0);
      expect(senderSockets.closeCount, 'sender websocket must not close during proof').toBe(0);
      await expect(admin.page.locator('.workspace-main-video')).toBeVisible();
      await expect(sender.page.locator('.workspace-main-video')).toBeVisible();
    }

    const noisyFailures = consoleEvents.filter((entry) => (
      /VideoFrame was garbage collected|sqlite lock|Internal Server Error|websocket_one_shot|participant_left|participant_disconnected/i.test(entry.text)
      || entry.type === 'pageerror'
    ));
    artifact.consoleEvents = consoleEvents.slice(-200);
    artifact.finishedAt = new Date().toISOString();
    artifact.noisyFailures = noisyFailures;
    await writeArtifact(testInfo, artifact);
    expect(noisyFailures).toEqual([]);
  } catch (error) {
    artifact.error = error instanceof Error ? error.message : String(error || '');
    artifact.failureState = {
      adminPixels: admin?.page ? await remoteVideoPixelSnapshot(admin.page).catch((snapshotError) => ({
        error: snapshotError instanceof Error ? snapshotError.message : String(snapshotError || ''),
      })) : null,
      adminSockets: admin?.page ? await socketSnapshot(admin.page).catch((snapshotError) => ({
        error: snapshotError instanceof Error ? snapshotError.message : String(snapshotError || ''),
      })) : null,
      adminUrl: admin?.page ? admin.page.url() : null,
      senderPixels: sender?.page ? await remoteVideoPixelSnapshot(sender.page).catch((snapshotError) => ({
        error: snapshotError instanceof Error ? snapshotError.message : String(snapshotError || ''),
      })) : null,
      senderSockets: sender?.page ? await socketSnapshot(sender.page).catch((snapshotError) => ({
        error: snapshotError instanceof Error ? snapshotError.message : String(snapshotError || ''),
      })) : null,
      senderUrl: sender?.page ? sender.page.url() : null,
    };
    artifact.consoleEvents = consoleEvents.slice(-200);
    artifact.finishedAt = new Date().toISOString();
    await writeArtifact(testInfo, artifact);
    throw error;
  } finally {
    await Promise.allSettled([
      admin?.context?.close?.(),
      sender?.context?.close?.(),
    ]);
  }
});
