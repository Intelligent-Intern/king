(() => {
  const bridgeProtocol = 'king.call_app.iframe.v1';
  const appKey = 'planning-image';
  const maxImageBytes = 8 * 1024 * 1024;
  const canvas = document.getElementById('imageCanvas');
  const ctx = canvas.getContext('2d');
  const imageInput = document.getElementById('imageInput');
  const statusEl = document.getElementById('status');
  const imageMetaEl = document.getElementById('imageMeta');
  const modeBadge = document.getElementById('modeBadge');
  const uploadButton = document.querySelector('.upload-button');
  const controls = {
    fit: document.getElementById('fitImage'),
    reset: document.getElementById('resetZoom'),
    out: document.getElementById('zoomOut'),
    in: document.getElementById('zoomIn'),
    exportPng: document.getElementById('exportPng'),
  };

  let parentOrigin = '*';
  let appSessionId = '';
  let callId = '';
  let documentId = '';
  let actorId = '';
  let grantState = 'denied';
  let capabilities = new Set();
  let permissionActions = new Set();
  let latestClock = 0;
  let pollTimer = 0;
  let imageElement = null;
  let sharedImage = null;
  let pendingFit = false;
  const appliedOps = new Set();
  const view = { scale: 1, offsetX: 0, offsetY: 0, dragging: false, lastX: 0, lastY: 0 };

  function setStatus(message) {
    statusEl.textContent = String(message || '');
  }

  function setMode() {
    const readable = canRead();
    const writable = canWrite();
    modeBadge.textContent = writable ? 'Editor' : (readable ? 'Viewer' : 'No access');
    imageInput.disabled = !writable;
    uploadButton.classList.toggle('disabled', !writable);
  }

  function canRead() {
    return grantState === 'allowed' && capabilities.has('call_apps.crdt.read');
  }

  function canWrite() {
    return grantState === 'allowed'
      && capabilities.has('call_apps.crdt.append')
      && permissionActions.has('write');
  }

  function emit(type, payload = {}) {
    window.parent.postMessage({ bridge_protocol: bridgeProtocol, type, app_key: appKey, ...payload }, parentOrigin || '*');
  }

  function operationId(type) {
    return `planning_${type.replaceAll('.', '_')}_${Date.now()}_${Math.random().toString(36).slice(2, 10)}`;
  }

  function appendOperation(payloadType, payload) {
    if (!canWrite()) {
      setStatus('Viewer mode. Upload is disabled for this participant.');
      return;
    }
    emit('call_app.crdt.op.append', {
      request_id: operationId('request'),
      operation: {
        operation_id: operationId(payloadType),
        payload_type: payloadType,
        causal_dependencies: latestClock > 0 ? [{ logical_clock: latestClock }] : [],
        payload,
      },
    });
    setStatus('Shared image update queued.');
  }

  function requestBootstrap(afterClock = 0) {
    emit('call_app.crdt.bootstrap.request', {
      request_id: operationId('bootstrap'),
      after_clock: afterClock,
    });
  }

  function requestOps() {
    if (!canRead()) return;
    emit('call_app.crdt.ops.request', {
      request_id: operationId('ops'),
      after_clock: latestClock,
      limit: 100,
    });
  }

  function resizeCanvas() {
    const rect = canvas.getBoundingClientRect();
    const dpr = Math.max(1, window.devicePixelRatio || 1);
    const nextWidth = Math.max(320, Math.floor(rect.width * dpr));
    const nextHeight = Math.max(220, Math.floor(rect.height * dpr));
    if (canvas.width !== nextWidth || canvas.height !== nextHeight) {
      canvas.width = nextWidth;
      canvas.height = nextHeight;
    }
    render();
  }

  function viewportSize() {
    const dpr = Math.max(1, window.devicePixelRatio || 1);
    return { width: canvas.width / dpr, height: canvas.height / dpr, dpr };
  }

  function fitImage() {
    if (!imageElement) return;
    const box = viewportSize();
    const scaleX = (box.width - 36) / imageElement.naturalWidth;
    const scaleY = (box.height - 36) / imageElement.naturalHeight;
    view.scale = Math.max(0.05, Math.min(8, Math.min(scaleX, scaleY)));
    view.offsetX = (box.width - imageElement.naturalWidth * view.scale) / 2;
    view.offsetY = (box.height - imageElement.naturalHeight * view.scale) / 2;
    render();
  }

  function resetZoom() {
    if (!imageElement) return;
    const box = viewportSize();
    view.scale = 1;
    view.offsetX = (box.width - imageElement.naturalWidth) / 2;
    view.offsetY = (box.height - imageElement.naturalHeight) / 2;
    render();
  }

  function zoomAt(factor, x, y) {
    if (!imageElement) return;
    const previous = view.scale;
    const next = Math.max(0.05, Math.min(12, previous * factor));
    const imageX = (x - view.offsetX) / previous;
    const imageY = (y - view.offsetY) / previous;
    view.scale = next;
    view.offsetX = x - imageX * next;
    view.offsetY = y - imageY * next;
    render();
  }

  function drawEmpty(box) {
    ctx.fillStyle = '#0a0f16';
    ctx.fillRect(0, 0, box.width, box.height);
    ctx.fillStyle = '#9fb0c3';
    ctx.font = '600 18px system-ui, sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText('Upload an image to review it together.', box.width / 2, box.height / 2 - 8);
    ctx.font = '13px system-ui, sans-serif';
    ctx.fillText('Use wheel or buttons to zoom, then drag to pan.', box.width / 2, box.height / 2 + 18);
  }

  function render() {
    const box = viewportSize();
    ctx.setTransform(box.dpr, 0, 0, box.dpr, 0, 0);
    ctx.clearRect(0, 0, box.width, box.height);
    if (!imageElement) {
      drawEmpty(box);
      imageMetaEl.textContent = 'No image';
      return;
    }
    ctx.fillStyle = '#05080d';
    ctx.fillRect(0, 0, box.width, box.height);
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(
      imageElement,
      view.offsetX,
      view.offsetY,
      imageElement.naturalWidth * view.scale,
      imageElement.naturalHeight * view.scale,
    );
    const zoom = Math.round(view.scale * 100);
    imageMetaEl.textContent = `${sharedImage?.name || 'Image'} - ${imageElement.naturalWidth}x${imageElement.naturalHeight} - ${zoom}%`;
  }

  function applyImagePayload(payload, fit = false) {
    const dataUrl = String(payload?.data_url || '').trim();
    if (!dataUrl.startsWith('data:image/')) return;
    const img = new Image();
    img.onload = () => {
      imageElement = img;
      sharedImage = {
        data_url: dataUrl,
        name: String(payload.name || 'image').slice(0, 120),
        mime_type: String(payload.mime_type || ''),
        updated_at: String(payload.updated_at || ''),
      };
      if (fit || pendingFit) {
        pendingFit = false;
        fitImage();
      } else {
        render();
      }
      setStatus(canWrite() ? 'Image synchronized.' : 'Read-only image synchronized.');
    };
    img.onerror = () => setStatus('Could not decode the shared image.');
    img.src = dataUrl;
  }

  function clearImage() {
    imageElement = null;
    sharedImage = null;
    render();
  }

  function applySnapshot(snapshot) {
    if (snapshot?.kind !== 'planning_image.snapshot.v1') return;
    const image = snapshot.state?.image;
    if (image?.data_url) applyImagePayload(image, true);
  }

  function applyEnvelope(envelope) {
    if (!envelope || appliedOps.has(envelope.operation_id)) return;
    appliedOps.add(envelope.operation_id);
    latestClock = Math.max(latestClock, Number(envelope.logical_clock || 0));
    const payloadType = String(envelope.payload_type || '');
    if (payloadType === 'planning_image.replace') {
      applyImagePayload(envelope.payload || {}, true);
    } else if (payloadType === 'planning_image.clear') {
      clearImage();
    }
  }

  function applyAccessState(result = {}) {
    if (typeof result.grant_state === 'string') grantState = result.grant_state;
    if (Array.isArray(result.permission_actions)) permissionActions = new Set(result.permission_actions);
    setMode();
  }

  function handleCrdtResult(result = {}) {
    applyAccessState(result);
    if (result.snapshot) applySnapshot(result.snapshot);
    for (const envelope of result.ops || []) applyEnvelope(envelope);
    setStatus(canWrite() ? 'Image planning synchronized.' : 'Read-only image planning synchronized.');
  }

  function readImageFile(file) {
    if (!file || !canWrite()) return;
    if (!String(file.type || '').startsWith('image/')) {
      setStatus('Choose an image file.');
      return;
    }
    if (file.size > maxImageBytes) {
      setStatus('Image is too large for shared planning. Use an image below 8 MB.');
      return;
    }
    const reader = new FileReader();
    reader.onload = () => {
      const dataUrl = String(reader.result || '');
      const payload = {
        data_url: dataUrl,
        name: file.name || 'planning-image',
        mime_type: file.type || 'image/*',
        size_bytes: file.size,
        updated_at: new Date().toISOString(),
      };
      pendingFit = true;
      applyImagePayload(payload, true);
      appendOperation('planning_image.replace', payload);
    };
    reader.onerror = () => setStatus('Image upload failed.');
    reader.readAsDataURL(file);
  }

  function exportPng() {
    if (!imageElement) {
      setStatus('No image to export.');
      return;
    }
    const link = document.createElement('a');
    link.href = canvas.toDataURL('image/png');
    link.download = 'kingrt-planning-image.png';
    link.click();
  }

  imageInput.addEventListener('change', () => {
    readImageFile(imageInput.files?.[0] || null);
    imageInput.value = '';
  });
  controls.fit.addEventListener('click', fitImage);
  controls.reset.addEventListener('click', resetZoom);
  controls.out.addEventListener('click', () => zoomAt(0.8, viewportSize().width / 2, viewportSize().height / 2));
  controls.in.addEventListener('click', () => zoomAt(1.25, viewportSize().width / 2, viewportSize().height / 2));
  controls.exportPng.addEventListener('click', exportPng);

  canvas.addEventListener('pointerdown', (event) => {
    if (!imageElement) return;
    view.dragging = true;
    view.lastX = event.clientX;
    view.lastY = event.clientY;
    canvas.classList.add('dragging');
    canvas.setPointerCapture(event.pointerId);
  });
  canvas.addEventListener('pointermove', (event) => {
    if (!view.dragging) return;
    view.offsetX += event.clientX - view.lastX;
    view.offsetY += event.clientY - view.lastY;
    view.lastX = event.clientX;
    view.lastY = event.clientY;
    render();
  });
  const endDrag = (event) => {
    view.dragging = false;
    canvas.classList.remove('dragging');
    try { canvas.releasePointerCapture(event.pointerId); } catch {}
  };
  canvas.addEventListener('pointerup', endDrag);
  canvas.addEventListener('pointercancel', endDrag);
  canvas.addEventListener('wheel', (event) => {
    event.preventDefault();
    const rect = canvas.getBoundingClientRect();
    zoomAt(event.deltaY < 0 ? 1.1 : 0.9, event.clientX - rect.left, event.clientY - rect.top);
  }, { passive: false });

  window.addEventListener('resize', resizeCanvas);
  window.addEventListener('message', (event) => {
    const message = event.data && typeof event.data === 'object' ? event.data : null;
    if (!message || message.bridge_protocol !== bridgeProtocol) return;
    if (message.type === 'call_app.launch') {
      parentOrigin = event.origin;
      appSessionId = String(message.app_session_id || '');
      callId = String(message.call_id || '');
      documentId = String(message.document_id || '');
      const context = message.launch_context || {};
      actorId = String(context.participant?.actor_id || '');
      grantState = String(context.grant_state || 'denied');
      capabilities = new Set(Array.isArray(message.capabilities) ? message.capabilities : []);
      permissionActions = new Set(Array.isArray(context.permission_actions) ? context.permission_actions : []);
      setMode();
      emit('call_app.ready', {
        app_session_id: appSessionId,
        call_id: callId,
        document_id: documentId,
        actor_id: actorId,
        launch_token_received: Boolean(message.launch_token),
        primary_session_token_received: false,
        capabilities: Array.isArray(message.capabilities) ? message.capabilities : [],
      });
      clearInterval(pollTimer);
      if (canRead()) {
        requestBootstrap(0);
        pollTimer = window.setInterval(requestOps, 2000);
        setStatus('Loading shared image.');
      } else {
        setStatus('Access not granted for this image planning session.');
      }
    } else if (message.type === 'call_app.crdt.bootstrap.response') {
      handleCrdtResult(message.result || {});
    } else if (message.type === 'call_app.crdt.ops.response') {
      handleCrdtResult(message.result || {});
    } else if (message.type === 'call_app.crdt.op.appended') {
      if (message.result?.operation) applyEnvelope(message.result.operation);
    } else if (message.type === 'call_app.crdt.error') {
      applyAccessState(message);
      setStatus(String(message.message || 'Call App sync error.'));
    }
  });

  setMode();
  resizeCanvas();
})();
