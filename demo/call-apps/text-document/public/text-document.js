(() => {
  const bridgeProtocol = 'king.call_app.iframe.v1';
  const appKey = 'text-document';
  const maxBlockChars = 5000;
  const editor = document.getElementById('editor');
  const statusEl = document.getElementById('status');
  const clockEl = document.getElementById('clock');
  const modeBadge = document.getElementById('modeBadge');
  const blockType = document.getElementById('blockType');
  const controls = {
    add: document.getElementById('addBlock'),
    del: document.getElementById('deleteBlock'),
    bold: document.getElementById('bold'),
    italic: document.getElementById('italic'),
    underline: document.getElementById('underline'),
    odt: document.getElementById('exportOdt'),
    pdf: document.getElementById('exportPdf'),
  };
  const state = {
    order: [],
    blocks: new Map(),
    applied: new Set(),
  };
  let parentOrigin = '';
  let appSessionId = '';
  let callId = '';
  let documentId = '';
  let actorId = '';
  let grantState = 'denied';
  let capabilities = new Set();
  let permissionActions = new Set();
  let activeBlockId = '';
  let latestClock = 0;
  let pollTimer = 0;
  let localRender = false;
  const pendingSaves = new Map();

  function canRead() {
    return grantState === 'allowed' && capabilities.has('call_apps.crdt.read');
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
      && (capabilities.has('call_apps.export.download') || capabilities.has('call_apps.export.request'));
  }

  function setStatus(message) {
    statusEl.textContent = String(message || '');
    clockEl.textContent = `${latestClock} ops`;
    modeBadge.textContent = canWrite() ? 'Editor' : (canRead() ? 'Viewer' : 'No access');
    blockType.disabled = !canWrite();
    controls.add.disabled = !canWrite();
    controls.bold.disabled = !canWrite();
    controls.italic.disabled = !canWrite();
    controls.underline.disabled = !canWrite();
    controls.del.disabled = !canDelete() || !activeBlockId;
    controls.odt.disabled = !canExport();
    controls.pdf.disabled = !canExport();
  }

  function emit(type, payload = {}) {
    if (!parentOrigin || !window.parent) return;
    window.parent.postMessage({
      bridge_protocol: bridgeProtocol,
      type,
      app_key: appKey,
      app_session_id: appSessionId,
      ...payload,
    }, parentOrigin);
  }

  function operationId(type) {
    return `td_${type.replaceAll('.', '_')}_${Date.now()}_${Math.random().toString(36).slice(2, 10)}`;
  }

  function appendOperation(payloadType, payload) {
    if (payloadType.endsWith('.delete') ? !canDelete() : !canWrite()) {
      setStatus('This participant does not have permission for that document action.');
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
    setStatus('Document update queued.');
  }

  function requestBootstrap(afterClock = 0) {
    if (!canRead()) return;
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
      limit: 250,
    });
  }

  function sameMarks(left = {}, right = {}) {
    return left.bold === right.bold && left.italic === right.italic && left.underline === right.underline;
  }

  function normalizeRuns(runs) {
    const output = [];
    const source = Array.isArray(runs) ? runs : [];
    for (const run of source) {
      const text = String(run?.text || '').slice(0, maxBlockChars);
      if (text === '') continue;
      const marks = run?.marks && typeof run.marks === 'object' ? run.marks : {};
      const normalized = {
        bold: marks.bold === true,
        italic: marks.italic === true,
        underline: marks.underline === true,
      };
      const previous = output[output.length - 1];
      if (previous && sameMarks(previous.marks, normalized)) {
        previous.text += text;
      } else {
        output.push({ text, marks: normalized });
      }
    }
    return output.length > 0 ? output : [{ text: '', marks: { bold: false, italic: false, underline: false } }];
  }

  function blockText(block) {
    return normalizeRuns(block?.runs).map((run) => run.text).join('');
  }

  function makeBlock(type = 'paragraph', text = '') {
    return {
      id: operationId('block'),
      type,
      runs: [{ text: String(text || ''), marks: { bold: false, italic: false, underline: false } }],
      updated_at: new Date().toISOString(),
      actor_id: actorId,
    };
  }

  function normalizeBlock(input = {}) {
    const allowed = new Set(['heading1', 'heading2', 'paragraph', 'bullet', 'numbered', 'note']);
    const type = allowed.has(String(input.type || '')) ? String(input.type) : 'paragraph';
    return {
      id: String(input.id || operationId('block')).slice(0, 160),
      type,
      runs: normalizeRuns(input.runs || [{ text: String(input.text || ''), marks: input.marks || {} }]),
      updated_at: String(input.updated_at || ''),
      actor_id: String(input.actor_id || ''),
    };
  }

  function upsertBlock(block, afterId = '') {
    const normalized = normalizeBlock(block);
    state.blocks.set(normalized.id, normalized);
    if (!state.order.includes(normalized.id)) {
      const index = afterId ? state.order.indexOf(afterId) : -1;
      state.order.splice(index >= 0 ? index + 1 : state.order.length, 0, normalized.id);
    }
    activeBlockId = normalized.id;
  }

  function deleteBlock(id) {
    const targetId = String(id || '');
    state.blocks.delete(targetId);
    state.order = state.order.filter((entry) => entry !== targetId);
    if (activeBlockId === targetId) activeBlockId = state.order[0] || '';
  }

  function ensureDraftBlock() {
    if (state.order.length > 0 || !canWrite()) return;
    const block = makeBlock('paragraph', '');
    state.blocks.set(block.id, block);
    state.order.push(block.id);
    activeBlockId = block.id;
  }

  function markerFor(block, index) {
    if (block.type === 'bullet') return '\u2022';
    if (block.type === 'numbered') return `${index + 1}.`;
    if (block.type === 'note') return '!';
    return '';
  }

  function renderRun(run) {
    const span = document.createElement('span');
    span.textContent = run.text;
    span.classList.toggle('run-bold', run.marks.bold);
    span.classList.toggle('run-italic', run.marks.italic);
    span.classList.toggle('run-underline', run.marks.underline);
    return span;
  }

  function activeBlock() {
    return state.blocks.get(activeBlockId) || null;
  }

  function syncToolbar() {
    const block = activeBlock();
    blockType.value = block?.type || 'paragraph';
    const runs = normalizeRuns(block?.runs);
    const allMarks = runs.reduce((marks, run) => ({
      bold: marks.bold || run.marks.bold,
      italic: marks.italic || run.marks.italic,
      underline: marks.underline || run.marks.underline,
    }), { bold: false, italic: false, underline: false });
    controls.bold.classList.toggle('active', allMarks.bold);
    controls.italic.classList.toggle('active', allMarks.italic);
    controls.underline.classList.toggle('active', allMarks.underline);
  }

  function render() {
    localRender = true;
    ensureDraftBlock();
    editor.textContent = '';
    const blocks = state.order.map((id) => state.blocks.get(id)).filter(Boolean);
    if (blocks.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'empty-message';
      empty.textContent = canRead() ? 'This document is empty.' : 'Access is not granted for this document.';
      editor.appendChild(empty);
      localRender = false;
      setStatus(canRead() ? 'Text document synchronized.' : 'Access not granted.');
      return;
    }
    blocks.forEach((block, index) => {
      const row = document.createElement('div');
      row.className = 'block-row';
      row.dataset.blockId = block.id;
      row.dataset.type = block.type;
      row.classList.toggle('active', block.id === activeBlockId);

      const marker = document.createElement('div');
      marker.className = 'block-marker';
      marker.textContent = markerFor(block, index);

      const text = document.createElement('div');
      text.className = 'block-text';
      text.contentEditable = canWrite() ? 'true' : 'false';
      text.spellcheck = true;
      text.dataset.blockId = block.id;
      text.setAttribute('role', 'textbox');
      text.setAttribute('aria-multiline', 'true');
      for (const run of normalizeRuns(block.runs)) text.appendChild(renderRun(run));
      if (blockText(block) === '') text.appendChild(document.createElement('br'));

      row.append(marker, text);
      editor.appendChild(row);
    });
    localRender = false;
    syncToolbar();
    setStatus(canWrite() ? 'Text document synchronized.' : 'Read-only text document synchronized.');
  }

  function editorForBlock(id) {
    return editor.querySelector(`.block-text[data-block-id="${CSS.escape(id)}"]`);
  }

  function selectionOffsets(element) {
    const selection = window.getSelection();
    if (!selection || selection.rangeCount === 0) return null;
    const range = selection.getRangeAt(0);
    if (!element.contains(range.startContainer) || !element.contains(range.endContainer)) return null;
    const before = document.createRange();
    before.selectNodeContents(element);
    before.setEnd(range.startContainer, range.startOffset);
    const selected = document.createRange();
    selected.selectNodeContents(element);
    selected.setStart(range.startContainer, range.startOffset);
    selected.setEnd(range.endContainer, range.endOffset);
    return {
      start: before.toString().length,
      end: before.toString().length + selected.toString().length,
    };
  }

  function toggleRuns(runs, start, end, mark) {
    const normalized = normalizeRuns(runs);
    const total = normalized.reduce((sum, run) => sum + run.text.length, 0);
    const rangeStart = start === end ? 0 : Math.max(0, Math.min(total, start));
    const rangeEnd = start === end ? total : Math.max(rangeStart, Math.min(total, end));
    const selectedRuns = [];
    let cursor = 0;
    for (const run of normalized) {
      const next = cursor + run.text.length;
      if (next <= rangeStart || cursor >= rangeEnd) {
        selectedRuns.push({ text: run.text, marks: { ...run.marks } });
      } else {
        const before = Math.max(0, rangeStart - cursor);
        const after = Math.max(0, next - rangeEnd);
        const middleEnd = run.text.length - after;
        if (before > 0) selectedRuns.push({ text: run.text.slice(0, before), marks: { ...run.marks } });
        selectedRuns.push({
          text: run.text.slice(before, middleEnd),
          marks: { ...run.marks, [mark]: !run.marks[mark] },
        });
        if (after > 0) selectedRuns.push({ text: run.text.slice(middleEnd), marks: { ...run.marks } });
      }
      cursor = next;
    }
    return normalizeRuns(selectedRuns);
  }

  function saveBlock(block, payloadType = 'text_document.block.upsert', afterId = '') {
    if (!canWrite()) return;
    appendOperation(payloadType, { block: normalizeBlock(block), after_id: afterId });
  }

  function scheduleSave(block) {
    clearTimeout(pendingSaves.get(block.id));
    pendingSaves.set(block.id, window.setTimeout(() => {
      pendingSaves.delete(block.id);
      saveBlock(block);
    }, 450));
  }

  function focusBlock(id) {
    activeBlockId = id;
    render();
    const target = editorForBlock(id);
    if (target) target.focus();
  }

  function handleTextInput(element) {
    if (localRender || !canWrite()) {
      render();
      return;
    }
    const block = state.blocks.get(element.dataset.blockId);
    if (!block) return;
    block.runs = [{ text: element.textContent.slice(0, maxBlockChars), marks: { bold: false, italic: false, underline: false } }];
    block.updated_at = new Date().toISOString();
    block.actor_id = actorId;
    scheduleSave(block);
    syncToolbar();
  }

  function applyFormat(mark) {
    const block = activeBlock();
    if (!block || !canWrite()) return;
    const element = editorForBlock(block.id);
    const offsets = element ? selectionOffsets(element) : null;
    block.runs = toggleRuns(block.runs, offsets?.start || 0, offsets?.end || 0, mark);
    block.updated_at = new Date().toISOString();
    block.actor_id = actorId;
    saveBlock(block, 'text_document.format.update');
    render();
    const target = editorForBlock(block.id);
    if (target) target.focus();
  }

  function applyBlockType(type) {
    const block = activeBlock();
    if (!block || !canWrite()) return;
    block.type = type;
    block.updated_at = new Date().toISOString();
    saveBlock(block, type === 'note' ? 'text_document.note.upsert' : 'text_document.block.upsert');
    render();
  }

  function addBlock() {
    if (!canWrite()) return;
    const afterId = activeBlockId;
    const type = blockType.value === 'note' ? 'note' : String(blockType.value || 'paragraph');
    const block = makeBlock(type, '');
    upsertBlock(block, afterId);
    saveBlock(block, type === 'note' ? 'text_document.note.upsert' : 'text_document.block.upsert', afterId);
    focusBlock(block.id);
  }

  function removeActiveBlock() {
    const id = activeBlockId;
    if (!id || !canDelete()) return;
    deleteBlock(id);
    appendOperation('text_document.block.delete', { id, deleted_at: new Date().toISOString() });
    render();
  }

  function applyEnvelope(envelope) {
    if (!envelope || state.applied.has(envelope.operation_id)) return;
    state.applied.add(envelope.operation_id);
    latestClock = Math.max(latestClock, Number(envelope.logical_clock || 0));
    const type = String(envelope.payload_type || '');
    const payload = envelope.payload && typeof envelope.payload === 'object' ? envelope.payload : {};
    if (type === 'text_document.block.delete') {
      deleteBlock(payload.id);
    } else if (['text_document.block.upsert', 'text_document.format.update', 'text_document.note.upsert'].includes(type)) {
      upsertBlock(payload.block || {}, String(payload.after_id || ''));
    }
  }

  function applyAccessState(result = {}) {
    if (typeof result.grant_state === 'string' && result.grant_state !== '') {
      grantState = result.grant_state;
    }
    if (Array.isArray(result.permission_actions)) permissionActions = new Set(result.permission_actions);
    if (!canRead()) {
      clearInterval(pollTimer);
      state.order = [];
      state.blocks.clear();
      activeBlockId = '';
    }
  }

  function handleCrdtResult(result = {}) {
    applyAccessState(result);
    for (const envelope of result.ops || []) applyEnvelope(envelope);
    render();
  }

  function exportBlocks() {
    return state.order.map((id) => state.blocks.get(id)).filter(Boolean);
  }

  function xmlEscape(value) {
    return String(value || '').replace(/[<>&'"]/g, (char) => ({
      '<': '&lt;',
      '>': '&gt;',
      '&': '&amp;',
      "'": '&apos;',
      '"': '&quot;',
    }[char]));
  }

  function odtTextRuns(block) {
    return normalizeRuns(block.runs).map((run) => {
      const text = xmlEscape(run.text);
      const marks = ['bold', 'italic', 'underline'].filter((name) => run.marks[name]);
      return marks.length === 0 ? text : `<text:span text:style-name="T_${marks.join('_')}">${text}</text:span>`;
    }).join('');
  }

  function odtBlock(block) {
    const text = odtTextRuns(block);
    if (block.type === 'heading1') return `<text:h text:outline-level="1">${text}</text:h>`;
    if (block.type === 'heading2') return `<text:h text:outline-level="2">${text}</text:h>`;
    if (block.type === 'bullet' || block.type === 'numbered') {
      const style = block.type === 'bullet' ? 'L_Bullet' : 'L_Numbered';
      return `<text:list text:style-name="${style}"><text:list-item><text:p>${text}</text:p></text:list-item></text:list>`;
    }
    const style = block.type === 'note' ? ' text:style-name="P_Note"' : '';
    return `<text:p${style}>${text}</text:p>`;
  }

  function odtContentXml(blocks) {
    const styles = ['bold', 'italic', 'underline', 'bold_italic', 'bold_underline', 'italic_underline', 'bold_italic_underline']
      .map((name) => {
        const props = [
          name.includes('bold') ? 'fo:font-weight="bold" style:font-weight-asian="bold" style:font-weight-complex="bold"' : '',
          name.includes('italic') ? 'fo:font-style="italic" style:font-style-asian="italic" style:font-style-complex="italic"' : '',
          name.includes('underline') ? 'style:text-underline-style="solid" style:text-underline-type="single"' : '',
        ].filter(Boolean).join(' ');
        return `<style:style style:name="T_${name}" style:family="text"><style:text-properties ${props}/></style:style>`;
      }).join('');
    return `<?xml version="1.0" encoding="UTF-8"?>` +
      `<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" office:version="1.2">` +
      `<office:automatic-styles>${styles}<style:style style:name="P_Note" style:family="paragraph"><style:paragraph-properties fo:background-color="#fff7d6"/></style:style><text:list-style style:name="L_Bullet"><text:list-level-style-bullet text:level="1" text:bullet-char="&#8226;"/></text:list-style><text:list-style style:name="L_Numbered"><text:list-level-style-number text:level="1" style:num-format="1"/></text:list-style></office:automatic-styles>` +
      `<office:body><office:text>${blocks.map(odtBlock).join('')}</office:text></office:body></office:document-content>`;
  }

  function crc32(bytes) {
    let crc = -1;
    for (const byte of bytes) {
      crc ^= byte;
      for (let index = 0; index < 8; index += 1) crc = (crc >>> 1) ^ (0xedb88320 & -(crc & 1));
    }
    return (crc ^ -1) >>> 0;
  }

  function u16(value) {
    return Uint8Array.of(value & 255, (value >>> 8) & 255);
  }

  function u32(value) {
    return Uint8Array.of(value & 255, (value >>> 8) & 255, (value >>> 16) & 255, (value >>> 24) & 255);
  }

  function zipStored(files) {
    const encoder = new TextEncoder();
    const chunks = [];
    const central = [];
    let offset = 0;
    const push = (part) => { chunks.push(part); offset += part.length; };
    for (const file of files) {
      const name = encoder.encode(file.name);
      const data = typeof file.data === 'string' ? encoder.encode(file.data) : file.data;
      const crc = crc32(data);
      const localOffset = offset;
      push(Uint8Array.of(80, 75, 3, 4, 20, 0, 0, 8, 0, 0, 0, 0, 33, 0));
      push(u32(crc)); push(u32(data.length)); push(u32(data.length)); push(u16(name.length)); push(u16(0)); push(name); push(data);
      central.push({ name, crc, size: data.length, offset: localOffset });
    }
    const centralOffset = offset;
    for (const file of central) {
      push(Uint8Array.of(80, 75, 1, 2, 20, 0, 20, 0, 0, 8, 0, 0, 0, 0, 33, 0));
      push(u32(file.crc)); push(u32(file.size)); push(u32(file.size)); push(u16(file.name.length)); push(u16(0)); push(u16(0));
      push(u16(0)); push(u16(0)); push(u32(0)); push(u32(file.offset)); push(file.name);
    }
    const centralSize = offset - centralOffset;
    push(Uint8Array.of(80, 75, 5, 6, 0, 0, 0, 0));
    push(u16(central.length)); push(u16(central.length)); push(u32(centralSize)); push(u32(centralOffset)); push(u16(0));
    return new Blob(chunks, { type: 'application/zip' });
  }

  function downloadBlob(blob, filename) {
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = filename;
    anchor.rel = 'noopener';
    anchor.click();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
  }

  function exportOdt() {
    if (!canExport()) return;
    const content = odtContentXml(exportBlocks());
    const manifest = `<?xml version="1.0" encoding="UTF-8"?><manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.2"><manifest:file-entry manifest:full-path="/" manifest:media-type="application/vnd.oasis.opendocument.text"/><manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/></manifest:manifest>`;
    const blob = zipStored([
      { name: 'mimetype', data: 'application/vnd.oasis.opendocument.text' },
      { name: 'content.xml', data: content },
      { name: 'META-INF/manifest.xml', data: manifest },
    ]);
    downloadBlob(new Blob([blob], { type: 'application/vnd.oasis.opendocument.text' }), 'kingrt-text-document.odt');
  }

  function pdfEscape(value) {
    return String(value || '').replace(/[\\()]/g, '\\$&').replace(/[\r\n]+/g, ' ');
  }

  function pdfLines(blocks) {
    const lines = [];
    for (const block of blocks) {
      const prefix = block.type === 'bullet' ? '- ' : (block.type === 'numbered' ? '1. ' : (block.type === 'note' ? 'Note: ' : ''));
      const words = `${prefix}${blockText(block)}`.trim().split(/\s+/).filter(Boolean);
      let line = '';
      const width = block.type.startsWith('heading') ? 54 : 74;
      for (const word of words.length ? words : ['']) {
        if (`${line} ${word}`.trim().length > width) {
          lines.push({ text: line, size: block.type === 'heading1' ? 18 : (block.type === 'heading2' ? 15 : 11) });
          line = word;
        } else {
          line = `${line} ${word}`.trim();
        }
      }
      lines.push({ text: line, size: block.type === 'heading1' ? 18 : (block.type === 'heading2' ? 15 : 11) });
      if (block.type.startsWith('heading')) lines.push({ text: '', size: 6 });
    }
    return lines.length > 0 ? lines : [{ text: 'Untitled document', size: 11 }];
  }

  function exportPdf() {
    if (!canExport()) return;
    const encoder = new TextEncoder();
    const chunks = [];
    const offsets = [0];
    let offset = 0;
    const pushBytes = (bytes) => { chunks.push(bytes); offset += bytes.length; };
    const push = (text) => pushBytes(encoder.encode(text));
    const objectStart = (id) => { offsets[id] = offset; push(`${id} 0 obj\n`); };
    const lines = pdfLines(exportBlocks());
    const pages = [];
    for (let index = 0; index < lines.length; index += 42) pages.push(lines.slice(index, index + 42));
    push('%PDF-1.4\n');
    objectStart(1); push('<< /Type /Catalog /Pages 2 0 R >>\nendobj\n');
    objectStart(2); push(`<< /Type /Pages /Kids [${pages.map((_, i) => `${3 + i * 2} 0 R`).join(' ')}] /Count ${pages.length} >>\nendobj\n`);
    pages.forEach((page, pageIndex) => {
      const pageId = 3 + pageIndex * 2;
      const contentId = pageId + 1;
      const commands = ['BT /F1 11 Tf 72 740 Td'];
      page.forEach((line, lineIndex) => {
        if (lineIndex > 0) commands.push('0 -16 Td');
        commands.push(`/F1 ${line.size} Tf (${pdfEscape(line.text)}) Tj`);
      });
      commands.push('ET');
      const stream = commands.join('\n');
      objectStart(pageId); push(`<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 99 0 R >> >> /Contents ${contentId} 0 R >>\nendobj\n`);
      objectStart(contentId); push(`<< /Length ${encoder.encode(stream).length} >>\nstream\n${stream}\nendstream\nendobj\n`);
    });
    objectStart(99); push('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n');
    const xref = offset;
    push('xref\n0 100\n0000000000 65535 f \n');
    for (let id = 1; id < 100; id += 1) push(offsets[id] ? `${String(offsets[id]).padStart(10, '0')} 00000 n \n` : '0000000000 00000 f \n');
    push(`trailer\n<< /Size 100 /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`);
    downloadBlob(new Blob(chunks, { type: 'application/pdf' }), 'kingrt-text-document.pdf');
  }

  editor.addEventListener('focusin', (event) => {
    const id = event.target?.dataset?.blockId || '';
    if (id && state.blocks.has(id)) {
      activeBlockId = id;
      syncToolbar();
      setStatus(canWrite() ? 'Editing text document.' : 'Read-only text document.');
      document.querySelectorAll('.block-row').forEach((row) => row.classList.toggle('active', row.dataset.blockId === id));
    }
  });
  editor.addEventListener('input', (event) => {
    if (event.target?.classList?.contains('block-text')) handleTextInput(event.target);
  });
  blockType.addEventListener('change', () => applyBlockType(blockType.value));
  controls.add.addEventListener('click', addBlock);
  controls.del.addEventListener('click', removeActiveBlock);
  controls.bold.addEventListener('click', () => applyFormat('bold'));
  controls.italic.addEventListener('click', () => applyFormat('italic'));
  controls.underline.addEventListener('click', () => applyFormat('underline'));
  controls.odt.addEventListener('click', exportOdt);
  controls.pdf.addEventListener('click', exportPdf);

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
        pollTimer = window.setInterval(requestOps, 1800);
        setStatus('Loading shared text document.');
      } else {
        render();
      }
    } else if (message.type === 'call_app.crdt.bootstrap.response') {
      handleCrdtResult(message.result || {});
    } else if (message.type === 'call_app.crdt.ops.response') {
      handleCrdtResult(message.result || {});
    } else if (message.type === 'call_app.crdt.op.appended') {
      if (message.result?.operation) {
        applyEnvelope(message.result.operation);
        render();
      }
    } else if (message.type === 'call_app.crdt.error') {
      applyAccessState(message);
      render();
      setStatus(String(message.message || 'Call App sync error.'));
    }
  });

  render();
})();
