(() => {
  const bridgeProtocol = 'king.call_app.iframe.v1';
  const appKey = 'presentation';
  const pptxMime = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
  const slideCx = 12192000;
  const slideCy = 6858000;
  const dom = {
    addSlide: document.getElementById('addSlide'),
    deleteSlide: document.getElementById('deleteSlide'),
    addRect: document.getElementById('addRect'),
    addEllipse: document.getElementById('addEllipse'),
    addImage: document.getElementById('addImagePlaceholder'),
    deleteObject: document.getElementById('deleteObject'),
    presentToggle: document.getElementById('presentToggle'),
    prevSlide: document.getElementById('prevSlide'),
    nextSlide: document.getElementById('nextSlide'),
    exportPptx: document.getElementById('exportPptx'),
    modeBadge: document.getElementById('modeBadge'),
    thumbnailList: document.getElementById('thumbnailList'),
    slideStage: document.getElementById('slideStage'),
    title: document.getElementById('slideTitle'),
    body: document.getElementById('slideBody'),
    objectLayer: document.getElementById('objectLayer'),
    status: document.getElementById('status'),
    slideMeta: document.getElementById('slideMeta'),
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
  let textTimer = 0;
  let selectedObjectId = '';
  let dragState = null;
  const appliedOps = new Set();
  const state = {
    slides: [createSlide('slide_1', 'Project update', 'Agenda\nStatus\nNext steps')],
    selectedSlideId: 'slide_1',
    playback: { active: false, slideId: 'slide_1' },
  };

  function createSlide(id, title = 'Untitled slide', body = 'Add talking points') {
    return { id, title, body, objects: [] };
  }

  function cleanText(value, maxLength = 1800) {
    return String(value || '').replace(/\u0000/g, '').slice(0, maxLength);
  }

  function clamp(value, min, max) {
    return Math.max(min, Math.min(max, Number(value) || 0));
  }

  function objectPayload(type) {
    const count = selectedSlide().objects.length;
    return {
      id: makeId(type === 'image_placeholder' ? 'image' : 'shape'),
      type,
      x: 16 + (count % 3) * 9,
      y: 42 + (count % 2) * 14,
      w: type === 'image_placeholder' ? 30 : 22,
      h: type === 'image_placeholder' ? 22 : 16,
      text: type === 'image_placeholder' ? 'Image placeholder' : '',
      fill: type === 'ellipse' ? '#FCE6B8' : '#DFF8F2',
      stroke: type === 'image_placeholder' ? '#667381' : '#355161',
    };
  }

  function canRead() {
    return grantState === 'allowed'
      && capabilities.has('call_apps.crdt.read')
      && permissionActions.has('read');
  }

  function canWrite() {
    return grantState === 'allowed'
      && capabilities.has('call_apps.crdt.append')
      && permissionActions.has('write');
  }

  function canDelete() {
    return grantState === 'allowed' && permissionActions.has('delete');
  }

  function canExport() {
    return grantState === 'allowed'
      && capabilities.has('call_apps.export.download')
      && permissionActions.has('read');
  }

  function canAppendPayload(payloadType) {
    return String(payloadType || '').trim().toLowerCase().endsWith('.delete') ? canDelete() : canWrite();
  }

  function slideById(slideId) {
    return state.slides.find((slide) => slide.id === slideId) || null;
  }

  function selectedSlide() {
    return slideById(state.selectedSlideId) || state.slides[0];
  }

  function activeSlide() {
    if (state.playback.active) {
      return slideById(state.playback.slideId) || selectedSlide();
    }
    return selectedSlide();
  }

  function ensureSlides() {
    if (state.slides.length === 0) {
      state.slides.push(createSlide('slide_1', 'Project update', 'Agenda\nStatus\nNext steps'));
    }
    if (!slideById(state.selectedSlideId)) state.selectedSlideId = state.slides[0].id;
    if (!slideById(state.playback.slideId)) state.playback.slideId = state.selectedSlideId;
  }

  function setStatus(message) {
    dom.status.textContent = String(message || '');
    dom.modeBadge.textContent = canWrite() ? 'Editor' : (canRead() ? 'Viewer' : 'No access');
    dom.slideMeta.textContent = `${state.slides.length} slides - ${latestClock} ops`;
  }

  function setControls() {
    const readable = canRead();
    const writable = canWrite();
    const deletable = canDelete();
    const selectedObject = Boolean(selectedObjectId && selectedSlide().objects.some((object) => object.id === selectedObjectId));
    dom.title.disabled = !writable;
    dom.body.disabled = !writable;
    dom.addSlide.disabled = !writable;
    dom.addRect.disabled = !writable;
    dom.addEllipse.disabled = !writable;
    dom.addImage.disabled = !writable;
    dom.presentToggle.disabled = !writable;
    dom.deleteSlide.disabled = !deletable || state.slides.length <= 1;
    dom.deleteObject.disabled = !deletable || !selectedObject;
    dom.prevSlide.disabled = !readable || state.slides.length <= 1 || (state.playback.active && !writable);
    dom.nextSlide.disabled = dom.prevSlide.disabled;
    dom.exportPptx.disabled = !canExport();
    dom.presentToggle.textContent = state.playback.active ? 'Stop' : 'Present';
    dom.slideStage.classList.toggle('playback', state.playback.active);
  }

  function emit(type, payload = {}) {
    if (!parentOrigin || !window.parent) return;
    const message = JSON.parse(JSON.stringify({
      type,
      bridge_protocol: bridgeProtocol,
      app_key: appKey,
      app_session_id: appSessionId,
      ...payload,
    }));
    window.parent.postMessage(message, parentOrigin);
  }

  function makeId(prefix) {
    return `${prefix}_${Date.now()}_${Math.random().toString(36).slice(2, 9)}`;
  }

  function operationId(type) {
    return `presentation_${type.replaceAll('.', '_')}_${Date.now()}_${Math.random().toString(36).slice(2, 9)}`;
  }

  function appendOperation(payloadType, payload, options = {}) {
    if (!canAppendPayload(payloadType)) {
      setStatus(payloadType.endsWith('.delete') ? 'Delete permission is not granted.' : 'Write permission is not granted.');
      setControls();
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
    if (options.optimistic !== false) {
      applyOperation(payloadType, payload);
      render();
    }
    setStatus('Presentation update queued.');
    return operation;
  }

  function requestBootstrap(afterClock = 0) {
    if (!canRead()) return;
    emit('call_app.crdt.bootstrap.request', {
      request_id: operationId('bootstrap'),
      after_clock: afterClock,
    });
  }

  function requestOps() {
    if (!appSessionId || !canRead()) return;
    emit('call_app.crdt.ops.request', {
      request_id: operationId('ops'),
      after_clock: latestClock,
      limit: 160,
    });
  }

  function renderThumbnails() {
    dom.thumbnailList.textContent = '';
    state.slides.forEach((slide, index) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = `thumbnail ${slide.id === activeSlide().id ? 'active' : ''}`;
      button.disabled = !canRead();
      button.innerHTML = `<span class="thumbnail-title"></span><span class="thumbnail-index"></span>`;
      button.querySelector('.thumbnail-title').textContent = slide.title || 'Untitled slide';
      button.querySelector('.thumbnail-index').textContent = `Slide ${index + 1}`;
      button.addEventListener('click', () => selectSlide(slide.id));
      dom.thumbnailList.appendChild(button);
    });
  }

  function renderStage() {
    const slide = activeSlide();
    if (document.activeElement !== dom.title) dom.title.value = slide.title || '';
    if (document.activeElement !== dom.body) dom.body.value = slide.body || '';
    dom.objectLayer.textContent = '';
    slide.objects.forEach((object) => {
      const element = document.createElement('div');
      element.className = `slide-object ${object.type || 'rect'} ${object.id === selectedObjectId ? 'selected' : ''}`;
      element.setAttribute('role', 'button');
      element.tabIndex = 0;
      element.dataset.objectId = object.id;
      element.textContent = object.type === 'image_placeholder' ? (object.text || 'Image placeholder') : (object.text || '');
      positionObjectElement(element, object);
      element.addEventListener('pointerdown', (event) => startObjectDrag(event, object, element));
      element.addEventListener('click', () => {
        selectedObjectId = object.id;
        syncObjectSelection();
        setControls();
      });
      dom.objectLayer.appendChild(element);
    });
  }

  function render() {
    ensureSlides();
    renderThumbnails();
    renderStage();
    setControls();
    const message = appSessionId === ''
      ? 'Waiting for Call App launch.'
      : (canWrite() ? 'Presentation synchronized.' : (canRead() ? 'Read-only presentation synchronized.' : 'Access not granted.'));
    setStatus(message);
  }

  function positionObjectElement(element, object) {
    element.style.left = `${clamp(object.x, 0, 96)}%`;
    element.style.top = `${clamp(object.y, 0, 96)}%`;
    element.style.width = `${clamp(object.w, 4, 80)}%`;
    element.style.height = `${clamp(object.h, 4, 70)}%`;
  }

  function syncObjectSelection() {
    dom.objectLayer.querySelectorAll('.slide-object').forEach((element) => {
      element.classList.toggle('selected', element.dataset.objectId === selectedObjectId);
    });
  }

  function selectSlide(slideId) {
    if (!slideById(slideId)) return;
    dom.title.blur();
    dom.body.blur();
    selectedObjectId = '';
    state.selectedSlideId = slideId;
    if (state.playback.active && canWrite()) {
      appendOperation('presentation.playback.update', { active: true, slide_id: slideId });
    } else {
      render();
    }
  }

  function normalizeSlide(raw) {
    const slide = raw && typeof raw === 'object' ? raw : {};
    const id = cleanText(slide.id || makeId('slide'), 80);
    const objects = Array.isArray(slide.objects) ? slide.objects.map(normalizeObject).filter(Boolean) : [];
    return {
      id,
      title: cleanText(slide.title || 'Untitled slide', 140),
      body: cleanText(slide.body || '', 1800),
      objects,
    };
  }

  function normalizeObject(raw) {
    const object = raw && typeof raw === 'object' ? raw : {};
    const type = ['rect', 'ellipse', 'image_placeholder'].includes(object.type) ? object.type : 'rect';
    const id = cleanText(object.id || makeId('object'), 80);
    return {
      id,
      type,
      x: clamp(object.x, 0, 96),
      y: clamp(object.y, 0, 96),
      w: clamp(object.w || 20, 4, 80),
      h: clamp(object.h || 14, 4, 70),
      text: cleanText(object.text || '', 160),
      fill: cleanText(object.fill || '#DFF8F2', 16),
      stroke: cleanText(object.stroke || '#355161', 16),
    };
  }

  function applyOperation(payloadType, payload = {}) {
    const type = String(payloadType || '');
    const slideId = cleanText(payload.slide_id || payload.slideId || '', 80);
    if (type === 'presentation.slide.add') {
      const slide = normalizeSlide(payload.slide || payload);
      if (!slideById(slide.id)) state.slides.push(slide);
    } else if (type === 'presentation.playback.update') {
      const nextSlideId = slideById(slideId) ? slideId : state.selectedSlideId;
      state.playback = { active: payload.active === true, slideId: nextSlideId };
      if (state.playback.active) state.selectedSlideId = nextSlideId;
    } else if (type === 'presentation.slide.update' || type === 'presentation.text.update') {
      const slide = slideById(slideId);
      if (slide) {
        if (payload.title !== undefined) slide.title = cleanText(payload.title, 140);
        if (payload.body !== undefined) slide.body = cleanText(payload.body, 1800);
      }
    } else if (type === 'presentation.slide.delete') {
      const index = state.slides.findIndex((slide) => slide.id === slideId);
      if (index >= 0 && state.slides.length > 1) state.slides.splice(index, 1);
      if (state.selectedSlideId === slideId) state.selectedSlideId = state.slides[Math.max(0, index - 1)]?.id || state.slides[0]?.id || '';
      if (state.playback.slideId === slideId) state.playback.slideId = state.selectedSlideId;
    } else if (type.endsWith('.add')) {
      const slide = slideById(slideId);
      const object = normalizeObject(payload.object || payload);
      if (slide && !slide.objects.some((entry) => entry.id === object.id)) slide.objects.push(object);
    } else if (type.endsWith('.update')) {
      const slide = slideById(slideId);
      const object = normalizeObject(payload.object || payload);
      const index = slide ? slide.objects.findIndex((entry) => entry.id === object.id) : -1;
      if (slide && index >= 0) slide.objects[index] = { ...slide.objects[index], ...object };
    } else if (type.endsWith('.delete')) {
      const slide = slideById(slideId);
      const objectId = cleanText(payload.object_id || payload.objectId || payload.id || '', 80);
      if (slide) slide.objects = slide.objects.filter((object) => object.id !== objectId);
      if (selectedObjectId === objectId) selectedObjectId = '';
    }
  }

  function applyEnvelope(envelope) {
    if (!envelope || appliedOps.has(envelope.operation_id)) return;
    appliedOps.add(envelope.operation_id);
    latestClock = Math.max(latestClock, Number(envelope.logical_clock || 0));
    applyOperation(String(envelope.payload_type || ''), envelope.payload || {});
  }

  function applyAccessState(result = {}) {
    const nextGrantState = String(result.grant_state || '').trim().toLowerCase();
    if (nextGrantState) grantState = nextGrantState;
    if (Array.isArray(result.permission_actions)) permissionActions = new Set(result.permission_actions);
    if (!canRead()) {
      clearInterval(pollTimer);
      pollTimer = 0;
    }
    setControls();
  }

  function handleCrdtResult(result = {}) {
    applyAccessState(result);
    (result.ops || []).forEach(applyEnvelope);
    render();
  }

  function queueTextUpdate() {
    if (!canWrite()) {
      render();
      return;
    }
    const slide = activeSlide();
    slide.title = cleanText(dom.title.value, 140);
    slide.body = cleanText(dom.body.value, 1800);
    renderThumbnails();
    clearTimeout(textTimer);
    textTimer = window.setTimeout(() => {
      appendOperation('presentation.text.update', {
        slide_id: slide.id,
        title: slide.title,
        body: slide.body,
      }, { optimistic: false });
    }, 320);
  }

  function addSlide() {
    const slide = createSlide(makeId('slide'), 'Untitled slide', 'Add talking points');
    state.selectedSlideId = slide.id;
    appendOperation('presentation.slide.add', { slide });
  }

  function deleteSlide() {
    const slide = selectedSlide();
    appendOperation('presentation.slide.delete', { slide_id: slide.id });
  }

  function addObject(type) {
    const slide = selectedSlide();
    const object = objectPayload(type);
    selectedObjectId = object.id;
    const payloadType = type === 'image_placeholder' ? 'presentation.image_placeholder.add' : 'presentation.shape.add';
    appendOperation(payloadType, { slide_id: slide.id, object });
  }

  function deleteObject() {
    const slide = selectedSlide();
    const object = slide.objects.find((entry) => entry.id === selectedObjectId);
    if (!object) return;
    const payloadType = object.type === 'image_placeholder' ? 'presentation.image_placeholder.delete' : 'presentation.shape.delete';
    appendOperation(payloadType, { slide_id: slide.id, object_id: object.id });
  }

  function startObjectDrag(event, object, element) {
    if (!canWrite()) return;
    selectedObjectId = object.id;
    syncObjectSelection();
    setControls();
    const rect = dom.slideStage.getBoundingClientRect();
    dragState = {
      object,
      element,
      startX: event.clientX,
      startY: event.clientY,
      originX: object.x,
      originY: object.y,
      width: Math.max(1, rect.width),
      height: Math.max(1, rect.height),
    };
    try { element.setPointerCapture(event.pointerId); } catch {}
    event.preventDefault();
  }

  function finishObjectDrag() {
    if (!dragState) return;
    const object = dragState.object;
    dragState = null;
    const slide = selectedSlide();
    const payloadType = object.type === 'image_placeholder' ? 'presentation.image_placeholder.update' : 'presentation.shape.update';
    appendOperation(payloadType, { slide_id: slide.id, object: { ...object } }, { optimistic: false });
  }

  function moveActiveSlide(delta) {
    const current = state.slides.findIndex((slide) => slide.id === activeSlide().id);
    const nextIndex = (current + delta + state.slides.length) % state.slides.length;
    const nextSlide = state.slides[nextIndex];
    if (!nextSlide) return;
    state.selectedSlideId = nextSlide.id;
    if (state.playback.active && canWrite()) {
      appendOperation('presentation.playback.update', { active: true, slide_id: nextSlide.id });
    } else {
      render();
    }
  }

  function togglePresentation() {
    if (!canWrite()) return;
    appendOperation('presentation.playback.update', {
      active: !state.playback.active,
      slide_id: selectedSlide().id,
    });
  }

  function exportPresentation() {
    if (!canExport()) {
      setStatus('Export permission is not granted.');
      return;
    }
    const blob = buildPptx();
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'kingrt-presentation.pptx';
    link.rel = 'noopener';
    link.click();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    setStatus('PowerPoint-compatible PPTX exported.');
  }

  function xml(value) {
    return String(value || '').replace(/[<>&'"]/g, (char) => ({
      '<': '&lt;',
      '>': '&gt;',
      '&': '&amp;',
      "'": '&apos;',
      '"': '&quot;',
    })[char]);
  }

  function color(value, fallback) {
    const normalized = String(value || '').replace(/[^0-9A-Fa-f]/g, '').slice(0, 6).toUpperCase();
    return normalized.length === 6 ? normalized : fallback;
  }

  function paragraphs(text, size) {
    const lines = cleanText(text || ' ', 1800).split(/\r?\n/).slice(0, 18);
    return lines.map((line) => `<a:p><a:r><a:rPr lang="en-US" sz="${size}"/><a:t>${xml(line || ' ')}</a:t></a:r></a:p>`).join('');
  }

  function pctToEmu(value, total) {
    return Math.round(clamp(value, 0, 100) / 100 * total);
  }

  function shapeXml(object, id) {
    const preset = object.type === 'ellipse' ? 'ellipse' : 'rect';
    const fill = object.type === 'image_placeholder' ? 'ECEFF3' : color(object.fill, 'DFF8F2');
    const stroke = color(object.stroke, '355161');
    const dash = object.type === 'image_placeholder' ? '<a:prstDash val="dash"/>' : '';
    const text = object.type === 'image_placeholder' ? (object.text || 'Image placeholder') : object.text;
    return `<p:sp><p:nvSpPr><p:cNvPr id="${id}" name="${xml(object.type)}"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr><p:spPr><a:xfrm><a:off x="${pctToEmu(object.x, slideCx)}" y="${pctToEmu(object.y, slideCy)}"/><a:ext cx="${pctToEmu(object.w, slideCx)}" cy="${pctToEmu(object.h, slideCy)}"/></a:xfrm><a:prstGeom prst="${preset}"><a:avLst/></a:prstGeom><a:solidFill><a:srgbClr val="${fill}"/></a:solidFill><a:ln w="19050"><a:solidFill><a:srgbClr val="${stroke}"/></a:solidFill>${dash}</a:ln></p:spPr><p:txBody><a:bodyPr anchor="ctr"/><a:lstStyle/>${paragraphs(text, 1400)}</p:txBody></p:sp>`;
  }

  function slideXml(slide) {
    const objects = slide.objects.map((object, index) => shapeXml(normalizeObject(object), index + 4)).join('');
    return `<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"><p:cSld><p:bg><p:bgPr><a:solidFill><a:srgbClr val="FBFBF8"/></a:solidFill></p:bgPr></p:bg><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="${slideCx}" cy="${slideCy}"/><a:chOff x="0" y="0"/><a:chExt cx="${slideCx}" cy="${slideCy}"/></a:xfrm></p:grpSpPr><p:sp><p:nvSpPr><p:cNvPr id="2" name="Title"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr><p:spPr><a:xfrm><a:off x="720000" y="520000"/><a:ext cx="10752000" cy="900000"/></a:xfrm></p:spPr><p:txBody><a:bodyPr/><a:lstStyle/>${paragraphs(slide.title, 3600)}</p:txBody></p:sp><p:sp><p:nvSpPr><p:cNvPr id="3" name="Body"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr><p:spPr><a:xfrm><a:off x="900000" y="1900000"/><a:ext cx="10300000" cy="3300000"/></a:xfrm></p:spPr><p:txBody><a:bodyPr wrap="square"/><a:lstStyle/>${paragraphs(slide.body, 2200)}</p:txBody></p:sp>${objects}</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sld>`;
  }

  function staticPptxFiles(slides) {
    const slideOverrides = slides.map((_, index) => `<Override PartName="/ppt/slides/slide${index + 1}.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>`).join('');
    const slideIds = slides.map((_, index) => `<p:sldId id="${256 + index}" r:id="rId${index + 2}"/>`).join('');
    const slideRels = slides.map((_, index) => `<Relationship Id="rId${index + 2}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide${index + 1}.xml"/>`).join('');
    return [
      { name: '[Content_Types].xml', content: `<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/><Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/><Override PartName="/ppt/presProps.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presProps+xml"/><Override PartName="/ppt/viewProps.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.viewProps+xml"/><Override PartName="/ppt/tableStyles.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.tableStyles+xml"/><Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml"/><Override PartName="/ppt/slideLayouts/slideLayout1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml"/><Override PartName="/ppt/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>${slideOverrides}</Types>` },
      { name: '_rels/.rels', content: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>' },
      { name: 'docProps/core.xml', content: `<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>King presentation export</dc:title><dc:creator>KINGRT Call App</dc:creator><cp:lastModifiedBy>KINGRT Call App</cp:lastModifiedBy><dcterms:created xsi:type="dcterms:W3CDTF">${new Date().toISOString()}</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">${new Date().toISOString()}</dcterms:modified></cp:coreProperties>` },
      { name: 'docProps/app.xml', content: `<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>KINGRT Presentation Call App</Application><PresentationFormat>On-screen Show (16:9)</PresentationFormat><Slides>${slides.length}</Slides></Properties>` },
      { name: 'ppt/presentation.xml', content: `<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:presentation xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"><p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst><p:sldIdLst>${slideIds}</p:sldIdLst><p:sldSz cx="${slideCx}" cy="${slideCy}" type="screen16x9"/><p:notesSz cx="6858000" cy="9144000"/></p:presentation>` },
      { name: 'ppt/_rels/presentation.xml.rels', content: `<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster1.xml"/>${slideRels}<Relationship Id="rId${slides.length + 2}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/presProps" Target="presProps.xml"/><Relationship Id="rId${slides.length + 3}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/viewProps" Target="viewProps.xml"/><Relationship Id="rId${slides.length + 4}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/tableStyles" Target="tableStyles.xml"/></Relationships>` },
      { name: 'ppt/presProps.xml', content: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:presentationPr xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"/>' },
      { name: 'ppt/viewProps.xml', content: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:viewPr xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"/>' },
      { name: 'ppt/tableStyles.xml', content: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><a:tblStyleLst xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" def="{5C22544A-7EE6-4342-B048-85BDC9FD1C3A}"/>' },
      { name: 'ppt/slideMasters/slideMaster1.xml', content: `<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:sldMaster xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"><p:cSld><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr/></p:spTree></p:cSld><p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2" accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/><p:sldLayoutIdLst><p:sldLayoutId id="2147483649" r:id="rId1"/></p:sldLayoutIdLst><p:txStyles><p:titleStyle/><p:bodyStyle/><p:otherStyle/></p:txStyles></p:sldMaster>` },
      { name: 'ppt/slideMasters/_rels/slideMaster1.xml.rels', content: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="../theme/theme1.xml"/></Relationships>' },
      { name: 'ppt/slideLayouts/slideLayout1.xml', content: `<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:sldLayout xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" type="blank" preserve="1"><p:cSld name="Blank"><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr/></p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sldLayout>` },
      { name: 'ppt/slideLayouts/_rels/slideLayout1.xml.rels', content: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="../slideMasters/slideMaster1.xml"/></Relationships>' },
      { name: 'ppt/theme/theme1.xml', content: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="King"><a:themeElements><a:clrScheme name="King"><a:dk1><a:srgbClr val="171A1F"/></a:dk1><a:lt1><a:srgbClr val="FBFBF8"/></a:lt1><a:dk2><a:srgbClr val="355161"/></a:dk2><a:lt2><a:srgbClr val="ECEFF3"/></a:lt2><a:accent1><a:srgbClr val="42C2A8"/></a:accent1><a:accent2><a:srgbClr val="F0B44C"/></a:accent2><a:accent3><a:srgbClr val="667381"/></a:accent3><a:accent4><a:srgbClr val="DFF8F2"/></a:accent4><a:accent5><a:srgbClr val="FCE6B8"/></a:accent5><a:accent6><a:srgbClr val="1D242C"/></a:accent6><a:hlink><a:srgbClr val="2F80ED"/></a:hlink><a:folHlink><a:srgbClr val="7B61FF"/></a:folHlink></a:clrScheme><a:fontScheme name="King"><a:majorFont><a:latin typeface="Aptos Display"/></a:majorFont><a:minorFont><a:latin typeface="Aptos"/></a:minorFont></a:fontScheme><a:fmtScheme name="King"><a:fillStyleLst><a:solidFill><a:schemeClr val="accent1"/></a:solidFill></a:fillStyleLst><a:lnStyleLst><a:ln w="9525"><a:solidFill><a:schemeClr val="tx1"/></a:solidFill></a:ln></a:lnStyleLst><a:effectStyleLst><a:effectStyle><a:effectLst/></a:effectStyle></a:effectStyleLst><a:bgFillStyleLst><a:solidFill><a:schemeClr val="lt1"/></a:solidFill></a:bgFillStyleLst></a:fmtScheme></a:themeElements></a:theme>' },
    ];
  }

  function buildPptx() {
    const slides = state.slides.map(normalizeSlide);
    const files = staticPptxFiles(slides);
    slides.forEach((slide, index) => {
      files.push({ name: `ppt/slides/slide${index + 1}.xml`, content: slideXml(slide) });
      files.push({ name: `ppt/slides/_rels/slide${index + 1}.xml.rels`, content: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/></Relationships>' });
    });
    return new Blob([zipStore(files)], { type: pptxMime });
  }

  function crc32(bytes) {
    let crc = -1;
    for (let i = 0; i < bytes.length; i += 1) {
      crc = (crc >>> 8) ^ crcTable[(crc ^ bytes[i]) & 0xff];
    }
    return (crc ^ -1) >>> 0;
  }

  const crcTable = (() => {
    const table = new Uint32Array(256);
    for (let n = 0; n < 256; n += 1) {
      let c = n;
      for (let k = 0; k < 8; k += 1) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
      table[n] = c >>> 0;
    }
    return table;
  })();

  function zipStore(files) {
    const encoder = new TextEncoder();
    const chunks = [];
    const central = [];
    let offset = 0;
    files.forEach((file) => {
      const nameBytes = encoder.encode(file.name);
      const bytes = typeof file.content === 'string' ? encoder.encode(file.content) : file.content;
      const crc = crc32(bytes);
      const local = new Uint8Array(30 + nameBytes.length);
      const view = new DataView(local.buffer);
      view.setUint32(0, 0x04034b50, true);
      view.setUint16(4, 20, true);
      view.setUint32(14, crc, true);
      view.setUint32(18, bytes.length, true);
      view.setUint32(22, bytes.length, true);
      view.setUint16(26, nameBytes.length, true);
      local.set(nameBytes, 30);
      chunks.push(local, bytes);
      central.push({ nameBytes, bytes, crc, offset });
      offset += local.length + bytes.length;
    });
    const centralStart = offset;
    central.forEach((entry) => {
      const header = new Uint8Array(46 + entry.nameBytes.length);
      const view = new DataView(header.buffer);
      view.setUint32(0, 0x02014b50, true);
      view.setUint16(4, 20, true);
      view.setUint16(6, 20, true);
      view.setUint32(16, entry.crc, true);
      view.setUint32(20, entry.bytes.length, true);
      view.setUint32(24, entry.bytes.length, true);
      view.setUint16(28, entry.nameBytes.length, true);
      view.setUint32(42, entry.offset, true);
      header.set(entry.nameBytes, 46);
      chunks.push(header);
      offset += header.length;
    });
    const end = new Uint8Array(22);
    const endView = new DataView(end.buffer);
    endView.setUint32(0, 0x06054b50, true);
    endView.setUint16(8, central.length, true);
    endView.setUint16(10, central.length, true);
    endView.setUint32(12, offset - centralStart, true);
    endView.setUint32(16, centralStart, true);
    chunks.push(end);
    return new Blob(chunks, { type: 'application/zip' });
  }

  dom.title.addEventListener('input', queueTextUpdate);
  dom.body.addEventListener('input', queueTextUpdate);
  dom.addSlide.addEventListener('click', addSlide);
  dom.deleteSlide.addEventListener('click', deleteSlide);
  dom.addRect.addEventListener('click', () => addObject('rect'));
  dom.addEllipse.addEventListener('click', () => addObject('ellipse'));
  dom.addImage.addEventListener('click', () => addObject('image_placeholder'));
  dom.deleteObject.addEventListener('click', deleteObject);
  dom.presentToggle.addEventListener('click', togglePresentation);
  dom.prevSlide.addEventListener('click', () => moveActiveSlide(-1));
  dom.nextSlide.addEventListener('click', () => moveActiveSlide(1));
  dom.exportPptx.addEventListener('click', exportPresentation);
  window.addEventListener('pointermove', (event) => {
    if (!dragState) return;
    const nextX = dragState.originX + ((event.clientX - dragState.startX) / dragState.width) * 100;
    const nextY = dragState.originY + ((event.clientY - dragState.startY) / dragState.height) * 100;
    dragState.object.x = clamp(nextX, 0, 96 - dragState.object.w);
    dragState.object.y = clamp(nextY, 0, 96 - dragState.object.h);
    positionObjectElement(dragState.element, dragState.object);
  });
  window.addEventListener('pointerup', finishObjectDrag);
  window.addEventListener('pointercancel', finishObjectDrag);

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
        setStatus('Loading shared presentation.');
      } else {
        setStatus('Access not granted for this presentation.');
      }
      render();
    } else if (message.type === 'call_app.crdt.bootstrap.response') {
      handleCrdtResult(message.result || {});
    } else if (message.type === 'call_app.crdt.ops.response') {
      handleCrdtResult(message.result || {});
    } else if (message.type === 'call_app.crdt.op.appended') {
      applyAccessState(message.result || {});
      if (message.result?.operation) applyEnvelope(message.result.operation);
      render();
    } else if (message.type === 'call_app.crdt.error') {
      applyAccessState(message);
      setStatus(String(message.message || 'Call App sync error.'));
    }
  });

  render();
})();
