import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { chromium } from '@playwright/test';

const SCRIPT_DIR = path.dirname(fileURLToPath(import.meta.url));
const FRONTEND_DIR = path.resolve(SCRIPT_DIR, '../..');
const VIDEOCHAT_DIR = path.resolve(FRONTEND_DIR, '..');
const LOCAL_ENV_FILE = path.join(VIDEOCHAT_DIR, '.env.local');

const HD_WIDTH = 1280;
const HD_HEIGHT = 720;
const QUALITY_FRAME_RATE = 27;
const MIN_REMOTE_WIDTH = 854;
const MIN_REMOTE_HEIGHT = 480;
const PRESSURE_DURATION_MS = Math.max(15_000, Number.parseInt(process.env.VIDEOCHAT_ONLINE_PRESSURE_DURATION_MS || '45000', 10));
const SAMPLE_INTERVAL_MS = Math.max(1_000, Number.parseInt(process.env.VIDEOCHAT_ONLINE_PRESSURE_SAMPLE_INTERVAL_MS || '2500', 10));
const SLOW_SUBSCRIBER_DURATION_MS = Math.max(5_000, Number.parseInt(process.env.VIDEOCHAT_ONLINE_PRESSURE_SLOW_MS || '12000', 10));
const CRITICAL_BUFFERED_BYTES = 10 * 1024 * 1024;
const MAX_ACCEPTED_BUFFERED_BYTES = 8 * 1024 * 1024;
const MAX_NO_TRANSITION_BUFFERED_BYTES = 1024 * 1024;
const MAX_FINAL_BUFFERED_BYTES = 512 * 1024;
const MAX_TRANSIENT_BLACK_SAMPLES = 1;

const BLOCKED_RUNTIME_PATTERNS = [
  /\bwrong_key_id\b/i,
  /\bmalformed_protected_frame\b/i,
  /\bsfu_protected_frame_decrypt_failed\b/i,
  /\bsfu_source_readback_budget_exceeded\b/i,
  /\bsfu_source_readback_budget_pressure\b/i,
  /\bsfu_source_readback_profile_downshift\b/i,
  /\bsfu_send_backpressure_critical\b/i,
  /\bSFU video backpressure\b/i,
  /\bremote video frozen\b/i,
  /\brestarting SFU socket after video stall\b/i,
  /\bsend_buffer_drain_timeout\b/i,
  /\bSFU frame send failed\b/i,
  /\blegacy_chunked_json\b/i,
  /\bTypeError: .* is not a function\b/i,
  /\b\/api\/runtime\b.*\b5\d\d\b/i,
  /\bBad Gateway\b/i,
];

let latestSummary = null;

function unquoteEnvValue(value) {
  const trimmed = String(value || '').trim();
  if (trimmed.length >= 2 && trimmed.startsWith('"') && trimmed.endsWith('"')) {
    return trimmed.slice(1, -1).replace(/\\n/g, '\n').replace(/\\"/g, '"').replace(/\\\\/g, '\\');
  }
  if (trimmed.length >= 2 && trimmed.startsWith("'") && trimmed.endsWith("'")) return trimmed.slice(1, -1);
  return trimmed;
}

function loadLocalEnv(filePath) {
  if (!fs.existsSync(filePath)) return;
  const raw = fs.readFileSync(filePath, 'utf8');
  for (const line of raw.split(/\r?\n/)) {
    const trimmed = line.trim();
    if (trimmed === '' || trimmed.startsWith('#')) continue;
    const match = /^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)=(.*)$/.exec(trimmed);
    if (!match) continue;
    const [, key, value] = match;
    if (process.env[key] === undefined) process.env[key] = unquoteEnvValue(value);
  }
}

function domainFromEnv() {
  return String(process.env.VIDEOCHAT_DEPLOY_DOMAIN || process.env.VIDEOCHAT_V1_PUBLIC_HOST || 'kingrt.com').trim();
}

function configureOnlineOrigins() {
  loadLocalEnv(LOCAL_ENV_FILE);
  const domain = domainFromEnv();
  const apiDomain = String(process.env.VIDEOCHAT_DEPLOY_API_DOMAIN || `api.${domain}`).trim();
  const wsDomain = String(process.env.VIDEOCHAT_DEPLOY_WS_DOMAIN || `ws.${domain}`).trim();
  const sfuDomain = String(process.env.VIDEOCHAT_DEPLOY_SFU_DOMAIN || `sfu.${domain}`).trim();

  process.env.VITE_VIDEOCHAT_BACKEND_ORIGIN ||= `https://${apiDomain}`;
  process.env.VITE_VIDEOCHAT_WS_ORIGIN ||= `wss://${wsDomain}`;
  process.env.VITE_VIDEOCHAT_SFU_ORIGIN ||= `wss://${sfuDomain}`;
  process.env.VITE_VIDEOCHAT_ALLOW_INSECURE_WS ||= '0';

  return {
    baseURL: String(process.env.VIDEOCHAT_ONLINE_BASE_URL || `https://${domain}`).replace(/\/+$/, ''),
    backendOrigin: process.env.VITE_VIDEOCHAT_BACKEND_ORIGIN,
    wsOrigin: process.env.VITE_VIDEOCHAT_WS_ORIGIN,
    sfuOrigin: process.env.VITE_VIDEOCHAT_SFU_ORIGIN,
  };
}

function credentialsFromEnv(defaults) {
  const role = String(defaults.role || '').trim().toUpperCase();
  const deployPasswordKey = role === 'ADMIN' ? 'VIDEOCHAT_DEPLOY_ADMIN_PASSWORD' : 'VIDEOCHAT_DEPLOY_USER_PASSWORD';
  const e2ePasswordKey = role === 'ADMIN' ? 'VIDEOCHAT_E2E_ADMIN_PASSWORD' : 'VIDEOCHAT_E2E_USER_PASSWORD';
  const e2eEmailKey = role === 'ADMIN' ? 'VIDEOCHAT_E2E_ADMIN_EMAIL' : 'VIDEOCHAT_E2E_USER_EMAIL';
  return {
    email: String(process.env[e2eEmailKey] || defaults.email || '').trim(),
    password: String(process.env[e2ePasswordKey] || process.env[deployPasswordKey] || defaults.password || '').trim(),
  };
}

function installRuntimeMonitor(page, label) {
  const events = [];
  const push = (type, text) => {
    events.push({ label, type, text: String(text || ''), at: Date.now() });
  };
  page.on('console', (message) => push(`console:${message.type()}`, message.text()));
  page.on('pageerror', (error) => push('pageerror', error?.stack || error?.message || error));
  page.on('requestfailed', (request) => {
    const url = request.url();
    if (!/\/(?:api\/runtime|sfu|ws)(?:[/?#]|$)/i.test(url)) return;
    push('requestfailed', `${request.method()} ${url} ${request.failure()?.errorText || ''}`);
  });
  page.on('request', (request) => {
    const url = request.url();
    if (!/\/api\/user\/client-diagnostics(?:[/?#]|$)/i.test(url)) return;
    const postData = request.postData() || '';
    if (postData !== '') push('request:client-diagnostics', postData);
  });
  page.on('response', (response) => {
    const url = response.url();
    if (!/\/api\/runtime(?:[/?#]|$)/i.test(url) && !/\/assets\/CallWorkspaceView-/i.test(url)) return;
    if (response.status() < 400) return;
    push('response', `${response.status()} ${url}`);
  });
  return events;
}

function runtimeFailures(events, sinceMs) {
  return events.filter((event) => event.at >= sinceMs && BLOCKED_RUNTIME_PATTERNS.some((pattern) => pattern.test(event.text)));
}

function sleep(ms) {
  return new Promise((resolve) => {
    setTimeout(resolve, ms);
  });
}

async function waitUntil(label, timeoutMs, probe) {
  const startedAt = Date.now();
  let lastValue = null;
  while ((Date.now() - startedAt) < timeoutMs) {
    lastValue = await probe();
    if (lastValue) return lastValue;
    await sleep(1_000);
  }
  throw new Error(`${label} timed out; last=${JSON.stringify(lastValue)}`);
}

async function remoteVideoCanvases(page) {
  return page.evaluate(() => {
    const sampleCanvas = (canvas) => {
      try {
        const width = Number(canvas.width || 0);
        const height = Number(canvas.height || 0);
        if (width <= 0 || height <= 0) return { hash: 0, readable: false, luma: 0 };
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        if (!ctx) return { hash: 0, readable: false, luma: 0 };
        let hash = 2166136261;
        let luma = 0;
        let count = 0;
        for (let y = 1; y <= 5; y += 1) {
          for (let x = 1; x <= 5; x += 1) {
            const px = Math.max(0, Math.min(width - 1, Math.floor((width * x) / 6)));
            const py = Math.max(0, Math.min(height - 1, Math.floor((height * y) / 6)));
            const data = ctx.getImageData(px, py, 1, 1).data;
            luma += (0.2126 * data[0]) + (0.7152 * data[1]) + (0.0722 * data[2]);
            count += 1;
            for (const byte of data) {
              hash ^= byte;
              hash = Math.imul(hash, 16777619) >>> 0;
            }
          }
        }
        return { hash, readable: true, luma: count > 0 ? luma / count : 0 };
      } catch (error) {
        return { hash: 0, readable: false, luma: 0, error: String(error?.message || error || '') };
      }
    };

    return Array.from(document.querySelectorAll('#decoded-video-container canvas.remote-video, canvas.remote-video'))
      .map((canvas) => {
        const sample = sampleCanvas(canvas);
        return {
          width: Number(canvas.width || 0),
          height: Number(canvas.height || 0),
          publisherId: String(canvas.dataset.publisherId || ''),
          userId: String(canvas.dataset.userId || ''),
          inGridSlot: Boolean(canvas.closest('.workspace-grid-video-slot')),
          inDecodedFallback: Boolean(canvas.closest('#decoded-video-container')),
          rendered: canvas.width > 0 && canvas.height > 0 && canvas.isConnected,
          hash: sample.hash,
          readable: sample.readable,
          luma: sample.luma,
          hashError: sample.error || '',
        };
      });
  });
}

function firstHealthyCanvas(snapshot) {
  return (Array.isArray(snapshot) ? snapshot : []).find((canvas) => (
    canvas.rendered
    && canvas.width >= MIN_REMOTE_WIDTH
    && canvas.height >= MIN_REMOTE_HEIGHT
    && canvas.luma > 8
  )) || null;
}

async function waitForRemoteVideo(page, label, minWidth = MIN_REMOTE_WIDTH, minHeight = MIN_REMOTE_HEIGHT) {
  return waitUntil(`${label} remote video`, 90_000, async () => {
    const snapshot = await remoteVideoCanvases(page);
    return snapshot.some((canvas) => (
      canvas.rendered && canvas.width >= minWidth && canvas.height >= minHeight && canvas.luma > 8
    )) ? snapshot : null;
  });
}

async function waitForSfuBinaryFlow(sfuSocketStats, page, label) {
  return waitUntil(`${label} SFU binary flow`, 60_000, async () => {
    const stats = await sfuSocketStats(page);
    return stats.binaryInCount > 5 && stats.binaryOutCount > 5 ? stats : null;
  });
}

async function switchCallLayoutMode(page, mode, label) {
  await waitUntil(`${label} layout control`, 30_000, async () => page.locator('#call-left-layout-mode').count());
  await page.locator('#call-left-layout-mode').selectOption(mode);
  await waitUntil(`${label} layout ${mode}`, 30_000, async () => page.evaluate((nextMode) => {
    const stage = document.querySelector('.workspace-stage');
    return Boolean(stage?.classList.contains(`layout-${String(nextMode || '').replace('_', '-')}`));
  }, mode));
}

async function assertNoManualVideoQualitySelect(page, label) {
  const count = await page.locator('#call-left-video-quality').count();
  if (count !== 0) throw new Error(`${label} exposes manual video quality select.`);
}

function automaticQualityTransitions(events, sinceMs) {
  return events
    .filter((event) => event.at >= sinceMs && /\bSFU encode pressure - lowering outgoing video quality\b/.test(event.text))
    .map((event) => ({
      label: event.label,
      atMs: event.at - sinceMs,
      text: event.text,
      from: /\bfrom=([^\s]+)/.exec(event.text)?.[1] || '',
      to: /\bto=([^\s]+)/.exec(event.text)?.[1] || '',
      reason: /\breason=([^\s]+)/.exec(event.text)?.[1] || '',
    }));
}

function assertAutomaticQualityTransitions(events, sinceMs) {
  const transitions = automaticQualityTransitions(events, sinceMs);
  if (transitions.length === 0) {
    throw new Error('Pressure acceptance did not observe automatic SFU quality downshift.');
  }
  return transitions;
}

function maxObservedBufferedAmount(samples) {
  let maxBufferedAmount = 0;
  for (const sample of samples) {
    for (const side of ['admin', 'user']) {
      maxBufferedAmount = Math.max(
        maxBufferedAmount,
        Number(sample?.[side]?.stats?.maxBufferedAmountAfterSend || 0),
        Number(sample?.[side]?.stats?.currentBufferedAmount || 0),
      );
    }
  }
  return maxBufferedAmount;
}

function assertAutomaticQualityTransitionsOrNoPressure(events, sinceMs, samples) {
  const transitions = automaticQualityTransitions(events, sinceMs);
  if (transitions.length > 0) return transitions;

  const maxBufferedAmount = maxObservedBufferedAmount(samples);
  if (maxBufferedAmount > MAX_NO_TRANSITION_BUFFERED_BYTES) {
    throw new Error(
      `Pressure acceptance did not observe automatic SFU quality downshift despite bufferedAmount=${maxBufferedAmount}.`,
    );
  }

  return [{
    side: 'both',
    profile: 'no-transition-needed',
    atMs: Date.now() - sinceMs,
    reason: 'pressure_below_auto_downshift_threshold',
    maxBufferedAmountAfterSend: maxBufferedAmount,
  }];
}

async function installSlowSubscriberNetwork(context, page) {
  const cdp = await context.newCDPSession(page);
  await cdp.send('Network.enable');
  await cdp.send('Network.emulateNetworkConditions', {
    offline: false,
    latency: 220,
    downloadThroughput: 320 * 1024,
    uploadThroughput: -1,
  });
  return async () => {
    await cdp.send('Network.emulateNetworkConditions', {
      offline: false,
      latency: 0,
      downloadThroughput: -1,
      uploadThroughput: -1,
    }).catch(() => {});
    await cdp.detach().catch(() => {});
  };
}

async function collectSample({ adminPage, userPage, sfuSocketStats, elapsedMs, phase }) {
  return {
    elapsedMs,
    phase,
    admin: {
      remote: await remoteVideoCanvases(adminPage),
      stats: await sfuSocketStats(adminPage),
    },
    user: {
      remote: await remoteVideoCanvases(userPage),
      stats: await sfuSocketStats(userPage),
    },
  };
}

function assertStatsHealth(sample, baselineFailures) {
  for (const side of ['admin', 'user']) {
    const stats = sample[side].stats;
    if (stats.socketFailureCount > baselineFailures[side]) {
      throw new Error(`${side} SFU socket closed or errored after media flow started.`);
    }
    if (stats.maxBufferedAmountAfterSend > MAX_ACCEPTED_BUFFERED_BYTES) {
      throw new Error(`${side} SFU bufferedAmount exceeded profile budget: ${stats.maxBufferedAmountAfterSend}`);
    }
    if (stats.currentBufferedAmount > CRITICAL_BUFFERED_BYTES) {
      throw new Error(`${side} SFU current bufferedAmount reached critical pressure: ${stats.currentBufferedAmount}`);
    }
  }
}

function assertStableSamples(samples, baselineFailures) {
  if (samples.length < 3) throw new Error('Pressure acceptance did not collect enough samples.');
  for (const sample of samples) assertStatsHealth(sample, baselineFailures);

  for (const side of ['admin', 'user']) {
    const hashes = [];
    let healthySampleCount = 0;
    let transientBlackSamples = 0;
    let consecutiveBlackSamples = 0;
    let firstTransientBlackFrame = null;
    for (const sample of samples) {
      const canvas = firstHealthyCanvas(sample[side].remote);
      if (!canvas) {
        transientBlackSamples += 1;
        consecutiveBlackSamples += 1;
        firstTransientBlackFrame ||= {
          elapsedMs: sample.elapsedMs,
          phase: sample.phase,
          remote: sample[side].remote,
          stats: sample[side].stats,
          reason: 'transient_remote_black_frame',
        };
        if (
          transientBlackSamples > MAX_TRANSIENT_BLACK_SAMPLES
          || consecutiveBlackSamples > MAX_TRANSIENT_BLACK_SAMPLES
        ) {
          throw new Error(`${side} lost non-black remote video during ${sample.phase}: ${JSON.stringify({
            firstTransientBlackFrame,
            elapsedMs: sample.elapsedMs,
            remote: sample[side].remote,
            stats: sample[side].stats,
          })}`);
        }
        continue;
      }
      healthySampleCount += 1;
      consecutiveBlackSamples = 0;
      if (canvas.readable) hashes.push(String(canvas.hash));
    }
    const finalCanvas = firstHealthyCanvas(samples[samples.length - 1][side].remote);
    if (!finalCanvas) {
      throw new Error(`${side} final remote video stayed black or missing: ${JSON.stringify({
        firstTransientBlackFrame,
        finalSample: samples[samples.length - 1][side],
      })}`);
    }
    if (healthySampleCount < 2) throw new Error(`${side} remote video did not recover enough healthy samples.`);
    if (new Set(hashes).size < 2) throw new Error(`${side} remote video did not keep moving.`);

    const first = samples[0][side].stats;
    const last = samples[samples.length - 1][side].stats;
    if (last.binaryInCount <= first.binaryInCount + 5) throw new Error(`${side} SFU inbound binary count stopped.`);
    if (last.binaryOutCount <= first.binaryOutCount + 5) throw new Error(`${side} SFU outbound binary count stopped.`);
    if (last.currentBufferedAmount > MAX_FINAL_BUFFERED_BYTES) {
      throw new Error(`${side} SFU bufferedAmount did not drain at the end: ${last.currentBufferedAmount}`);
    }
  }
}

async function activeCallWorkspaceAssets(page) {
  return page.evaluate(() => performance.getEntriesByType('resource')
    .map((entry) => String(entry.name || ''))
    .filter((name) => /\/assets\/(?:CallWorkspaceView|session|index)-[^/]+\.js(?:[?#].*)?$/i.test(name))
    .map((name) => name.replace(/^.*\/assets\//, '/assets/')));
}

async function pageFailureSnapshot(page, sfuSocketStats) {
  if (!page || page.isClosed?.()) return null;
  const [remote, stats, assets, dom] = await Promise.all([
    remoteVideoCanvases(page).catch((error) => ({ error: String(error?.message || error || '') })),
    sfuSocketStats(page).catch((error) => ({ error: String(error?.message || error || '') })),
    activeCallWorkspaceAssets(page).catch(() => []),
    page.evaluate(() => ({
      url: location.href,
      remoteCanvasCount: document.querySelectorAll('#decoded-video-container canvas.remote-video, canvas.remote-video').length,
      remotePeerSlots: document.querySelectorAll('.workspace-grid-video-slot, [data-publisher-id], [data-user-id]').length,
      decodedContainerPresent: Boolean(document.querySelector('#decoded-video-container')),
      workspacePresent: Boolean(document.querySelector('.workspace-stage, .workspace-main-video')),
      bodyText: String(document.body?.innerText || '').slice(0, 800),
    })).catch((error) => ({ error: String(error?.message || error || '') })),
  ]);
  return { remote, stats, assets, dom };
}

async function collectFailureDebug({
  adminPage,
  userPage,
  adminMonitor,
  userMonitor,
  sfuSocketStats,
  sinceMs,
}) {
  const [admin, user] = await Promise.all([
    pageFailureSnapshot(adminPage, sfuSocketStats),
    pageFailureSnapshot(userPage, sfuSocketStats),
  ]);
  return {
    admin,
    user,
    runtimeFailures: runtimeFailures([...adminMonitor, ...userMonitor], sinceMs).slice(-20),
    recentRuntimeEvents: [...adminMonitor, ...userMonitor].slice(-40),
  };
}

async function main() {
  const origins = configureOnlineOrigins();
  const {
    adminCredentials,
    userCredentials,
    admitFirstLobbyUser,
    createAuthenticatedPage,
    createInvitedCallViaApi,
    createPersonalAccessJoinPath,
    enterOwnerWorkspaceCall,
    escapeRegExp,
    queueUserAdmission,
    sfuSocketStats,
  } = await import('./helpers/nativeAudioTransferHarness.js');

  const launchOptions = {};
  if (process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH) {
    launchOptions.executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;
  }

  const browser = await chromium.launch({ headless: true, ...launchOptions });
  const adminMonitor = [];
  const userMonitor = [];
  const gateStartedAt = Date.now();
  let adminContext = null;
  let userContext = null;
  let adminPage = null;
  let userPage = null;
  let clearSlowSubscriber = null;
  const summary = {
    baseURL: origins.baseURL,
    backendOrigin: origins.backendOrigin,
    wsOrigin: origins.wsOrigin,
    sfuOrigin: origins.sfuOrigin,
    callId: '',
    pressureDurationMs: PRESSURE_DURATION_MS,
    slowSubscriberDurationMs: SLOW_SUBSCRIBER_DURATION_MS,
    qualityTransitions: [],
    samples: [],
    assets: {},
  };
  latestSummary = summary;

  try {
    const admin = await createAuthenticatedPage(
      browser,
      origins.baseURL,
      credentialsFromEnv({ ...adminCredentials, role: 'admin' }),
      {
        audioFrequency: 440,
        videoWidth: HD_WIDTH,
        videoHeight: HD_HEIGHT,
        videoFrameRate: QUALITY_FRAME_RATE,
        highMotionVideo: true,
        outgoingVideoQualityProfile: 'quality',
      },
    );
    const user = await createAuthenticatedPage(
      browser,
      origins.baseURL,
      credentialsFromEnv({ ...userCredentials, role: 'user' }),
      {
        audioFrequency: 660,
        videoWidth: HD_WIDTH,
        videoHeight: HD_HEIGHT,
        videoFrameRate: QUALITY_FRAME_RATE,
        highMotionVideo: true,
        outgoingVideoQualityProfile: 'quality',
      },
    );
    adminContext = admin.context;
    userContext = user.context;
    adminPage = admin.page;
    userPage = user.page;
    adminMonitor.push(...installRuntimeMonitor(admin.page, 'admin'));
    userMonitor.push(...installRuntimeMonitor(user.page, 'user'));

    const participantUserId = user.storedSession.userId || 2;
    const callTitle = `Online SFU Pressure ${Date.now()}`;
    const callId = await createInvitedCallViaApi({
      sessionToken: admin.storedSession.sessionToken,
      title: callTitle,
      participantUserId,
    });
    summary.callId = callId;

    const userJoinPath = await createPersonalAccessJoinPath({
      callId,
      sessionToken: admin.storedSession.sessionToken,
      participantUserId,
    });

    await enterOwnerWorkspaceCall(admin.page, callId);
    await queueUserAdmission(user.page, userJoinPath);
    await admitFirstLobbyUser(admin.page);
    await user.page.waitForURL(new RegExp(`/workspace/call/${escapeRegExp(callId)}(?:[/?#].*)?$`), { timeout: 30_000 });
    await waitForRemoteVideo(admin.page, 'admin');
    await waitForRemoteVideo(user.page, 'user');
    await waitForSfuBinaryFlow(sfuSocketStats, admin.page, 'admin');
    await waitForSfuBinaryFlow(sfuSocketStats, user.page, 'user');
    await switchCallLayoutMode(admin.page, 'grid', 'admin');
    await assertNoManualVideoQualitySelect(admin.page, 'admin');
    await assertNoManualVideoQualitySelect(user.page, 'user');
    await waitForRemoteVideo(admin.page, 'admin grid');
    await waitForRemoteVideo(user.page, 'user grid');

    summary.assets.admin = await activeCallWorkspaceAssets(admin.page);
    summary.assets.user = await activeCallWorkspaceAssets(user.page);
    const stableStartedAt = Date.now();
    const baselineFailures = {
      admin: (await sfuSocketStats(admin.page)).socketFailureCount,
      user: (await sfuSocketStats(user.page)).socketFailureCount,
    };

    clearSlowSubscriber = await installSlowSubscriberNetwork(user.context, user.page);
    summary.qualityTransitions.push({ side: 'user', profile: 'slow-subscriber-network', atMs: Date.now() - stableStartedAt });
    const slowStartedAt = Date.now();
    while ((Date.now() - slowStartedAt) < SLOW_SUBSCRIBER_DURATION_MS) {
      await sleep(SAMPLE_INTERVAL_MS);
      const sample = await collectSample({
        adminPage: admin.page,
        userPage: user.page,
        sfuSocketStats,
        elapsedMs: Date.now() - stableStartedAt,
        phase: 'slow-subscriber',
      });
      assertStatsHealth(sample, baselineFailures);
      summary.samples.push(sample);
    }

    await clearSlowSubscriber();
    clearSlowSubscriber = null;
    summary.qualityTransitions.push(...assertAutomaticQualityTransitionsOrNoPressure(
      [...adminMonitor, ...userMonitor],
      stableStartedAt,
      summary.samples,
    ));

    while ((Date.now() - stableStartedAt) < PRESSURE_DURATION_MS) {
      await sleep(SAMPLE_INTERVAL_MS);
      const sample = await collectSample({
        adminPage: admin.page,
        userPage: user.page,
        sfuSocketStats,
        elapsedMs: Date.now() - stableStartedAt,
        phase: 'recovery',
      });
      assertStatsHealth(sample, baselineFailures);
      summary.samples.push(sample);
    }

    assertStableSamples(summary.samples, baselineFailures);
    const stableFailures = runtimeFailures([...adminMonitor, ...userMonitor], stableStartedAt);
    if (stableFailures.length > 0) {
      throw new Error(`Runtime failures during pressure window: ${JSON.stringify(stableFailures.slice(0, 8))}`);
    }

    console.log('[online-sfu-pressure] PASS');
    console.log(JSON.stringify({
      ...summary,
      sampleCount: summary.samples.length,
      finalSample: summary.samples[summary.samples.length - 1],
    }, null, 2));
  } catch (error) {
    summary.failureDebug = await collectFailureDebug({
      adminPage,
      userPage,
      adminMonitor,
      userMonitor,
      sfuSocketStats,
      sinceMs: gateStartedAt,
    }).catch((debugError) => ({ error: String(debugError?.message || debugError || '') }));
    throw error;
  } finally {
    await clearSlowSubscriber?.().catch(() => {});
    await Promise.allSettled([
      adminContext?.close(),
      userContext?.close(),
      browser.close(),
    ]);
  }
}

main().catch((error) => {
  console.error('[online-sfu-pressure] FAIL');
  console.error(error?.stack || error?.message || error);
  if (latestSummary) {
    console.error(JSON.stringify({
      ...latestSummary,
      sampleCount: latestSummary.samples.length,
      samples: latestSummary.samples.slice(-3),
      finalSample: latestSummary.samples[latestSummary.samples.length - 1] || null,
    }, null, 2));
  }
  process.exitCode = 1;
});
