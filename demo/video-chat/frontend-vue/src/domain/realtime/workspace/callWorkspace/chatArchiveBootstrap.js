function unrefValue(value) {
  if (value && typeof value === 'object' && 'value' in value) return value.value;
  if (typeof value === 'function') return value();
  return value;
}

function normalizeId(value) {
  return String(value || '').trim();
}

function positiveInt(value, fallback, min, max) {
  const parsed = Number(value);
  if (!Number.isFinite(parsed)) return fallback;
  return Math.max(min, Math.min(max, Math.floor(parsed)));
}

function archiveRoomId(archive, fallbackRoomId) {
  const filtersRoomId = normalizeId(archive?.filters?.room_id || archive?.filters?.roomId || '');
  if (filtersRoomId !== '') return filtersRoomId;
  const roomId = normalizeId(archive?.room_id || archive?.roomId || '');
  return roomId !== '' ? roomId : fallbackRoomId;
}

function normalizeArchiveMessage(row, roomId) {
  const message = row && typeof row === 'object' ? row : {};
  const sender = message.sender && typeof message.sender === 'object' ? message.sender : {};
  return {
    type: 'chat/message',
    room_id: roomId,
    source: 'chat_archive_bootstrap',
    history_backfill: true,
    message: {
      id: normalizeId(message.id),
      client_message_id: message.client_message_id ?? null,
      text: String(message.text || ''),
      attachments: Array.isArray(message.attachments) ? message.attachments : [],
      sender: {
        user_id: Number(sender.user_id || sender.userId || 0) || 0,
        display_name: String(sender.display_name || sender.displayName || '').trim(),
        role: String(sender.role || 'user').trim() || 'user',
      },
      server_unix_ms: Number(message.server_unix_ms || message.serverUnixMs || 0) || 0,
      server_time: String(message.server_time || message.serverTime || ''),
      seq: Number(message.seq || 0) || 0,
    },
    time: String(message.server_time || message.serverTime || ''),
  };
}

export function createCallWorkspaceChatHistorySyncState() {
  return {
    loading: false,
    loadingOlder: false,
    error: '',
    hasOlder: false,
    nextCursor: null,
    lastLoadedCount: 0,
    lastReason: '',
  };
}

export function createCallWorkspaceChatHistorySync({
  callbacks = {},
  refs = {},
  options = {},
  state = createCallWorkspaceChatHistorySyncState(),
} = {}) {
  const {
    apiRequest,
    appendChatMessage,
    captureClientDiagnostic = () => {},
    ensureRoomBuckets = () => {},
  } = callbacks;
  const initialLimit = positiveInt(options.initialLimit ?? options.limit, 50, 50, 100);
  const olderLimit = positiveInt(options.olderLimit ?? options.limit, 50, 1, 100);
  const minIntervalMs = positiveInt(options.minIntervalMs, 1500, 0, 60_000);
  const lastLoadedAtByKey = new Map();
  let disposed = false;
  let inFlightKey = '';

  async function loadHistoryPage({ reason = 'unspecified', cursor = null, limit = initialLimit, loadingKey = 'loading' } = {}) {
    if (disposed || typeof apiRequest !== 'function' || typeof appendChatMessage !== 'function') {
      return false;
    }

    const callId = normalizeId(unrefValue(refs.activeCallId));
    const roomId = normalizeId(unrefValue(refs.activeRoomId));
    if (callId === '' || roomId === '') {
      return false;
    }

    const key = `${callId}:${roomId}`;
    const pageCursor = positiveInt(cursor, 0, 0, Number.MAX_SAFE_INTEGER);
    const pageKey = `${key}:${pageCursor}`;
    if (inFlightKey === pageKey) {
      return false;
    }
    const now = Date.now();
    if (pageCursor === 0 && now - Number(lastLoadedAtByKey.get(key) || 0) < minIntervalMs) {
      return false;
    }

    inFlightKey = pageKey;
    if (pageCursor === 0) lastLoadedAtByKey.set(key, now);
    state[loadingKey] = true;
    state.error = '';
    try {
      const params = new URLSearchParams();
      params.set('room_id', roomId);
      params.set('tail', '1');
      params.set('limit', String(limit));
      if (pageCursor > 0) {
        params.set('cursor', String(pageCursor));
      }
      const payload = await apiRequest(`/api/calls/${encodeURIComponent(callId)}/chat-archive?${params.toString()}`);
      const archive = payload?.result?.archive && typeof payload.result.archive === 'object' ? payload.result.archive : {};
      const messages = Array.isArray(archive.messages) ? archive.messages : [];
      const resolvedRoomId = archiveRoomId(archive, roomId);
      ensureRoomBuckets(resolvedRoomId);
      for (const message of messages) {
        appendChatMessage(normalizeArchiveMessage(message, resolvedRoomId));
      }
      const pagination = archive?.pagination && typeof archive.pagination === 'object' ? archive.pagination : {};
      state.hasOlder = Boolean(pagination.has_next);
      state.nextCursor = state.hasOlder ? (Number(pagination.next_cursor || 0) || null) : null;
      state.lastLoadedCount = messages.length;
      state.lastReason = String(reason || 'unspecified');
      captureClientDiagnostic({
        category: 'realtime',
        level: 'info',
        eventType: 'chat_history_db_sync_loaded',
        code: 'chat_history_db_sync_loaded',
        message: 'Call chat history was synchronized from the database.',
        payload: {
          call_id: callId,
          room_id: resolvedRoomId,
          reason: String(reason || 'unspecified'),
          message_count: messages.length,
          cursor: pageCursor,
          limit,
          next_cursor: Number(pagination.next_cursor || 0) || 0,
          has_next: Boolean(pagination.has_next),
        },
      });
      return true;
    } catch (error) {
      state.error = error instanceof Error ? error.message : 'Call chat history sync failed.';
      captureClientDiagnostic({
        category: 'realtime',
        level: 'warning',
        eventType: 'chat_history_db_sync_failed',
        code: 'chat_history_db_sync_failed',
        message: state.error,
        payload: {
          call_id: callId,
          room_id: roomId,
          reason: String(reason || 'unspecified'),
          response_status: Number(error?.responseStatus || 0) || 0,
          response_code: String(error?.responseCode || '').trim(),
        },
      });
      return false;
    } finally {
      state[loadingKey] = false;
      if (inFlightKey === pageKey) {
        inFlightKey = '';
      }
    }
  }

  function bootstrapChatArchive(reason = 'unspecified') {
    return loadHistoryPage({
      reason,
      cursor: null,
      limit: initialLimit,
      loadingKey: 'loading',
    });
  }

  function loadOlderChatHistory(reason = 'older_requested') {
    if (!state.hasOlder || state.nextCursor === null) return Promise.resolve(false);
    return loadHistoryPage({
      reason,
      cursor: state.nextCursor,
      limit: olderLimit,
      loadingKey: 'loadingOlder',
    });
  }

  function dispose() {
    disposed = true;
    inFlightKey = '';
    lastLoadedAtByKey.clear();
  }

  return {
    bootstrapChatArchive,
    loadOlderChatHistory,
    state,
    dispose,
  };
}

export const createCallWorkspaceChatArchiveBootstrap = createCallWorkspaceChatHistorySync;
