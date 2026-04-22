import { test, expect } from '@playwright/test';

const backendOrigin = process.env.VITE_VIDEOCHAT_BACKEND_ORIGIN || 'http://127.0.0.1:18080';
const sessionStorageKey = 'ii_videocall_v1_session';

const adminCredentials = {
  email: 'admin@intelligent-intern.com',
  password: 'admin123',
};

const userCredentials = {
  email: 'user@intelligent-intern.com',
  password: 'user123',
};

function escapeRegExp(value) {
  return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function toLocalDateTimeInputValue(date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  const hours = String(date.getHours()).padStart(2, '0');
  const minutes = String(date.getMinutes()).padStart(2, '0');
  return `${year}-${month}-${day}T${hours}:${minutes}`;
}

function normalizeRosterName(value) {
  return String(value || '').replace(/\s+/g, ' ').trim();
}

function expectedRosterNames() {
  return ['Platform Admin', 'Call User'].sort();
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
        headers: {
          accept: 'application/json',
          'content-type': 'application/json',
        },
        body: JSON.stringify({ email, password }),
      });

      let payload = null;
      try {
        payload = await response.json();
      } catch {
        payload = null;
      }

      if (response.ok && payload && payload.status === 'ok') {
        return buildStoredSession(payload);
      }

      const message = payload?.error?.message || `Login failed (${response.status}).`;
      lastError = new Error(message);
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Login request failed.';
      lastError = new Error(message);
    }

    await new Promise((resolve) => setTimeout(resolve, 800 * (attempt + 1)));
  }

  throw lastError;
}

async function installSocketInstrumentation(context) {
  await context.addInitScript(() => {
    const NativeWebSocket = window.WebSocket;
    if (!NativeWebSocket || NativeWebSocket.__kingVideoChatInstrumented) return;

    const events = [];
    window.__videoCallSocketEvents = events;

    const snapshotFrame = (data) => {
      if (typeof data === 'string') {
        try {
          return JSON.parse(data);
        } catch {
          return { type: '__text__' };
        }
      }

      if (data instanceof ArrayBuffer) {
        return { type: '__binary__', bytes: data.byteLength };
      }

      if (ArrayBuffer.isView(data)) {
        return { type: '__binary__', bytes: data.byteLength || data.length || 0 };
      }

      if (typeof Blob !== 'undefined' && data instanceof Blob) {
        return { type: '__blob__', bytes: data.size };
      }

      return { type: '__unknown__' };
    };

    const record = (direction, url, data) => {
      events.push({
        direction,
        url: String(url || ''),
        frame: snapshotFrame(data),
        at: Date.now(),
      });
    };

    class InstrumentedWebSocket extends NativeWebSocket {
      constructor(url, protocols) {
        if (protocols === undefined) {
          super(url);
        } else {
          super(url, protocols);
        }
        this.__kingVideoChatUrl = String(url || '');
        this.addEventListener('message', (event) => {
          record('in', this.__kingVideoChatUrl, event.data);
        });
      }

      send(data) {
        record('out', this.__kingVideoChatUrl || this.url, data);
        return super.send(data);
      }
    }

    for (const key of ['CONNECTING', 'OPEN', 'CLOSING', 'CLOSED']) {
      Object.defineProperty(InstrumentedWebSocket, key, {
        value: NativeWebSocket[key],
        enumerable: true,
      });
    }

    InstrumentedWebSocket.__kingVideoChatInstrumented = true;
    window.WebSocket = InstrumentedWebSocket;
  });
}

async function installMediaDeviceShim(context) {
  await context.addInitScript(() => {
    const resources = [];
    window.__videoCallMediaShimResources = resources;

    const createVideoTrack = () => {
      if (typeof document === 'undefined') return null;
      const canvas = document.createElement('canvas');
      canvas.width = 320;
      canvas.height = 240;
      const context = canvas.getContext('2d');
      let frame = 0;
      const draw = () => {
        if (!context) return;
        context.fillStyle = frame % 2 === 0 ? '#0e7490' : '#155e75';
        context.fillRect(0, 0, canvas.width, canvas.height);
        context.fillStyle = '#f8fafc';
        context.font = '24px sans-serif';
        context.fillText(`KingRT ${frame}`, 28, 124);
        frame += 1;
      };
      draw();
      const intervalId = window.setInterval(draw, 250);
      const stream = typeof canvas.captureStream === 'function' ? canvas.captureStream(8) : null;
      const track = stream?.getVideoTracks?.()[0] || null;
      resources.push({ canvas, intervalId, stream });
      return track;
    };

    const createAudioTrack = () => {
      const AudioContextClass = window.AudioContext || window.webkitAudioContext;
      if (!AudioContextClass) return null;
      try {
        const audioContext = new AudioContextClass();
        const oscillator = audioContext.createOscillator();
        const gain = audioContext.createGain();
        const destination = audioContext.createMediaStreamDestination();
        oscillator.frequency.value = 220;
        gain.gain.value = 0.01;
        oscillator.connect(gain);
        gain.connect(destination);
        oscillator.start();
        resources.push({ audioContext, oscillator, destination });
        return destination.stream.getAudioTracks()[0] || null;
      } catch {
        return null;
      }
    };

    const mediaDevices = {
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
      getSupportedConstraints: () => ({
        audio: true,
        video: true,
        deviceId: true,
        echoCancellation: true,
        noiseSuppression: true,
      }),
    };

    Object.defineProperty(navigator, 'mediaDevices', {
      configurable: true,
      value: mediaDevices,
    });
  });
}

async function createAuthenticatedPage(browser, baseURL, { email, password }) {
  const storedSession = await fetchStoredSession(email, password);
  const context = await browser.newContext({
    baseURL,
    permissions: ['camera', 'microphone'],
  });
  await installMediaDeviceShim(context);
  await installSocketInstrumentation(context);
  await context.addInitScript(
    ({ key, value }) => {
      try {
        localStorage.setItem(key, value);
      } catch {
        // ignore
      }
    },
    { key: sessionStorageKey, value: JSON.stringify(storedSession) },
  );
  const page = await context.newPage();
  return { context, page, storedSession };
}

async function visibleRosterNames(page) {
  await page.getByRole('tab', { name: 'Users' }).click();
  const activeUsersPanel = page.locator('.panel-users.active');
  await expect(activeUsersPanel).toBeVisible({ timeout: 10_000 });
  await expect(activeUsersPanel.locator('.user-row .user-name')).toHaveCount(2, { timeout: 30_000 });

  const names = await activeUsersPanel.locator('.user-row .user-name').allTextContents();
  return names.map(normalizeRosterName).filter(Boolean).sort();
}

async function expectSharedParticipantRoster(adminPage, userPage) {
  const expected = expectedRosterNames();

  await expect.poll(
    async () => visibleRosterNames(adminPage),
    {
      timeout: 30_000,
      message: 'admin browser should show the admitted owner and user roster',
    },
  ).toEqual(expected);

  await expect.poll(
    async () => visibleRosterNames(userPage),
    {
      timeout: 30_000,
      message: 'user browser should show the admitted owner and user roster',
    },
  ).toEqual(expected);
}

async function mediaSignalEventCount(page) {
  return page.evaluate(() => {
    const events = Array.isArray(window.__videoCallSocketEvents) ? window.__videoCallSocketEvents : [];

    return events.filter((event) => {
      const url = String(event?.url || '');
      const frame = event?.frame && typeof event.frame === 'object' ? event.frame : {};
      const type = String(frame.type || '').trim();
      const payload = frame.payload && typeof frame.payload === 'object' ? frame.payload : {};
      const payloadKind = String(payload.kind || '').trim();
      const hasNativeSdp = (type === 'call/offer' || type === 'call/answer') && payload.sdp;
      const hasNativeIce = type === 'call/ice' && payload.candidate;
      const hasSfuSignal = url.includes('/sfu') || type.startsWith('sfu/');

      return hasSfuSignal || hasNativeSdp || hasNativeIce || payloadKind.startsWith('webrtc_');
    }).length;
  });
}

async function expectMediaSignalExchange(page, label) {
  await expect.poll(
    async () => mediaSignalEventCount(page),
    {
      timeout: 45_000,
      message: `${label} should exchange SFU or WebRTC media signals`,
    },
  ).toBeGreaterThan(0);
}

async function renderedMediaState(page) {
  return page.evaluate(() => {
    const nodes = Array.from(document.querySelectorAll([
      '#local-video-container video',
      '#remote-video-container video',
      '#remote-video-container canvas',
      '.workspace-mini-video-slot video',
      '.workspace-mini-video-slot canvas',
    ].join(',')));

    const liveTrackCount = (value) => {
      if (!(value instanceof MediaStream)) return 0;
      return value.getTracks().filter((track) => track?.readyState !== 'ended').length;
    };

    const localLiveTrackCount = nodes
      .filter((node) => node.tagName === 'VIDEO' && !node.classList.contains('remote-video'))
      .reduce((count, node) => count + liveTrackCount(node.srcObject), 0);
    const remoteLiveTrackCount = nodes
      .filter((node) => node.tagName === 'VIDEO' && node.classList.contains('remote-video'))
      .reduce((count, node) => count + liveTrackCount(node.srcObject), 0);
    const remoteCanvasCount = nodes.filter((node) => (
      node.tagName === 'CANVAS'
      && node.classList.contains('remote-video')
      && Number(node.width || 0) > 0
      && Number(node.height || 0) > 0
    )).length;
    const remoteVideoCount = nodes.filter((node) => (
      node.tagName === 'VIDEO'
      && node.classList.contains('remote-video')
    )).length;

    const hasLocal = localLiveTrackCount > 0;
    const hasRemote = remoteLiveTrackCount > 0 || remoteCanvasCount > 0;

    return { hasLocal, hasRemote, localLiveTrackCount, remoteLiveTrackCount, remoteCanvasCount, remoteVideoCount };
  });
}

async function expectRenderedLocalAndRemoteMedia(page, label) {
  await expect.poll(
    async () => renderedMediaState(page),
    {
      timeout: 45_000,
      message: `${label} should render both local and remote media nodes`,
    },
  ).toMatchObject({ hasLocal: true, hasRemote: true });
}

async function waitForCallRow(page, callTitle) {
  const searchCalls = async () => {
    await page.getByPlaceholder('Search call title').fill(callTitle);
    await page.getByRole('button', { name: 'Search' }).first().click();
  };

  for (let attempt = 0; attempt < 10; attempt += 1) {
    await searchCalls();
    const row = page.locator('tbody tr', { hasText: callTitle }).first();
    try {
      await expect(row).toBeVisible({ timeout: 2500 });
      return row;
    } catch {
      // The table refresh is async after filtering; reload and retry below.
    }
    await page.reload({ waitUntil: 'domcontentloaded' });
  }

  throw new Error(`Could not find call row for title: ${callTitle}`);
}

async function createPersonalAccessJoinPath({ callId, sessionToken, participantUserId }) {
  const response = await fetch(`${backendOrigin}/api/calls/${encodeURIComponent(callId)}/access-link`, {
    method: 'POST',
    headers: {
      accept: 'application/json',
      'content-type': 'application/json',
      authorization: `Bearer ${sessionToken}`,
    },
    body: JSON.stringify({
      link_kind: 'personal',
      participant_user_id: participantUserId,
    }),
  });
  const payload = await response.json().catch(() => null);

  if (!response.ok || !payload || payload.status !== 'ok') {
    const message = payload?.error?.message || `Access-link creation failed (${response.status}).`;
    throw new Error(message);
  }

  const rawJoinPath = String(payload?.result?.join_path || '').trim();
  if (rawJoinPath !== '') return rawJoinPath;

  const accessId = String(payload?.result?.access_link?.id || '').trim();
  if (accessId !== '') return `/join/${accessId}`;

  throw new Error('Access-link payload is missing join_path and access id.');
}

test('admin creates/invites and admits user from lobby, both browsers share roster and media signals', async ({ browser }) => {
  test.setTimeout(180_000);
  const baseURL = test.info().project.use.baseURL || 'http://127.0.0.1:4174';

  const { context: adminContext, page: adminPage, storedSession: adminStoredSession } = await createAuthenticatedPage(browser, baseURL, adminCredentials);
  const { context: userContext, page: userPage } = await createAuthenticatedPage(browser, baseURL, userCredentials);
  const callTitle = `E2E Lobby Admit ${Date.now()}`;

  try {
    await adminPage.goto('/admin/calls');
    await expect(adminPage).toHaveURL(/\/admin\/calls$/);

    await adminPage.getByRole('tab', { name: 'Calendar' }).click();
    await adminPage.getByRole('button', { name: /Schedule video call/i }).click();
    const composeModal = adminPage.getByRole('dialog', { name: 'Call compose modal' });
    await expect(composeModal).toBeVisible();

    await composeModal.locator('input[placeholder="Weekly Product Sync"]').fill(callTitle);
    const startsAt = new Date(Date.now() - 60_000);
    const endsAt = new Date(Date.now() + (59 * 60_000));
    await composeModal.getByLabel('Call starts at').fill(toLocalDateTimeInputValue(startsAt));
    await composeModal.getByLabel('Call ends at').fill(toLocalDateTimeInputValue(endsAt));
    await composeModal.locator('label[aria-label="Participant search"] input[placeholder="Search users"]').fill(userCredentials.email);
    await composeModal.locator('label[aria-label="Participant search"] button', { hasText: 'Search' }).click();

    const invitedUserRow = composeModal.locator('.calls-participant-row', { hasText: userCredentials.email }).first();
    await expect(invitedUserRow).toBeVisible({ timeout: 15_000 });
    await invitedUserRow.locator('input[type="checkbox"]').check();

    const removeExternalButtons = composeModal.locator('button[title="Remove external participant"]');
    for (let index = await removeExternalButtons.count(); index > 0; index -= 1) {
      await removeExternalButtons.first().click();
    }

    await composeModal.getByRole('button', { name: /Schedule call|Start now/i }).click();
    await expect(composeModal).toBeHidden({ timeout: 20_000 });

    await adminPage.getByRole('tab', { name: 'Calls' }).click();
    const adminCallRow = await waitForCallRow(adminPage, callTitle);
    const callId = ((await adminCallRow.locator('.call-subline.code').first().textContent()) || '').trim();
    expect(callId.length).toBeGreaterThan(0);

    const userJoinPath = await createPersonalAccessJoinPath({
      callId,
      sessionToken: adminStoredSession.sessionToken,
      participantUserId: 2,
    });

    await adminCallRow.locator('button[title="Enter video call"]').click();

    const adminEnterCallModal = adminPage.getByRole('dialog', { name: 'Enter video call' });
    await expect(adminEnterCallModal).toBeVisible();
    await adminEnterCallModal.getByRole('button', { name: /Join call/i }).click();

    await adminPage.waitForURL(/\/workspace\/call\/[^/]+$/, { timeout: 30_000 });
    await expect(adminPage.locator('.workspace-main-video')).toBeVisible({ timeout: 12_000 });

    const callRef = decodeURIComponent((adminPage.url().split('/workspace/call/')[1] || '').split(/[?#]/)[0] || '');
    expect(callRef.length).toBeGreaterThan(0);

    await userPage.goto(userJoinPath);
    const joinCallModal = userPage.getByRole('dialog', { name: 'Join video call' });
    await expect(joinCallModal).toBeVisible({ timeout: 15_000 });
    await joinCallModal.getByRole('button', { name: /Join call/i }).click();
    await expect(joinCallModal).toContainText(/Call owner has been notified|Waiting for host/i, { timeout: 15_000 });

    const lobbyBadge = adminPage.locator('.tab-lobby .tab-notice-badge');
    await expect(lobbyBadge).toBeVisible({ timeout: 30_000 });

    await adminPage.locator('button.tab-lobby').click();
    const lobbyPanel = adminPage.locator('.panel-lobby.active');
    await expect(lobbyPanel).toBeVisible({ timeout: 10_000 });

    const allowUserButton = lobbyPanel.locator('button[title="Allow user"]').first();
    await expect(allowUserButton).toBeVisible({ timeout: 20_000 });
    await allowUserButton.click();

    await userPage.waitForURL(new RegExp(`/workspace/call/${escapeRegExp(callRef)}(?:[/?#].*)?$|/workspace/call/[^/]+$`), { timeout: 30_000 });
    await expect(userPage.locator('.workspace-main-video')).toBeVisible({ timeout: 12_000 });

    const miniStrip = adminPage.locator('.workspace-mini-strip');
    await expect(miniStrip).toBeVisible({ timeout: 30_000 });
    await expect(miniStrip.locator('.workspace-mini-tile').first()).toBeVisible({ timeout: 30_000 });
    await expect(joinCallModal).toBeHidden({ timeout: 30_000 });
    await expect(userPage.locator('button.tab-lobby')).toHaveCount(0);
    await expect(miniStrip.locator('.workspace-mini-tile', { hasText: 'Call User' })).toBeVisible({ timeout: 30_000 });
    await expectSharedParticipantRoster(adminPage, userPage);
    await Promise.all([
      expectMediaSignalExchange(adminPage, 'admin browser'),
      expectMediaSignalExchange(userPage, 'user browser'),
    ]);
    await Promise.all([
      expectRenderedLocalAndRemoteMedia(adminPage, 'admin browser'),
      expectRenderedLocalAndRemoteMedia(userPage, 'user browser'),
    ]);

    const chatMessage = `chat button e2e ${Date.now()}`;
    await adminPage.getByRole('tab', { name: 'Chat' }).click();
    const adminChatInput = adminPage.getByPlaceholder('Write a message');
    await expect(adminChatInput).toBeVisible({ timeout: 10_000 });
    await adminChatInput.fill(chatMessage);
    const adminSendButton = adminPage.locator('.workspace-chat-compose button[type="submit"]');
    await expect(adminSendButton).toBeEnabled({ timeout: 10_000 });
    await adminSendButton.click();
    await expect(adminPage.locator('.workspace-chat-message').last()).toContainText(chatMessage, { timeout: 10_000 });

    await userPage.getByRole('tab', { name: 'Chat' }).click();
    await expect(userPage.locator('.workspace-chat-message').last()).toContainText(chatMessage, { timeout: 15_000 });
  } finally {
    await Promise.allSettled([
      adminContext.close(),
      userContext.close(),
    ]);
  }
});
