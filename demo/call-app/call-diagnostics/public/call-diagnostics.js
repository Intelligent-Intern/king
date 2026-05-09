(function () {
  'use strict';

  const APP_KEY = 'call-diagnostics';
  const BRIDGE_PROTOCOL = 'king.call_app.iframe.v1';
  const MAX_LOGS = 500;
  const MAX_DETAIL = 900;
  const MAX_PAUSED_LOGS = 250;
  const POLL_MS = 1800;

  const state = {
    appSessionId: '',
    actorId: '',
    displayName: '',
    capabilities: new Set(),
    permissionActions: new Set(),
    launched: false,
    paused: false,
    filter: 'all',
    search: '',
    latestClock: 0,
    logs: [],
    pausedLogs: [],
    staged: new Map(),
    appliedOps: new Set(),
    persistedEventIds: new Set(),
    pendingEventIds: new Set(),
    requestCounter: 0,
    pollTimer: null,
  };

  const nodes = {};

  function byId(id) {
    return document.getElementById(id);
  }

  function initNodes() {
    nodes.connectionBadge = byId('connectionBadge');
    nodes.pauseToggle = byId('pauseToggle');
    nodes.exportJson = byId('exportJson');
    nodes.tailSearch = byId('tailSearch');
    nodes.tailList = byId('tailList');
    nodes.tailShell = document.querySelector('.tail-shell');
    nodes.filterButtons = Array.from(document.querySelectorAll('.filter-button'));
    nodes.stageCards = new Map(Array.from(document.querySelectorAll('.stage-card')).map((card) => [card.dataset.stage, card]));
  }

  function safeString(value, fallback = '', maxLength = 240) {
    const normalized = String(value == null ? '' : value).trim();
    const result = normalized || fallback;
    return result.length <= maxLength ? result : result.slice(0, maxLength);
  }

  function safeIdentifier(value, fallback = '') {
    return safeString(value, fallback, 96).toLowerCase().replace(/[^a-z0-9._:-]+/g, '_').replace(/^[_:.-]+|[_:.-]+$/g, '') || fallback;
  }

  function isPlainObject(value) {
    return value && typeof value === 'object' && !Array.isArray(value);
  }

  function summarizePayload(value, depth = 0) {
    if (depth >= 3) return '[depth]';
    if (value == null || typeof value === 'number' || typeof value === 'boolean') return value;
    if (typeof value === 'string') return safeString(value, '', MAX_DETAIL);
    if (Array.isArray(value)) return value.slice(0, 16).map((entry) => summarizePayload(entry, depth + 1));
    if (typeof value === 'object') {
      const result = {};
      let count = 0;
      for (const [key, entry] of Object.entries(value)) {
        const normalizedKey = safeIdentifier(key, 'field');
        if (/token|authorization|secret|password|credential|cookie/i.test(normalizedKey)) continue;
        if (count >= 20) {
          result.__truncated__ = true;
          break;
        }
        result[normalizedKey] = summarizePayload(entry, depth + 1);
        count += 1;
      }
      return result;
    }
    return safeString(value, '', 240);
  }

  function payloadText(payload) {
    if (!payload || !isPlainObject(payload)) return '';
    try {
      return safeString(JSON.stringify(summarizePayload(payload)), '', MAX_DETAIL);
    } catch {
      return '';
    }
  }

  function payloadPrettyText(payload) {
    if (!payload || !isPlainObject(payload)) return '';
    try {
      return safeString(JSON.stringify(summarizePayload(payload), null, 2), '', MAX_DETAIL * 2);
    } catch {
      return '';
    }
  }

  function post(type, payload = {}) {
    if (!state.appSessionId) return;
    window.parent.postMessage({
      type,
      bridge_protocol: BRIDGE_PROTOCOL,
      app_session_id: state.appSessionId,
      app_key: APP_KEY,
      ...payload,
    }, '*');
  }

  function requestId(prefix) {
    state.requestCounter += 1;
    return `${prefix}_${Date.now()}_${state.requestCounter}`;
  }

  function requestBootstrap() {
    if (!canRead()) return;
    post('call_app.crdt.bootstrap.request', {
      request_id: requestId('bootstrap'),
      after_clock: 0,
    });
  }

  function requestOps() {
    if (!canRead()) return;
    post('call_app.crdt.ops.request', {
      request_id: requestId('ops'),
      after_clock: state.latestClock,
      limit: 120,
    });
  }

  function canRead() {
    return state.capabilities.has('call_apps.crdt.read') && state.permissionActions.has('read');
  }

  function canWrite() {
    return state.capabilities.has('call_apps.crdt.append') && state.permissionActions.has('write');
  }

  function canExport() {
    return state.capabilities.has('call_apps.export.request') && state.capabilities.has('call_apps.export.download');
  }

  function normalizeLaunch(message) {
    const launchContext = isPlainObject(message.launch_context) ? message.launch_context : {};
    const participant = isPlainObject(launchContext.participant) ? launchContext.participant : {};
    state.appSessionId = safeString(message.app_session_id || message.session_id || '', '', 140);
    state.actorId = safeString(participant.actor_id || message.participant?.user_id || message.actor_id || '', '', 120);
    state.displayName = safeString(participant.display_name || message.participant?.display_name || message.display_name || '', 'Participant', 160);
    state.capabilities = new Set(Array.isArray(message.capabilities) ? message.capabilities.map((entry) => safeString(entry)) : []);
    const actions = Array.isArray(launchContext.permission_actions)
      ? launchContext.permission_actions
      : Array.isArray(message.permission_actions)
        ? message.permission_actions
        : ['read'];
    state.permissionActions = new Set(actions.map((entry) => safeIdentifier(entry)).filter(Boolean));
    state.launched = true;
    setConnectionState('live');
    post('call_app.ready', {
      primary_session_token_received: false,
      capabilities: Array.from(state.capabilities),
      permission_actions: Array.from(state.permissionActions),
    });
    requestBootstrap();
    if (state.pollTimer) window.clearInterval(state.pollTimer);
    state.pollTimer = window.setInterval(requestOps, POLL_MS);
  }

  function setConnectionState(mode) {
    if (!nodes.connectionBadge) return;
    nodes.connectionBadge.className = `status-pill state-${mode}`;
    nodes.connectionBadge.textContent = mode === 'live' ? 'Live' : mode === 'paused' ? 'Paused' : 'Waiting';
  }

  function classifyStage(entry) {
    const text = `${entry.stage || ''} ${entry.category || ''} ${entry.event_type || ''} ${entry.code || ''} ${entry.message || ''} ${payloadText(entry.payload)}`.toLowerCase();
    if (/(turn|relay|relay_candidate|typ relay)/.test(text)) return 'turn';
    if (/(stun|srflx|server reflexive|typ srflx)/.test(text)) return 'stun';
    if (/(ice|candidate|host candidate|typ host|p2p)/.test(text)) return 'host';
    if (/(websocket|socket|signaling|room_snapshot|room_sync|foreground_reconnect)/.test(text)) return 'signaling';
    if (/(sfu|media|publisher|remote_video|decoder|keyframe|webrtc)/.test(text)) return 'sfu';
    if (/(call_app|iframe|crdt|launch|marketplace|availability)/.test(text)) return 'callapp';
    if (/(datachannel|data channel|queue)/.test(text)) return 'data';
    return 'runtime';
  }

  function statusForEntry(entry) {
    const level = safeIdentifier(entry.level, 'info');
    const text = `${entry.event_type || ''} ${entry.message || ''} ${entry.code || ''}`.toLowerCase();
    if (level === 'error' || /failed|fatal|timeout|denied|blocked|disconnect|500/.test(text)) return 'error';
    if (level === 'warning' || /warn|retry|reconnect|stale|fallback|degraded|paused/.test(text)) return 'warn';
    if (/ready|connected|accepted|healthy|started|attached|live|ok/.test(text)) return 'ok';
    return 'active';
  }

  function normalizeDiagnostic(input) {
    const payload = isPlainObject(input?.payload) ? input.payload : {};
    const entry = {
      id: safeString(input?.id || input?.event_id || input?.operation_id || `${Date.now()}_${Math.random().toString(16).slice(2)}`, '', 180),
      source: safeIdentifier(input?.source || payload.source || input?.category || 'client', 'client'),
      category: safeIdentifier(input?.category || payload.category || 'runtime', 'runtime'),
      level: safeIdentifier(input?.level || payload.level || 'info', 'info'),
      event_type: safeIdentifier(input?.event_type || input?.eventType || payload.event_type || 'diagnostic_event', 'diagnostic_event'),
      code: safeIdentifier(input?.code || payload.code || '', ''),
      message: safeString(input?.message || payload.message || input?.event_type || 'Diagnostic event', 'Diagnostic event', 500),
      stage: safeIdentifier(input?.stage || payload.stage || '', ''),
      call_id: safeString(input?.call_id || payload.call_id || '', '', 120),
      room_id: safeString(input?.room_id || payload.room_id || '', '', 120),
      repeat_count: Math.max(1, Number(input?.repeat_count || payload.repeat_count || 1) || 1),
      client_time: safeString(input?.client_time || input?.recorded_at || payload.client_time || new Date().toISOString(), '', 80),
      timestamp_unix_ms: Number(input?.timestamp_unix_ms || payload.timestamp_unix_ms || Date.now()) || Date.now(),
      persist: input?.persist !== false && payload.persist !== false,
      payload: summarizePayload(payload),
    };
    if (entry.stage === '') entry.stage = classifyStage(entry);
    return entry;
  }

  function appendLog(entry, options = {}) {
    if (!entry || !entry.id) return;
    if (state.logs.some((row) => row.id === entry.id)) return;
    if (state.paused && !options.forceAppend) {
      if (!state.pausedLogs.some((row) => row.id === entry.id)) {
        state.pausedLogs.push(entry);
        if (state.pausedLogs.length > MAX_PAUSED_LOGS) {
          state.pausedLogs.splice(0, state.pausedLogs.length - MAX_PAUSED_LOGS);
        }
      }
      if (!options.skipPersist && entry.persist !== false) persistLog(entry);
      setConnectionState('paused');
      return;
    }
    state.logs.push(entry);
    if (state.logs.length > MAX_LOGS) state.logs.splice(0, state.logs.length - MAX_LOGS);
    updateStage(entry);
    if (!options.skipPersist && entry.persist !== false) persistLog(entry);
    render();
  }

  function flushPausedLogs() {
    const pausedLogs = state.pausedLogs.splice(0);
    for (const entry of pausedLogs) {
      appendLog(entry, { skipPersist: true, forceAppend: true });
    }
    render();
  }

  function updateStage(entry) {
    const stage = safeIdentifier(entry.stage, 'runtime');
    const previous = state.staged.get(stage) || { count: 0, status: 'idle', label: 'idle' };
    const status = statusForEntry(entry);
    const stronger = previous.status === 'error' && status !== 'ok' ? 'error' : status;
    state.staged.set(stage, {
      count: previous.count + 1,
      status: stronger,
      label: entry.event_type || entry.message || 'event',
    });
    renderStages();
  }

  function renderStages() {
    for (const [stage, card] of nodes.stageCards || []) {
      const info = state.staged.get(stage) || { count: 0, status: 'idle', label: 'idle' };
      card.classList.toggle('status-ok', info.status === 'ok' || info.status === 'active');
      card.classList.toggle('status-warn', info.status === 'warn');
      card.classList.toggle('status-error', info.status === 'error');
      const label = card.querySelector('small');
      if (label) label.textContent = info.count > 0 ? `${info.count} ${safeString(info.label, 'event', 26)}` : 'idle';
    }
  }

  function operationIdForEntry(entry) {
    return `diag_${safeIdentifier(entry.source, 'client')}_${safeIdentifier(entry.id, 'event')}`.slice(0, 180);
  }

  function persistLog(entry) {
    if (!canWrite()) return;
    if (state.persistedEventIds.has(entry.id) || state.pendingEventIds.has(entry.id)) return;
    state.pendingEventIds.add(entry.id);
    post('call_app.crdt.op.append', {
      request_id: requestId('append'),
      operation: {
        operation_id: operationIdForEntry(entry),
        payload_type: 'diagnostic.log.append',
        payload: {
          entry,
        },
      },
    });
  }

  function applyEnvelope(envelope) {
    if (!isPlainObject(envelope)) return;
    const operationId = safeString(envelope.operation_id || '', '', 180);
    if (operationId && state.appliedOps.has(operationId)) return;
    const payloadType = safeIdentifier(envelope.payload_type || '');
    const payload = isPlainObject(envelope.payload) ? envelope.payload : {};
    if (operationId) state.appliedOps.add(operationId);
    state.latestClock = Math.max(state.latestClock, Number(envelope.logical_clock || 0) || 0);
    if (payloadType === 'diagnostic.log.append') {
      appendLog(normalizeDiagnostic(payload.entry || payload), { skipPersist: true });
    } else if (payloadType === 'diagnostic.log.clear') {
      state.logs = [];
      state.staged.clear();
      renderStages();
      render();
    } else if (payloadType === 'diagnostic.stage.update') {
      const entry = normalizeDiagnostic(payload.entry || payload);
      updateStage(entry);
    }
  }

  function applyOpsResult(result) {
    const ops = Array.isArray(result?.ops) ? result.ops : [];
    for (const envelope of ops) applyEnvelope(envelope);
    const cursorClock = Number(result?.replay_cursor?.last_clock || result?.latest_clock || 0) || 0;
    state.latestClock = Math.max(state.latestClock, cursorClock);
  }

  function markPersisted(result) {
    const operation = result?.operation || {};
    if (safeIdentifier(operation.payload_type || '') !== 'diagnostic.log.append') return;
    const entry = operation.payload?.entry || {};
    const id = safeString(entry.id || '', '', 180);
    if (id) {
      state.pendingEventIds.delete(id);
      state.persistedEventIds.add(id);
    }
    applyEnvelope(operation);
  }

  function visibleLogs() {
    const term = state.search.toLowerCase();
    return state.logs.filter((entry) => filterMatchesEntry(entry, term));
  }

  function filterMatchesEntry(entry, term) {
    const filter = safeIdentifier(state.filter, 'all');
    const stage = safeIdentifier(entry.stage, 'runtime');
    const level = safeIdentifier(entry.level, 'info');
    if (filter === 'error' && !['error', 'warning'].includes(level)) return false;
    if (filter === 'ice' && !['host', 'stun', 'turn'].includes(stage)) return false;
    if (!['all', 'error', 'ice'].includes(filter) && stage !== filter) return false;
    if (term === '') return true;
    return `${stage} ${entry.event_type} ${entry.code} ${entry.message} ${payloadText(entry.payload)}`.toLowerCase().includes(term);
  }

  function render() {
    if (!nodes.tailList) return;
    const autoScroll = !state.paused && nodes.tailList.scrollTop + nodes.tailList.clientHeight >= nodes.tailList.scrollHeight - 48;
    const rows = visibleLogs();
    nodes.tailList.replaceChildren(...rows.map(renderRow));
    nodes.tailShell?.classList.toggle('has-entries', rows.length > 0);
    if (autoScroll) nodes.tailList.scrollTop = nodes.tailList.scrollHeight;
  }

  function renderRow(entry) {
    const row = document.createElement('li');
    row.className = `tail-entry level-${safeIdentifier(entry.level, 'info')}`;
    row.dataset.stage = entry.stage;
    const time = document.createElement('span');
    time.className = 'tail-time';
    time.textContent = timeLabel(entry.timestamp_unix_ms, entry.client_time);
    const stage = document.createElement('span');
    stage.className = 'tail-stage';
    stage.textContent = entry.stage;
    const message = document.createElement('span');
    message.className = 'tail-message';
    message.textContent = `${entry.event_type}${entry.code ? `:${entry.code}` : ''} ${entry.message}`.trim();
    row.append(time, stage, message);
    const detail = payloadPrettyText(entry.payload);
    if (detail) {
      const detailNode = document.createElement('span');
      detailNode.className = 'tail-detail';
      detailNode.textContent = detail;
      row.append(detailNode);
    }
    return row;
  }

  function timeLabel(timestamp, fallback) {
    const date = new Date(Number(timestamp || 0));
    if (Number.isFinite(date.getTime())) return date.toLocaleTimeString([], { hour12: false });
    return safeString(fallback, '', 12);
  }

  function exportJson() {
    if (!canExport()) return;
    const blob = new Blob([JSON.stringify({ app_key: APP_KEY, exported_at: new Date().toISOString(), logs: state.logs }, null, 2)], {
      type: 'application/json',
    });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `call-diagnostics-${Date.now()}.json`;
    link.click();
    window.setTimeout(() => URL.revokeObjectURL(link.href), 500);
  }

  function bindUi() {
    nodes.pauseToggle?.addEventListener('click', () => {
      state.paused = !state.paused;
      nodes.pauseToggle.setAttribute('aria-pressed', state.paused ? 'true' : 'false');
      if (state.paused) {
        setConnectionState('paused');
        return;
      }
      flushPausedLogs();
      setConnectionState(state.launched ? 'live' : 'waiting');
    });
    nodes.exportJson?.addEventListener('click', exportJson);
    nodes.tailSearch?.addEventListener('input', () => {
      state.search = safeString(nodes.tailSearch.value, '', 120);
      render();
    });
    for (const button of nodes.filterButtons) {
      button.addEventListener('click', () => {
        state.filter = safeIdentifier(button.dataset.filter, 'all');
        for (const entry of nodes.filterButtons) entry.classList.toggle('active', entry === button);
        render();
      });
    }
  }

  function handleMessage(event) {
    const message = event.data && typeof event.data === 'object' ? event.data : null;
    if (!message || message.bridge_protocol !== BRIDGE_PROTOCOL || message.app_key !== APP_KEY) return;
    if (message.type === 'call_app.launch') {
      normalizeLaunch(message);
    } else if (message.type === 'call_app.diagnostic.event' || message.type === 'call_app.diagnostics.tail.event') {
      appendLog(normalizeDiagnostic(message.diagnostic || message.payload || {}));
    } else if (message.type === 'call_app.crdt.bootstrap.response' || message.type === 'call_app.crdt.ops.response') {
      applyOpsResult(message.result || {});
    } else if (message.type === 'call_app.crdt.op.appended') {
      markPersisted(message.result || {});
    } else if (message.type === 'call_app.crdt.error') {
      appendLog(normalizeDiagnostic({
        source: 'call_app_bridge',
        category: 'call_app',
        level: 'error',
        stage: 'callapp',
        event_type: 'call_app_crdt_error',
        message: message.message || 'CRDT bridge request failed.',
        payload: {
          reason: message.reason,
          response_status: message.response_status,
          response_code: message.response_code,
        },
      }), { skipPersist: true });
    }
  }

  initNodes();
  bindUi();
  window.addEventListener('message', handleMessage);
  setConnectionState('waiting');
})();
