import {
  resolveBackendWebSocketOriginCandidates,
  setBackendWebSocketOrigin,
} from './backendOrigin';

const RECONNECT_DELAYS_MS = [500, 1000, 2000, 3000, 5000];
const MAX_PENDING_PUBLISHES = 20;

function normalizeTopic(value) {
  const topic = String(value || '').trim().toLowerCase();
  if (topic === 'calls' || topic === 'users' || topic === 'overview') {
    return topic;
  }
  return 'all';
}

function normalizeReason(value) {
  const reason = String(value || '').trim();
  if (reason === '') return 'updated';
  return reason.slice(0, 80);
}

function socketUrlForOrigin(origin, sessionToken) {
  const rawOrigin = String(origin || '').trim();
  if (rawOrigin === '') return null;

  try {
    const url = new URL(rawOrigin);
    url.protocol = url.protocol === 'https:' ? 'wss:' : 'ws:';
    url.pathname = '/ws';
    url.search = '';
    url.searchParams.set('room', 'lobby');
    const token = String(sessionToken || '').trim();
    if (token !== '') {
      url.searchParams.set('session', token);
    }
    return url.toString();
  } catch {
    return null;
  }
}

function socketIsOpen(socket) {
  return socket instanceof WebSocket && socket.readyState === WebSocket.OPEN;
}

/**
 * @param {{
 *   getSessionToken: () => string,
 *   onSync?: (event: any) => void,
 *   onStateChange?: (state: string, reason: string) => void,
 *   onOpen?: () => void
 * }} options
 */
export function createAdminSyncSocket(options = {}) {
  const getSessionToken = typeof options.getSessionToken === 'function'
    ? options.getSessionToken
    : (() => '');
  const onSync = typeof options.onSync === 'function' ? options.onSync : (() => {});
  const onStateChange = typeof options.onStateChange === 'function'
    ? options.onStateChange
    : (() => {});
  const onOpen = typeof options.onOpen === 'function' ? options.onOpen : (() => {});

  /** @type {WebSocket|null} */
  let socket = null;
  let reconnectTimer = 0;
  let connectGeneration = 0;
  let reconnectAttempt = 0;
  let manuallyClosed = false;
  const pendingPublishes = [];

  function emitState(state, reason) {
    onStateChange(String(state || ''), String(reason || ''));
  }

  function clearReconnectTimer() {
    if (reconnectTimer > 0) {
      window.clearTimeout(reconnectTimer);
      reconnectTimer = 0;
    }
  }

  function enqueuePublish(payload) {
    pendingPublishes.push(payload);
    while (pendingPublishes.length > MAX_PENDING_PUBLISHES) {
      pendingPublishes.shift();
    }
  }

  function flushPendingPublishes() {
    if (!socketIsOpen(socket) || pendingPublishes.length === 0) return;

    while (pendingPublishes.length > 0) {
      const payload = pendingPublishes.shift();
      try {
        socket.send(JSON.stringify(payload));
      } catch {
        if (payload && typeof payload === 'object') {
          enqueuePublish(payload);
        }
        break;
      }
    }
  }

  function scheduleReconnect() {
    clearReconnectTimer();
    if (manuallyClosed) return;

    reconnectAttempt += 1;
    const delay = RECONNECT_DELAYS_MS[Math.min(reconnectAttempt - 1, RECONNECT_DELAYS_MS.length - 1)];
    emitState('retrying', 'network_retry');
    reconnectTimer = window.setTimeout(() => {
      void connect();
    }, delay);
  }

  function connectWithOriginAt(candidates, originIndex, generation, sessionToken) {
    if (generation !== connectGeneration || manuallyClosed) return;

    if (originIndex >= candidates.length) {
      emitState('offline', 'socket_unreachable');
      scheduleReconnect();
      return;
    }

    const socketOrigin = candidates[originIndex];
    const wsUrl = socketUrlForOrigin(socketOrigin, sessionToken);
    if (!wsUrl) {
      connectWithOriginAt(candidates, originIndex + 1, generation, sessionToken);
      return;
    }

    const ws = new WebSocket(wsUrl);
    socket = ws;
    let opened = false;
    let failedOver = false;

    const failOverToNextOrigin = () => {
      if (failedOver) return;
      failedOver = true;
      if (socket === ws) {
        socket = null;
      }
      try {
        ws.close(1000, 'failover');
      } catch {
        // ignore
      }
      connectWithOriginAt(candidates, originIndex + 1, generation, sessionToken);
    };

    ws.addEventListener('open', () => {
      if (generation !== connectGeneration || manuallyClosed) {
        try {
          ws.close(1000, 'stale_connect');
        } catch {
          // ignore
        }
        return;
      }

      opened = true;
      reconnectAttempt = 0;
      setBackendWebSocketOrigin(socketOrigin);
      emitState('online', 'ready');
      onOpen();
      flushPendingPublishes();
    });

    ws.addEventListener('message', (event) => {
      let payload = null;
      try {
        payload = JSON.parse(event.data);
      } catch {
        payload = null;
      }
      if (!payload || typeof payload !== 'object') return;
      if (String(payload.type || '').trim().toLowerCase() !== 'admin/sync') return;

      onSync(payload);
    });

    ws.addEventListener('error', () => {
      if (generation !== connectGeneration || manuallyClosed) return;
      if (!opened) {
        failOverToNextOrigin();
        return;
      }
      emitState('offline', 'socket_error');
    });

    ws.addEventListener('close', (event) => {
      if (generation !== connectGeneration) return;
      if (socket === ws) {
        socket = null;
      }
      clearReconnectTimer();

      if (manuallyClosed) {
        emitState('idle', 'closed');
        return;
      }

      const closeReason = String(event?.reason || '').trim().toLowerCase();
      const closeCode = Number(event?.code || 0);
      if (closeReason === 'session_invalidated' || closeCode === 1008) {
        emitState('expired', closeReason || 'session_invalidated');
        return;
      }
      if (closeReason === 'auth_backend_error' || closeReason === 'role_not_allowed') {
        emitState('blocked', closeReason);
        return;
      }

      if (!opened) {
        failOverToNextOrigin();
        return;
      }

      emitState('offline', closeReason || 'socket_closed');
      scheduleReconnect();
    });
  }

  function connect() {
    const sessionToken = String(getSessionToken() || '').trim();
    if (sessionToken === '') {
      emitState('idle', 'missing_session');
      return false;
    }

    manuallyClosed = false;
    connectGeneration += 1;
    clearReconnectTimer();
    reconnectAttempt = 0;

    if (socket) {
      try {
        socket.close(1000, 'reconnect');
      } catch {
        // ignore
      }
      socket = null;
    }

    emitState('connecting', 'starting');
    const candidates = resolveBackendWebSocketOriginCandidates();
    connectWithOriginAt(candidates, 0, connectGeneration, sessionToken);
    return true;
  }

  function disconnect() {
    manuallyClosed = true;
    connectGeneration += 1;
    clearReconnectTimer();
    pendingPublishes.length = 0;
    if (socket) {
      try {
        socket.close(1000, 'manual_close');
      } catch {
        // ignore
      }
      socket = null;
    }
    emitState('idle', 'closed');
  }

  function publish(topic = 'all', reason = 'updated') {
    const payload = {
      type: 'admin/sync/publish',
      topic: normalizeTopic(topic),
      reason: normalizeReason(reason),
    };

    if (socketIsOpen(socket)) {
      try {
        socket.send(JSON.stringify(payload));
        return true;
      } catch {
        enqueuePublish(payload);
        scheduleReconnect();
        return false;
      }
    }

    enqueuePublish(payload);
    connect();
    return false;
  }

  return {
    connect,
    reconnect: connect,
    disconnect,
    publish,
    isOnline: () => socketIsOpen(socket),
  };
}
