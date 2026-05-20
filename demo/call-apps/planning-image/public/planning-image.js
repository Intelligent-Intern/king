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
  const imagePicker = document.getElementById('imagePicker');
  const imageThumbList = document.getElementById('imageThumbList');
  const controls = {
    fit: document.getElementById('fitImage'),
    reset: document.getElementById('resetZoom'),
    out: document.getElementById('zoomOut'),
    in: document.getElementById('zoomIn'),
    deleteImage: document.getElementById('deleteImage'),
    exportPng: document.getElementById('exportPng'),
  };

  let parentOrigin = '*';
  let appSessionId = '';
  let callId = '';
  let documentId = '';
  let actorId = '';
  let actorDisplayName = '';
  let grantState = 'denied';
  let capabilities = new Set();
  let permissionActions = new Set();
  let latestClock = 0;
  let pollTimer = 0;
  let imageElement = null;
  let sharedImage = null;
  let selectedImageId = '';
  let pendingFit = false;
  const images = new Map();
  const appliedOps = new Set();
  const view = { scale: 1, offsetX: 0, offsetY: 0, dragging: false, lastX: 0, lastY: 0 };

  function setStatus(message) {
    statusEl.textContent = String(message || '');
  }

  function canRead() {
    return grantState === 'allowed' && capabilities.has('call_apps.crdt.read');
  }

  function canUpload() {
    return grantState === 'allowed'
      && capabilities.has('call_apps.crdt.append')
      && permissionActions.has('write');
  }

  function canWrite() {
    return canUpload();
  }

  function canDeleteByPermission() {
    return grantState === 'allowed' && permissionActions.has('delete');
  }

  function currentImage() {
    return selectedImageId !== '' ? images.get(selectedImageId) || null : null;
  }

  function canDeleteImage(image = currentImage()) {
    if (!image || grantState !== 'allowed') return false;
    const uploadedBy = String(image.uploaded_by_actor_id || '').trim();
    return canDeleteByPermission() || (uploadedBy !== '' && uploadedBy === actorId);
  }

  function setMode() {
    const labels = [];
    if (canRead()) labels.push('View');
    if (canUpload()) labels.push('Upload');
    if (canDeleteByPermission()) labels.push('Delete');
    modeBadge.textContent = labels.length > 0 ? labels.join(' / ') : 'No access';
    imageInput.disabled = !canUpload();
    uploadButton.classList.toggle('disabled', !canUpload());
    updateControls();
  }

  function emit(type, payload = {}) {
    window.parent.postMessage({ bridge_protocol: bridgeProtocol, type, app_key: appKey, ...payload }, parentOrigin || '*');
  }

  function operationId(type) {
    return `planning_${type.replaceAll('.', '_')}_${Date.now()}_${Math.random().toString(36).slice(2, 10)}`;
  }

  function imageUuid() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
      return `img_${window.crypto.randomUUID()}`;
    }
    return operationId('image');
  }

  function appendOperation(payloadType, payload, requiredAction = 'write') {
    if (requiredAction === 'write' && !canUpload()) {
      setStatus('Upload permission is disabled for this participant.');
      return false;
    }
    if (requiredAction === 'delete' && !canDeleteImage(images.get(String(payload?.image_id || '').trim()))) {
      setStatus('Delete permission is disabled for this image.');
      return false;
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
    return true;
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
      imageMetaEl.textContent = images.size > 0 ? 'Choose an image' : 'No image';
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
    const owner = sharedImage?.uploaded_by_display_name ? ` by ${sharedImage.uploaded_by_display_name}` : '';
    imageMetaEl.textContent = `${sharedImage?.name || 'Image'}${owner} - ${imageElement.naturalWidth}x${imageElement.naturalHeight} - ${zoom}%`;
  }

  function updateControls() {
    const hasImage = Boolean(currentImage());
    controls.fit.disabled = !hasImage;
    controls.reset.disabled = !hasImage;
    controls.out.disabled = !hasImage;
    controls.in.disabled = !hasImage;
    controls.deleteImage.disabled = !hasImage || !canDeleteImage();
    controls.exportPng.disabled = !hasImage;
  }

  function renderThumbnails() {
    imageThumbList.replaceChildren();
    const rows = Array.from(images.values());
    imagePicker.classList.toggle('empty', rows.length === 0);
    for (const image of rows) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'image-thumb';
      button.classList.toggle('active', image.image_id === selectedImageId);
      button.title = image.name;
      button.dataset.imageId = image.image_id;

      const thumb = document.createElement('img');
      thumb.alt = '';
      thumb.src = image.data_url;
      button.appendChild(thumb);

      const label = document.createElement('span');
      label.className = 'image-thumb-name';
      label.textContent = image.name;
      button.appendChild(label);

      if (canDeleteImage(image)) {
        const marker = document.createElement('span');
        marker.className = 'image-thumb-delete-marker';
        marker.textContent = 'Del';
        button.appendChild(marker);
      }

      button.addEventListener('click', () => {
        selectImage(image.image_id, { fit: false, broadcast: canUpload() });
      });
      imageThumbList.appendChild(button);
    }
    updateControls();
  }

  function normalizeImagePayload(payload) {
    const dataUrl = String(payload?.data_url || '').trim();
    if (!dataUrl.startsWith('data:image/')) return null;
    const imageId = String(payload?.image_id || payload?.imageId || '').trim() || imageUuid();
    const uploadedBy = String(payload?.uploaded_by_actor_id || payload?.uploadedByActorId || '').trim();
    return {
      image_id: imageId,
      data_url: dataUrl,
      name: String(payload.name || 'image').slice(0, 120),
      mime_type: String(payload.mime_type || ''),
      size_bytes: Number(payload.size_bytes || 0) || 0,
      uploaded_by_actor_id: uploadedBy,
      uploaded_by_display_name: String(payload.uploaded_by_display_name || '').slice(0, 80),
      uploaded_at: String(payload.uploaded_at || payload.updated_at || ''),
      updated_at: String(payload.updated_at || ''),
    };
  }

  function selectFallbackImage() {
    const last = Array.from(images.keys()).pop() || '';
    if (last !== '') {
      selectImage(last, { fit: true, broadcast: false });
      return;
    }
    selectedImageId = '';
    imageElement = null;
    sharedImage = null;
    renderThumbnails();
    render();
  }

  function selectImage(imageId, options = {}) {
    const normalizedImageId = String(imageId || '').trim();
    const image = images.get(normalizedImageId);
    if (!image) {
      if (selectedImageId === normalizedImageId) selectFallbackImage();
      return;
    }
    selectedImageId = normalizedImageId;
    sharedImage = image;
    const img = new Image();
    img.onload = () => {
      if (selectedImageId !== normalizedImageId) return;
      imageElement = img;
      if (options.fit || pendingFit) {
        pendingFit = false;
        fitImage();
      } else {
        render();
      }
      renderThumbnails();
      setStatus(canUpload() ? 'Image synchronized.' : 'Read-only image synchronized.');
    };
    img.onerror = () => setStatus('Could not decode the shared image.');
    img.src = image.data_url;
    renderThumbnails();
    if (options.broadcast === true) {
      appendOperation('planning_image.select', { image_id: normalizedImageId }, 'write');
    }
  }

  function applyImagePayload(payload, fit = false) {
    const image = normalizeImagePayload(payload);
    if (!image) return;
    images.set(image.image_id, image);
    if (selectedImageId === '' || fit) {
      selectImage(image.image_id, { fit: true, broadcast: false });
    } else {
      renderThumbnails();
    }
  }

  function applySelectPayload(payload) {
    const imageId = String(payload?.image_id || '').trim();
    if (imageId !== '') selectImage(imageId, { fit: false, broadcast: false });
  }

  function applyDeletePayload(payload) {
    const imageId = String(payload?.image_id || '').trim();
    if (imageId === '') return;
    images.delete(imageId);
    if (selectedImageId === imageId) {
      selectFallbackImage();
    } else {
      renderThumbnails();
      render();
    }
  }

  function clearImage() {
    images.clear();
    imageElement = null;
    sharedImage = null;
    selectedImageId = '';
    renderThumbnails();
    render();
  }

  function applySnapshot(snapshot) {
    if (snapshot?.kind !== 'planning_image.snapshot.v1') return;
    images.clear();
    for (const image of Array.isArray(snapshot.state?.images) ? snapshot.state.images : []) {
      const normalized = normalizeImagePayload(image);
      if (normalized) images.set(normalized.image_id, normalized);
    }
    const nextSelectedId = String(snapshot.state?.selected_image_id || '').trim();
    if (nextSelectedId !== '' && images.has(nextSelectedId)) {
      selectImage(nextSelectedId, { fit: true, broadcast: false });
    } else {
      selectFallbackImage();
    }
  }

  function applyEnvelope(envelope) {
    if (!envelope || appliedOps.has(envelope.operation_id)) return;
    appliedOps.add(envelope.operation_id);
    latestClock = Math.max(latestClock, Number(envelope.logical_clock || 0));
    const payloadType = String(envelope.payload_type || '');
    if (payloadType === 'planning_image.add' || payloadType === 'planning_image.replace') {
      applyImagePayload(envelope.payload || {}, true);
    } else if (payloadType === 'planning_image.select') {
      applySelectPayload(envelope.payload || {});
    } else if (payloadType === 'planning_image.delete') {
      applyDeletePayload(envelope.payload || {});
    } else if (payloadType === 'planning_image.clear') {
      clearImage();
    }
  }

  function applyAccessState(result = {}) {
    if (typeof result.grant_state === 'string') grantState = result.grant_state;
    if (Array.isArray(result.permission_actions)) permissionActions = new Set(result.permission_actions);
    setMode();
    renderThumbnails();
  }

  function handleCrdtResult(result = {}) {
    applyAccessState(result);
    if (result.document?.snapshot_clock) latestClock = Math.max(latestClock, Number(result.document.snapshot_clock));
    applySnapshot(result.document?.snapshot || result.snapshot || {});
    for (const envelope of result.ops || []) applyEnvelope(envelope);
    setStatus(canUpload() ? 'Image planning synchronized.' : 'Read-only image planning synchronized.');
  }

  function readImageFile(file) {
    if (!file || !canUpload()) return;
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
        image_id: imageUuid(),
        data_url: dataUrl,
        name: file.name || 'planning-image',
        mime_type: file.type || 'image/*',
        size_bytes: file.size,
        uploaded_by_actor_id: actorId,
        uploaded_by_display_name: actorDisplayName,
        uploaded_at: new Date().toISOString(),
        updated_at: new Date().toISOString(),
      };
      pendingFit = true;
      applyImagePayload(payload, true);
      appendOperation('planning_image.add', payload, 'write');
      appendOperation('planning_image.select', { image_id: payload.image_id }, 'write');
      setStatus('Image shared with call participants.');
    };
    reader.onerror = () => setStatus('Image upload failed.');
    reader.readAsDataURL(file);
  }

  function deleteCurrentImage() {
    const image = currentImage();
    if (!image || !canDeleteImage(image)) {
      setStatus('Delete permission is disabled for this image.');
      return;
    }
    const payload = {
      image_id: image.image_id,
      deleted_by_actor_id: actorId,
      deleted_at: new Date().toISOString(),
    };
    if (appendOperation('planning_image.delete', payload, 'delete')) {
      applyDeletePayload(payload);
      setStatus('Image removed from this planning session.');
    }
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

  function isTextInputTarget(target) {
    const tag = String(target?.tagName || '').toUpperCase();
    return target?.isContentEditable === true || ['INPUT', 'TEXTAREA', 'SELECT'].includes(tag);
  }

  imageInput.addEventListener('change', () => {
    for (const file of Array.from(imageInput.files || [])) {
      readImageFile(file);
    }
    imageInput.value = '';
  });
  controls.fit.addEventListener('click', fitImage);
  controls.reset.addEventListener('click', resetZoom);
  controls.out.addEventListener('click', () => zoomAt(0.8, viewportSize().width / 2, viewportSize().height / 2));
  controls.in.addEventListener('click', () => zoomAt(1.25, viewportSize().width / 2, viewportSize().height / 2));
  controls.deleteImage.addEventListener('click', deleteCurrentImage);
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

  window.addEventListener('keydown', (event) => {
    if (event.key !== 'Delete' || isTextInputTarget(event.target)) return;
    if (!currentImage()) return;
    event.preventDefault();
    deleteCurrentImage();
  });

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
      actorDisplayName = String(context.participant?.display_name || '').trim();
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
        setStatus('Loading shared images.');
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
  renderThumbnails();
  resizeCanvas();
})();
