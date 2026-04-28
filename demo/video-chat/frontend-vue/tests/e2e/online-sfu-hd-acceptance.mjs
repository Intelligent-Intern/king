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
const HD_FRAME_RATE = 30;
const STABLE_DURATION_MS = Math.max(10_000, Number.parseInt(process.env.VIDEOCHAT_ONLINE_HD_DURATION_MS || '60000', 10));
const SAMPLE_INTERVAL_MS = Math.max(1_000, Number.parseInt(process.env.VIDEOCHAT_ONLINE_HD_SAMPLE_INTERVAL_MS || '4700', 10));

const BLOCKED_RUNTIME_PATTERNS = [
  /\bwrong_key_id\b/i,
  /\bmalformed_protected_frame\b/i,
  /\bsfu_protected_frame_decrypt_failed\b/i,
  /\bSFU video backpressure\b/i,
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
  if (trimmed.length >= 2 && trimmed.startsWith("'") && trimmed.endsWith("'")) {
    return trimmed.slice(1, -1);
  }
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
    if (process.env[key] === undefined) {
      process.env[key] = unquoteEnvValue(value);
    }
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
    events.push({
      label,
      type,
      text: String(text || ''),
      at: Date.now(),
    });
  };

  page.on('console', (message) => push(`console:${message.type()}`, message.text()));
  page.on('pageerror', (error) => push('pageerror', error?.stack || error?.message || error));
  page.on('requestfailed', (request) => {
    const url = request.url();
    if (!/\/(?:api\/runtime|sfu|ws)(?:[/?#]|$)/i.test(url)) return;
    push('requestfailed', `${request.method()} ${url} ${request.failure()?.errorText || ''}`);
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
    const hashCanvas = (canvas) => {
      try {
        const width = Number(canvas.width || 0);
        const height = Number(canvas.height || 0);
        if (width <= 0 || height <= 0) return { hash: 0, readable: false };
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        if (!ctx) return { hash: 0, readable: false };
        let hash = 2166136261;
        for (let y = 1; y <= 5; y += 1) {
          for (let x = 1; x <= 5; x += 1) {
            const px = Math.max(0, Math.min(width - 1, Math.floor((width * x) / 6)));
            const py = Math.max(0, Math.min(height - 1, Math.floor((height * y) / 6)));
            const data = ctx.getImageData(px, py, 1, 1).data;
            for (const byte of data) {
              hash ^= byte;
              hash = Math.imul(hash, 16777619) >>> 0;
            }
          }
        }
        return { hash, readable: true };
      } catch (error) {
        return { hash: 0, readable: false, error: String(error?.message || error || '') };
      }
    };

    return Array.from(document.querySelectorAll('#decoded-video-container canvas.remote-video, canvas.remote-video'))
      .map((canvas) => {
        const hash = hashCanvas(canvas);
        return {
          width: Number(canvas.width || 0),
          height: Number(canvas.height || 0),
          publisherId: String(canvas.dataset.publisherId || ''),
          userId: String(canvas.dataset.userId || ''),
          rendered: canvas.width > 0 && canvas.height > 0 && canvas.isConnected,
          hash: hash.hash,
          readable: hash.readable,
          hashError: hash.error || '',
        };
      });
  });
}

function hasHdRemoteCanvas(snapshot) {
  return Array.isArray(snapshot)
    && snapshot.some((canvas) => canvas.rendered && canvas.width >= HD_WIDTH && canvas.height >= HD_HEIGHT);
}

function firstHdCanvas(snapshot) {
  return (Array.isArray(snapshot) ? snapshot : []).find((canvas) => (
    canvas.rendered && canvas.width >= HD_WIDTH && canvas.height >= HD_HEIGHT
  )) || null;
}

async function waitForHdRemote(page, label) {
  return waitUntil(`${label} HD remote video`, 90_000, async () => {
    const snapshot = await remoteVideoCanvases(page);
    return hasHdRemoteCanvas(snapshot) ? snapshot : null;
  });
}

async function waitForSfuBinaryFlow(sfuSocketStats, page, label) {
  return waitUntil(`${label} SFU binary flow`, 60_000, async () => {
    const stats = await sfuSocketStats(page);
    return stats.binaryInCount > 5 && stats.binaryOutCount > 5 ? stats : null;
  });
}

function assertStableSamples(samples) {
  for (const side of ['admin', 'user']) {
    const hashes = [];
    for (const sample of samples) {
      const canvas = firstHdCanvas(sample[side].remote);
      if (!canvas) {
        throw new Error(`${side} lost the HD remote canvas during the stable window.`);
      }
      if (canvas.readable) hashes.push(String(canvas.hash));
    }
    if (new Set(hashes).size < 2) {
      throw new Error(`${side} remote canvas did not show frame motion during the stable window.`);
    }

    const first = samples[0][side].stats;
    const last = samples[samples.length - 1][side].stats;
    if (last.binaryInCount <= first.binaryInCount + 5) {
      throw new Error(`${side} SFU inbound binary count did not keep increasing.`);
    }
    if (last.binaryOutCount <= first.binaryOutCount + 5) {
      throw new Error(`${side} SFU outbound binary count did not keep increasing.`);
    }
  }
}

async function activeCallWorkspaceAssets(page) {
  return page.evaluate(() => performance.getEntriesByType('resource')
    .map((entry) => String(entry.name || ''))
    .filter((name) => /\/assets\/(?:CallWorkspaceView|session|index)-[^/]+\.js(?:[?#].*)?$/i.test(name))
    .map((name) => name.replace(/^.*\/assets\//, '/assets/')));
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

  const browser = await chromium.launch({
    headless: true,
    ...launchOptions,
  });

  const adminMonitor = [];
  const userMonitor = [];
  let adminContext = null;
  let userContext = null;
  const summary = {
    baseURL: origins.baseURL,
    backendOrigin: origins.backendOrigin,
    wsOrigin: origins.wsOrigin,
    sfuOrigin: origins.sfuOrigin,
    callId: '',
    stableDurationMs: STABLE_DURATION_MS,
    samples: [],
    assets: {},
    preStableRuntimeEvents: [],
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
        videoFrameRate: HD_FRAME_RATE,
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
        videoFrameRate: HD_FRAME_RATE,
        outgoingVideoQualityProfile: 'quality',
      },
    );
    adminContext = admin.context;
    userContext = user.context;
    adminMonitor.push(...installRuntimeMonitor(admin.page, 'admin'));
    userMonitor.push(...installRuntimeMonitor(user.page, 'user'));

    const participantUserId = user.storedSession.userId || 2;
    const callTitle = `Online SFU HD ${Date.now()}`;
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
    await waitForHdRemote(admin.page, 'admin');
    await waitForHdRemote(user.page, 'user');
    await waitForSfuBinaryFlow(sfuSocketStats, admin.page, 'admin');
    await waitForSfuBinaryFlow(sfuSocketStats, user.page, 'user');

    summary.assets.admin = await activeCallWorkspaceAssets(admin.page);
    summary.assets.user = await activeCallWorkspaceAssets(user.page);
    const stableStartedAt = Date.now();
    summary.preStableRuntimeEvents = [...adminMonitor, ...userMonitor]
      .filter((event) => BLOCKED_RUNTIME_PATTERNS.some((pattern) => pattern.test(event.text)))
      .map((event) => ({ label: event.label, type: event.type, text: event.text.slice(0, 240) }));

    while ((Date.now() - stableStartedAt) < STABLE_DURATION_MS) {
      await sleep(SAMPLE_INTERVAL_MS);
      summary.samples.push({
        elapsedMs: Date.now() - stableStartedAt,
        admin: {
          remote: await remoteVideoCanvases(admin.page),
          stats: await sfuSocketStats(admin.page),
        },
        user: {
          remote: await remoteVideoCanvases(user.page),
          stats: await sfuSocketStats(user.page),
        },
      });
    }

    assertStableSamples(summary.samples);
    const stableFailures = runtimeFailures([...adminMonitor, ...userMonitor], stableStartedAt);
    if (stableFailures.length > 0) {
      throw new Error(`Runtime failures during stable HD window: ${JSON.stringify(stableFailures.slice(0, 8))}`);
    }

    console.log('[online-sfu-hd] PASS');
    console.log(JSON.stringify({
      ...summary,
      sampleCount: summary.samples.length,
      finalSample: summary.samples[summary.samples.length - 1],
    }, null, 2));
  } finally {
    await Promise.allSettled([
      adminContext?.close(),
      userContext?.close(),
      browser.close(),
    ]);
  }
}

main().catch((error) => {
  console.error('[online-sfu-hd] FAIL');
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
