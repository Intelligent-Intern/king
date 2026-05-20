import { mkdir, writeFile } from 'node:fs/promises';
import path, { dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

import { expect, test } from '@playwright/test';

import {
  createAuthenticatedPage,
  enterOwnerWorkspaceCall,
  escapeRegExp,
  installMediaDeviceShim,
  installOutgoingVideoQualityPreference,
  installSocketInstrumentation,
  remoteVideoPixelSnapshot,
} from './helpers/nativeAudioTransferHarness.js';

const REQUIRED_FLAG = 'KINGRT_LIVE_SPUTNIK_SWARM';
const DEFAULT_CALL_ID = '39c5b3ea-855b-40fd-b030-c8af1d512605';
const DEFAULT_PARTICIPANTS = 20;
const DEFAULT_DURATION_MS = 10 * 60 * 1000;
const DEFAULT_SAMPLE_INTERVAL_MS = 15 * 1000;
const __dirname = dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');

const SPUTNIK_TONES = Object.freeze([
  330, 349, 392, 440, 494,
  523, 554, 587, 659, 698,
  740, 784, 831, 880, 932,
  988, 1047, 1109, 1175, 1245,
  1319, 1397, 1480, 1568, 1661,
]);

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

function credentialsFor(role) {
  const upperRole = String(role || '').trim().toUpperCase();
  const defaultAdminEmail = upperRole === 'ADMIN' ? 'admin@intelligent-intern.com' : '';
  return {
    email: envValue(
      `VIDEOCHAT_PRODUCTION_${upperRole}_EMAIL`,
      `VIDEOCHAT_E2E_${upperRole}_EMAIL`,
      `VIDEOCHAT_DEPLOY_${upperRole}_EMAIL`,
    ) || defaultAdminEmail,
    password: envValue(
      `VIDEOCHAT_PRODUCTION_${upperRole}_PASSWORD`,
      `VIDEOCHAT_E2E_${upperRole}_PASSWORD`,
      `VIDEOCHAT_DEPLOY_${upperRole}_PASSWORD`,
    ),
  };
}

function productionBaseUrl(testInfo) {
  return String(
    testInfo.project.use.baseURL
      || process.env.PLAYWRIGHT_PRODUCTION_BASE_URL
      || process.env.VIDEOCHAT_ONLINE_BASE_URL
      || 'https://app.kingrt.com',
  ).replace(/\/+$/, '');
}

function callIdFromJoinUrl(joinUrl, fallback) {
  const explicit = envValue('KINGRT_LIVE_CALL_ID');
  if (explicit !== '') return explicit;
  return fallback;
}

function sanitizedConsoleMessage(message) {
  return String(message || '')
    .replace(/\/join\/[^/?#\s]+/gi, '/join/[REDACTED]')
    .replace(/\/workspace\/call\/[^/?#\s]+/gi, '/workspace/call/[REDACTED]')
    .replace(/\b(?:https?|wss?):\/\/[^\s"'<>\\]+/gi, '[REDACTED_URL]')
    .slice(0, 360);
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

async function installLoadCounter(context, key) {
  await context.addInitScript(({ storageKey }) => {
    if (window.top !== window) return;
    try {
      const nextCount = Number.parseInt(window.localStorage.getItem(storageKey) || '0', 10) + 1;
      window.localStorage.setItem(storageKey, String(nextCount));
    } catch {
      // Sandboxed iframes do not need the top-level reload counter.
    }
  }, { storageKey: key });
}

async function pageLoadCount(page, key) {
  return page.evaluate((storageKey) => Number.parseInt(window.localStorage.getItem(storageKey) || '0', 10), key)
    .catch(() => 0);
}

async function socketSnapshot(page) {
  return page.evaluate(() => {
    const events = Array.isArray(window.__kingNativeAudioSocketEvents) ? window.__kingNativeAudioSocketEvents : [];
    const websocketEvents = events.filter((event) => {
      const url = String(event?.url || '');
      return url.includes('/ws') || url.startsWith('ws:') || url.startsWith('wss:');
    });
    return {
      closeCount: websocketEvents.filter((event) => event?.frame?.type === '__socket_close__').length,
      errorCount: websocketEvents.filter((event) => event?.frame?.type === '__socket_error__').length,
      inCount: websocketEvents.filter((event) => event?.direction === 'in').length,
      outCount: websocketEvents.filter((event) => event?.direction === 'out').length,
    };
  }).catch(() => ({ closeCount: 0, errorCount: 0, inCount: 0, outCount: 0 }));
}

async function openSputnikGuest(page, joinUrl, callId, displayName) {
  await page.goto(joinUrl, { waitUntil: 'domcontentloaded', timeout: 60_000 });
  const workspacePattern = new RegExp(`/workspace/call/${escapeRegExp(callId)}(?:[/?#].*)?$`);
  if (workspacePattern.test(new URL(page.url()).pathname)) return 'workspace';

  const joinDialog = page.locator('.call-access-join-modal').first();
  await expect(joinDialog).toBeVisible({ timeout: 45_000 });
  const guestNameInput = joinDialog.locator('.call-access-join-guest-name input, input[placeholder*="display name" i], input[type="text"]').first();
  await expect(guestNameInput).toBeVisible({ timeout: 30_000 });
  await guestNameInput.fill(displayName);
  await guestNameInput.evaluate((element, value) => {
    if (!(element instanceof HTMLInputElement)) return;
    element.value = value;
    element.dispatchEvent(new Event('input', { bubbles: true }));
    element.dispatchEvent(new Event('change', { bubbles: true }));
  }, displayName);

  const joinButton = joinDialog.getByRole('button', { name: /^Join call$/i });
  await expect(joinButton).toBeVisible({ timeout: 30_000 });
  await joinButton.click();

  const outcome = await Promise.race([
    page.waitForURL(workspacePattern, { timeout: 60_000 }).then(() => 'workspace').catch(() => null),
    expect(joinDialog).toContainText(/Call owner has been notified|Waiting for host/i, { timeout: 60_000 }).then(() => 'lobby').catch(() => null),
  ]);
  return outcome || 'unknown';
}

async function sendLobbyAllowAll(page) {
  await expect.poll(async () => page.evaluate(() => {
    const sockets = Array.isArray(window.__kingNativeAudioSockets) ? window.__kingNativeAudioSockets : [];
    return sockets.some((candidate) => {
      const url = String(candidate?.url || candidate?.__kingNativeAudioUrl || '');
      return url.includes('/ws') && candidate?.readyState === WebSocket.OPEN;
    });
  }), { timeout: 45_000 }).toBe(true);

  return page.evaluate(() => {
    const sockets = Array.isArray(window.__kingNativeAudioSockets) ? window.__kingNativeAudioSockets : [];
    const socket = sockets.find((candidate) => {
      const url = String(candidate?.url || candidate?.__kingNativeAudioUrl || '');
      return url.includes('/ws') && candidate?.readyState === WebSocket.OPEN;
    });
    if (!socket) return false;
    socket.send(JSON.stringify({ type: 'lobby/allow_all' }));
    return true;
  });
}

async function admitSputnikLobbyGuests(adminPage, participants, callId, artifact) {
  const sent = await sendLobbyAllowAll(adminPage);
  artifact.lobbyAllowAllSent = sent;
  if (!sent) return [];

  const workspacePattern = new RegExp(`/workspace/call/${escapeRegExp(callId)}(?:[/?#].*)?$`);
  const results = [];
  for (const participant of participants) {
    const automatic = await participant.page.waitForURL(workspacePattern, { timeout: 45_000 })
      .then(() => true)
      .catch(() => false);
    if (automatic) {
      results.push({ displayName: participant.displayName, outcome: 'workspace_after_allow' });
      continue;
    }

    await participant.page.goto(`/workspace/call/${callId}?entry=invite`, {
      waitUntil: 'domcontentloaded',
      timeout: 45_000,
    }).catch(() => null);
    const forced = workspacePattern.test(new URL(participant.page.url()).pathname);
    results.push({
      displayName: participant.displayName,
      outcome: forced ? 'workspace_forced_after_allow' : 'lobby_after_allow',
    });
  }
  return results;
}

async function writeArtifact(testInfo, payload) {
  const artifactPath = testInfo.outputPath('live-call-sputnik-swarm.json');
  const repoArtifactPath = path.join(
    repoRoot,
    'analysis',
    'live-call-sputnik-swarm',
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

async function createSputnikParticipant(browser, {
  baseURL,
  displayName,
  index,
  loadCounterKey,
  toneHz,
  videoFrameRate,
}) {
  const context = await browser.newContext({
    baseURL,
    permissions: ['camera', 'microphone'],
    viewport: { width: 1280, height: 720 },
  });
  await installMediaDeviceShim(context, {
    audioFrequency: toneHz,
    audioPulse: true,
    audioPulseIntervalMs: 1800 + (index % 5) * 450,
    deterministicVideoPattern: true,
    videoFrameRate,
    videoHeight: 360,
    videoPatternLabel: displayName,
    videoWidth: 640,
  });
  await installOutgoingVideoQualityPreference(context, 'rescue');
  await installSocketInstrumentation(context);
  await installLoadCounter(context, loadCounterKey);
  const page = await context.newPage();
  return { context, displayName, index, page, toneHz };
}

test('online Sputnik swarm joins the live call with generated video and beep audio', async ({ browser }, testInfo) => {
  test.skip(!isEnabled(process.env[REQUIRED_FLAG]), `${REQUIRED_FLAG}=1 is required for the online Sputnik swarm.`);

  const joinUrl = envValue('KINGRT_LIVE_JOIN_URL');
  if (joinUrl === '') throw new Error('KINGRT_LIVE_JOIN_URL is required.');

  const requestedParticipants = positiveEnvInteger('KINGRT_LIVE_SPUTNIK_PARTICIPANTS', DEFAULT_PARTICIPANTS);
  const participantCount = Math.max(1, Math.min(25, requestedParticipants));
  const durationMs = positiveEnvInteger('KINGRT_LIVE_SPUTNIK_DURATION_MS', DEFAULT_DURATION_MS);
  const sampleIntervalMs = positiveEnvInteger('KINGRT_LIVE_SPUTNIK_SAMPLE_INTERVAL_MS', DEFAULT_SAMPLE_INTERVAL_MS);
  const videoFrameRate = positiveEnvInteger('KINGRT_LIVE_SPUTNIK_VIDEO_FPS', 10);
  test.setTimeout(durationMs + participantCount * 20_000 + 240_000);

  const baseURL = productionBaseUrl(testInfo);
  const callId = callIdFromJoinUrl(joinUrl, DEFAULT_CALL_ID);
  const consoleEvents = [];
  const loadCounterKey = 'kingrt_live_sputnik_swarm_loads';
  const adminCredentials = credentialsFor('admin');
  const artifact = {
    baseURL,
    callId,
    durationMs,
    participantCount,
    samples: [],
    startedAt: new Date().toISOString(),
    videoFrameRate,
  };
  let admin = null;
  const participants = [];

  try {
    if (adminCredentials.email !== '' && adminCredentials.password !== '') {
      admin = await createAuthenticatedPage(browser, baseURL, adminCredentials, {
        audioFrequency: 220,
        deterministicVideoPattern: false,
        videoFrameRate: 10,
        videoHeight: 360,
        videoWidth: 640,
      });
      attachConsoleCapture(admin.page, 'admin', consoleEvents);
      await enterOwnerWorkspaceCall(admin.page, callId);
      artifact.adminMonitor = 'workspace';
    } else {
      artifact.adminMonitor = 'missing_credentials';
    }

    for (let index = 0; index < participantCount; index += 1) {
      const displayName = `Sputnik ${String(index + 1).padStart(2, '0')}`;
      const participant = await createSputnikParticipant(browser, {
        baseURL,
        displayName,
        index,
        loadCounterKey,
        toneHz: SPUTNIK_TONES[index % SPUTNIK_TONES.length],
        videoFrameRate,
      });
      attachConsoleCapture(participant.page, displayName, consoleEvents);
      participants.push(participant);
    }

    const joinResults = [];
    const batchSize = Math.max(1, Math.min(5, positiveEnvInteger('KINGRT_LIVE_SPUTNIK_JOIN_BATCH_SIZE', 4)));
    for (let offset = 0; offset < participants.length; offset += batchSize) {
      const batch = participants.slice(offset, offset + batchSize);
      const batchResults = await Promise.all(batch.map(async (participant) => {
        const outcome = await openSputnikGuest(participant.page, joinUrl, callId, participant.displayName);
        return { displayName: participant.displayName, outcome };
      }));
      joinResults.push(...batchResults);
      await new Promise((resolve) => setTimeout(resolve, 1000));
    }
    artifact.joinResults = joinResults;

    if (joinResults.some((entry) => entry.outcome === 'lobby')) {
      if (!admin?.page) {
        throw new Error('Sputnik guests are waiting in lobby, but admin credentials are missing for allow_all.');
      }
      artifact.admissionResults = await admitSputnikLobbyGuests(admin.page, participants, callId, artifact);
    } else {
      artifact.admissionResults = [];
    }

    const finalJoinResults = [
      ...joinResults.filter((entry) => entry.outcome === 'workspace'),
      ...artifact.admissionResults.filter((entry) => String(entry.outcome || '').startsWith('workspace')),
    ];
    const joined = finalJoinResults;
    expect(joined.length, 'all Sputnik guests should enter the live call workspace').toBe(participantCount);

    await Promise.all(participants.map(async (participant) => {
      await expect(participant.page.locator('.workspace-main-video')).toBeVisible({ timeout: 45_000 });
    }));

    const monitor = participants[0];
    const loadBaseline = await pageLoadCount(monitor.page, loadCounterKey);
    const startMs = Date.now();
    const deadlineMs = startMs + durationMs;
    while (Date.now() < deadlineMs) {
      await new Promise((resolve) => setTimeout(resolve, Math.min(sampleIntervalMs, Math.max(1, deadlineMs - Date.now()))));
      const [pixels, sockets, monitorLoads] = await Promise.all([
        remoteVideoPixelSnapshot(monitor.page),
        socketSnapshot(monitor.page),
        pageLoadCount(monitor.page, loadCounterKey),
      ]);
      const visiblePatternSurfaces = pixels.filter((entry) => Number(entry.patternScore || 0) >= 4);
      artifact.samples.push({
        at: new Date().toISOString(),
        monitorLoads,
        pixelSurfaces: pixels,
        sockets,
        visiblePatternSurfaceCount: visiblePatternSurfaces.length,
      });
      expect(monitorLoads, 'monitor page must not reload during Sputnik swarm').toBe(loadBaseline);
      expect(sockets.errorCount, 'monitor websocket must not error during Sputnik swarm').toBe(0);
      expect(sockets.closeCount, 'monitor websocket must not close during Sputnik swarm').toBe(0);
      expect(visiblePatternSurfaces.length, 'monitor should see at least one generated Sputnik video surface').toBeGreaterThanOrEqual(1);
    }

    artifact.consoleEvents = consoleEvents.slice(-250);
    artifact.finishedAt = new Date().toISOString();
    await writeArtifact(testInfo, artifact);
  } catch (error) {
    artifact.error = error instanceof Error ? error.message : String(error || '');
    artifact.consoleEvents = consoleEvents.slice(-250);
    artifact.finishedAt = new Date().toISOString();
    await writeArtifact(testInfo, artifact);
    throw error;
  } finally {
    await Promise.allSettled(participants.map((participant) => participant.context.close()));
    await admin?.context?.close?.().catch?.(() => {});
  }
});
