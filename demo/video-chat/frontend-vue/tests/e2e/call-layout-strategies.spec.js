import { test, expect } from '@playwright/test';

async function withCallLayoutHelpers(page, callbackSource) {
  await page.goto('/src/domain/realtime/layout/strategies.js', { waitUntil: 'domcontentloaded' });
  return page.evaluate(async (source) => {
    const helpers = await import('/src/domain/realtime/layout/strategies.js');
    const callback = new Function('helpers', `return (${source})(helpers);`);
    return callback(helpers);
  }, callbackSource.toString());
}

test('layout state normalization keeps only valid modes, strategies, and participant ids', async ({ page }) => {
  const result = await withCallLayoutHelpers(page, (helpers) => helpers.normalizeCallLayoutState({
    call_id: 'call-layout-ui',
    room_id: 'room-layout-ui',
    mode: 'grid',
    strategy: 'active_speaker_main',
    automation_paused: true,
    pinned_user_ids: [2, '2', 0, 'abc', 3],
    selected_user_ids: [3, '4', 3],
    selection: {
      main_user_id: '2',
      visible_user_ids: [2, 3, 4],
      mini_user_ids: [3, 4],
    },
  }));

  expect(result).toMatchObject({
    callId: 'call-layout-ui',
    roomId: 'room-layout-ui',
    mode: 'grid',
    strategy: 'active_speaker_main',
    automationPaused: true,
    pinnedUserIds: [2, 3],
    selectedUserIds: [3, 4],
  });
  expect(result.selection.mainUserId).toBe(2);
  expect(result.selection.visibleUserIds).toEqual([2, 3, 4]);
});

test('activity strategy moves the active participant into main video without exceeding visible limits', async ({ page }) => {
  const result = await withCallLayoutHelpers(page, (helpers) => {
    const participants = [
      { userId: 1, displayName: 'Owner', role: 'admin', callRole: 'owner' },
      { userId: 2, displayName: 'Quiet', role: 'user', callRole: 'participant' },
      { userId: 3, displayName: 'Speaker', role: 'user', callRole: 'participant' },
      { userId: 4, displayName: 'Mover', role: 'user', callRole: 'participant' },
      { userId: 5, displayName: 'Observer', role: 'user', callRole: 'participant' },
      { userId: 6, displayName: 'Overflow', role: 'user', callRole: 'participant' },
    ];
    return helpers.selectCallLayoutParticipants({
      participants,
      currentUserId: 1,
      pinnedUsers: {},
      activityByUserId: {
        3: { score2s: 88 },
        4: { score2s: 70 },
        2: { score2s: 12 },
      },
      layoutState: {
        mode: 'main_mini',
        strategy: 'active_speaker_main',
      },
      nowMs: 1_776_000_000_000,
    });
  });

  expect(result.mode).toBe('main_mini');
  expect(result.mainUserId).toBe(3);
  expect(result.visibleUserIds).toHaveLength(5);
  expect(result.visibleUserIds).toEqual([3, 4, 2, 1, 5]);
  expect(result.miniParticipants.map((row) => row.userId)).toEqual([4, 2, 1, 5]);
});

test('pinning wins over activity and grid mode is capped to eight visible videos', async ({ page }) => {
  const result = await withCallLayoutHelpers(page, (helpers) => {
    const participants = Array.from({ length: 10 }, (_, index) => ({
      userId: index + 1,
      displayName: `User ${index + 1}`,
      role: index === 0 ? 'admin' : 'user',
      callRole: index === 0 ? 'owner' : 'participant',
    }));
    const activityByUserId = Object.fromEntries(participants.map((row) => [row.userId, { score2s: row.userId * 10 }]));
    return helpers.selectCallLayoutParticipants({
      participants,
      currentUserId: 1,
      pinnedUsers: { 2: true },
      activityByUserId,
      layoutState: {
        mode: 'grid',
        strategy: 'most_active_window',
        pinned_user_ids: [2],
      },
      nowMs: 1_776_000_000_000,
    });
  });

  expect(result.mode).toBe('grid');
  expect(result.mainUserId).toBe(2);
  expect(result.visibleUserIds).toHaveLength(8);
  expect(result.visibleUserIds[0]).toBe(2);
  expect(result.gridParticipants.map((row) => row.userId)).toEqual([2, 10, 9, 8, 7, 6, 5, 4]);
});

test('paused automation keeps manual selection instead of replacing it with activity ranking', async ({ page }) => {
  const result = await withCallLayoutHelpers(page, (helpers) => helpers.selectCallLayoutParticipants({
    participants: [
      { userId: 1, displayName: 'Owner', role: 'admin', callRole: 'owner' },
      { userId: 2, displayName: 'Manual Main', role: 'user', callRole: 'participant' },
      { userId: 3, displayName: 'Loud Speaker', role: 'user', callRole: 'participant' },
    ],
    currentUserId: 1,
    activityByUserId: { 3: { score2s: 99 } },
    layoutState: {
      mode: 'main_only',
      strategy: 'active_speaker_main',
      automation_paused: true,
      selected_user_ids: [2, 1, 3],
      main_user_id: 2,
    },
    nowMs: 1_776_000_000_000,
  }));

  expect(result.mode).toBe('main_only');
  expect(result.automationPaused).toBe(true);
  expect(result.mainUserId).toBe(2);
  expect(result.visibleUserIds).toEqual([2]);
  expect(result.miniParticipants).toEqual([]);
});

const sessionStorageKey = 'ii_videocall_v1_session';

function corsHeaders() {
  return {
    'access-control-allow-origin': '*',
    'access-control-allow-credentials': 'true',
    'access-control-allow-headers': 'content-type, authorization, x-session-id',
    'access-control-allow-methods': 'GET, POST, PATCH, DELETE, OPTIONS',
    'access-control-max-age': '86400',
  };
}

async function installLayoutApiRoutes(page) {
  const call = {
    id: 'call-layout-ui',
    room_id: 'room-layout-ui',
    title: 'Layout UI Call',
    status: 'active',
    starts_at: '2026-04-19T12:00:00.000Z',
    ends_at: '2026-04-19T13:00:00.000Z',
    owner: {
      user_id: 1,
      display_name: 'Layout Admin',
      email: 'admin@example.test',
    },
    participants: [
      { user_id: 1, display_name: 'Layout Admin', email: 'admin@example.test', call_role: 'owner', invite_state: 'allowed', joined_at: '2026-04-19T12:00:00.000Z' },
      { user_id: 2, display_name: 'Active User', email: 'user@example.test', call_role: 'participant', invite_state: 'allowed', joined_at: '2026-04-19T12:00:01.000Z' },
    ],
  };

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
          session: { id: 'sess_layout_ui', token: 'sess_layout_ui', expires_at: '2030-01-01T00:00:00.000Z' },
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

    if (url.pathname === '/api/calls/resolve/room-layout-ui') {
      await route.fulfill({
        status: 200,
        headers: { ...corsHeaders(), 'content-type': 'application/json; charset=utf-8' },
        json: {
          status: 'ok',
          result: {
            state: 'resolved',
            resolved_as: 'call',
            call,
          },
        },
      });
      return;
    }

    if (url.pathname === '/api/calls/call-layout-ui') {
      await route.fulfill({
        status: 200,
        headers: { ...corsHeaders(), 'content-type': 'application/json; charset=utf-8' },
        json: { status: 'ok', call },
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

async function installFakeLayoutSocket(page, options = {}) {
  await page.addInitScript((socketOptions) => {
    const listenersSymbol = Symbol('listeners');
    const roomId = 'room-layout-ui';
    const callId = 'call-layout-ui';
    const defaultParticipants = [
      { connection_id: 'conn-admin', room_id: roomId, user: { id: 1, display_name: 'Layout Admin', role: 'admin', call_role: 'owner' }, connected_at: '2026-04-19T12:00:00.000Z' },
      { connection_id: 'conn-user', room_id: roomId, user: { id: 2, display_name: 'Active User', role: 'user', call_role: 'participant' }, connected_at: '2026-04-19T12:00:01.000Z' },
    ];
    const participants = Array.isArray(socketOptions?.participants) ? socketOptions.participants : defaultParticipants;
    window.__layoutSocketFrames = [];
    window.__layoutSocketState = {
      layout: {
        call_id: callId,
        room_id: roomId,
        mode: 'main_mini',
        strategy: 'manual_pinned',
        automation_paused: false,
        pinned_user_ids: [],
        selected_user_ids: [1, 2],
        main_user_id: 1,
        selection: {
          main_user_id: 1,
          visible_user_ids: [1, 2],
          mini_user_ids: [2],
          pinned_user_ids: [],
        },
      },
      participants,
    };
    window.__layoutSockets = [];

    function snapshotPayload(reason = 'requested') {
      return {
        type: 'room/snapshot',
        room_id: roomId,
        participant_count: window.__layoutSocketState.participants.length,
        participants: window.__layoutSocketState.participants,
        viewer: { user_id: 1, role: 'admin', call_id: callId, call_role: 'owner', can_moderate: true },
        layout: window.__layoutSocketState.layout,
        activity: [{ user_id: 2, score: 91, score_2s: 90, score_5s: 86, score_15s: 70, is_speaking: true, updated_at_ms: Date.now() }],
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
        window.__layoutSockets.push(this);
        if (String(url || '').includes('/ws') || String(url || '').includes('room=')) {
          window.__layoutSocket = this;
        }
        setTimeout(() => {
          this.readyState = FakeWebSocket.OPEN;
          this.dispatch('open', {});
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
        for (const callback of this[listenersSymbol][type] || []) {
          callback(event);
        }
      }

      emit(payload) {
        this.dispatch('message', { data: JSON.stringify(payload) });
      }

      send(data) {
        const payload = JSON.parse(String(data || '{}'));
        window.__layoutSocketFrames.push(payload);
        if (payload.type === 'room/snapshot/request') {
          setTimeout(() => this.emit(snapshotPayload('requested')), 0);
          return;
        }
        if (payload.type === 'layout/mode') {
          window.__layoutSocketState.layout = {
            ...window.__layoutSocketState.layout,
            mode: payload.mode,
            selection: {
              ...window.__layoutSocketState.layout.selection,
              visible_user_ids: payload.mode === 'main_only' ? [1] : [1, 2],
              mini_user_ids: payload.mode === 'main_mini' ? [2] : [],
            },
          };
          setTimeout(() => this.emit({ type: 'layout/mode', room_id: roomId, call_id: callId, layout: window.__layoutSocketState.layout }), 0);
          return;
        }
        if (payload.type === 'layout/strategy') {
          window.__layoutSocketState.layout = {
            ...window.__layoutSocketState.layout,
            strategy: payload.strategy || window.__layoutSocketState.layout.strategy,
            automation_paused: Boolean(payload.automation_paused),
          };
          setTimeout(() => this.emit({ type: 'layout/strategy', room_id: roomId, call_id: callId, layout: window.__layoutSocketState.layout }), 0);
          return;
        }
        if (payload.type === 'layout/selection') {
          window.__layoutSocketState.layout = {
            ...window.__layoutSocketState.layout,
            pinned_user_ids: payload.pinned_user_ids || [],
            selected_user_ids: payload.selected_user_ids || [],
            main_user_id: payload.main_user_id || 0,
          };
          setTimeout(() => this.emit({ type: 'layout/selection', room_id: roomId, call_id: callId, layout: window.__layoutSocketState.layout }), 0);
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
  }, options);
}

test('mobile admin switches layout strategy in the sidebar and moves the mini-video strip', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await installLayoutApiRoutes(page);
  await installFakeLayoutSocket(page);
  await page.addInitScript(
    ({ key }) => {
      localStorage.setItem(key, JSON.stringify({
        role: 'admin',
        displayName: 'Layout Admin',
        email: 'admin@example.test',
        userId: 1,
        sessionId: 'sess_layout_ui',
        sessionToken: 'sess_layout_ui',
        expiresAt: '2030-01-01T00:00:00.000Z',
      }));
    },
    { key: sessionStorageKey },
  );

  await page.goto('/workspace/call/room-layout-ui');
  const stage = page.locator('.workspace-stage');
  await expect(page.locator('.workspace-mini-strip')).toBeVisible();
  await expect(stage).toHaveClass(/has-mini-strip/);

  const miniStripToggle = page.getByRole('button', { name: 'Move mini videos above main video' });
  await expect(miniStripToggle).toBeVisible();
  await miniStripToggle.click();
  await expect(stage).toHaveClass(/mini-strip-above/);
  await expect(page.getByRole('button', { name: 'Move mini videos below main video' })).toBeVisible();

  const callSettings = page.locator('.call-left-owner-edit-block');
  if (!(await callSettings.isVisible())) {
    await page.getByRole('button', { name: 'Open left sidebar' }).click();
  }
  await expect(callSettings).toBeVisible();

  await callSettings.getByLabel('Video layout mode').selectOption('grid');
  await expect(page.locator('.workspace-stage.layout-grid')).toBeVisible();

  await callSettings.getByLabel('Activity strategy').selectOption('active_speaker_main');
  await expect(callSettings.getByLabel('Activity strategy')).toHaveValue('active_speaker_main');

  await callSettings.getByLabel('Video layout mode').selectOption('main_only');
  await expect(page.locator('.workspace-stage.layout-main-only')).toBeVisible();
});

test('call owner remains visible when the room snapshot has no participant rows yet', async ({ page }) => {
  await installLayoutApiRoutes(page);
  await installFakeLayoutSocket(page, { participants: [] });
  await page.addInitScript(
    ({ key }) => {
      localStorage.setItem(key, JSON.stringify({
        role: 'admin',
        displayName: 'Layout Admin',
        email: 'admin@example.test',
        userId: 1,
        sessionId: 'sess_layout_ui',
        sessionToken: 'sess_layout_ui',
        expiresAt: '2030-01-01T00:00:00.000Z',
      }));
    },
    { key: sessionStorageKey },
  );

  await page.goto('/workspace/call/room-layout-ui');
  await expect(page.locator('.user-list-empty')).toHaveCount(0);
  await expect(page.locator('.user-row.self .user-name')).toHaveText('Layout Admin');
});
