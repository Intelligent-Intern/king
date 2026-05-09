import { mkdir, writeFile } from 'node:fs/promises';
import { dirname } from 'node:path';

import { test, expect } from '@playwright/test';

import {
  adminCredentials as defaultAdminCredentials,
  userCredentials as defaultUserCredentials,
  admitFirstLobbyUser,
  createAuthenticatedPage,
  createInvitedCallViaApi,
  createPersonalAccessJoinPath,
  escapeRegExp,
  measureNativeAudioBridgeEnergy,
  nativeAudioBridgeSnapshot,
  queueUserAdmission,
  sfuRemoteVideoSnapshot,
  sfuSocketStats,
} from './helpers/nativeAudioTransferHarness.js';

const REQUIRED_FLAG = 'VIDEOCHAT_PRODUCTION_BROWSER_SMOKE';
const BACKGROUND_SMOKE_FLAG = 'bgf07-segmentation-unavailable';
const MEDIA_PREFS_KEY = 'ii.videocall.preview_prefs.v1';
const REQUIRED_BACKGROUND_EVENTS = [
  'local_background_backend_init',
  'local_background_matte_rejected',
  'local_background_replacement_unavailable',
  'local_background_replacement_modal_choice',
];

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

function productionBaseUrl(testInfo) {
  return String(
    testInfo.project.use.baseURL
      || process.env.PLAYWRIGHT_PRODUCTION_BASE_URL
      || process.env.VIDEOCHAT_ONLINE_BASE_URL
      || 'https://app.kingrt.com',
  ).replace(/\/+$/, '');
}

function serializeError(error) {
  if (!error) return null;
  return {
    message: error instanceof Error ? error.message : String(error),
    name: error instanceof Error ? error.name : 'Error',
    stack: error instanceof Error && typeof error.stack === 'string' ? error.stack : '',
  };
}

function browserProof(browser) {
  let browserName = '';
  let browserVersion = '';
  try {
    browserName = String(browser.browserType?.().name?.() || '');
  } catch {
    browserName = '';
  }
  try {
    browserVersion = String(browser.version?.() || '');
  } catch {
    browserVersion = '';
  }
  return {
    name: browserName,
    version: browserVersion,
  };
}

function projectProof(testInfo) {
  const use = testInfo.project.use || {};
  return {
    name: String(testInfo.project.name || ''),
    outputDir: String(testInfo.project.outputDir || ''),
    repeatEach: Number(testInfo.project.repeatEach || 0),
    retries: Number(testInfo.project.retries || 0),
    timeout: Number(testInfo.project.timeout || 0),
    use: {
      baseURL: String(use.baseURL || ''),
      browserName: String(use.browserName || ''),
      channel: String(use.channel || ''),
      headless: typeof use.headless === 'boolean' ? use.headless : null,
      viewport: use.viewport || null,
    },
  };
}

function credentialsFor(role) {
  const upperRole = String(role || '').trim().toUpperCase();
  const defaults = upperRole === 'ADMIN' ? defaultAdminCredentials : defaultUserCredentials;
  return {
    email: envValue(
      `VIDEOCHAT_PRODUCTION_${upperRole}_EMAIL`,
      `VIDEOCHAT_E2E_${upperRole}_EMAIL`,
    ) || defaults.email,
    password: envValue(
      `VIDEOCHAT_PRODUCTION_${upperRole}_PASSWORD`,
      `VIDEOCHAT_E2E_${upperRole}_PASSWORD`,
      upperRole === 'ADMIN' ? 'VIDEOCHAT_DEPLOY_ADMIN_PASSWORD' : 'VIDEOCHAT_DEPLOY_USER_PASSWORD',
    ) || defaults.password,
  };
}

function smokeQuery() {
  const query = new URLSearchParams();
  query.set('kingrt_background_smoke', BACKGROUND_SMOKE_FLAG);
  query.set('kingrt_background_force_segmentation_unavailable', '1');
  return query.toString();
}

async function installProductionSmokeHooks(context, { forceBackgroundUnavailable = false } = {}) {
  await context.addInitScript(({ mediaPrefsKey, backgroundSmokeFlag, forceBackground }) => {
    window.__bgfProdSmoke = {
      diagnostics: [],
      fetches: [],
      focusEvents: [],
      media: { enumerateDevices: [], getDisplayMedia: [], getUserMedia: [] },
      sockets: [],
    };

    const previousPrefsRaw = localStorage.getItem(mediaPrefsKey);
    let previousPrefs = {};
    try {
      previousPrefs = previousPrefsRaw ? JSON.parse(previousPrefsRaw) : {};
    } catch {
      previousPrefs = {};
    }
    localStorage.setItem(mediaPrefsKey, JSON.stringify({
      ...previousPrefs,
      audio_id: previousPrefs.audio_id || 'king-audio',
      background_apply_outgoing: true,
      background_backdrop_mode: 'image',
      background_filter_mode: 'replace',
      background_replacement_image_url: '/assets/orgas/kingrt/social/invitation-preview.png',
      video_id: previousPrefs.video_id || 'king-video',
      outgoing_video_quality_profile: 'balanced',
      outgoing_video_quality_profile_version: 5,
    }));

    if (forceBackground) {
      window.__kingrtExpectedBackgroundSmokeFlag = backgroundSmokeFlag;
    }

    const nativeFetch = window.fetch.bind(window);
    window.fetch = async (...args) => {
      const request = args[0];
      const init = args[1] || {};
      const url = String(request?.url || request || '');
      const method = String(init.method || request?.method || 'GET').toUpperCase();
      const body = typeof init.body === 'string' ? init.body : '';
      window.__bgfProdSmoke.fetches.push({ url, method, body, at: Date.now() });
      const response = await nativeFetch(...args);
      if (/\/api\/user\/client-diagnostics(?:[/?#]|$)/.test(url) && method === 'POST') {
        window.__bgfProdSmoke.diagnostics.push({
          url,
          status: response.status,
          body,
          at: Date.now(),
        });
      }
      return response;
    };

    const nativeAddEventListener = window.addEventListener.bind(window);
    for (const eventName of ['focus', 'blur', 'visibilitychange', 'pagehide', 'pageshow']) {
      nativeAddEventListener(eventName, () => {
        window.__bgfProdSmoke.focusEvents.push({
          type: eventName,
          visibilityState: document.visibilityState,
          at: Date.now(),
        });
      });
    }

    const NativeWebSocket = window.WebSocket;
    if (NativeWebSocket && !NativeWebSocket.__bgfProdSmokeWrapped) {
      class InstrumentedWebSocket extends NativeWebSocket {
        constructor(url, protocols) {
          if (protocols === undefined) super(url);
          else super(url, protocols);
          const entry = {
            url: String(url || ''),
            opens: 0,
            closes: [],
            errors: 0,
            sent: [],
            at: Date.now(),
          };
          window.__bgfProdSmoke.sockets.push(entry);
          this.addEventListener('open', () => { entry.opens += 1; });
          this.addEventListener('close', (event) => {
            entry.closes.push({ code: event.code, reason: event.reason, at: Date.now() });
          });
          this.addEventListener('error', () => { entry.errors += 1; });
          this.__bgfProdSmokeEntry = entry;
        }

        send(data) {
          const text = typeof data === 'string' ? data : '';
          this.__bgfProdSmokeEntry?.sent?.push(text.slice(0, 300));
          return super.send(data);
        }
      }
      for (const key of ['CONNECTING', 'OPEN', 'CLOSING', 'CLOSED']) {
        Object.defineProperty(InstrumentedWebSocket, key, { value: NativeWebSocket[key] });
      }
      InstrumentedWebSocket.__bgfProdSmokeWrapped = true;
      window.WebSocket = InstrumentedWebSocket;
    }

    function createDisplayMediaStream() {
      const canvas = document.createElement('canvas');
      canvas.width = 1280;
      canvas.height = 720;
      const ctx = canvas.getContext('2d');
      let frame = 0;
      const draw = () => {
        if (!ctx) return;
        ctx.fillStyle = frame % 2 === 0 ? '#0f172a' : '#155e75';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = '#22c55e';
        ctx.fillRect((frame * 31) % canvas.width, 0, 180, canvas.height);
        ctx.fillStyle = '#f8fafc';
        ctx.font = '44px sans-serif';
        ctx.fillText('KingRT BGF screen smoke', 72, 180);
        ctx.fillText(`frame ${frame}`, 72, 250);
        frame += 1;
      };
      draw();
      window.setInterval(draw, 100);
      const stream = canvas.captureStream?.(10) || new MediaStream();
      const track = stream.getVideoTracks?.()[0] || null;
      if (track) {
        try {
          Object.defineProperty(track, 'label', {
            configurable: true,
            get: () => 'KingRT BGF smoke screen',
          });
        } catch {
          // Browser labels are read-only in some engines; the stream itself is enough proof.
        }
      }
      return stream;
    }

    function wrapMediaDevices() {
      const current = navigator.mediaDevices || {};
      if (current.__bgfProdSmokeWrapped) return true;
      const nativeGetUserMedia = current.getUserMedia?.bind(current);
      const nativeEnumerateDevices = current.enumerateDevices?.bind(current);
      Object.defineProperty(navigator, 'mediaDevices', {
        configurable: true,
        value: {
          ...current,
          __bgfProdSmokeWrapped: true,
          getDisplayMedia: async (constraints = {}) => {
            window.__bgfProdSmoke.media.getDisplayMedia.push({ constraints, at: Date.now() });
            return createDisplayMediaStream();
          },
          getUserMedia: async (constraints = {}) => {
            window.__bgfProdSmoke.media.getUserMedia.push({ constraints, at: Date.now() });
            if (typeof nativeGetUserMedia !== 'function') return new MediaStream();
            return nativeGetUserMedia(constraints);
          },
          enumerateDevices: async () => {
            const devices = typeof nativeEnumerateDevices === 'function'
              ? await nativeEnumerateDevices()
              : [];
            window.__bgfProdSmoke.media.enumerateDevices.push(devices.map((device) => ({
              deviceId: device.deviceId,
              kind: device.kind,
              label: device.label,
            })));
            return devices;
          },
        },
      });
      return true;
    }

    if (!wrapMediaDevices()) {
      nativeAddEventListener('DOMContentLoaded', wrapMediaDevices, { once: true });
    }
  }, {
    backgroundSmokeFlag: BACKGROUND_SMOKE_FLAG,
    forceBackground: Boolean(forceBackgroundUnavailable),
    mediaPrefsKey: MEDIA_PREFS_KEY,
  });
}

async function openOwnerCallWithBackgroundSmoke(page, callId) {
  await page.goto(`/workspace/call/${encodeURIComponent(callId)}?${smokeQuery()}`);
  const joinDialog = page.getByRole('dialog', { name: /(?:enter|join) video call/i });
  if (await joinDialog.isVisible({ timeout: 15_000 }).catch(() => false)) {
    await joinDialog.getByRole('button', { name: /join call/i }).click();
  }
  await page.waitForURL(new RegExp(`/workspace/call/${escapeRegExp(callId)}(?:[/?#].*)?$`), { timeout: 30_000 });
  await expect(page.locator('.workspace-main-video')).toBeVisible({ timeout: 30_000 });
}

async function smokeSnapshot(page) {
  return page.evaluate(() => {
    const setup = document.querySelector('.workspace-call-view')?.__vueParentComponent?.setupState;
    const smoke = window.__bgfProdSmoke || {};
    const media = smoke.media || {};
    const sockets = Array.isArray(smoke.sockets) ? smoke.sockets : [];
    const stream = setup?.localStreamRef?.value instanceof MediaStream ? setup.localStreamRef.value : null;
    return {
      connectionState: String(setup?.connectionState || ''),
      connectionReason: String(setup?.connectionReason || ''),
      controlState: {
        cameraEnabled: Boolean(setup?.controlState?.cameraEnabled),
        micEnabled: Boolean(setup?.controlState?.micEnabled),
        screenEnabled: Boolean(setup?.controlState?.screenEnabled),
      },
      diagnostics: Array.isArray(smoke.diagnostics) ? smoke.diagnostics : [],
      focusEvents: Array.isArray(smoke.focusEvents) ? smoke.focusEvents : [],
      media: {
        enumerateDevices: Array.isArray(media.enumerateDevices) ? media.enumerateDevices : [],
        getDisplayMedia: Array.isArray(media.getDisplayMedia) ? media.getDisplayMedia : [],
        getUserMedia: Array.isArray(media.getUserMedia) ? media.getUserMedia : [],
      },
      socketSummary: sockets.map((socket) => ({
        closes: socket.closes,
        errors: socket.errors,
        opens: socket.opens,
        sent: socket.sent,
        url: socket.url,
      })),
      tracks: stream ? stream.getTracks().map((track) => ({
        enabled: Boolean(track.enabled),
        id: String(track.id || ''),
        kind: String(track.kind || ''),
        label: String(track.label || ''),
        readyState: String(track.readyState || ''),
      })) : [],
    };
  });
}

function diagnosticEntries(snapshot) {
  const entries = [];
  for (const request of snapshot.diagnostics || []) {
    let payload = null;
    try {
      payload = JSON.parse(request.body || '{}');
    } catch {
      payload = null;
    }
    const batch = Array.isArray(payload?.entries) ? payload.entries : [];
    for (const entry of batch) {
      entries.push(entry);
    }
  }
  return entries;
}

function diagnosticEventTypes(snapshot) {
  return diagnosticEntries(snapshot).map((entry) => String(entry?.event_type || entry?.eventType || ''));
}

function mediaHookCounts(snapshot) {
  const media = snapshot?.media || {};
  return {
    enumerateDevices: Array.isArray(media.enumerateDevices) ? media.enumerateDevices.length : 0,
    getDisplayMedia: Array.isArray(media.getDisplayMedia) ? media.getDisplayMedia.length : 0,
    getUserMedia: Array.isArray(media.getUserMedia) ? media.getUserMedia.length : 0,
  };
}

function socketSummaries(snapshot) {
  return (snapshot?.socketSummary || []).map((socket) => ({
    closeCount: Array.isArray(socket.closes) ? socket.closes.length : 0,
    closes: Array.isArray(socket.closes) ? socket.closes : [],
    errors: Number(socket.errors || 0),
    opens: Number(socket.opens || 0),
    sentCount: Array.isArray(socket.sent) ? socket.sent.length : 0,
    url: String(socket.url || ''),
  }));
}

async function captureRoleProof(role, page) {
  const proof = {
    captured: false,
    capturedAt: new Date().toISOString(),
    diagnosticsEventTypes: [],
    error: null,
    mediaHookCounts: {
      enumerateDevices: 0,
      getDisplayMedia: 0,
      getUserMedia: 0,
    },
    role,
    snapshot: null,
    socketSummaries: [],
  };
  if (!page) {
    proof.error = {
      message: `${role} page was not created.`,
      name: 'SnapshotUnavailableError',
      stack: '',
    };
    return proof;
  }
  try {
    const snapshot = await smokeSnapshot(page);
    proof.captured = true;
    proof.snapshot = snapshot;
    proof.diagnosticsEventTypes = diagnosticEventTypes(snapshot);
    proof.mediaHookCounts = mediaHookCounts(snapshot);
    proof.socketSummaries = socketSummaries(snapshot);
  } catch (error) {
    proof.error = serializeError(error);
  }
  return proof;
}

async function writeJsonArtifact(testInfo, fileName, payload) {
  const artifactPath = testInfo.outputPath(fileName);
  await mkdir(dirname(artifactPath), { recursive: true });
  await writeFile(artifactPath, `${JSON.stringify(payload, null, 2)}\n`, 'utf8');
  return artifactPath;
}

async function writeSmokeProofArtifacts({
  admin,
  baseURL,
  browser,
  callId,
  testError,
  testInfo,
  user,
}) {
  const [adminProof, userProof] = await Promise.all([
    captureRoleProof('admin', admin?.page),
    captureRoleProof('user', user?.page),
  ]);
  const proof = {
    baseURL,
    browser: browserProof(browser),
    callId,
    capturedAt: new Date().toISOString(),
    project: projectProof(testInfo),
    requiredBackgroundEvents: REQUIRED_BACKGROUND_EVENTS,
    roles: {
      admin: adminProof,
      user: userProof,
    },
    status: {
      expected: testInfo.expectedStatus,
      retry: testInfo.retry,
      testError: serializeError(testError),
      workerIndex: testInfo.workerIndex,
    },
    summaries: {
      admin: {
        diagnosticsEventTypes: adminProof.diagnosticsEventTypes,
        mediaHookCounts: adminProof.mediaHookCounts,
        socketSummaries: adminProof.socketSummaries,
      },
      user: {
        diagnosticsEventTypes: userProof.diagnosticsEventTypes,
        mediaHookCounts: userProof.mediaHookCounts,
        socketSummaries: userProof.socketSummaries,
      },
    },
    test: {
      file: testInfo.file,
      line: testInfo.line,
      title: testInfo.title,
    },
  };

  const proofPath = await writeJsonArtifact(testInfo, 'bgf-production-browser-smoke-proof.json', proof);
  await Promise.all([
    writeJsonArtifact(testInfo, 'bgf-production-admin-final-smoke-snapshot.json', adminProof),
    writeJsonArtifact(testInfo, 'bgf-production-user-final-smoke-snapshot.json', userProof),
  ]);
  return proofPath;
}

function reconnectDiagnostics(snapshot) {
  return diagnosticEntries(snapshot).filter((entry) => {
    const text = JSON.stringify(entry || {});
    return /reconnect|backfill|sfu_video_reconnect|websocket_reconnect/i.test(text);
  });
}

function socketFailureCount(snapshot) {
  return (snapshot.socketSummary || []).reduce((total, socket) => (
    total + Number(socket.errors || 0) + (Array.isArray(socket.closes) ? socket.closes.length : 0)
  ), 0);
}

async function expectDiagnostics(page, requiredEvents) {
  await expect.poll(async () => {
    const seen = new Set(diagnosticEventTypes(await smokeSnapshot(page)));
    return requiredEvents.filter((eventType) => seen.has(eventType));
  }, {
    intervals: [1_000, 2_000, 3_000],
    timeout: 20_000,
  }).toEqual(requiredEvents);
}

async function unavailableChoices(page) {
  return page.getByRole('dialog', { name: 'Background replacement unavailable' }).evaluate((dialog) => {
    const visibleText = (node) => {
      const style = window.getComputedStyle(node);
      if (style.display === 'none' || style.visibility === 'hidden' || Number(style.opacity) === 0) return '';
      return String(node.textContent || '').replace(/\s+/g, ' ').trim();
    };
    return Array.from(dialog.querySelectorAll('button, label.background-unavailable-upload'))
      .map(visibleText)
      .filter(Boolean);
  });
}

async function waitForRemoteVideo(page) {
  await expect.poll(async () => {
    const canvases = await sfuRemoteVideoSnapshot(page);
    return canvases.filter((canvas) => canvas.rendered && canvas.width > 0 && canvas.height > 0).length;
  }, {
    timeout: 90_000,
  }).toBeGreaterThan(0);
}

async function waitForSfuFlow(page) {
  await expect.poll(async () => {
    const stats = await sfuSocketStats(page);
    return Math.min(stats.binaryInCount, stats.binaryOutCount);
  }, {
    timeout: 60_000,
  }).toBeGreaterThan(5);
}

test('deployed browser call proves BGF fallback, media, screenshare, and focus stability', async ({ browser }, testInfo) => {
  test.setTimeout(240_000);
  test.skip(!isEnabled(process.env[REQUIRED_FLAG]), `${REQUIRED_FLAG}=1 is required for deployed production smoke.`);

  const adminCredentials = credentialsFor('admin');
  const userCredentials = credentialsFor('user');
  if (!adminCredentials.email || !adminCredentials.password || !userCredentials.email || !userCredentials.password) {
    throw new Error('Production browser smoke requires admin and user credentials in VIDEOCHAT_PRODUCTION_* or VIDEOCHAT_E2E_* env vars.');
  }

  const baseURL = productionBaseUrl(testInfo);
  let admin = null;
  let user = null;
  let callId = '';
  let testError = null;
  try {
    admin = await createAuthenticatedPage(browser, baseURL, adminCredentials, {
      audioFrequency: 440,
      outgoingVideoQualityProfile: 'balanced',
      videoFrameRate: 12,
      videoHeight: 360,
      videoWidth: 640,
    });
    user = await createAuthenticatedPage(browser, baseURL, userCredentials, {
      audioFrequency: 660,
      outgoingVideoQualityProfile: 'balanced',
      videoFrameRate: 12,
      videoHeight: 360,
      videoWidth: 640,
    });

    await installProductionSmokeHooks(admin.context, { forceBackgroundUnavailable: true });
    await installProductionSmokeHooks(user.context);

    const participantUserId = user.storedSession.userId || 2;
    callId = await createInvitedCallViaApi({
      sessionToken: admin.storedSession.sessionToken,
      title: `BGF production browser smoke ${Date.now()}`,
      participantUserId,
    });
    const userJoinPath = await createPersonalAccessJoinPath({
      callId,
      sessionToken: admin.storedSession.sessionToken,
      participantUserId,
    });

    await openOwnerCallWithBackgroundSmoke(admin.page, callId);
    await queueUserAdmission(user.page, userJoinPath);
    await admitFirstLobbyUser(admin.page);
    await user.page.waitForURL(new RegExp(`/workspace/call/${escapeRegExp(callId)}(?:[/?#].*)?$`), { timeout: 30_000 });
    await expect(user.page.locator('.workspace-main-video')).toBeVisible({ timeout: 30_000 });

    await Promise.all([
      waitForRemoteVideo(admin.page),
      waitForRemoteVideo(user.page),
      waitForSfuFlow(admin.page),
      waitForSfuFlow(user.page),
    ]);
    await Promise.all([
      expect.poll(() => nativeAudioBridgeSnapshot(admin.page), { timeout: 60_000 }).toMatchObject({ hasLiveTrack: true }),
      expect.poll(() => nativeAudioBridgeSnapshot(user.page), { timeout: 60_000 }).toMatchObject({ hasLiveTrack: true }),
    ]);
    await Promise.all([
      expect.poll(async () => (await measureNativeAudioBridgeEnergy(admin.page)).maxRms, { timeout: 45_000 }).toBeGreaterThan(0.003),
      expect.poll(async () => (await measureNativeAudioBridgeEnergy(user.page)).maxRms, { timeout: 45_000 }).toBeGreaterThan(0.003),
    ]);
    await admin.page.screenshot({ fullPage: true, path: testInfo.outputPath('bgf-production-call-active.png') });

    const backgroundDialog = admin.page.getByRole('dialog', { name: 'Background replacement unavailable' });
    await expect(backgroundDialog).toBeVisible({ timeout: 30_000 });
    await expect(unavailableChoices(admin.page)).resolves.toEqual([
      'Use standard avatar',
      'Upload avatar',
      'Send unfiltered video',
    ]);
    await admin.page.screenshot({ fullPage: true, path: testInfo.outputPath('bgf-production-background-unavailable.png') });
    await backgroundDialog.getByRole('button', { name: 'Send unfiltered video' }).click();
    await expect(backgroundDialog).toBeHidden({ timeout: 15_000 });
    await expectDiagnostics(admin.page, REQUIRED_BACKGROUND_EVENTS);

    await admin.page.getByRole('button', { name: 'Share screen' }).click();
    await expect.poll(async () => (await smokeSnapshot(admin.page)).controlState.screenEnabled, {
      timeout: 30_000,
    }).toBe(true);
    await expect.poll(async () => (await smokeSnapshot(admin.page)).media.getDisplayMedia.length, {
      timeout: 15_000,
    }).toBeGreaterThan(0);
    await expectDiagnostics(admin.page, ['local_screen_share_started']);
    await admin.page.screenshot({ fullPage: true, path: testInfo.outputPath('bgf-production-screenshare-active.png') });

    await admin.page.getByRole('button', { name: 'Share screen' }).click();
    await expect.poll(async () => (await smokeSnapshot(admin.page)).controlState.screenEnabled, {
      timeout: 45_000,
    }).toBe(false);
    await expectDiagnostics(admin.page, ['local_screen_share_stopped']);
    const restoredMedia = await smokeSnapshot(admin.page);
    expect(restoredMedia.tracks.some((track) => track.kind === 'audio' && track.readyState === 'live')).toBe(true);
    expect(restoredMedia.tracks.some((track) => track.kind === 'video' && track.readyState === 'live')).toBe(true);

    const beforeFocus = await smokeSnapshot(admin.page);
    await admin.page.locator('.workspace-main-video').click({ position: { x: 20, y: 20 } });
    await admin.page.evaluate(() => {
      window.dispatchEvent(new Event('blur'));
      window.dispatchEvent(new Event('focus'));
      window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
      document.dispatchEvent(new Event('visibilitychange'));
    });
    await admin.page.bringToFront();
    await admin.page.waitForTimeout(1_500);
    const afterFocus = await smokeSnapshot(admin.page);
    expect(afterFocus.connectionState).toBe('online');
    expect(socketFailureCount(afterFocus)).toBe(socketFailureCount(beforeFocus));
    expect(reconnectDiagnostics(afterFocus).slice(reconnectDiagnostics(beforeFocus).length)).toEqual([]);

    console.log('[background-production-browser-smoke] PASS');
    console.log(JSON.stringify({
      baseURL,
      browserName: testInfo.project.name,
      browserVersion: browser.version(),
      callId,
      backgroundEvents: diagnosticEventTypes(await smokeSnapshot(admin.page))
        .filter((eventType) => REQUIRED_BACKGROUND_EVENTS.includes(eventType)),
    }, null, 2));
  } catch (error) {
    testError = error;
    throw error;
  } finally {
    const artifactError = await writeSmokeProofArtifacts({
      admin,
      baseURL,
      browser,
      callId,
      testError,
      testInfo,
      user,
    }).catch((error) => error);
    if (artifactError) {
      console.warn('[background-production-browser-smoke] failed to write JSON proof artifacts', serializeError(artifactError));
    }
    await Promise.allSettled([
      admin?.context?.close?.(),
      user?.context?.close?.(),
    ]);
    if (artifactError && !testError) throw artifactError;
  }
});
