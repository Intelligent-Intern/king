import { expect } from '@playwright/test';

export const backendOrigin = process.env.VITE_VIDEOCHAT_BACKEND_ORIGIN || 'http://127.0.0.1:18080';
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

async function installSocketInstrumentation(context) {
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
      }

      send(data) {
        events.push({ direction: 'out', url: this.__kingNativeAudioUrl || this.url, frame: snapshotFrame(data), at: Date.now() });
        return super.send(data);
      }
    }

    for (const key of ['CONNECTING', 'OPEN', 'CLOSING', 'CLOSED']) {
      Object.defineProperty(InstrumentedWebSocket, key, { value: NativeWebSocket[key], enumerable: true });
    }
    InstrumentedWebSocket.__kingNativeAudioInstrumented = true;
    window.WebSocket = InstrumentedWebSocket;
  });
}

async function installMediaDeviceShim(context, { audioFrequency = 440 } = {}) {
  await context.addInitScript(({ frequency }) => {
    const resources = [];
    window.__kingNativeAudioMediaResources = resources;

    const createVideoTrack = () => {
      const canvas = document.createElement('canvas');
      canvas.width = 320;
      canvas.height = 240;
      const ctx = canvas.getContext('2d');
      let frame = 0;
      const draw = () => {
        if (!ctx) return;
        ctx.fillStyle = frame % 2 === 0 ? '#123c55' : '#1a5f72';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = '#f8fafc';
        ctx.font = '22px sans-serif';
        ctx.fillText(`audio ${frequency}Hz`, 34, 122);
        frame += 1;
      };
      draw();
      const intervalId = window.setInterval(draw, 200);
      const stream = typeof canvas.captureStream === 'function' ? canvas.captureStream(12) : null;
      resources.push({ canvas, intervalId, stream });
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
        gain.gain.value = 0.08;
        oscillator.connect(gain);
        gain.connect(destination);
        oscillator.start();
        audioContext.resume?.().catch(() => {});
        resources.push({ audioContext, oscillator, gain, destination });
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
            const videoTrack = createVideoTrack();
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
        getSupportedConstraints: () => ({ audio: true, video: true, deviceId: true }),
      },
    });
  }, { frequency: audioFrequency });
}

export async function createAuthenticatedPage(browser, baseURL, credentials, options = {}) {
  const storedSession = await fetchStoredSession(credentials.email, credentials.password);
  const context = await browser.newContext({ baseURL, permissions: ['camera', 'microphone'] });
  await installMediaDeviceShim(context, options);
  await installSocketInstrumentation(context);
  await context.addInitScript(({ key, value }) => {
    localStorage.setItem(key, value);
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

export async function admitFirstLobbyUser(page) {
  const lobbyBadge = page.locator('.tab-lobby .tab-notice-badge');
  await expect(lobbyBadge).toBeVisible({ timeout: 30_000 });
  await page.locator('button.tab-lobby').click();
  const lobbyPanel = page.locator('.panel-lobby.active');
  await expect(lobbyPanel).toBeVisible({ timeout: 10_000 });
  const allowUserButton = lobbyPanel.locator('button[title="Allow user"]').first();
  await expect(allowUserButton).toBeVisible({ timeout: 20_000 });
  await allowUserButton.click();
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
