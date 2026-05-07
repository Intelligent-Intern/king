(() => {
  const bridgeProtocol = 'king.call_app.iframe.v1';
  const appKey = 'whiteboard';
  const boardWidth = 1600;
  const boardHeight = 900;
  const colors = ['#1582bf', '#59c7f2', '#00652f', '#f47221', '#ef4423', '#000010'];
  const presenceThrottleMs = {
    'cursor.move': 600,
    'selection.update': 250,
  };
  const canvas = document.getElementById('board');
  const ctx = canvas.getContext('2d');
  const status = document.getElementById('status');
  const clock = document.getElementById('clock');
  const modeBadge = document.getElementById('modeBadge');
  const widthInput = document.getElementById('width');
  const inlineEditor = document.getElementById('inlineEditor');
  const inlineText = document.getElementById('inlineText');
  const state = {
    strokes: new Map(),
    shapes: new Map(),
    texts: new Map(),
    notes: new Map(),
    cursors: new Map(),
    selections: new Map(),
    applied: new Set(),
  };
  let parentOrigin = '';
  let appSessionId = '';
  let callId = '';
  let documentId = '';
  let actorId = '';
  let grantState = 'denied';
  let capabilities = new Set();
  let activeTool = 'pen';
  let activeColor = colors[0];
  let latestClock = 0;
  let drawing = null;
  let preview = null;
  let selectedId = '';
  let moving = null;
  let editorPoint = null;
  let editorKind = 'text';
  let pollTimer = 0;
  const lastPresenceSentAt = new Map();
  const undoStack = [];
  const redoStack = [];

  function canAppend() {
    return grantState === 'allowed' && capabilities.has('call_apps.crdt.append');
  }

  function canRead() {
    return grantState === 'allowed' && capabilities.has('call_apps.crdt.read');
  }

  function setStatus(message) {
    status.textContent = message;
    modeBadge.textContent = canAppend() ? 'Editor' : (canRead() ? 'Viewer' : 'No access');
    clock.textContent = `${latestClock} ops`;
    document.querySelectorAll('[data-tool], .swatch, #width, #undo, #redo').forEach((element) => {
      element.disabled = !canAppend();
    });
    document.getElementById('undo').disabled = !canAppend() || undoStack.length === 0;
    document.getElementById('redo').disabled = !canAppend() || redoStack.length === 0;
  }

  function applyAccessState(result = {}) {
    const nextGrantState = String(result?.grant_state || '').trim().toLowerCase();
    if (nextGrantState) {
      grantState = nextGrantState;
    }
    if (!canRead()) {
      clearInterval(pollTimer);
    }
  }

  function emit(type, payload = {}) {
    if (!parentOrigin || !window.parent) return;
    window.parent.postMessage({
      type,
      bridge_protocol: bridgeProtocol,
      app_key: appKey,
      app_session_id: appSessionId,
      ...payload,
    }, parentOrigin);
  }

  function requestBootstrap(afterClock = 0) {
    if (!canRead()) return;
    emit('call_app.crdt.bootstrap.request', {
      request_id: `wb_bootstrap_${Date.now()}`,
      after_clock: afterClock,
    });
  }

  function requestOps() {
    if (!appSessionId || !canRead()) return;
    emit('call_app.crdt.ops.request', {
      request_id: `wb_ops_${Date.now()}`,
      after_clock: latestClock,
      limit: 250,
    });
  }

  function operationId(type) {
    return `wb_${type.replaceAll('.', '_')}_${Date.now()}_${Math.random().toString(36).slice(2, 10)}`;
  }

  function appendOperation(payloadType, payload, options = {}) {
    if (!canAppend()) {
      setStatus('Viewer mode. Drawing is disabled for this participant.');
      return null;
    }
    const operation = {
      operation_id: operationId(payloadType),
      payload_type: payloadType,
      causal_dependencies: latestClock > 0 ? [{ logical_clock: latestClock }] : [],
      payload,
    };
    emit('call_app.crdt.op.append', {
      request_id: operationId('request'),
      operation,
    });
    if (options.history !== false) {
      undoStack.push({ payloadType, payload: structuredClone(payload) });
      redoStack.length = 0;
    }
    setStatus('Whiteboard update queued.');
    return operation;
  }

  function canPublishPresence() {
    return grantState === 'allowed' && capabilities.has('call_apps.presence.publish');
  }

  function applyPresence(payloadType, payload = {}, sourceActorId = actorId) {
    const withActor = { ...payload, actor_id: sourceActorId };
    if (payloadType === 'cursor.move') state.cursors.set(sourceActorId, withActor);
    if (payloadType === 'selection.update') state.selections.set(sourceActorId, withActor);
    render();
  }

  function publishPresence(payloadType, payload) {
    if (!canPublishPresence()) return false;
    const now = Date.now();
    const throttleMs = Number(presenceThrottleMs[payloadType] || 500);
    if (now - Number(lastPresenceSentAt.get(payloadType) || 0) < throttleMs) return false;
    lastPresenceSentAt.set(payloadType, now);
    applyPresence(payloadType, payload, actorId);
    emit('call_app.presence.publish', {
      request_id: operationId('presence'),
      payload_type: payloadType,
      payload,
    });
    return true;
  }

  function normalizePoint(event) {
    const rect = canvas.getBoundingClientRect();
    return {
      x: Math.max(0, Math.min(boardWidth, ((event.clientX - rect.left) / rect.width) * boardWidth)),
      y: Math.max(0, Math.min(boardHeight, ((event.clientY - rect.top) / rect.height) * boardHeight)),
    };
  }

  function renderScene(targetCtx, includeCursors = true) {
    targetCtx.clearRect(0, 0, boardWidth, boardHeight);
    targetCtx.fillStyle = '#ffffff';
    targetCtx.fillRect(0, 0, boardWidth, boardHeight);
    targetCtx.lineCap = 'round';
    targetCtx.lineJoin = 'round';
    targetCtx.strokeStyle = '#efefe7';
    targetCtx.lineWidth = 1;
    for (let x = 0; x <= boardWidth; x += 80) {
      targetCtx.beginPath();
      targetCtx.moveTo(x, 0);
      targetCtx.lineTo(x, boardHeight);
      targetCtx.stroke();
    }
    for (let y = 0; y <= boardHeight; y += 80) {
      targetCtx.beginPath();
      targetCtx.moveTo(0, y);
      targetCtx.lineTo(boardWidth, y);
      targetCtx.stroke();
    }
    for (const stroke of state.strokes.values()) drawStroke(targetCtx, stroke);
    for (const shape of state.shapes.values()) drawShape(targetCtx, shape);
    for (const note of state.notes.values()) drawNote(targetCtx, note);
    for (const text of state.texts.values()) drawText(targetCtx, text);
    if (selectedId) drawSelection(targetCtx, selectedId);
    if (preview?.kind === 'stroke') drawStroke(targetCtx, preview);
    if (preview?.kind === 'shape') drawShape(targetCtx, preview);
    if (includeCursors) {
      for (const cursor of state.cursors.values()) {
        if (cursor.actor_id !== actorId) drawCursor(targetCtx, cursor);
      }
    }
  }

  function render() {
    renderScene(ctx, true);
  }

  function drawStroke(targetCtx, stroke) {
    const points = Array.isArray(stroke.points) ? stroke.points : [];
    if (points.length < 1) return;
    targetCtx.save();
    targetCtx.globalAlpha = stroke.tool === 'highlighter' ? 0.35 : 1;
    targetCtx.strokeStyle = stroke.color || activeColor;
    targetCtx.lineWidth = Math.max(1, Number(stroke.width || 4));
    targetCtx.beginPath();
    targetCtx.moveTo(points[0].x, points[0].y);
    for (const point of points.slice(1)) targetCtx.lineTo(point.x, point.y);
    targetCtx.stroke();
    targetCtx.restore();
  }

  function drawShape(targetCtx, shape) {
    targetCtx.save();
    targetCtx.strokeStyle = shape.color || activeColor;
    targetCtx.lineWidth = Math.max(2, Number(shape.width || 4));
    const type = String(shape.type || 'rect');
    if (type === 'line') {
      targetCtx.beginPath();
      targetCtx.moveTo(shape.x, shape.y);
      targetCtx.lineTo(shape.x + shape.w, shape.y + shape.h);
      targetCtx.stroke();
    } else if (type === 'ellipse') {
      targetCtx.beginPath();
      targetCtx.ellipse(
        shape.x + shape.w / 2,
        shape.y + shape.h / 2,
        Math.abs(shape.w / 2),
        Math.abs(shape.h / 2),
        0,
        0,
        Math.PI * 2,
      );
      targetCtx.stroke();
    } else {
      targetCtx.strokeRect(shape.x, shape.y, shape.w, shape.h);
    }
    targetCtx.restore();
  }

  function itemBounds(id) {
    const note = state.notes.get(id);
    if (note) return { x: note.x, y: note.y, w: 260, h: 160 };
    const text = state.texts.get(id);
    if (text) return { x: text.x, y: text.y, w: 380, h: 90 };
    const shape = state.shapes.get(id);
    if (shape) return {
      x: Math.min(shape.x, shape.x + shape.w),
      y: Math.min(shape.y, shape.y + shape.h),
      w: Math.abs(shape.w),
      h: Math.abs(shape.h),
    };
    return null;
  }

  function drawSelection(targetCtx, id) {
    const bounds = itemBounds(id);
    if (!bounds) return;
    targetCtx.save();
    targetCtx.setLineDash([10, 8]);
    targetCtx.strokeStyle = '#1582bf';
    targetCtx.lineWidth = 3;
    targetCtx.strokeRect(bounds.x - 8, bounds.y - 8, bounds.w + 16, bounds.h + 16);
    targetCtx.restore();
  }

  function drawText(targetCtx, item) {
    targetCtx.save();
    targetCtx.fillStyle = item.color || '#000010';
    targetCtx.font = '700 28px Nunito, system-ui, sans-serif';
    wrapText(targetCtx, String(item.text || ''), item.x, item.y, 380, 34);
    targetCtx.restore();
  }

  function drawNote(targetCtx, item) {
    targetCtx.save();
    targetCtx.fillStyle = '#efefe7';
    targetCtx.strokeStyle = item.color || '#f47221';
    targetCtx.lineWidth = 4;
    targetCtx.fillRect(item.x, item.y, 260, 160);
    targetCtx.strokeRect(item.x, item.y, 260, 160);
    targetCtx.fillStyle = '#000010';
    targetCtx.font = '700 23px Nunito, system-ui, sans-serif';
    wrapText(targetCtx, String(item.text || ''), item.x + 18, item.y + 34, 224, 30);
    targetCtx.restore();
  }

  function drawCursor(targetCtx, cursor) {
    targetCtx.save();
    targetCtx.strokeStyle = cursor.color || '#1582bf';
    targetCtx.fillStyle = cursor.color || '#1582bf';
    targetCtx.lineWidth = 3;
    targetCtx.beginPath();
    targetCtx.moveTo(cursor.x, cursor.y);
    targetCtx.lineTo(cursor.x + 18, cursor.y + 28);
    targetCtx.lineTo(cursor.x + 6, cursor.y + 24);
    targetCtx.closePath();
    targetCtx.fill();
    targetCtx.font = '700 18px Nunito, system-ui, sans-serif';
    targetCtx.fillText(String(cursor.label || 'User'), cursor.x + 24, cursor.y + 28);
    targetCtx.restore();
  }

  function wrapText(targetCtx, text, x, y, maxWidth, lineHeight) {
    const words = text.split(/\s+/).filter(Boolean);
    let line = '';
    for (const word of words) {
      const test = line ? `${line} ${word}` : word;
      if (targetCtx.measureText(test).width > maxWidth && line) {
        targetCtx.fillText(line, x, y);
        line = word;
        y += lineHeight;
      } else {
        line = test;
      }
    }
    if (line) targetCtx.fillText(line, x, y);
  }

  function applySnapshot(snapshot) {
    if (snapshot?.kind !== 'whiteboard.snapshot.v1' || typeof snapshot.state !== 'object') return;
    for (const key of ['strokes', 'shapes', 'texts', 'notes']) state[key].clear();
    state.cursors.clear();
    state.selections.clear();
    for (const stroke of snapshot.state.strokes || []) state.strokes.set(stroke.id, stroke);
    for (const shape of snapshot.state.shapes || []) state.shapes.set(shape.id, shape);
    for (const text of snapshot.state.texts || []) state.texts.set(text.id, text);
    for (const note of snapshot.state.notes || []) state.notes.set(note.id, note);
  }

  function applyEnvelope(envelope) {
    if (!envelope || state.applied.has(envelope.operation_id)) return;
    state.applied.add(envelope.operation_id);
    latestClock = Math.max(latestClock, Number(envelope.logical_clock || 0));
    const payload = envelope.payload && typeof envelope.payload === 'object' ? envelope.payload : {};
    const withActor = { ...payload, actor_id: envelope.actor_id };
    if (envelope.payload_type === 'stroke.add') state.strokes.set(payload.id, withActor);
    if (envelope.payload_type === 'shape.add') state.shapes.set(payload.id, withActor);
    if (envelope.payload_type === 'shape.update' && state.shapes.has(payload.id)) {
      state.shapes.set(payload.id, { ...state.shapes.get(payload.id), ...withActor });
    }
    if (envelope.payload_type === 'shape.delete') {
      state.shapes.delete(payload.id);
      state.strokes.delete(payload.id);
      state.texts.delete(payload.id);
      state.notes.delete(payload.id);
    }
    if (envelope.payload_type === 'text.add') state.texts.set(payload.id, withActor);
    if (envelope.payload_type === 'text.update' && state.texts.has(payload.id)) {
      state.texts.set(payload.id, { ...state.texts.get(payload.id), ...withActor });
    }
    if (envelope.payload_type === 'sticky_note.add') state.notes.set(payload.id, withActor);
    if (envelope.payload_type === 'sticky_note.update' && state.notes.has(payload.id)) {
      state.notes.set(payload.id, { ...state.notes.get(payload.id), ...withActor });
    }
    setStatus(canAppend() ? 'Whiteboard synchronized.' : 'Read-only whiteboard synchronized.');
    render();
  }

  function objectForHistory(id) {
    if (state.strokes.has(id)) return { payloadType: 'stroke.add', payload: structuredClone(state.strokes.get(id)) };
    if (state.shapes.has(id)) return { payloadType: 'shape.add', payload: structuredClone(state.shapes.get(id)) };
    if (state.texts.has(id)) return { payloadType: 'text.add', payload: structuredClone(state.texts.get(id)) };
    if (state.notes.has(id)) return { payloadType: 'sticky_note.add', payload: structuredClone(state.notes.get(id)) };
    return null;
  }

  function updateObjectPosition(id, dx, dy) {
    let payloadType = '';
    let before = null;
    if (state.shapes.has(id)) {
      payloadType = 'shape.update';
      before = state.shapes.get(id);
    } else if (state.texts.has(id)) {
      payloadType = 'text.update';
      before = state.texts.get(id);
    } else if (state.notes.has(id)) {
      payloadType = 'sticky_note.update';
      before = state.notes.get(id);
    }
    if (!before) return false;
    const from = { id, x: before.x, y: before.y };
    const to = { id, x: before.x + dx, y: before.y + dy };
    const operation = appendOperation(payloadType, to, { history: false });
    if (!operation) return false;
    undoStack.push({ payloadType, payload: to, before: from, after: to });
    redoStack.length = 0;
    setStatus('Whiteboard update queued.');
    return true;
  }

  function distanceToSegment(point, a, b) {
    const dx = b.x - a.x;
    const dy = b.y - a.y;
    if (dx === 0 && dy === 0) return Math.hypot(point.x - a.x, point.y - a.y);
    const t = Math.max(0, Math.min(1, ((point.x - a.x) * dx + (point.y - a.y) * dy) / (dx * dx + dy * dy)));
    return Math.hypot(point.x - (a.x + t * dx), point.y - (a.y + t * dy));
  }

  function hitTest(point) {
    const notes = [...state.notes.values()].reverse();
    const texts = [...state.texts.values()].reverse();
    const shapes = [...state.shapes.values()].reverse();
    const strokes = [...state.strokes.values()].reverse();
    const boxHit = (item, width, height) => point.x >= item.x && point.x <= item.x + width && point.y >= item.y && point.y <= item.y + height;
    const note = notes.find((item) => boxHit(item, 260, 160));
    if (note) return note.id;
    const text = texts.find((item) => boxHit(item, 380, 80));
    if (text) return text.id;
    const shape = shapes.find((item) => boxHit(item, item.w, item.h));
    if (shape) return shape.id;
    for (const stroke of strokes) {
      const points = Array.isArray(stroke.points) ? stroke.points : [];
      for (let index = 1; index < points.length; index += 1) {
        if (distanceToSegment(point, points[index - 1], points[index]) <= Math.max(12, Number(stroke.width || 4) + 8)) return stroke.id;
      }
    }
    return '';
  }

  function handleCrdtResult(result = {}) {
    applyAccessState(result);
    if (result.document?.snapshot_clock) latestClock = Math.max(latestClock, Number(result.document.snapshot_clock));
    applySnapshot(result.document?.snapshot);
    for (const envelope of result.ops || []) applyEnvelope(envelope);
    render();
    setStatus(canAppend() ? 'Whiteboard ready.' : 'Viewer mode. Drawing is disabled.');
  }

  function openInlineEditor(point, kind) {
    editorPoint = point;
    editorKind = kind;
    const rect = canvas.getBoundingClientRect();
    inlineEditor.style.left = `${Math.min(rect.width - 280, (point.x / boardWidth) * rect.width)}px`;
    inlineEditor.style.top = `${Math.min(rect.height - 110, (point.y / boardHeight) * rect.height)}px`;
    inlineEditor.classList.add('open');
    inlineText.value = '';
    inlineText.focus();
  }

  function closeInlineEditor() {
    inlineEditor.classList.remove('open');
    editorPoint = null;
  }

  function pointerDown(event) {
    if (!canAppend()) return;
    const point = normalizePoint(event);
    if (activeTool === 'text' || activeTool === 'sticky') {
      openInlineEditor(point, activeTool);
      return;
    }
    if (activeTool === 'delete') {
      const id = hitTest(point);
      const deleted = id ? objectForHistory(id) : null;
      if (id) {
        appendOperation('shape.delete', { id }, { history: false });
        if (deleted) {
          undoStack.push({ payloadType: 'shape.delete', payload: { id }, deleted });
          redoStack.length = 0;
        }
      }
      return;
    }
    if (activeTool === 'select') {
      selectedId = hitTest(point);
      if (selectedId) {
        const bounds = itemBounds(selectedId);
        moving = { id: selectedId, start: point, bounds };
        publishPresence('selection.update', { selected_id: selectedId, x: point.x, y: point.y });
      }
      render();
      return;
    }
    canvas.setPointerCapture(event.pointerId);
    drawing = { start: point, points: [point] };
    preview = (activeTool === 'rect' || activeTool === 'line' || activeTool === 'ellipse')
      ? { kind: 'shape', type: activeTool, x: point.x, y: point.y, w: 1, h: 1, color: activeColor, width: Number(widthInput.value) }
      : { kind: 'stroke', tool: activeTool, points: [point], color: activeColor, width: Number(widthInput.value) };
    render();
  }

  function pointerMove(event) {
    const point = normalizePoint(event);
    sendCursor(point);
    if (moving && canAppend()) {
      const dx = point.x - moving.start.x;
      const dy = point.y - moving.start.y;
      preview = { kind: 'shape', type: 'rect', x: moving.bounds.x + dx, y: moving.bounds.y + dy, w: moving.bounds.w, h: moving.bounds.h, color: '#1582bf', width: 3 };
      render();
      return;
    }
    if (!drawing || !canAppend()) return;
    if (activeTool === 'rect' || activeTool === 'line' || activeTool === 'ellipse') {
      preview = {
        kind: 'shape',
        type: activeTool,
        x: activeTool === 'line' ? drawing.start.x : Math.min(drawing.start.x, point.x),
        y: activeTool === 'line' ? drawing.start.y : Math.min(drawing.start.y, point.y),
        w: activeTool === 'line' ? point.x - drawing.start.x : Math.abs(point.x - drawing.start.x),
        h: activeTool === 'line' ? point.y - drawing.start.y : Math.abs(point.y - drawing.start.y),
        color: activeColor,
        width: Number(widthInput.value),
      };
    } else {
      drawing.points.push(point);
      preview = { kind: 'stroke', tool: activeTool, points: drawing.points.slice(), color: activeColor, width: Number(widthInput.value) };
    }
    render();
  }

  function pointerUp(event) {
    if (moving && canAppend()) {
      const point = normalizePoint(event);
      const dx = point.x - moving.start.x;
      const dy = point.y - moving.start.y;
      if (Math.hypot(dx, dy) > 4) updateObjectPosition(moving.id, dx, dy);
      moving = null;
      preview = null;
      render();
      return;
    }
    if (!drawing || !canAppend()) return;
    const point = normalizePoint(event);
    if (activeTool === 'rect' || activeTool === 'line' || activeTool === 'ellipse') {
      const shape = preview;
      if (Math.abs(shape.w) > 10 || Math.abs(shape.h) > 10) {
        appendOperation('shape.add', { id: operationId('shape'), ...shape, kind: undefined });
      }
    } else {
      drawing.points.push(point);
      if (drawing.points.length > 1) {
        appendOperation('stroke.add', {
          id: operationId('stroke'),
          tool: activeTool,
          points: drawing.points,
          color: activeColor,
          width: Number(widthInput.value),
        });
      }
    }
    drawing = null;
    preview = null;
    render();
  }

  function sendCursor(point) {
    publishPresence('cursor.move', {
      x: point.x,
      y: point.y,
      color: activeColor,
      label: actorId ? actorId.slice(5, 11) : 'User',
    });
  }

  function undoLast() {
    const action = undoStack.pop();
    if (!action) return;
    if (action.before && action.payloadType.endsWith('.update')) {
      appendOperation(action.payloadType, action.before, { history: false });
      redoStack.push(action);
    } else if (action.payloadType.endsWith('.add')) {
      appendOperation('shape.delete', { id: action.payload.id }, { history: false });
      redoStack.push(action);
    } else if (action.payloadType === 'shape.delete' && action.deleted) {
      appendOperation(action.deleted.payloadType, action.deleted.payload, { history: false });
      redoStack.push(action);
    }
    setStatus('Undo queued.');
  }

  function redoLast() {
    const action = redoStack.pop();
    if (!action) return;
    if (action.after && action.payloadType.endsWith('.update')) {
      appendOperation(action.payloadType, action.after, { history: false });
      undoStack.push(action);
    } else if (action.payloadType.endsWith('.add')) {
      appendOperation(action.payloadType, action.payload, { history: false });
      undoStack.push(action);
    } else if (action.payloadType === 'shape.delete') {
      appendOperation('shape.delete', action.payload, { history: false });
      undoStack.push(action);
    }
    setStatus('Redo queued.');
  }

  function exportCanvas(mimeType) {
    const exportCanvasElement = document.createElement('canvas');
    exportCanvasElement.width = boardWidth;
    exportCanvasElement.height = boardHeight;
    renderScene(exportCanvasElement.getContext('2d'), false);
    return exportCanvasElement.toDataURL(mimeType);
  }

  function downloadDataUrl(dataUrl, filename) {
    const anchor = document.createElement('a');
    anchor.href = dataUrl;
    anchor.download = filename;
    anchor.rel = 'noopener';
    anchor.click();
  }

  function exportPdf() {
    const jpg = exportCanvas('image/jpeg');
    const imageBytes = Uint8Array.from(atob(jpg.split(',')[1]), (char) => char.charCodeAt(0));
    const encoder = new TextEncoder();
    const chunks = [];
    const offsets = [0];
    let offset = 0;
    const pushBytes = (bytes) => { chunks.push(bytes); offset += bytes.length; };
    const push = (text) => pushBytes(encoder.encode(text));
    const objectStart = (id) => { offsets[id] = offset; push(`${id} 0 obj\n`); };
    push('%PDF-1.4\n');
    objectStart(1); push('<< /Type /Catalog /Pages 2 0 R >>\nendobj\n');
    objectStart(2); push('<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n');
    objectStart(3); push('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 960 540] /Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>\nendobj\n');
    objectStart(4);
    push(`<< /Type /XObject /Subtype /Image /Width ${boardWidth} /Height ${boardHeight} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ${imageBytes.length} >>\nstream\n`);
    pushBytes(imageBytes);
    push('\nendstream\nendobj\n');
    const content = 'q 960 0 0 540 0 0 cm /Im0 Do Q';
    objectStart(5); push(`<< /Length ${content.length} >>\nstream\n${content}\nendstream\nendobj\n`);
    const xref = offset;
    push(`xref\n0 6\n0000000000 65535 f \n${offsets.slice(1).map((value) => `${String(value).padStart(10, '0')} 00000 n `).join('\n')}\ntrailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`);
    const blob = new Blob(chunks, { type: 'application/pdf' });
    downloadDataUrl(URL.createObjectURL(blob), 'kingrt-whiteboard.pdf');
  }

  document.querySelectorAll('[data-tool]').forEach((button) => {
    button.addEventListener('click', () => {
      activeTool = button.dataset.tool;
      document.querySelectorAll('[data-tool]').forEach((item) => item.classList.toggle('active', item === button));
    });
  });

  document.querySelectorAll('.swatch').forEach((button) => {
    button.addEventListener('click', () => {
      activeColor = button.dataset.color;
      document.querySelectorAll('.swatch').forEach((item) => item.classList.toggle('active', item === button));
    });
  });

  inlineEditor.addEventListener('submit', (event) => {
    event.preventDefault();
    const text = inlineText.value.trim();
    if (text && editorPoint) {
      appendOperation(editorKind === 'sticky' ? 'sticky_note.add' : 'text.add', {
        id: operationId(editorKind),
        text,
        x: editorPoint.x,
        y: editorPoint.y,
        color: activeColor,
      });
    }
    closeInlineEditor();
  });

  canvas.addEventListener('pointerdown', pointerDown);
  canvas.addEventListener('pointermove', pointerMove);
  canvas.addEventListener('pointerup', pointerUp);
  canvas.addEventListener('pointercancel', pointerUp);
  document.getElementById('undo').addEventListener('click', undoLast);
  document.getElementById('redo').addEventListener('click', redoLast);
  document.getElementById('exportPng').addEventListener('click', () => downloadDataUrl(exportCanvas('image/png'), 'kingrt-whiteboard.png'));
  document.getElementById('exportPdf').addEventListener('click', exportPdf);

  window.addEventListener('message', (event) => {
    const message = event.data && typeof event.data === 'object' ? event.data : null;
    if (!message || message.bridge_protocol !== bridgeProtocol) return;
    if (message.type === 'call_app.launch') {
      parentOrigin = event.origin;
      appSessionId = String(message.app_session_id || '');
      callId = String(message.call_id || '');
      documentId = String(message.document_id || '');
      const context = message.launch_context || {};
      grantState = String(context.grant_state || 'denied');
      actorId = String(context.participant?.actor_id || '');
      capabilities = new Set(Array.isArray(message.capabilities) ? message.capabilities : []);
      emit('call_app.ready', {
        app_session_id: appSessionId,
        call_id: callId,
        document_id: documentId,
        launch_token_received: Boolean(message.launch_token),
        primary_session_token_received: false,
        capabilities: Array.isArray(message.capabilities) ? message.capabilities : [],
      });
      clearInterval(pollTimer);
      if (canRead()) {
        requestBootstrap(0);
        pollTimer = window.setInterval(requestOps, 1500);
        setStatus('Loading whiteboard.');
      } else {
        render();
        setStatus('Access not granted for this whiteboard.');
      }
    } else if (message.type === 'call_app.crdt.bootstrap.response') {
      handleCrdtResult(message.result || {});
    } else if (message.type === 'call_app.crdt.ops.response') {
      applyAccessState(message.result || {});
      for (const envelope of message.result?.ops || []) applyEnvelope(envelope);
      setStatus(canAppend() ? 'Whiteboard synchronized.' : 'Read-only whiteboard synchronized.');
    } else if (message.type === 'call_app.crdt.op.appended') {
      if (message.result?.operation) applyEnvelope(message.result.operation);
    } else if (message.type === 'call_app.presence.update') {
      applyPresence(String(message.payload_type || ''), message.payload || {}, String(message.actor_id || ''));
    } else if (message.type === 'call_app.crdt.error') {
      applyAccessState(message);
      const reason = String(message.reason || '').trim();
      setStatus(reason === 'participant_grant_denied'
        ? 'Access revoked for this whiteboard.'
        : String(message.message || 'CRDT bridge error.'));
    }
  });

  render();
  setStatus('Waiting for Call App launch.');
})();
