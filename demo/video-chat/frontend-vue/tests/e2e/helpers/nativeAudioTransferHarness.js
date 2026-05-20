import { expect } from '@playwright/test';

function defaultBackendOrigin() {
  if (/^(?:1|true|yes|on)$/i.test(String(process.env.PLAYWRIGHT_PRODUCTION_BROWSER_SMOKE || process.env.VIDEOCHAT_PRODUCTION_BROWSER_SMOKE || ''))) {
    return 'https://api.kingrt.com';
  }
  return 'http://127.0.0.1:18080';
}

export const backendOrigin = process.env.VITE_VIDEOCHAT_BACKEND_ORIGIN || process.env.VIDEOCHAT_BACKEND_ORIGIN || defaultBackendOrigin();
const sessionStorageKey = 'ii_videocall_v1_session';

export const adminCredentials = Object.freeze({
  email: 'admin@intelligent-intern.com',
  password: 'admin123',
});

export const userCredentials = Object.freeze({
  email: 'user@intelligent-intern.com',
  password: 'user123',
});

export function escapeRegExp(value) {
  return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function buildStoredSession(payload) {
  const session = payload?.session || {};
  const user = payload?.user || {};
  return {
    role: String(user.role || '').trim(),
    displayName: String(user.display_name || '').trim(),
    email: String(user.email || '').trim(),
    userId: Number.isInteger(user.id) ? user.id : 0,
    avatarPath: typeof user.avatar_path === 'string' && user.avatar_path.trim() !== '' ? user.avatar_path.trim() : null,
    timeFormat: typeof user.time_format === 'string' && user.time_format.trim() !== '' ? user.time_format.trim() : '24h',
    theme: typeof user.theme === 'string' && user.theme.trim() !== '' ? user.theme.trim() : 'dark',
    status: typeof user.status === 'string' ? user.status.trim() : '',
    sessionId: String(session.id || session.token || '').trim(),
    sessionToken: String(session.token || session.id || '').trim(),
    expiresAt: typeof session.expires_at === 'string' ? session.expires_at.trim() : '',
  };
}

async function fetchStoredSession(email, password) {
  let lastError = new Error('Login failed.');
  for (let attempt = 0; attempt < 4; attempt += 1) {
    try {
      const response = await fetch(`${backendOrigin}/api/auth/login`, {
        method: 'POST',
        headers: { accept: 'application/json', 'content-type': 'application/json' },
        body: JSON.stringify({ email, password }),
      });
      const payload = await response.json().catch(() => null);
      if (response.ok && payload?.status === 'ok') return buildStoredSession(payload);
      lastError = new Error(payload?.error?.message || `Login failed (${response.status}).`);
    } catch (error) {
      lastError = new Error(error instanceof Error ? error.message : 'Login request failed.');
    }
    await new Promise((resolve) => setTimeout(resolve, 800 * (attempt + 1)));
  }
  throw lastError;
}

export async function installSocketInstrumentation(context) {
  await context.addInitScript(() => {
    const NativeWebSocket = window.WebSocket;
    if (!NativeWebSocket || NativeWebSocket.__kingNativeAudioInstrumented) return;

    const events = [];
    window.__kingNativeAudioSocketEvents = events;
    window.__kingNativeAudioSockets = [];

    const snapshotFrame = (data) => {
      if (typeof data === 'string') {
        try {
          return JSON.parse(data);
        } catch {
          return { type: '__text__' };
        }
      }
      if (data instanceof ArrayBuffer) return { type: '__binary__', bytes: data.byteLength };
      if (ArrayBuffer.isView(data)) return { type: '__binary__', bytes: data.byteLength || data.length || 0 };
      if (typeof Blob !== 'undefined' && data instanceof Blob) return { type: '__blob__', bytes: data.size };
      return { type: '__unknown__' };
    };

    class InstrumentedWebSocket extends NativeWebSocket {
      constructor(url, protocols) {
        if (protocols === undefined) {
          super(url);
        } else {
          super(url, protocols);
        }
        this.__kingNativeAudioUrl = String(url || '');
        window.__kingNativeAudioSockets.push(this);
        this.addEventListener('message', (event) => {
          events.push({ direction: 'in', url: this.__kingNativeAudioUrl, frame: snapshotFrame(event.data), at: Date.now() });
        });
        this.addEventListener('close', (event) => {
          events.push({
            direction: 'state',
            url: this.__kingNativeAudioUrl,
            frame: { type: '__socket_close__', code: Number(event.code || 0), reason: String(event.reason || '') },
            at: Date.now(),
          });
        });
        this.addEventListener('error', () => {
          events.push({
            direction: 'state',
            url: this.__kingNativeAudioUrl,
            frame: { type: '__socket_error__' },
            at: Date.now(),
          });
        });
      }

      send(data) {
        const frame = snapshotFrame(data);
        const bufferedAmountBefore = Number(this.bufferedAmount || 0);
        const result = super.send(data);
        events.push({
          direction: 'out',
          url: this.__kingNativeAudioUrl || this.url,
          frame,
          bufferedAmountBefore,
          bufferedAmountAfter: Number(this.bufferedAmount || 0),
          at: Date.now(),
        });
        return result;
      }
    }

    for (const key of ['CONNECTING', 'OPEN', 'CLOSING', 'CLOSED']) {
      Object.defineProperty(InstrumentedWebSocket, key, { value: NativeWebSocket[key], enumerable: true });
    }
    InstrumentedWebSocket.__kingNativeAudioInstrumented = true;
    window.WebSocket = InstrumentedWebSocket;
  });
}

export async function installMediaDeviceShim(context, {
  audioFrequency = 440,
  audioPulse = false,
  audioPulseIntervalMs = 2500,
  videoWidth = 320,
  videoHeight = 240,
  videoFrameRate = 12,
  highMotionVideo = false,
  deterministicVideoPattern = false,
  videoPatternLabel = 'KINGRT LIVE PROOF',
} = {}) {
  await context.addInitScript(({
    frequency,
    pulseAudio,
    pulseIntervalMs,
    width,
    height,
    frameRate,
    highMotion,
    deterministicPattern,
    patternLabel,
  }) => {
    const resources = [];
    window.__kingNativeAudioMediaResources = resources;

    const coerceConstraintNumber = (constraint, fallback) => {
      if (typeof constraint === 'number' && Number.isFinite(constraint)) return constraint;
      if (constraint && typeof constraint === 'object') {
        for (const key of ['exact', 'ideal', 'max', 'min']) {
          const value = Number(constraint[key]);
          if (Number.isFinite(value) && value > 0) return value;
        }
      }
      return fallback;
    };

    const resolveVideoSettings = (constraints) => {
      const video = constraints && typeof constraints === 'object' ? constraints : {};
      const nextWidth = Math.max(64, Math.round(coerceConstraintNumber(video.width, width)));
      const nextHeight = Math.max(64, Math.round(coerceConstraintNumber(video.height, height)));
      const nextFrameRate = Math.max(1, Math.round(coerceConstraintNumber(video.frameRate, frameRate)));
      return { width: nextWidth, height: nextHeight, frameRate: nextFrameRate };
    };

    const createVideoTrack = (constraints = {}) => {
      const settings = resolveVideoSettings(constraints);
      const canvas = document.createElement('canvas');
      canvas.width = settings.width;
      canvas.height = settings.height;
      const ctx = canvas.getContext('2d');
      let frame = 0;
      const drawDeterministicPattern = () => {
        const palette = ['#ff0054', '#00f5d4', '#ffe66d', '#1f2937', '#7c3aed'];
        const bandCount = Math.max(4, Math.min(9, Math.floor(canvas.width / Math.max(80, canvas.height / 4))));
        const bandWidth = Math.ceil(canvas.width / bandCount);
        for (let index = 0; index < bandCount; index += 1) {
          ctx.fillStyle = palette[(index + frame) % palette.length];
          ctx.fillRect(index * bandWidth, 0, bandWidth + 1, canvas.height);
        }

        const marker = Math.max(18, Math.round(Math.min(canvas.width, canvas.height) * 0.12));
        const pulse = (frame % 30) / 29;
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, marker, marker);
        ctx.fillRect(canvas.width - marker, canvas.height - marker, marker, marker);
        ctx.fillStyle = '#111827';
        ctx.fillRect(canvas.width - marker, 0, marker, marker);
        ctx.fillRect(0, canvas.height - marker, marker, marker);
        ctx.fillStyle = '#22c55e';
        ctx.fillRect(
          Math.round((canvas.width - marker) * pulse),
          Math.round(canvas.height * 0.42),
          marker,
          Math.max(8, Math.round(marker * 0.28)),
        );
        ctx.fillStyle = 'rgba(17, 24, 39, 0.84)';
        ctx.fillRect(0, Math.round(canvas.height * 0.62), canvas.width, Math.max(42, Math.round(canvas.height * 0.18)));
        ctx.fillStyle = '#f8fafc';
        ctx.font = `${Math.max(18, Math.round(canvas.height / 11))}px sans-serif`;
        ctx.fillText(patternLabel, Math.max(16, Math.round(canvas.width / 24)), Math.round(canvas.height * 0.72));
        ctx.font = `${Math.max(14, Math.round(canvas.height / 18))}px sans-serif`;
        ctx.fillText(`frame ${frame} ${settings.width}x${settings.height}@${settings.frameRate}`, Math.max(16, Math.round(canvas.width / 24)), Math.round(canvas.height * 0.82));
      };
      const draw = () => {
        if (!ctx) return;
        if (deterministicPattern) {
          drawDeterministicPattern();
        } else if (highMotion) {
          const cell = Math.max(12, Math.floor(Math.min(canvas.width, canvas.height) / 9));
          const offset = (frame * 17) % cell;
          for (let y = -cell; y < canvas.height + cell; y += cell) {
            for (let x = -cell; x < canvas.width + cell; x += cell) {
              const toneSeed = Math.floor((x + offset) / cell) + Math.floor((y - offset) / cell) + frame;
              const tone = ((toneSeed % 6) + 6) % 6;
              ctx.fillStyle = ['#0f172a', '#0e7490', '#22c55e', '#f59e0b', '#ef4444', '#f8fafc'][tone];
              ctx.fillRect(x + offset, y - offset, cell, cell);
            }
          }
          ctx.fillStyle = '#ffffff';
          ctx.globalAlpha = 0.82;
          ctx.fillRect((frame * 31) % (canvas.width + cell) - cell, 0, Math.max(cell * 2, canvas.width / 8), canvas.height);
          ctx.fillStyle = '#111827';
          ctx.globalAlpha = 0.7;
          ctx.fillRect(0, (frame * 23) % (canvas.height + cell) - cell, canvas.width, Math.max(cell, canvas.height / 10));
          ctx.globalAlpha = 1;
        } else {
          ctx.fillStyle = frame % 2 === 0 ? '#123c55' : '#1a5f72';
          ctx.fillRect(0, 0, canvas.width, canvas.height);
        }
        ctx.fillStyle = '#f8fafc';
        ctx.font = `${Math.max(14, Math.round(canvas.height / 16))}px sans-serif`;
        ctx.fillText(`audio ${frequency}Hz`, Math.max(12, Math.round(canvas.width / 24)), Math.max(32, Math.round(canvas.height * 0.48)));
        ctx.fillText(`frame ${frame}`, Math.max(12, Math.round(canvas.width / 24)), Math.max(54, Math.round(canvas.height * 0.6)));
        frame += 1;
      };
      draw();
      const intervalId = window.setInterval(draw, Math.max(16, Math.round(1000 / Math.max(1, settings.frameRate))));
      const stream = typeof canvas.captureStream === 'function' ? canvas.captureStream(settings.frameRate) : null;
      resources.push({
        canvas,
        deterministicPattern,
        intervalId,
        patternLabel,
        settings,
        stream,
      });
      return stream?.getVideoTracks?.()[0] || null;
    };

    const createAudioTrack = () => {
      const AudioContextClass = window.AudioContext || window.webkitAudioContext;
      if (!AudioContextClass) return null;
      try {
        const audioContext = new AudioContextClass();
        const oscillator = audioContext.createOscillator();
        const gain = audioContext.createGain();
        const destination = audioContext.createMediaStreamDestination();
        oscillator.frequency.value = frequency;
        gain.gain.value = pulseAudio ? 0.0001 : 0.08;
        oscillator.connect(gain);
        gain.connect(destination);
        oscillator.start();
        audioContext.resume?.().catch(() => {});
        let pulseTimer = 0;
        if (pulseAudio) {
          const playPulse = () => {
            const now = audioContext.currentTime;
            gain.gain.cancelScheduledValues(now);
            gain.gain.setValueAtTime(0.0001, now);
            gain.gain.exponentialRampToValueAtTime(0.12, now + 0.025);
            gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.3);
          };
          playPulse();
          pulseTimer = window.setInterval(playPulse, Math.max(500, Number(pulseIntervalMs || 0)));
        }
        resources.push({ audioContext, oscillator, gain, destination, pulseTimer });
        return destination.stream.getAudioTracks()[0] || null;
      } catch {
        return null;
      }
    };

    Object.defineProperty(navigator, 'mediaDevices', {
      configurable: true,
      value: {
        ...(navigator.mediaDevices || {}),
        getUserMedia: async (constraints = {}) => {
          const tracks = [];
          if (constraints.video !== false) {
            const videoTrack = createVideoTrack(constraints.video);
            if (videoTrack) tracks.push(videoTrack);
          }
          if (constraints.audio !== false) {
            const audioTrack = createAudioTrack();
            if (audioTrack) tracks.push(audioTrack);
          }
          return new MediaStream(tracks);
        },
        enumerateDevices: async () => [
          { deviceId: 'king-video', kind: 'videoinput', label: 'KingRT test camera', groupId: 'king-e2e' },
          { deviceId: 'king-audio', kind: 'audioinput', label: 'KingRT test microphone', groupId: 'king-e2e' },
        ],
        getSupportedConstraints: () => ({ audio: true, video: true, deviceId: true, width: true, height: true, frameRate: true }),
      },
    });
  }, {
    frequency: audioFrequency,
    pulseAudio: Boolean(audioPulse),
    pulseIntervalMs: Math.max(500, Number(audioPulseIntervalMs || 2500)),
    width: videoWidth,
    height: videoHeight,
    frameRate: videoFrameRate,
    highMotion: Boolean(highMotionVideo),
    deterministicPattern: Boolean(deterministicVideoPattern),
    patternLabel: String(videoPatternLabel || 'KINGRT LIVE PROOF').slice(0, 64),
  });
}

export async function installOutgoingVideoQualityPreference(context, profile = 'quality') {
  await context.addInitScript(({ key, qualityProfile }) => {
    try {
      if (window.top !== window) return;
      const previousRaw = localStorage.getItem(key);
      let previous;
      try {
        previous = previousRaw ? JSON.parse(previousRaw) : {};
      } catch {
        previous = {};
      }
      localStorage.setItem(key, JSON.stringify({
        ...previous,
        video_id: 'king-video',
        audio_id: 'king-audio',
        outgoing_video_quality_profile: qualityProfile,
        outgoing_video_quality_profile_version: 6,
      }));
    } catch {
      // Sandboxed iframes can have opaque origins; media preferences only belong to the top-level app.
    }
  }, {
    key: 'ii.videocall.preview_prefs.v1',
    qualityProfile: String(profile || 'quality').trim().toLowerCase() || 'quality',
  });
}

export async function createAuthenticatedPage(browser, baseURL, credentials, options = {}) {
  const storedSession = await fetchStoredSession(credentials.email, credentials.password);
  let browserTypeName = '';
  try {
    browserTypeName = typeof browser?.browserType === 'function' ? String(browser.browserType().name?.() || '') : '';
  } catch {
    browserTypeName = '';
  }
  const browserName = [
    options.browserName,
    options.projectName,
    browserTypeName,
  ].map((candidate) => String(candidate || '').trim().toLowerCase()).find(Boolean) || '';
  const contextOptions = { baseURL };
  if (!browserName.includes('firefox')) {
    contextOptions.permissions = ['camera', 'microphone'];
  }
  let context = null;
  try {
    context = await browser.newContext(contextOptions);
  } catch (error) {
    if (!/Unknown permission/i.test(String(error?.message || error))) {
      throw error;
    }
    context = await browser.newContext({ baseURL });
  }
  await installMediaDeviceShim(context, options);
  if (options.outgoingVideoQualityProfile) {
    await installOutgoingVideoQualityPreference(context, options.outgoingVideoQualityProfile);
  }
  await installSocketInstrumentation(context);
  await context.addInitScript(({ key, value }) => {
    if (window.top !== window) return;
    try {
      localStorage.setItem(key, value);
    } catch {
      // Sandboxed iframes can have opaque origins; the stored session is only needed in the top-level app.
    }
  }, { key: sessionStorageKey, value: JSON.stringify(storedSession) });
  return { context, page: await context.newPage(), storedSession };
}

export async function createInvitedCallViaApi({ sessionToken, title, participantUserId }) {
  const response = await fetch(`${backendOrigin}/api/calls`, {
    method: 'POST',
    headers: { accept: 'application/json', 'content-type': 'application/json', authorization: `Bearer ${sessionToken}` },
    body: JSON.stringify({
      title,
      access_mode: 'invite_only',
      room_id: title.toLowerCase().replace(/[^a-z0-9-]+/g, '-').slice(0, 80) || 'audio-e2e',
      starts_at: new Date(Date.now() - 60_000).toISOString(),
      ends_at: new Date(Date.now() + 59 * 60_000).toISOString(),
      internal_participant_user_ids: [participantUserId],
      external_participants: [],
    }),
  });
  const payload = await response.json().catch(() => null);
  if (!response.ok || payload?.status !== 'ok') {
    throw new Error(payload?.error?.message || `Call creation failed (${response.status}).`);
  }
  const callId = String(payload?.result?.call?.id || '').trim();
  if (callId === '') throw new Error('Call creation payload is missing call id.');
  return callId;
}

export async function createPersonalAccessJoinPath({ callId, sessionToken, participantUserId }) {
  const response = await fetch(`${backendOrigin}/api/calls/${encodeURIComponent(callId)}/access-link`, {
    method: 'POST',
    headers: { accept: 'application/json', 'content-type': 'application/json', authorization: `Bearer ${sessionToken}` },
    body: JSON.stringify({ link_kind: 'personal', participant_user_id: participantUserId }),
  });
  const payload = await response.json().catch(() => null);
  if (!response.ok || payload?.status !== 'ok') {
    throw new Error(payload?.error?.message || `Access-link creation failed (${response.status}).`);
  }
  const joinPath = String(payload?.result?.join_path || '').trim();
  if (joinPath !== '') return joinPath;
  const accessId = String(payload?.result?.access_link?.id || '').trim();
  if (accessId !== '') return `/join/${accessId}`;
  throw new Error('Access-link payload is missing join_path and access id.');
}

async function clickJoinButtonIfVisible(page) {
  const dialog = page.getByRole('dialog', { name: /(?:Enter|Join) video call/i });
  if (!(await dialog.isVisible({ timeout: 15_000 }).catch(() => false))) return;
  await dialog.getByRole('button', { name: /Join call/i }).click();
}

export async function enterOwnerWorkspaceCall(page, callId) {
  await page.goto(`/workspace/call/${callId}`);
  await clickJoinButtonIfVisible(page);
  await page.waitForURL(new RegExp(`/workspace/call/${escapeRegExp(callId)}(?:[/?#].*)?$`), { timeout: 30_000 });
  await expect(page.locator('.workspace-main-video')).toBeVisible({ timeout: 20_000 });
}

export async function queueUserAdmission(page, joinPath) {
  await page.goto(joinPath);
  const joinCallModal = page.getByRole('dialog', { name: 'Join video call' });
  await expect(joinCallModal).toBeVisible({ timeout: 15_000 });
  await joinCallModal.getByRole('button', { name: /Join call/i }).click();
  await expect(joinCallModal).toContainText(/Call owner has been notified|Waiting for host/i, { timeout: 15_000 });
}

export async function admitFirstLobbyUser(page, targetUserId = 0) {
  const sendDirectLobbyFrame = async (frame) => page.evaluate((payload) => {
    if (!payload || typeof payload !== 'object') return false;
    const sockets = Array.isArray(window.__kingNativeAudioSockets) ? window.__kingNativeAudioSockets : [];
    const socket = sockets.find((candidate) => {
      const url = String(candidate?.url || candidate?.__kingNativeAudioUrl || '');
      return url.includes('/ws') && candidate?.readyState === WebSocket.OPEN;
    });
    if (!socket) return false;
    socket.send(JSON.stringify(payload));
    return true;
  }, frame);

  const sendDirectAllow = async () => {
    const normalizedUserId = Number(targetUserId);
    if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return false;
    return sendDirectLobbyFrame({ type: 'lobby/allow', target_user_id: normalizedUserId });
  };

  const sendDirectAllowAll = async () => sendDirectLobbyFrame({ type: 'lobby/allow_all' });

  try {
    const lobbyBadge = page.locator('.tabs-right .tab-notice-badge, .tab-notice-badge').first();
    await expect(lobbyBadge).toBeVisible({ timeout: 30_000 });

    const lobbyToast = page.locator('.workspace-lobby-toast').first();
    if (await lobbyToast.isVisible({ timeout: 1_000 }).catch(() => false)) {
      await lobbyToast.click();
    }
    const showRightSidebar = page.locator('.workspace-show-right-btn, .show-right-sidebar-overlay').first();
    if (await showRightSidebar.isVisible({ timeout: 1_000 }).catch(() => false)) {
      await showRightSidebar.click();
    }
    const usersTab = page.locator('button[role="tab"][aria-label="Users"], button[role="tab"][title="Users"], .tabs-right button[role="tab"]:has(.tab-notice-badge)').first();
    if (await usersTab.isVisible({ timeout: 2_000 }).catch(() => false)) {
      await usersTab.click();
    }

    const lobbyPanel = page.locator('.right-roster-panel.active .roster-section-lobby, .roster-section-lobby, .panel-lobby.active').first();
    await expect(lobbyPanel).toBeVisible({ timeout: 10_000 });
    const allowUserButton = lobbyPanel.locator('button[title="Allow user"], button[aria-label="Allow user"], .roster-action-btn:has(img[src*="add_to_call"])').first();
    await expect(allowUserButton).toBeVisible({ timeout: 20_000 });
    await allowUserButton.click();
    return;
  } catch (error) {
    if (await sendDirectAllow()) return;
    if (await sendDirectAllowAll()) return;
    throw error;
  }
}

export async function nativeMediaSignalCount(page) {
  return page.evaluate(() => {
    const events = Array.isArray(window.__kingNativeAudioSocketEvents) ? window.__kingNativeAudioSocketEvents : [];
    return events.filter((event) => {
      const frame = event?.frame && typeof event.frame === 'object' ? event.frame : {};
      const type = String(frame.type || '').trim();
      const payload = frame.payload && typeof frame.payload === 'object' ? frame.payload : {};
      return type === 'call/offer'
        || type === 'call/answer'
        || type === 'call/ice'
        || type === 'call/media-security-sync-request'
        || type === 'media-security/hello'
        || type === 'media-security/sender-key'
        || String(payload.kind || '').startsWith('webrtc_');
    }).length;
  });
}

export async function nativeAudioBridgeSnapshot(page) {
  return page.evaluate(() => {
    const nodes = Array.from(document.querySelectorAll('audio[data-role="native-audio-bridge"]'));
    const audioTracks = nodes.flatMap((audio) => {
      const stream = audio.srcObject;
      if (!(stream instanceof MediaStream)) return [];
      return stream.getAudioTracks().map((track) => ({
        id: String(track.id || ''),
        readyState: String(track.readyState || ''),
        enabled: Boolean(track.enabled),
      }));
    });
    return {
      elementCount: nodes.length,
      audioTrackCount: audioTracks.length,
      liveAudioTrackCount: audioTracks.filter((track) => track.readyState === 'live').length,
      hasLiveTrack: audioTracks.some((track) => track.readyState === 'live' && track.enabled),
    };
  });
}

export async function sfuRemoteVideoSnapshot(page) {
  return page.evaluate(() => {
    const canvases = Array.from(document.querySelectorAll('#decoded-video-container canvas.remote-video, canvas.remote-video'));
    return canvases.map((canvas) => ({
      width: Number(canvas.width || 0),
      height: Number(canvas.height || 0),
      publisherId: String(canvas.dataset.publisherId || ''),
      userId: String(canvas.dataset.userId || ''),
      rendered: canvas.width > 0 && canvas.height > 0 && canvas.isConnected,
    }));
  });
}

export async function remoteVideoPixelSnapshot(page) {
  return page.evaluate(() => {
    const selectors = [
      '#decoded-video-container canvas.remote-video',
      '#decoded-video-container canvas',
      'canvas.remote-video',
      '[data-role="remote-video"] canvas',
      '[data-testid="remote-video"] canvas',
      '.workspace-main-video canvas',
      '.workspace-mini-video-slot canvas',
      '.call-app-workspace-mini-video-slot canvas',
      'video.remote-video',
      '[data-role="remote-video"] video',
      '[data-testid="remote-video"] video',
      '.workspace-main-video video',
      '.workspace-mini-video-slot video',
      '.call-app-workspace-mini-video-slot video',
    ];
    const palette = [
      [255, 0, 84],
      [0, 245, 212],
      [255, 230, 109],
      [31, 41, 55],
      [124, 58, 237],
      [34, 197, 94],
    ];
    const sampleCanvas = document.createElement('canvas');
    const sampleWidth = 64;
    const sampleHeight = 36;
    sampleCanvas.width = sampleWidth;
    sampleCanvas.height = sampleHeight;
    const sampleContext = sampleCanvas.getContext('2d', { willReadFrequently: true });

    const uniqueElements = [];
    const seen = new Set();
    for (const selector of selectors) {
      for (const element of Array.from(document.querySelectorAll(selector))) {
        if (seen.has(element)) continue;
        seen.add(element);
        uniqueElements.push({ element, selector });
      }
    }

    const colorDistance = (sample, expected) => Math.sqrt(
      ((sample[0] || 0) - expected[0]) ** 2
      + ((sample[1] || 0) - expected[1]) ** 2
      + ((sample[2] || 0) - expected[2]) ** 2,
    );

    const scorePixels = (data) => {
      const paletteHits = new Set();
      let total = 0;
      let vivid = 0;
      let bright = 0;
      let dark = 0;
      let green = 0;
      for (let offset = 0; offset < data.length; offset += 4) {
        const red = data[offset] || 0;
        const greenChannel = data[offset + 1] || 0;
        const blue = data[offset + 2] || 0;
        const max = Math.max(red, greenChannel, blue);
        const min = Math.min(red, greenChannel, blue);
        total += 1;
        if (max - min > 70 && max > 120) vivid += 1;
        if (red > 225 && greenChannel > 225 && blue > 225) bright += 1;
        if (red < 45 && greenChannel < 55 && blue < 70) dark += 1;
        if (greenChannel > 150 && red < 90 && blue < 150) green += 1;
        palette.forEach((expected, index) => {
          if (colorDistance([red, greenChannel, blue], expected) < 72) paletteHits.add(index);
        });
      }
      const vividRatio = total > 0 ? vivid / total : 0;
      const brightRatio = total > 0 ? bright / total : 0;
      const darkRatio = total > 0 ? dark / total : 0;
      const greenRatio = total > 0 ? green / total : 0;
      const patternScore = paletteHits.size
        + (vividRatio > 0.2 ? 1 : 0)
        + (brightRatio > 0.005 ? 1 : 0)
        + (darkRatio > 0.005 ? 1 : 0)
        + (greenRatio > 0.005 ? 1 : 0);
      return {
        brightRatio,
        darkRatio,
        greenRatio,
        paletteHits: Array.from(paletteHits).sort((left, right) => left - right),
        patternScore,
        vividRatio,
      };
    };

    return uniqueElements.map(({ element, selector }) => {
      const rect = element.getBoundingClientRect();
      const visible = element.isConnected
        && rect.width > 0
        && rect.height > 0
        && window.getComputedStyle(element).visibility !== 'hidden'
        && window.getComputedStyle(element).display !== 'none';
      const isCanvas = element instanceof HTMLCanvasElement;
      const isVideo = element instanceof HTMLVideoElement;
      const sourceWidth = isCanvas ? Number(element.width || 0) : Number(element.videoWidth || 0);
      const sourceHeight = isCanvas ? Number(element.height || 0) : Number(element.videoHeight || 0);
      if (!visible || !sampleContext || sourceWidth <= 0 || sourceHeight <= 0) {
        return {
          className: String(element.className || ''),
          dataset: { ...element.dataset },
          height: sourceHeight,
          id: String(element.id || ''),
          patternScore: 0,
          reason: visible ? 'missing_source_pixels' : 'not_visible',
          selector,
          tagName: element.tagName.toLowerCase(),
          visibleHeight: rect.height,
          visibleWidth: rect.width,
          width: sourceWidth,
        };
      }
      try {
        sampleContext.clearRect(0, 0, sampleWidth, sampleHeight);
        sampleContext.drawImage(element, 0, 0, sampleWidth, sampleHeight);
        const image = sampleContext.getImageData(0, 0, sampleWidth, sampleHeight);
        return {
          ...scorePixels(image.data),
          className: String(element.className || ''),
          dataset: { ...element.dataset },
          height: sourceHeight,
          id: String(element.id || ''),
          reason: 'ok',
          selector,
          tagName: element.tagName.toLowerCase(),
          visibleHeight: rect.height,
          visibleWidth: rect.width,
          width: sourceWidth,
        };
      } catch (error) {
        return {
          className: String(element.className || ''),
          dataset: { ...element.dataset },
          height: sourceHeight,
          id: String(element.id || ''),
          patternScore: 0,
          reason: error instanceof Error ? error.message : 'pixel_sample_failed',
          selector,
          tagName: element.tagName.toLowerCase(),
          visibleHeight: rect.height,
          visibleWidth: rect.width,
          width: sourceWidth,
        };
      }
    });
  });
}

export async function waitForDeterministicRemoteVideo(page, {
  minPatternScore = 4,
  timeout = 90_000,
} = {}) {
  await expect.poll(async () => {
    const snapshot = await remoteVideoPixelSnapshot(page);
    return snapshot.reduce((max, entry) => Math.max(max, Number(entry.patternScore || 0)), 0);
  }, {
    timeout,
  }).toBeGreaterThanOrEqual(minPatternScore);
  return remoteVideoPixelSnapshot(page);
}

export async function sfuSocketStats(page) {
  return page.evaluate(() => {
    const events = Array.isArray(window.__kingNativeAudioSocketEvents) ? window.__kingNativeAudioSocketEvents : [];
    const isSfuUrl = (url) => {
      try {
        const parsed = new URL(String(url || ''), window.location.origin);
        return parsed.hostname.toLowerCase().startsWith('sfu.') || parsed.pathname.replace(/\/+$/, '') === '/sfu';
      } catch {
        return false;
      }
    };
    const sfuEvents = events.filter((event) => isSfuUrl(event?.url));
    const binaryIn = sfuEvents.filter((event) => event?.direction === 'in' && event?.frame?.type === '__binary__');
    const binaryOut = sfuEvents.filter((event) => event?.direction === 'out' && event?.frame?.type === '__binary__');
    const maxBinaryOutBytes = binaryOut.reduce((max, event) => Math.max(max, Number(event?.frame?.bytes || 0)), 0);
    const maxBinaryInBytes = binaryIn.reduce((max, event) => Math.max(max, Number(event?.frame?.bytes || 0)), 0);
    const sfuSockets = Array.from(window.__kingNativeAudioSockets || [])
      .filter((socket) => isSfuUrl(socket?.url || socket?.__kingNativeAudioUrl));
    const socketFailures = sfuEvents.filter((event) => event?.direction === 'state'
      && (event?.frame?.type === '__socket_error__' || event?.frame?.type === '__socket_close__'));
    const maxBufferedAmountAfterSend = sfuEvents.reduce((max, event) => Math.max(max, Number(event?.bufferedAmountAfter || 0)), 0);
    const currentBufferedAmount = sfuSockets.reduce((max, socket) => Math.max(max, Number(socket?.bufferedAmount || 0)), 0);
    return {
      binaryInCount: binaryIn.length,
      binaryOutCount: binaryOut.length,
      maxBinaryInBytes,
      maxBinaryOutBytes,
      maxBufferedAmountAfterSend,
      currentBufferedAmount,
      socketFailureCount: socketFailures.length,
    };
  });
}

export async function measureNativeAudioBridgeEnergy(page) {
  return page.evaluate(async () => {
    const audio = Array.from(document.querySelectorAll('audio[data-role="native-audio-bridge"]'))
      .find((candidate) => {
        const stream = candidate.srcObject;
        return stream instanceof MediaStream
          && stream.getAudioTracks().some((track) => track.readyState === 'live' && track.enabled);
      });
    if (!audio) return { maxRms: 0, reason: 'missing_native_audio_bridge_track' };

    const stream = audio.srcObject;
    const AudioContextClass = window.AudioContext || window.webkitAudioContext;
    if (!AudioContextClass) return { maxRms: 0, reason: 'missing_audio_context' };

    const audioContext = new AudioContextClass();
    await audioContext.resume?.().catch(() => {});
    const source = audioContext.createMediaStreamSource(new MediaStream(stream.getAudioTracks()));
    const analyser = audioContext.createAnalyser();
    analyser.fftSize = 2048;
    source.connect(analyser);

    const data = new Uint8Array(analyser.fftSize);
    let maxRms = 0;
    for (let attempt = 0; attempt < 8; attempt += 1) {
      await new Promise((resolve) => setTimeout(resolve, 100));
      analyser.getByteTimeDomainData(data);
      let sum = 0;
      for (const sample of data) {
        const normalized = (sample - 128) / 128;
        sum += normalized * normalized;
      }
      maxRms = Math.max(maxRms, Math.sqrt(sum / data.length));
    }
    await audioContext.close().catch(() => {});
    return { maxRms, reason: maxRms > 0 ? 'ok' : 'silent' };
  });
}
