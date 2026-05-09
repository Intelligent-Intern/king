import { expect, test } from '@playwright/test';

const sessionStorageKey = 'ii_videocall_v1_session';
const callId = 'call-app-fullscreen-smoke';
const roomId = 'room-call-app-fullscreen-smoke';
const sessionId = 'session-call-app-fullscreen-smoke';

const participants = [
  { id: 1, name: 'Layout Admin', role: 'owner' },
  { id: 2, name: 'Ada Analyst', role: 'participant' },
  { id: 3, name: 'Bert Builder', role: 'participant' },
  { id: 4, name: 'Cora Caller', role: 'participant' },
  { id: 5, name: 'Dina Designer', role: 'participant' },
  { id: 6, name: 'Eli Engineer', role: 'participant' },
];

function corsHeaders() {
  return {
    'access-control-allow-origin': '*',
    'access-control-allow-credentials': 'true',
    'access-control-allow-headers': 'content-type, authorization, x-session-id',
    'access-control-allow-methods': 'GET, POST, PATCH, DELETE, OPTIONS',
    'access-control-max-age': '86400',
  };
}

function participantRows() {
  return participants.map((participant, index) => ({
    user_id: participant.id,
    display_name: participant.name,
    email: `call-app-${participant.id}@example.test`,
    call_role: participant.role,
    invite_state: 'allowed',
    joined_at: `2026-04-19T12:00:0${index}.000Z`,
    connected_at: `2026-04-29T01:00:0${index}.000Z`,
  }));
}

function callFixture() {
  return {
    id: callId,
    room_id: roomId,
    title: 'Call App fullscreen smoke',
    status: 'active',
    starts_at: '2026-04-19T12:00:00.000Z',
    ends_at: '2026-04-19T13:00:00.000Z',
    owner: {
      user_id: 1,
      display_name: 'Layout Admin',
      email: 'admin@example.test',
    },
    participants: participantRows(),
  };
}

function activeCallAppSession() {
  return {
    id: sessionId,
    call_id: callId,
    app_key: 'whiteboard',
    status: 'active',
    document_id: 'document-call-app-fullscreen-smoke',
    default_app_policy: 'allowed_by_default',
    app: {
      name: 'Whiteboard',
      category: 'collaboration',
      version: '1.0.0',
      iframe_entrypoint: 'index.html',
      health_status: 'healthy',
      crdt_protocol: 'king.call_app.crdt.v1',
    },
    grants: participants.map((participant) => ({
      user_id: participant.id,
      grant_state: 'allowed',
      capabilities: [
        'call_apps.launch',
        'call_apps.crdt.read',
        'call_apps.crdt.append',
        'call_apps.crdt.replay',
        'call_apps.presence.publish',
      ],
    })),
  };
}

async function installApiRoutes(page) {
  const call = callFixture();

  await page.route('**/call-app/whiteboard/index.html', async (route) => {
    await route.fulfill({
      status: 200,
      headers: { 'cache-control': 'no-store', 'content-type': 'text/html; charset=utf-8' },
      body: `<!doctype html>
        <html lang="en">
          <head>
            <meta charset="utf-8">
            <title>Whiteboard smoke fixture</title>
            <style>
              html, body { width: 100%; height: 100%; margin: 0; background: #f8fbff; color: #00123d; }
              body { display: grid; place-items: center; font: 700 18px system-ui, sans-serif; }
            </style>
          </head>
          <body>
            <main>Whiteboard smoke fixture</main>
            <script>
              window.addEventListener('message', (event) => {
                if (event.data && event.data.type === 'call_app.launch') {
                  window.parent.postMessage({
                    type: 'call_app.ready',
                    bridge_protocol: 'king.call_app.iframe.v1',
                    app_session_id: event.data.app_session_id,
                    app_key: event.data.app_key
                  }, '*');
                }
              });
            </script>
          </body>
        </html>`,
    });
  });

  await page.route('**/api/**', async (route) => {
    if (route.request().method() === 'OPTIONS') {
      await route.fulfill({ status: 204, headers: corsHeaders() });
      return;
    }

    const url = new URL(route.request().url());
    if (url.pathname === '/api/auth/session-state' || url.pathname === '/api/auth/session') {
      await route.fulfill({
        status: 200,
        headers: { ...corsHeaders(), 'content-type': 'application/json; charset=utf-8' },
        json: {
          status: 'ok',
          result: { state: 'authenticated' },
          session: { id: 'sess_call_app_smoke', token: 'sess_call_app_smoke', expires_at: '2030-01-01T00:00:00.000Z' },
          user: {
            id: 1,
            email: 'admin@example.test',
            display_name: 'Layout Admin',
            role: 'admin',
            status: 'active',
            time_format: '24h',
            date_format: 'dmy_dot',
            theme: 'dark',
            account_type: 'account',
            is_guest: false,
          },
        },
      });
      return;
    }

    if (url.pathname === `/api/calls/resolve/${roomId}`) {
      await route.fulfill({
        status: 200,
        headers: { ...corsHeaders(), 'content-type': 'application/json; charset=utf-8' },
        json: { status: 'ok', result: { state: 'resolved', resolved_as: 'call', call } },
      });
      return;
    }

    if (url.pathname === `/api/calls/${callId}`) {
      await route.fulfill({
        status: 200,
        headers: { ...corsHeaders(), 'content-type': 'application/json; charset=utf-8' },
        json: { status: 'ok', call },
      });
      return;
    }

    if (url.pathname === `/api/call-app-sessions/${sessionId}/launch-token`) {
      await route.fulfill({
        status: 200,
        headers: { ...corsHeaders(), 'content-type': 'application/json; charset=utf-8' },
        json: {
          status: 'ok',
          result: {
            launch_token: 'launch-token-call-app-fullscreen-smoke',
            launch_token_id: 'launch-token-id-call-app-fullscreen-smoke',
            expires_at: '2030-01-01T00:00:00.000Z',
            context: {
              grant_state: 'allowed',
              capabilities: [
                'call_apps.launch',
                'call_apps.crdt.read',
                'call_apps.crdt.append',
                'call_apps.crdt.replay',
                'call_apps.presence.publish',
              ],
              participant: { subject_type: 'user', actor_id: '1', display_name: 'Layout Admin' },
              app: { name: 'Whiteboard', category: 'collaboration', crdt_protocol: 'king.call_app.crdt.v1' },
            },
          },
        },
      });
      return;
    }

    if (url.pathname === `/api/call-app-sessions/${sessionId}/crdt/bootstrap`) {
      await route.fulfill({
        status: 200,
        headers: { ...corsHeaders(), 'content-type': 'application/json; charset=utf-8' },
        json: {
          status: 'ok',
          result: {
            grant_state: 'allowed',
            document: {
              document_id: 'document-call-app-fullscreen-smoke',
              schema_version: 'king.call_app.crdt.v1',
              snapshot: { kind: 'whiteboard.snapshot.v1', state: {} },
              snapshot_clock: 0,
              compacted_through_clock: 0,
              op_count: 0,
            },
            ops: [],
            replay_cursor: { after_clock: 0 },
          },
        },
      });
      return;
    }

    if (url.pathname === '/api/admin/users') {
      await route.fulfill({
        status: 200,
        headers: { ...corsHeaders(), 'content-type': 'application/json; charset=utf-8' },
        json: { status: 'ok', users: [], pagination: { page: 1, page_size: 10, total: 0, page_count: 1 } },
      });
      return;
    }

    await route.fulfill({
      status: 404,
      headers: { ...corsHeaders(), 'content-type': 'application/json; charset=utf-8' },
      json: { status: 'error', error: { code: 'not_found', message: `missing fixture: ${url.pathname}` } },
    });
  });
}

async function installFakeSocket(page) {
  await page.addInitScript(({ callIdValue, roomIdValue, session }) => {
    const listenersSymbol = Symbol('listeners');
    window.__callAppFullscreenSocketFrames = [];
    window.__callAppFullscreenSocketState = {
      layout: {
        call_id: callIdValue,
        room_id: roomIdValue,
        mode: 'call_app_workspace',
        strategy: 'manual_pinned',
        automation_paused: false,
        pinned_user_ids: [],
        selected_user_ids: [1, 2, 3, 4, 5],
        main_user_id: 1,
        selection: {
          main_user_id: 1,
          visible_user_ids: [1, 2, 3, 4, 5],
          mini_user_ids: [1, 2, 3, 4, 5],
          pinned_user_ids: [],
        },
      },
      participants: [
        { connection_id: 'conn-1', room_id: roomIdValue, user: { id: 1, display_name: 'Layout Admin', role: 'admin', call_role: 'owner' }, connected_at: '2026-04-29T01:00:00.000Z' },
        { connection_id: 'conn-2', room_id: roomIdValue, user: { id: 2, display_name: 'Ada Analyst', role: 'user', call_role: 'participant' }, connected_at: '2026-04-29T01:00:01.000Z' },
        { connection_id: 'conn-3', room_id: roomIdValue, user: { id: 3, display_name: 'Bert Builder', role: 'user', call_role: 'participant' }, connected_at: '2026-04-29T01:00:02.000Z' },
        { connection_id: 'conn-4', room_id: roomIdValue, user: { id: 4, display_name: 'Cora Caller', role: 'user', call_role: 'participant' }, connected_at: '2026-04-29T01:00:03.000Z' },
        { connection_id: 'conn-5', room_id: roomIdValue, user: { id: 5, display_name: 'Dina Designer', role: 'user', call_role: 'participant' }, connected_at: '2026-04-29T01:00:04.000Z' },
        { connection_id: 'conn-6', room_id: roomIdValue, user: { id: 6, display_name: 'Eli Engineer', role: 'user', call_role: 'participant' }, connected_at: '2026-04-29T01:00:05.000Z' },
      ],
      callApps: {
        active_sessions: [session],
        active_session_count: 1,
        has_active_session: true,
      },
    };

    function snapshotPayload(reason = 'requested') {
      return {
        type: 'room/snapshot',
        room_id: roomIdValue,
        participant_count: window.__callAppFullscreenSocketState.participants.length,
        participants: window.__callAppFullscreenSocketState.participants,
        viewer: { user_id: 1, role: 'admin', call_id: callIdValue, call_role: 'owner', can_moderate: true },
        layout: window.__callAppFullscreenSocketState.layout,
        call_apps: window.__callAppFullscreenSocketState.callApps,
        activity: [],
        reason,
        time: new Date().toISOString(),
      };
    }

    class FakeWebSocket {
      static CONNECTING = 0;
      static OPEN = 1;
      static CLOSING = 2;
      static CLOSED = 3;

      constructor(url) {
        this.url = url;
        this.readyState = FakeWebSocket.CONNECTING;
        this[listenersSymbol] = {};
        setTimeout(() => {
          this.readyState = FakeWebSocket.OPEN;
          this.dispatch('open', {});
          this.emit({
            type: 'system/welcome',
            active_room_id: roomIdValue,
            call_context: { user_id: 1, call_id: callIdValue, call_role: 'owner', can_moderate: true },
          });
        }, 0);
      }

      addEventListener(type, callback) {
        if (!this[listenersSymbol][type]) this[listenersSymbol][type] = [];
        this[listenersSymbol][type].push(callback);
        if (type === 'open' && this.readyState === FakeWebSocket.OPEN) {
          setTimeout(() => callback({}), 0);
        }
      }

      removeEventListener(type, callback) {
        this[listenersSymbol][type] = (this[listenersSymbol][type] || []).filter((row) => row !== callback);
      }

      dispatch(type, event) {
        for (const callback of this[listenersSymbol][type] || []) callback(event);
      }

      emit(payload) {
        this.dispatch('message', { data: JSON.stringify(payload) });
      }

      send(data) {
        const payload = JSON.parse(String(data || '{}'));
        window.__callAppFullscreenSocketFrames.push(payload);
        if (payload.type === 'room/snapshot/request') {
          setTimeout(() => this.emit(snapshotPayload('requested')), 0);
        }
      }

      close() {
        this.readyState = FakeWebSocket.CLOSED;
        this.dispatch('close', { code: 1000, reason: 'test_close' });
      }
    }

    window.WebSocket = FakeWebSocket;
    Object.defineProperty(navigator, 'mediaDevices', {
      configurable: true,
      value: {
        enumerateDevices: async () => [],
        getUserMedia: async () => new MediaStream(),
        addEventListener: () => {},
        removeEventListener: () => {},
      },
    });
  }, { callIdValue: callId, roomIdValue: roomId, session: activeCallAppSession() });
}

async function installSession(page) {
  await page.addInitScript(({ key }) => {
    localStorage.setItem(key, JSON.stringify({
      role: 'admin',
      displayName: 'Layout Admin',
      email: 'admin@example.test',
      userId: 1,
      sessionId: 'sess_call_app_smoke',
      sessionToken: 'sess_call_app_smoke',
      expiresAt: '2030-01-01T00:00:00.000Z',
    }));
  }, { key: sessionStorageKey });
}

async function openWorkspace(page, viewport) {
  await page.setViewportSize(viewport);
  await installApiRoutes(page);
  await installFakeSocket(page);
  await installSession(page);
  await page.goto(`/workspace/call/${roomId}`);
  await expect(page.locator('.workspace-stage.layout-call-app-workspace')).toBeVisible();
  await expect(page.locator('.call-app-workspace-host')).toBeVisible();
  await expect(page.locator('.call-app-workspace-frame')).toBeVisible();
}

async function layoutMetrics(page) {
  return page.evaluate(() => {
    function rect(selector) {
      const element = document.querySelector(selector);
      if (!element) return null;
      const box = element.getBoundingClientRect();
      return {
        x: box.x,
        y: box.y,
        width: box.width,
        height: box.height,
        top: box.top,
        right: box.right,
        bottom: box.bottom,
        left: box.left,
      };
    }

    function z(selector) {
      const element = document.querySelector(selector);
      if (!element) return 0;
      return Number(window.getComputedStyle(element).zIndex);
    }

    return {
      viewport: { width: window.innerWidth, height: window.innerHeight },
      host: rect('.call-app-workspace-host'),
      mini: rect('.call-app-workspace-mini-strip'),
      frameShell: rect('.call-app-workspace-frame-shell'),
      frame: rect('.call-app-workspace-frame'),
      overlay: rect('.workspace-video-fullscreen-overlay'),
      hostZ: z('.call-app-workspace-host'),
      miniZ: z('.call-app-workspace-mini-strip'),
      frameShellZ: z('.call-app-workspace-frame-shell'),
      overlayZ: document.querySelector('.workspace-video-fullscreen-overlay')
        ? z('.workspace-video-fullscreen-overlay')
        : 0,
    };
  });
}

for (const viewport of [
  { name: 'desktop', width: 1280, height: 720 },
  { name: 'mobile', width: 390, height: 844 },
]) {
  test(`Call App fullscreen keeps mini videos usable and below video fullscreen on ${viewport.name}`, async ({ page }) => {
    await openWorkspace(page, viewport);

    const miniTiles = page.locator('.call-app-workspace-mini-tile');
    await expect(miniTiles).toHaveCount(5);
    await expect(page.getByRole('button', { name: 'Open Call App fullscreen' })).toBeVisible();

    await page.getByRole('button', { name: 'Open Call App fullscreen' }).click();
    await expect(page.locator('.call-app-workspace-host.fullscreen')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Exit Call App fullscreen' })).toBeVisible();

    const fullscreen = await layoutMetrics(page);
    expect(fullscreen.host.x).toBeLessThanOrEqual(1);
    expect(fullscreen.host.y).toBeLessThanOrEqual(1);
    expect(fullscreen.host.width).toBeGreaterThanOrEqual(viewport.width - 1);
    expect(fullscreen.host.height).toBeGreaterThanOrEqual(viewport.height - 1);
    expect(fullscreen.mini.height).toBeGreaterThan(80);
    expect(fullscreen.frame.height).toBeGreaterThan(120);
    expect(fullscreen.mini.bottom).toBeLessThanOrEqual(fullscreen.frameShell.top + 1);
    expect(fullscreen.miniZ).toBeGreaterThan(fullscreen.frameShellZ);

    await miniTiles.first().dblclick();
    await expect(page.locator('.workspace-video-fullscreen-overlay')).toBeVisible();

    const videoFullscreen = await layoutMetrics(page);
    expect(videoFullscreen.overlay.x).toBeLessThanOrEqual(1);
    expect(videoFullscreen.overlay.y).toBeLessThanOrEqual(1);
    expect(videoFullscreen.overlay.width).toBeGreaterThanOrEqual(viewport.width - 1);
    expect(videoFullscreen.overlay.height).toBeGreaterThanOrEqual(viewport.height - 1);
    expect(videoFullscreen.overlayZ).toBeGreaterThan(fullscreen.hostZ);
  });
}
