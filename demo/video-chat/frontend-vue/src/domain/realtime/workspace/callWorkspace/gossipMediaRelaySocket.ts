function asArrayBuffer(payload: unknown): ArrayBuffer | null {
  if (payload instanceof ArrayBuffer) return payload;
  if (ArrayBuffer.isView(payload)) {
    return payload.buffer.slice(payload.byteOffset, payload.byteOffset + payload.byteLength);
  }
  return null;
}

function relayKey(roomId: string, callId: string, userId: string): string {
  return `${roomId || 'lobby'}:${callId || 'call'}:${userId || '0'}`;
}

function closeQuietly(socket: WebSocket | null, code = 1000, reason = 'media_relay_teardown'): void {
  if (!(socket instanceof WebSocket)) return;
  try {
    socket.close(code, reason);
  } catch {
    // Socket teardown is best-effort during route changes and call leave.
  }
}

export function createGossipMediaRelaySocket({
  callbacks,
}: {
  callbacks: {
    activeCallId: () => string;
    activeRoomId: () => string;
    captureClientDiagnostic?: (entry: Record<string, unknown>) => void;
    currentUserId: () => string;
    handleGossipBinaryServerFrame: (payload: ArrayBuffer) => boolean;
    handleGossipServerFrame?: (payload: Record<string, unknown>) => boolean;
    mediaRelaySocketUrlForRoom: (roomId: string, socketOrigin: string, callId: string) => string | null;
    resolveBackendWebSocketOriginCandidates: () => string[];
    setBackendWebSocketOrigin?: (origin: string) => void;
  };
}) {
  const {
    activeCallId,
    activeRoomId,
    captureClientDiagnostic = () => undefined,
    currentUserId,
    handleGossipBinaryServerFrame,
    handleGossipServerFrame = () => false,
    mediaRelaySocketUrlForRoom,
    resolveBackendWebSocketOriginCandidates,
    setBackendWebSocketOrigin = () => undefined,
  } = callbacks;

  let socket: WebSocket | null = null;
  let socketKey = '';
  let connecting = false;
  let originIndex = 0;
  let generation = 0;
  let reconnectTimer: ReturnType<typeof setTimeout> | null = null;
  let reconnectAttempt = 0;
  const pendingFrames: ArrayBuffer[] = [];
  const maxPendingFrames = 24;
  const maxPendingBytes = 8 * 1024 * 1024;
  const maxReconnectDelayMs = 10_000;
  type RelaySendResult = {
    sent: boolean;
    queued: boolean;
  };

  function pendingBytes(): number {
    return pendingFrames.reduce((total, frame) => total + Number(frame.byteLength || 0), 0);
  }

  function diagnostic(eventType: string, level: string, payload: Record<string, unknown> = {}): void {
    captureClientDiagnostic({
      category: 'media',
      level,
      eventType,
      code: eventType,
      message: 'Gossip media relay socket state changed.',
      payload: {
        relay: 'gossip_media_binary',
        room_id: String(activeRoomId() || ''),
        call_id: String(activeCallId() || ''),
        current_user_id: String(currentUserId() || ''),
        pending_frame_count: pendingFrames.length,
        pending_bytes: pendingBytes(),
        ...payload,
      },
    });
  }

  function flushPendingFrames(): void {
    if (!(socket instanceof WebSocket) || socket.readyState !== WebSocket.OPEN) return;
    while (pendingFrames.length > 0 && socket.readyState === WebSocket.OPEN) {
      const frame = pendingFrames.shift();
      if (!(frame instanceof ArrayBuffer) || frame.byteLength <= 0) continue;
      socket.send(frame);
    }
  }

  function clearReconnectTimer(): void {
    if (reconnectTimer === null) return;
    clearTimeout(reconnectTimer);
    reconnectTimer = null;
  }

  function reconnectDelayMs(): number {
    const exponent = Math.min(5, Math.max(0, reconnectAttempt));
    return Math.min(maxReconnectDelayMs, 500 * Math.max(1, 2 ** exponent));
  }

  function scheduleReconnect(key: string, reason: string, payload: Record<string, unknown> = {}): boolean {
    if (socketKey !== key) return false;
    if (key.includes(':call:') || key.endsWith(':0')) return false;
    if (reconnectTimer !== null) return true;

    connecting = false;
    originIndex = 0;
    const scheduledGeneration = generation;
    const delayMs = reconnectDelayMs();
    reconnectAttempt += 1;
    diagnostic('gossip_media_relay_socket_reconnect_scheduled', 'warning', {
      reason: String(reason || 'socket_closed'),
      reconnect_attempt: reconnectAttempt,
      retry_delay_ms: delayMs,
      ...payload,
    });

    reconnectTimer = setTimeout(() => {
      reconnectTimer = null;
      if (scheduledGeneration !== generation || socketKey !== key) return;
      connectCurrentOrigin(key);
    }, delayMs);
    return true;
  }

  function parseRelayTextFrame(raw: string): boolean {
    let payload: Record<string, unknown> | null = null;
    try {
      const decoded = JSON.parse(raw);
      if (decoded && typeof decoded === 'object' && !Array.isArray(decoded)) {
        payload = decoded as Record<string, unknown>;
      }
    } catch {
      return false;
    }
    if (!payload) return false;
    const type = String(payload.type || '').trim().toLowerCase();
    if (type === 'call/gossip-server-frame') {
      return handleGossipServerFrame(payload);
    }
    return false;
  }

  function connectCurrentOrigin(key: string): boolean {
    const origins = resolveBackendWebSocketOriginCandidates();
    if (!Array.isArray(origins) || originIndex >= origins.length) {
      if (Array.isArray(origins) && origins.length > 0) {
        return scheduleReconnect(key, 'origin_candidates_exhausted', {
          origin_count: origins.length,
        });
      }
      diagnostic('gossip_media_relay_socket_unavailable', 'warning', {
        origin_count: Array.isArray(origins) ? origins.length : 0,
      });
      connecting = false;
      return false;
    }

    const origin = String(origins[originIndex] || '').trim();
    const url = mediaRelaySocketUrlForRoom(activeRoomId(), origin, activeCallId());
    if (!url) {
      originIndex += 1;
      return connectCurrentOrigin(key);
    }

    connecting = true;
    const connectGeneration = generation;
    let nextSocket: WebSocket;
    try {
      nextSocket = new WebSocket(url);
    } catch (error) {
      connecting = false;
      originIndex += 1;
      diagnostic('gossip_media_relay_socket_connect_failed', 'warning', {
        origin_index: originIndex - 1,
        error_name: error instanceof Error ? error.name : 'Error',
        error_message: error instanceof Error ? error.message : String(error || ''),
      });
      return connectCurrentOrigin(key);
    }
    nextSocket.binaryType = 'arraybuffer';
    socket = nextSocket;

    nextSocket.addEventListener('open', () => {
      if (connectGeneration !== generation || socket !== nextSocket || socketKey !== key) {
        closeQuietly(nextSocket, 1000, 'stale_media_relay');
        return;
      }
      clearReconnectTimer();
      connecting = false;
      reconnectAttempt = 0;
      setBackendWebSocketOrigin(origin);
      diagnostic('gossip_media_relay_socket_open', 'info', {
        origin_index: originIndex,
      });
      flushPendingFrames();
    });

    nextSocket.addEventListener('message', (event) => {
      if (connectGeneration !== generation || socket !== nextSocket) return;
      const binaryPayload = asArrayBuffer(event.data);
      if (binaryPayload instanceof ArrayBuffer && binaryPayload.byteLength > 0) {
        handleGossipBinaryServerFrame(binaryPayload);
        return;
      }
      if (typeof event.data === 'string') {
        parseRelayTextFrame(event.data);
      }
    });

    nextSocket.addEventListener('error', () => {
      if (connectGeneration !== generation || socket !== nextSocket) return;
      diagnostic('gossip_media_relay_socket_error', 'warning', {
        origin_index: originIndex,
      });
    });

    nextSocket.addEventListener('close', (event) => {
      if (connectGeneration !== generation || socket !== nextSocket) return;
      socket = null;
      connecting = false;
      originIndex += 1;
      const closePayload = {
        close_code: Number(event?.code || 0),
        close_reason: String(event?.reason || ''),
        origin_index: originIndex - 1,
        origin_count: origins.length,
      };
      if (pendingFrames.length > 0 && originIndex < origins.length) {
        diagnostic('gossip_media_relay_socket_retrying_next_origin', 'warning', closePayload);
        connectCurrentOrigin(key);
      } else if (pendingFrames.length > 0) {
        scheduleReconnect(key, 'socket_closed_with_pending_frames', closePayload);
      }
    });

    return true;
  }

  function ensureSocket(): boolean {
    const key = relayKey(
      String(activeRoomId() || '').trim(),
      String(activeCallId() || '').trim(),
      String(currentUserId() || '').trim(),
    );
    if (key.includes(':call:') || key.endsWith(':0')) return false;
    if (socketKey !== key) {
      clearReconnectTimer();
      closeQuietly(socket, 1000, 'media_relay_context_changed');
      socket = null;
      socketKey = key;
      connecting = false;
      originIndex = 0;
      reconnectAttempt = 0;
      generation += 1;
      pendingFrames.length = 0;
    }
    if (socket instanceof WebSocket && socket.readyState === WebSocket.OPEN) return true;
    if (connecting) return true;
    return connectCurrentOrigin(key);
  }

  function sendBinaryFrame(payload: unknown): RelaySendResult {
    const frame = asArrayBuffer(payload);
    if (!(frame instanceof ArrayBuffer) || frame.byteLength <= 0) {
      return { sent: false, queued: false };
    }
    if (!ensureSocket()) {
      return { sent: false, queued: false };
    }
    if (socket instanceof WebSocket && socket.readyState === WebSocket.OPEN) {
      try {
        socket.send(frame);
        return { sent: true, queued: false };
      } catch {
        return { sent: false, queued: false };
      }
    }
    if (
      pendingFrames.length >= maxPendingFrames
      || pendingBytes() + frame.byteLength > maxPendingBytes
    ) {
      diagnostic('gossip_media_relay_socket_pending_buffer_full', 'warning', {
        frame_bytes: frame.byteLength,
        max_pending_frames: maxPendingFrames,
        max_pending_bytes: maxPendingBytes,
      });
      return { sent: false, queued: false };
    }
    pendingFrames.push(frame);
    return { sent: false, queued: true };
  }

  function close(): void {
    generation += 1;
    clearReconnectTimer();
    pendingFrames.length = 0;
    connecting = false;
    socketKey = '';
    originIndex = 0;
    reconnectAttempt = 0;
    closeQuietly(socket);
    socket = null;
  }

  return {
    close,
    sendBinaryFrame,
  };
}
