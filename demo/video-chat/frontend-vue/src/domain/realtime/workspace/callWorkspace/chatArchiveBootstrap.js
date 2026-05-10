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

export function createCallWorkspaceChatArchiveBootstrap({
  callbacks = {},
  refs = {},
  options = {},
} = {}) {
  const {
    apiRequest,
    appendChatMessage,
    captureClientDiagnostic = () => {},
    ensureRoomBuckets = () => {},
  } = callbacks;
  const limit = positiveInt(options.limit, 80, 1, 100);
  const minIntervalMs = positiveInt(options.minIntervalMs, 1500, 0, 60_000);
  const lastLoadedAtByKey = new Map();
  let disposed = false;
  let inFlightKey = '';

  async function bootstrapChatArchive(reason = 'unspecified') {
    if (disposed || typeof apiRequest !== 'function' || typeof appendChatMessage !== 'function') {
      return false;
    }

    const callId = normalizeId(unrefValue(refs.activeCallId));
    const roomId = normalizeId(unrefValue(refs.activeRoomId));
    if (callId === '' || roomId === '') {
      return false;
    }

    const key = `${callId}:${roomId}`;
    const now = Date.now();
    if (inFlightKey === key) {
      return false;
    }
    if (now - Number(lastLoadedAtByKey.get(key) || 0) < minIntervalMs) {
      return false;
    }

    inFlightKey = key;
    lastLoadedAtByKey.set(key, now);
    try {
      const params = new URLSearchParams();
      params.set('room_id', roomId);
      params.set('tail', '1');
      params.set('limit', String(limit));
      const payload = await apiRequest(`/api/calls/${encodeURIComponent(callId)}/chat-archive?${params.toString()}`);
      const archive = payload?.result?.archive && typeof payload.result.archive === 'object' ? payload.result.archive : {};
      const messages = Array.isArray(archive.messages) ? archive.messages : [];
      const resolvedRoomId = archiveRoomId(archive, roomId);
      ensureRoomBuckets(resolvedRoomId);
      for (const message of messages) {
        appendChatMessage(normalizeArchiveMessage(message, resolvedRoomId));
      }
      captureClientDiagnostic({
        category: 'realtime',
        level: 'info',
        eventType: 'chat_archive_bootstrap_loaded',
        code: 'chat_archive_bootstrap_loaded',
        message: 'Call chat archive tail was loaded for workspace reload backfill.',
        payload: {
          call_id: callId,
          room_id: resolvedRoomId,
          reason: String(reason || 'unspecified'),
          message_count: messages.length,
          next_cursor: Number(archive?.pagination?.next_cursor || 0) || 0,
          has_next: Boolean(archive?.pagination?.has_next),
        },
      });
      return true;
    } catch (error) {
      captureClientDiagnostic({
        category: 'realtime',
        level: 'warning',
        eventType: 'chat_archive_bootstrap_failed',
        code: 'chat_archive_bootstrap_failed',
        message: error instanceof Error ? error.message : 'Call chat archive bootstrap failed.',
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
      if (inFlightKey === key) {
        inFlightKey = '';
      }
    }
  }

  function dispose() {
    disposed = true;
    inFlightKey = '';
    lastLoadedAtByKey.clear();
  }

  return {
    bootstrapChatArchive,
    dispose,
  };
}
