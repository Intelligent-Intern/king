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
  const pendingFrames: ArrayBuffer[] = [];
  const maxPendingFrames = 24;
  const maxPendingBytes = 8 * 1024 * 1024;

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
    const nextSocket = new WebSocket(url);
    nextSocket.binaryType = 'arraybuffer';
    socket = nextSocket;

    nextSocket.addEventListener('open', () => {
      if (connectGeneration !== generation || socket !== nextSocket || socketKey !== key) {
        closeQuietly(nextSocket, 1000, 'stale_media_relay');
        return;
      }
      connecting = false;
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

    nextSocket.addEventListener('close', () => {
      if (connectGeneration !== generation || socket !== nextSocket) return;
      socket = null;
      connecting = false;
      originIndex += 1;
      if (pendingFrames.length > 0 && originIndex < origins.length) {
        connectCurrentOrigin(key);
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
      closeQuietly(socket, 1000, 'media_relay_context_changed');
      socket = null;
      socketKey = key;
      connecting = false;
      originIndex = 0;
      generation += 1;
      pendingFrames.length = 0;
    }
    if (socket instanceof WebSocket && socket.readyState === WebSocket.OPEN) return true;
    if (connecting) return true;
    return connectCurrentOrigin(key);
  }

  function sendBinaryFrame(payload: unknown): boolean {
    const frame = asArrayBuffer(payload);
    if (!(frame instanceof ArrayBuffer) || frame.byteLength <= 0) return false;
    if (!ensureSocket()) return false;
    if (socket instanceof WebSocket && socket.readyState === WebSocket.OPEN) {
      try {
        socket.send(frame);
        return true;
      } catch {
        return false;
      }
    }
    if (
      pendingFrames.length >= maxPendingFrames
      || pendingBytes() + frame.byteLength > maxPendingBytes
    ) {
      return false;
    }
    pendingFrames.push(frame);
    return true;
  }

  function close(): void {
    generation += 1;
    pendingFrames.length = 0;
    connecting = false;
    socketKey = '';
    closeQuietly(socket);
    socket = null;
  }

  return {
    close,
    sendBinaryFrame,
  };
}
