import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const backendOrigin = process.env.VITE_VIDEOCHAT_BACKEND_ORIGIN || 'http://127.0.0.1:18080';
export const sessionStorageKey = 'ii_videocall_v1_session';
export const matrixRoomId = 'room-matrix-ui';
export const matrixCallId = 'call-matrix-ui';
export const matrixCallRef = 'room-matrix-ui';

const helperDir = path.dirname(fileURLToPath(import.meta.url));
const fixturesRoot = path.resolve(helperDir, '../../fixtures/chat');
const generatedFixtures = new Map([
  ['allowed/notes.md', Buffer.from('# Chat notes\n\n- first item\n- second item\n', 'utf8')],
]);

export const matrixUsers = {
  admin: {
    id: 1,
    email: 'matrix-admin@example.test',
    displayName: 'Layout Admin',
    role: 'admin',
    callRole: 'owner',
    sessionId: 'sess_matrix_admin',
    sessionToken: 'sess_matrix_admin',
  },
  user: {
    id: 2,
    email: 'matrix-user@example.test',
    displayName: 'Active User',
    role: 'user',
    callRole: 'participant',
    sessionId: 'sess_matrix_user',
    sessionToken: 'sess_matrix_user',
  },
  outsider: {
    id: 9,
    email: 'matrix-outsider@example.test',
    displayName: 'Other User',
    role: 'user',
    callRole: 'none',
    sessionId: 'sess_matrix_outsider',
    sessionToken: 'sess_matrix_outsider',
  },
};

export const allowedFixtureNames = ['note.txt', 'table.csv', 'notes.md', 'brief.pdf', 'brief.docx', 'sheet.xlsx', 'notes.odt', 'screen.png', 'photo.jpg', 'still.webp'];
export const blockedFixtureNames = ['malware.exe', 'script.sh', 'blob.bin', 'renamed-binary.pdf'];

const mimeByExtension = {
  txt: 'text/plain',
  csv: 'text/csv',
  md: 'text/markdown',
  pdf: 'application/pdf',
  docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  odt: 'application/vnd.oasis.opendocument.text',
  png: 'image/png',
  jpg: 'image/jpeg',
  jpeg: 'image/jpeg',
  webp: 'image/webp',
  exe: 'application/x-msdownload',
  sh: 'application/x-sh',
  bin: 'application/octet-stream',
};

export function fixturePath(group, name) {
  return path.join(fixturesRoot, group, name);
}

function fixtureKey(group, name) {
  return `${group}/${name}`;
}

function fixtureBuffer(group, name) {
  const generated = generatedFixtures.get(fixtureKey(group, name));
  if (generated) return generated;

  return fs.readFileSync(fixturePath(group, name));
}

export function fixtureExists(group, name) {
  if (generatedFixtures.has(fixtureKey(group, name))) return true;

  return fs.existsSync(fixturePath(group, name));
}

export function fixtureMime(name) {
  const extension = String(name || '').split('.').pop().toLowerCase();
  return mimeByExtension[extension] || 'application/octet-stream';
}

export function fixtureFileSpec(group, name, overrideName = name) {
  return {
    name: overrideName,
    mimeType: fixtureMime(overrideName),
    buffer: fixtureBuffer(group, name),
  };
}

export function fixtureBrowserPayload(group, name, overrideName = name) {
  const buffer = fixtureBuffer(group, name);
  return {
    name: overrideName,
    type: fixtureMime(overrideName),
    base64: buffer.toString('base64'),
  };
}

export function storedSessionFor(user) {
  return {
    role: user.role,
    displayName: user.displayName,
    email: user.email,
    userId: user.id,
    sessionId: user.sessionId,
    sessionToken: user.sessionToken,
    expiresAt: '2030-01-01T00:00:00.000Z',
  };
}

export async function bootstrapStoredSession(context, user) {
  await context.addInitScript(
    ({ key, value }) => {
      localStorage.setItem(key, JSON.stringify(value));
    },
    { key: sessionStorageKey, value: storedSessionFor(user) },
  );
}

export function corsHeaders() {
  return {
    'access-control-allow-origin': '*',
    'access-control-allow-credentials': 'true',
    'access-control-allow-headers': 'content-type, authorization, x-session-id',
    'access-control-allow-methods': 'GET, POST, PATCH, DELETE, OPTIONS',
    'access-control-expose-headers': 'content-disposition, content-type',
    'access-control-max-age': '86400',
  };
}

function parseJsonBody(request) {
  const raw = request.postData() || '';
  if (raw.trim() === '') return {};
  try {
    const parsed = JSON.parse(raw);
    return parsed && typeof parsed === 'object' ? parsed : {};
  } catch {
    return {};
  }
}

function fixtureCall() {
  const participants = [matrixUsers.admin, matrixUsers.user].map((user) => ({
    user_id: user.id,
    display_name: user.displayName,
    email: user.email,
    call_role: user.callRole,
    invite_state: 'allowed',
    joined_at: '2026-04-19T12:00:00.000Z',
  }));

  return {
    id: matrixCallId,
    room_id: matrixRoomId,
    title: 'Matrix UI Call',
    status: 'active',
    starts_at: '2026-04-19T12:00:00.000Z',
    ends_at: '2026-04-19T13:00:00.000Z',
    owner: {
      user_id: matrixUsers.admin.id,
      display_name: matrixUsers.admin.displayName,
      email: matrixUsers.admin.email,
    },
    participants: {
      total: participants.length,
      internal: participants,
      external: [],
    },
    my_participation: {
      call_role: 'participant',
      invite_state: 'allowed',
    },
  };
}

function attachmentKind(name) {
  const extension = String(name || '').split('.').pop().toLowerCase();
  if (['jpg', 'jpeg', 'png', 'webp', 'gif'].includes(extension)) return 'image';
  if (['txt', 'csv', 'md'].includes(extension)) return 'text';
  if (extension === 'pdf') return 'pdf';
  return 'document';
}

function attachmentExtension(name) {
  return String(name || '').split('.').pop().toLowerCase();
}

function isMatrixParticipant(user) {
  return user.id === matrixUsers.admin.id || user.id === matrixUsers.user.id;
}

export async function installMatrixApiRoutes(context, user) {
  let attachmentSeq = 0;
  const uploaded = new Map();

  await context.route('**/api/**', async (route) => {
    const request = route.request();
    if (request.method() === 'OPTIONS') {
      await route.fulfill({ status: 204, headers: corsHeaders() });
      return;
    }

    const url = new URL(request.url());
    const call = fixtureCall();

    if (url.pathname === '/api/auth/session-state' || url.pathname === '/api/auth/session') {
      await route.fulfill({
        status: 200,
        headers: { ...corsHeaders(), 'content-type': 'application/json; charset=utf-8' },
        json: {
          status: 'ok',
          result: { state: 'authenticated' },
          session: {
            id: user.sessionId,
            token: user.sessionToken,
            expires_at: '2030-01-01T00:00:00.000Z',
          },
          user: {
            id: user.id,
            email: user.email,
            display_name: user.displayName,
            role: user.role,
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

    if (url.pathname === `/api/calls/resolve/${matrixCallRef}`) {
      await route.fulfill({
        status: 200,
        headers: { ...corsHeaders(), 'content-type': 'application/json; charset=utf-8' },
        json: { status: 'ok', result: { state: 'resolved', resolved_as: 'call', call } },
      });
      return;
    }

    if (url.pathname === `/api/calls/${matrixCallId}` && request.method() === 'GET') {
      await route.fulfill({
        status: 200,
        headers: { ...corsHeaders(), 'content-type': 'application/json; charset=utf-8' },
        json: { status: 'ok', call },
      });
      return;
    }

    if (url.pathname === '/api/admin/users') {
      await route.fulfill({
        status: user.role === 'admin' ? 200 : 403,
        headers: { ...corsHeaders(), 'content-type': 'application/json; charset=utf-8' },
        json: user.role === 'admin'
          ? { status: 'ok', users: [], pagination: { page: 1, page_size: 10, total: 0, page_count: 1 } }
          : { status: 'error', error: { code: 'forbidden', message: 'Admin role required.' } },
      });
      return;
    }

    if (url.pathname === `/api/calls/${matrixCallId}/chat/attachments` && request.method() === 'POST') {
      const body = parseJsonBody(request);
      const name = String(body.file_name || 'attachment.txt').trim();
      const binary = Buffer.from(String(body.content_base64 || ''), 'base64');
      if (name === 'renamed-binary.pdf' || binary.subarray(0, 2).toString('latin1') === 'MZ') {
        await route.fulfill({
          status: 400,
          headers: { ...corsHeaders(), 'content-type': 'application/json; charset=utf-8' },
          json: { status: 'error', error: { code: 'attachment_type_not_allowed', message: 'Attachment binary type is not allowed.' } },
        });
        return;
      }

      attachmentSeq += 1;
      const extension = attachmentExtension(name);
      const attachment = {
        id: `att-${user.id}-${attachmentSeq}`,
        name,
        content_type: fixtureMime(name),
        size_bytes: binary.length,
        kind: attachmentKind(name),
        extension,
        download_url: `/api/calls/${matrixCallId}/chat/attachments/att-${user.id}-${attachmentSeq}`,
      };
      uploaded.set(attachment.id, { ...attachment, binary });
      await route.fulfill({
        status: 200,
        headers: { ...corsHeaders(), 'content-type': 'application/json; charset=utf-8' },
        json: { status: 'ok', result: { attachment } },
      });
      return;
    }

    const attachmentMatch = url.pathname.match(new RegExp(`^/api/calls/${matrixCallId}/chat/attachments/([^/]+)$`));
    if (attachmentMatch) {
      if (!isMatrixParticipant(user)) {
        await route.fulfill({
          status: 403,
          headers: { ...corsHeaders(), 'content-type': 'application/json; charset=utf-8' },
          json: { status: 'error', error: { code: 'forbidden', message: 'Attachment download access denied.' } },
        });
        return;
      }
      const attachmentId = decodeURIComponent(attachmentMatch[1]);
      const row = uploaded.get(attachmentId);
      if (request.method() === 'DELETE') {
        uploaded.delete(attachmentId);
        await route.fulfill({ status: 200, headers: { ...corsHeaders(), 'content-type': 'application/json; charset=utf-8' }, json: { status: 'ok' } });
        return;
      }
      if (request.method() === 'GET' && row) {
        await route.fulfill({
          status: 200,
          headers: {
            ...corsHeaders(),
            'content-type': row.content_type,
            'content-disposition': `attachment; filename="${row.name.replace(/"/g, '')}"`,
          },
          body: row.binary,
        });
        return;
      }
    }

    if (url.pathname === `/api/calls/${matrixCallId}/chat-archive`) {
      const isParticipant = isMatrixParticipant(user);
      if (!isParticipant) {
        await route.fulfill({
          status: 403,
          headers: { ...corsHeaders(), 'content-type': 'application/json; charset=utf-8' },
          json: { status: 'error', error: { code: 'forbidden', message: 'Call archive access denied.' } },
        });
        return;
      }
      await route.fulfill({
        status: 200,
        headers: { ...corsHeaders(), 'content-type': 'application/json; charset=utf-8' },
        json: {
          status: 'ok',
          result: {
            archive: {
              call_id: matrixCallId,
              room_id: matrixRoomId,
              read_only: true,
              messages: [],
              files: { groups: { images: [], pdfs: [], office: [], text: [], documents: [] } },
              pagination: { cursor: 0, limit: 50, returned: 0, has_next: false, next_cursor: null },
            },
          },
        },
      });
      return;
    }

    await route.fulfill({
      status: 404,
      headers: { ...corsHeaders(), 'content-type': 'application/json; charset=utf-8' },
      json: { status: 'error', error: { code: 'not_found', message: `missing matrix fixture route: ${url.pathname}` } },
    });
  });
}

export async function installFakeMediaAndRealtime(context, user) {
  await context.addInitScript(({ activeUser, roomId, callId }) => {
    const listenersSymbol = Symbol('listeners');
    const participants = [
      { connection_id: 'conn-admin', room_id: roomId, user: { id: 1, display_name: 'Layout Admin', role: 'admin', call_role: 'owner' }, connected_at: '2026-04-19T12:00:00.000Z' },
      { connection_id: 'conn-user', room_id: roomId, user: { id: 2, display_name: 'Active User', role: 'user', call_role: 'participant' }, connected_at: '2026-04-19T12:00:01.000Z' },
    ];

    window.__matrixSocketFrames = [];
    window.__matrixSocketEvents = [];
    window.__matrixAttachmentStore = {};
    window.__matrixLastChatMessage = null;
    window.__matrixSockets = [];
    window.__matrixActiveUser = activeUser;
    window.__matrixParticipants = participants;
    window.__matrixFakeMedia = { audioLevel: 0, motionScore: 0 };
    window.__matrixLayout = {
      call_id: callId,
      room_id: roomId,
      mode: 'main_mini',
      strategy: 'manual_pinned',
      automation_paused: false,
      pinned_user_ids: [],
      selected_user_ids: [1, 2],
      main_user_id: 1,
      selection: { main_user_id: 1, visible_user_ids: [1, 2], mini_user_ids: [2], pinned_user_ids: [] },
    };

    const nativeFetch = window.fetch.bind(window);
    window.fetch = async (...args) => {
      const response = await nativeFetch(...args);
      const url = String(args[0]?.url || args[0] || '');
      const method = String(args[1]?.method || 'GET').toUpperCase();
      if (method === 'POST' && url.includes('/chat/attachments')) {
        try {
          const payload = await response.clone().json();
          const attachment = payload?.result?.attachment;
          if (attachment?.id) window.__matrixAttachmentStore[attachment.id] = attachment;
        } catch {
          // Non-JSON error responses are handled by the app request path.
        }
      }
      return response;
    };

    Object.defineProperty(navigator, 'mediaDevices', {
      configurable: true,
      value: {
        enumerateDevices: async () => [
          { kind: 'audioinput', deviceId: 'fake-audio', label: 'Fake microphone' },
          { kind: 'videoinput', deviceId: 'fake-video', label: 'Fake camera' },
        ],
        getUserMedia: async () => new MediaStream(),
        addEventListener: () => {},
        removeEventListener: () => {},
      },
    });

    function snapshotPayload(reason = 'requested') {
      return {
        type: 'room/snapshot',
        room_id: roomId,
        participant_count: participants.length,
        participants,
        viewer: { user_id: activeUser.id, role: activeUser.role, call_id: callId, call_role: activeUser.callRole, can_moderate: activeUser.role === 'admin' || activeUser.callRole === 'owner' || activeUser.callRole === 'moderator' },
        layout: window.__matrixLayout,
        activity: [],
        reason,
        time: new Date().toISOString(),
      };
    }

    function dispatchToWorkspace(payload) {
      const handler = document.querySelector('.workspace-call-view')?.__vueParentComponent?.setupState?.handleSocketMessage;
      if (typeof handler === 'function') {
        handler({ data: JSON.stringify(payload) });
      }
    }

    function dispatchToOpenSockets(payload) {
      window.__matrixSocketEvents.push(payload);
      dispatchToWorkspace(payload);
      for (const socket of window.__matrixSockets) {
        if (socket.readyState === FakeWebSocket.OPEN && typeof socket.emit === 'function') {
          socket.emit(payload);
        }
      }
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
        window.__matrixSockets.push(this);
        setTimeout(() => {
          this.readyState = FakeWebSocket.OPEN;
          this.dispatch('open', {});
        }, 0);
      }

      addEventListener(type, callback) {
        if (!this[listenersSymbol][type]) this[listenersSymbol][type] = [];
        this[listenersSymbol][type].push(callback);
        if (type === 'open' && this.readyState === FakeWebSocket.OPEN) setTimeout(() => callback({}), 0);
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
        let payload = null;
        try {
          payload = JSON.parse(String(data || '{}'));
        } catch {
          window.__matrixSocketFrames.push({ type: 'binary_or_invalid' });
          return;
        }
        window.__matrixSocketFrames.push(payload);
        if (payload.type === 'room/snapshot/request' || payload.type === 'room/join') {
          setTimeout(() => {
            const snapshot = snapshotPayload(payload.type);
            this.emit(snapshot);
            dispatchToWorkspace(snapshot);
          }, 0);
          return;
        }
        if (payload.type === 'chat/send') {
          const refs = Array.isArray(payload.attachments) ? payload.attachments : [];
          const attachments = refs
            .map((ref) => window.__matrixAttachmentStore[String(ref?.id || '')])
            .filter(Boolean);
          const message = {
            id: `chat-${Date.now()}`,
            text: String(payload.message || ''),
            sender: { user_id: activeUser.id, display_name: activeUser.displayName, role: activeUser.role },
            server_time: new Date().toISOString(),
            client_message_id: payload.client_message_id || null,
            attachments,
          };
          const event = { type: 'chat/message', room_id: roomId, message, time: new Date().toISOString() };
          window.__matrixLastChatMessage = event;
          setTimeout(() => {
            this.emit(event);
            dispatchToWorkspace(event);
          }, 0);
          return;
        }
        if (payload.type === 'layout/mode') {
          window.__matrixLayout = { ...window.__matrixLayout, mode: payload.mode };
          setTimeout(() => {
            const event = { type: 'layout/mode', room_id: roomId, call_id: callId, layout: window.__matrixLayout };
            this.emit(event);
            dispatchToWorkspace(event);
          }, 0);
          return;
        }
        if (payload.type === 'layout/strategy') {
          window.__matrixLayout = { ...window.__matrixLayout, strategy: payload.strategy || window.__matrixLayout.strategy, automation_paused: Boolean(payload.automation_paused) };
          setTimeout(() => {
            const event = { type: 'layout/strategy', room_id: roomId, call_id: callId, layout: window.__matrixLayout };
            this.emit(event);
            dispatchToWorkspace(event);
          }, 0);
          return;
        }
        if (payload.type === 'layout/selection') {
          const pinned = Array.isArray(payload.pinned_user_ids) ? payload.pinned_user_ids : [];
          window.__matrixLayout = { ...window.__matrixLayout, pinned_user_ids: pinned, selected_user_ids: payload.selected_user_ids || [1, 2], main_user_id: payload.main_user_id || 0, selection: { ...window.__matrixLayout.selection, pinned_user_ids: pinned } };
          setTimeout(() => {
            const event = { type: 'layout/selection', room_id: roomId, call_id: callId, layout: window.__matrixLayout };
            this.emit(event);
            dispatchToWorkspace(event);
          }, 0);
        }
      }

      close() {
        this.readyState = FakeWebSocket.CLOSED;
        this.dispatch('close', { code: 1000, reason: 'test_close' });
      }
    }

    window.__matrixEmit = dispatchToOpenSockets;
    window.__matrixCreateOpenSocket = () => {
      const socket = new FakeWebSocket(`ws://matrix.local/ws?room=${encodeURIComponent(roomId)}`);
      socket.readyState = FakeWebSocket.OPEN;
      return socket;
    };
    window.__matrixEmitActivity = (userId, score) => dispatchToOpenSockets({
      type: 'participant/activity',
      room_id: roomId,
      call_id: callId,
      activity: { user_id: userId, score, score_2s: score, score_5s: Math.max(0, score - 5), score_15s: Math.max(0, score - 10), is_speaking: score >= 50, updated_at_ms: Date.now() },
      participant: { user_id: userId, display_name: userId === 1 ? 'Layout Admin' : 'Active User' },
    });
    window.__matrixSetFakeMedia = (audioLevel, motionScore) => {
      window.__matrixFakeMedia = { audioLevel: Number(audioLevel) || 0, motionScore: Number(motionScore) || 0 };
    };
    window.WebSocket = FakeWebSocket;
  }, { activeUser: user, roomId: matrixRoomId, callId: matrixCallId });
}

export async function createMatrixPage(browser, baseURL, user) {
  const context = await browser.newContext({ baseURL, permissions: ['camera', 'microphone'] });
  await bootstrapStoredSession(context, user);
  await installMatrixApiRoutes(context, user);
  await installFakeMediaAndRealtime(context, user);
  const page = await context.newPage();
  return { context, page };
}

export async function openMatrixWorkspace(page) {
  await page.goto(`/workspace/call/${matrixCallRef}`);
  await page.waitForSelector('.workspace-call-view');
  await page.evaluate(({ roomId, callId }) => {
    const setup = document.querySelector('.workspace-call-view')?.__vueParentComponent?.setupState;
    if (!setup) throw new Error('Call workspace setup state is not available.');
    setup.connectionState = 'online';
    setup.connectionReason = 'ready';
    setup.serverRoomId = roomId;
    if ('activeCallId' in setup) setup.activeCallId = callId;
    if ('viewerCallRole' in setup) setup.viewerCallRole = window.__matrixActiveUser?.callRole || 'participant';
    setup.participantsRaw = Array.isArray(window.__matrixParticipants) ? window.__matrixParticipants : [];
    setup.socketRef = typeof window.__matrixCreateOpenSocket === 'function' ? window.__matrixCreateOpenSocket() : setup.socketRef;

    setup.callLayoutState.call_id = callId;
    setup.callLayoutState.room_id = roomId;
    setup.callLayoutState.mode = 'main_mini';
    setup.callLayoutState.strategy = 'manual_pinned';
    setup.callLayoutState.automation_paused = false;
    setup.callLayoutState.main_user_id = 1;
    setup.callLayoutState.pinned_user_ids.splice(0, setup.callLayoutState.pinned_user_ids.length);
    setup.callLayoutState.selected_user_ids.splice(0, setup.callLayoutState.selected_user_ids.length, 1, 2);
    setup.callLayoutState.selection.main_user_id = 1;
    setup.callLayoutState.selection.visible_user_ids.splice(0, setup.callLayoutState.selection.visible_user_ids.length, 1, 2);
    setup.callLayoutState.selection.mini_user_ids.splice(0, setup.callLayoutState.selection.mini_user_ids.length, 2);
    setup.callLayoutState.selection.pinned_user_ids.splice(0, setup.callLayoutState.selection.pinned_user_ids.length);
  }, { roomId: matrixRoomId, callId: matrixCallId });
}

export async function openChatTab(page) {
  await page.getByRole('tab', { name: 'Chat' }).click();
}

export async function dropFilesOnChatComposer(page, filePayloads) {
  await page.locator('.workspace-chat-compose').evaluate((form, payloads) => {
    const transfer = new DataTransfer();
    for (const payload of payloads) {
      const binary = atob(payload.base64);
      const bytes = new Uint8Array(binary.length);
      for (let index = 0; index < binary.length; index += 1) bytes[index] = binary.charCodeAt(index);
      transfer.items.add(new File([bytes], payload.name, { type: payload.type }));
    }
    form.dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: transfer }));
  }, filePayloads);
}
