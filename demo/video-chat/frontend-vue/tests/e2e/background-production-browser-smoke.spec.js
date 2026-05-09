import { test, expect } from '@playwright/test';

const REQUIRED_FLAG = 'VIDEOCHAT_PRODUCTION_BROWSER_SMOKE';
const SESSION_STORAGE_KEY = 'ii_videocall_v1_session';
const MEDIA_PREFS_KEY = 'ii.videocall.preview_prefs.v1';

const mediaLaunchArgs = [
  '--use-fake-device-for-media-stream',
  '--use-fake-ui-for-media-stream',
  '--autoplay-policy=no-user-gesture-required',
];
const chromiumExecutablePath = envValue('PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH');

test.use({
  permissions: ['camera', 'microphone'],
  launchOptions: {
    args: mediaLaunchArgs,
    ...(chromiumExecutablePath !== '' ? { executablePath: chromiumExecutablePath } : {}),
  },
});

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

function deployedCallUrl() {
  return envValue(
    'VIDEOCHAT_PRODUCTION_CALL_URL',
    'VIDEOCHAT_DEPLOY_CALL_URL',
    'VIDEOCHAT_ONLINE_CALL_URL',
    'BGF_PRODUCTION_CALL_URL',
  );
}

function credentialsFromEnv() {
  return {
    email: envValue('VIDEOCHAT_PRODUCTION_EMAIL', 'VIDEOCHAT_E2E_ADMIN_EMAIL', 'VIDEOCHAT_E2E_USER_EMAIL'),
    password: envValue(
      'VIDEOCHAT_PRODUCTION_PASSWORD',
      'VIDEOCHAT_E2E_ADMIN_PASSWORD',
      'VIDEOCHAT_E2E_USER_PASSWORD',
      'VIDEOCHAT_DEPLOY_ADMIN_PASSWORD',
      'VIDEOCHAT_DEPLOY_USER_PASSWORD',
    ),
  };
}

function configuredSessionJson() {
  return envValue('VIDEOCHAT_PRODUCTION_SESSION_JSON', 'VIDEOCHAT_DEPLOY_SESSION_JSON');
}

function callOrigin(callUrl) {
  return new URL(callUrl).origin;
}

async function installSmokeInstrumentation(context) {
  const sessionJson = configuredSessionJson();
  await context.addInitScript(({ mediaPrefsKey, sessionKey, sessionValue }) => {
    window.__bgfProdSmoke = {
      diagnostics: [],
      fetches: [],
      focusEvents: [],
      media: { enumerateDevices: [], getUserMedia: [] },
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
      background_apply_outgoing: true,
      background_backdrop_mode: 'image',
      background_filter_mode: 'replace',
      background_replacement_image_url: '/assets/orgas/kingrt/social/invitation-preview.png',
      video_id: previousPrefs.video_id || '',
      audio_id: previousPrefs.audio_id || '',
      outgoing_video_quality_profile: 'balanced',
      outgoing_video_quality_profile_version: 5,
    }));

    if (sessionValue) {
      localStorage.setItem(sessionKey, sessionValue);
    }

    const nativeFetch = window.fetch.bind(window);
    window.fetch = async (...args) => {
      const url = String(args[0]?.url || args[0] || '');
      const method = String(args[1]?.method || args[0]?.method || 'GET').toUpperCase();
      const body = String(args[1]?.body || args[0]?.body || '');
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
          if (protocols === undefined) {
            super(url);
          } else {
            super(url, protocols);
          }
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

    const wrapMediaDevices = () => {
      if (!navigator.mediaDevices || navigator.mediaDevices.__bgfProdSmokeWrapped) return true;
      const nativeGetUserMedia = navigator.mediaDevices.getUserMedia?.bind(navigator.mediaDevices);
      const nativeEnumerateDevices = navigator.mediaDevices.enumerateDevices?.bind(navigator.mediaDevices);
      if (typeof nativeGetUserMedia !== 'function' || typeof nativeEnumerateDevices !== 'function') return false;
      Object.defineProperty(navigator, 'mediaDevices', {
        configurable: true,
        value: {
          ...navigator.mediaDevices,
          __bgfProdSmokeWrapped: true,
          getUserMedia: async (constraints) => {
            window.__bgfProdSmoke.media.getUserMedia.push({ constraints, at: Date.now() });
            return nativeGetUserMedia(constraints);
          },
          enumerateDevices: async () => {
            const devices = await nativeEnumerateDevices();
            window.__bgfProdSmoke.media.enumerateDevices.push(devices.map((device) => ({
              kind: device.kind,
              label: device.label,
              deviceId: device.deviceId,
            })));
            return devices;
          },
        },
      });
      return true;
    };
    if (!wrapMediaDevices()) {
      nativeAddEventListener('DOMContentLoaded', wrapMediaDevices, { once: true });
    }
  }, {
    mediaPrefsKey: MEDIA_PREFS_KEY,
    sessionKey: SESSION_STORAGE_KEY,
    sessionValue: sessionJson,
  });
}

async function loginIfNeeded(page, callUrl) {
  const credentials = credentialsFromEnv();
  if (!credentials.email || !credentials.password) return;

  await page.goto(`${callOrigin(callUrl)}/login?redirect=${encodeURIComponent(new URL(callUrl).pathname + new URL(callUrl).search)}`);
  await expect(page.getByLabel('Email')).toBeVisible({ timeout: 20_000 });
  await page.getByLabel('Email').fill(credentials.email);
  await page.getByLabel('Password').fill(credentials.password);
  await page.getByRole('button', { name: /sign in/i }).click();
  await page.waitForFunction((key) => Boolean(localStorage.getItem(key)), SESSION_STORAGE_KEY, { timeout: 30_000 });
}

async function openCallPage(page, callUrl) {
  await page.goto(callUrl);
  await expect(page.locator('.workspace-call-view')).toBeVisible({ timeout: 45_000 });
  const joinDialog = page.getByRole('dialog', { name: /(?:enter|join) video call/i });
  if (await joinDialog.isVisible({ timeout: 8_000 }).catch(() => false)) {
    await joinDialog.getByRole('button', { name: /join call/i }).click();
  }
  await expect(page.locator('.workspace-main-video')).toBeVisible({ timeout: 45_000 });
}

async function forceBackgroundUnavailablePrompt(page) {
  await page.waitForFunction(() => {
    const setup = document.querySelector('.workspace-call-view')?.__vueParentComponent?.setupState;
    if (!setup?.callMediaPrefs) return false;
    setup.callMediaPrefs.backgroundReplacementUnavailablePromptOpen = true;
    setup.callMediaPrefs.backgroundReplacementUnavailableReason = 'production_browser_smoke_forced_unavailable';
    setup.callMediaPrefs.backgroundReplacementUnavailableFailures = ['production_browser_smoke_forced_unavailable'];
    return true;
  }, null, { timeout: 10_000 });
  await expect(page.getByRole('dialog', { name: 'Background replacement unavailable' })).toBeVisible({ timeout: 10_000 });
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

async function smokeSnapshot(page) {
  return page.evaluate(() => {
    const setup = document.querySelector('.workspace-call-view')?.__vueParentComponent?.setupState;
    const smoke = window.__bgfProdSmoke || {};
    const media = smoke.media || {};
    const sockets = Array.isArray(smoke.sockets) ? smoke.sockets : [];
    return {
      connectionState: String(setup?.connectionState || ''),
      connectionReason: String(setup?.connectionReason || ''),
      diagnostics: Array.isArray(smoke.diagnostics) ? smoke.diagnostics : [],
      focusEvents: Array.isArray(smoke.focusEvents) ? smoke.focusEvents : [],
      getUserMediaCalls: Array.isArray(media.getUserMedia) ? media.getUserMedia : [],
      enumerateDeviceSnapshots: Array.isArray(media.enumerateDevices) ? media.enumerateDevices : [],
      reconnectText: document.body.innerText.match(/reconnect\w*|diagnostic\w*/gi) || [],
      socketSummary: sockets.map((socket) => ({
        url: socket.url,
        opens: socket.opens,
        closes: socket.closes,
        errors: socket.errors,
        sent: socket.sent,
      })),
    };
  });
}

function reconnectDiagnostics(snapshot) {
  return snapshot.diagnostics.filter((entry) => /reconnect|backfill|sfu_video_reconnect|websocket_reconnect/i.test(
    `${entry.url}\n${entry.body}`,
  ));
}

test('production call page loads with fake browser media and keeps background fallback focus stable', async ({ context, page }) => {
  test.setTimeout(120_000);

  const callUrl = deployedCallUrl();
  test.skip(!isEnabled(process.env[REQUIRED_FLAG]), `${REQUIRED_FLAG}=1 is required for deployed production smoke.`);
  test.skip(callUrl === '', 'VIDEOCHAT_PRODUCTION_CALL_URL or VIDEOCHAT_DEPLOY_CALL_URL is required.');
  test.skip(configuredSessionJson() === '' && (!credentialsFromEnv().email || !credentialsFromEnv().password), 'Supply VIDEOCHAT_PRODUCTION_SESSION_JSON or production email/password env vars.');

  await context.grantPermissions(['camera', 'microphone'], { origin: callOrigin(callUrl) });
  await installSmokeInstrumentation(context);
  await loginIfNeeded(page, callUrl);
  await openCallPage(page, callUrl);

  await page.waitForFunction(() => {
    const calls = window.__bgfProdSmoke?.media?.getUserMedia || [];
    return calls.some((entry) => entry?.constraints?.audio !== false)
      && calls.some((entry) => entry?.constraints?.video !== false);
  }, null, { timeout: 30_000 });

  const mediaSnapshot = await smokeSnapshot(page);
  expect(mediaSnapshot.getUserMediaCalls.length).toBeGreaterThan(0);

  await forceBackgroundUnavailablePrompt(page);
  await expect(unavailableChoices(page)).resolves.toEqual([
    'Use standard avatar',
    'Upload avatar',
    'Send unfiltered video',
  ]);

  const beforeFocus = await smokeSnapshot(page);
  await page.evaluate(() => {
    window.dispatchEvent(new Event('blur'));
    window.dispatchEvent(new Event('focus'));
    window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
    document.dispatchEvent(new Event('visibilitychange'));
  });
  await page.bringToFront();
  await page.waitForTimeout(1_500);
  const afterFocus = await smokeSnapshot(page);

  expect(afterFocus.connectionState || beforeFocus.connectionState).not.toBe('retrying');
  expect(reconnectDiagnostics(afterFocus).slice(reconnectDiagnostics(beforeFocus).length)).toEqual([]);
  expect(afterFocus.reconnectText.filter((text) => !/unavailable/i.test(text))).toEqual([]);
});
