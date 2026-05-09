(() => {
  const bridgeProtocol = 'king.call_app.iframe.v1';
  const appKey = 'spreadsheet';
  const rowCount = 24;
  const colCount = 12;
  const statusEl = document.getElementById('status');
  const clockEl = document.getElementById('clock');
  const modeBadge = document.getElementById('modeBadge');
  const grid = document.getElementById('grid');
  const sheetTabs = document.getElementById('sheetTabs');
  const sheetNameInput = document.getElementById('sheetName');
  const nameBox = document.getElementById('nameBox');
  const formulaInput = document.getElementById('formulaInput');
  const controls = {
    addSheet: document.getElementById('addSheet'),
    renameSheet: document.getElementById('renameSheet'),
    deleteSheet: document.getElementById('deleteSheet'),
    bold: document.getElementById('boldFormat'),
    italic: document.getElementById('italicFormat'),
    fill: document.getElementById('fillFormat'),
    clear: document.getElementById('clearRange'),
    commit: document.getElementById('commitEdit'),
    exportCsv: document.getElementById('exportCsv'),
    exportXml: document.getElementById('exportXml'),
  };
  const state = { sheets: new Map(), order: [], applied: new Set(), remoteSelections: new Map() };
  let parentOrigin = '';
  let appSessionId = '';
  let callId = '';
  let documentId = '';
  let actorId = '';
  let participantLabel = 'User';
  let activeSheetId = 'sheet_1';
  let grantState = 'denied';
  let capabilities = new Set();
  let permissionActions = new Set();
  let permissionMap = {};
  let latestClock = 0;
  let pollTimer = 0;
  let dragging = false;
  let selectedRange = { start: { row: 1, col: 1 }, end: { row: 1, col: 1 } };
  let lastPresenceSentAt = 0;

  function columnName(col) {
    let name = '';
    let value = col;
    while (value > 0) {
      const offset = (value - 1) % 26;
      name = String.fromCharCode(65 + offset) + name;
      value = Math.floor((value - offset - 1) / 26);
    }
    return name;
  }

  function columnNumber(name) {
    return String(name || '').toUpperCase().split('').reduce((total, char) => (
      total * 26 + char.charCodeAt(0) - 64
    ), 0);
  }

  function cellKey(row, col) {
    return `${columnName(col)}${row}`;
  }

  function parseCellRef(ref) {
    const match = String(ref || '').trim().toUpperCase().match(/^([A-Z]+)([1-9]\d*)$/);
    if (!match) return null;
    const col = columnNumber(match[1]);
    const row = Number(match[2]);
    if (row < 1 || row > rowCount || col < 1 || col > colCount) return null;
    return { row, col, ref: cellKey(row, col) };
  }

  function orderedRange(range = selectedRange) {
    const start = range.start || { row: 1, col: 1 };
    const end = range.end || start;
    return {
      top: Math.min(start.row, end.row),
      bottom: Math.max(start.row, end.row),
      left: Math.min(start.col, end.col),
      right: Math.max(start.col, end.col),
    };
  }

  function rangeLabel(range = selectedRange) {
    const box = orderedRange(range);
    const first = cellKey(box.top, box.left);
    const last = cellKey(box.bottom, box.right);
    return first === last ? first : `${first}:${last}`;
  }

  function rangeRefs(range = selectedRange) {
    const box = orderedRange(range);
    const refs = [];
    for (let row = box.top; row <= box.bottom; row += 1) {
      for (let col = box.left; col <= box.right; col += 1) refs.push(cellKey(row, col));
    }
    return refs;
  }

  function ensureSheet(id = 'sheet_1', name = 'Sheet 1') {
    const safeId = String(id || 'sheet_1').trim() || 'sheet_1';
    if (!state.sheets.has(safeId)) {
      state.sheets.set(safeId, { id: safeId, name: String(name || 'Sheet').slice(0, 40), cells: new Map() });
      state.order.push(safeId);
    }
    if (!state.sheets.has(activeSheetId)) activeSheetId = safeId;
    return state.sheets.get(safeId);
  }

  function activeSheet() {
    if (state.order.length === 0) ensureSheet();
    return state.sheets.get(activeSheetId) || ensureSheet(state.order[0], 'Sheet 1');
  }

  function hasAction(action) {
    return permissionActions.has(action) || permissionMap[action] === true;
  }

  function canRead() {
    return grantState === 'allowed' && capabilities.has('call_apps.crdt.read') && hasAction('read');
  }

  function canWrite() {
    return grantState === 'allowed' && capabilities.has('call_apps.crdt.append') && hasAction('write');
  }

  function canDelete() {
    return grantState === 'allowed' && hasAction('delete');
  }

  function canExport() {
    return canRead()
      && (capabilities.has('call_apps.export.download')
        || capabilities.has('call_apps.export.request')
        || hasAction('export')
        || permissionMap.export === true);
  }

  function canPublishPresence() {
    return grantState === 'allowed' && capabilities.has('call_apps.presence.publish');
  }

  function setStatus(message) {
    statusEl.textContent = String(message || '');
    modeBadge.textContent = canWrite() ? 'Editor' : (canRead() ? 'Viewer' : 'No access');
    clockEl.textContent = `${latestClock} ops`;
    controls.commit.disabled = !canWrite();
    controls.addSheet.disabled = !canWrite();
    controls.renameSheet.disabled = !canWrite();
    controls.bold.disabled = !canWrite();
    controls.italic.disabled = !canWrite();
    controls.fill.disabled = !canWrite();
    controls.clear.disabled = !canDelete();
    controls.deleteSheet.disabled = !canDelete() || state.order.length <= 1;
    controls.exportCsv.disabled = !canExport();
    controls.exportXml.disabled = !canExport();
    formulaInput.disabled = !canWrite();
    sheetNameInput.disabled = !canWrite();
  }

  function clonePayload(payload) {
    return JSON.parse(JSON.stringify(payload));
  }

  function emit(type, payload = {}) {
    if (!parentOrigin || !window.parent) return;
    window.parent.postMessage(clonePayload({
      type,
      bridge_protocol: bridgeProtocol,
      app_key: appKey,
      app_session_id: appSessionId,
      ...payload,
    }), parentOrigin);
  }

  function operationId(type) {
    return `sheet_${type.replaceAll('.', '_')}_${Date.now()}_${Math.random().toString(36).slice(2, 10)}`;
  }

  function appendOperation(payloadType, payload) {
    const isDelete = String(payloadType || '').endsWith('.delete');
    if (isDelete ? !canDelete() : !canWrite()) {
      setStatus(isDelete ? 'Delete permission is disabled for this participant.' : 'Viewer mode. Editing is disabled.');
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
    setStatus('Spreadsheet update queued.');
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
    if (!canRead()) return;
    emit('call_app.crdt.ops.request', {
      request_id: operationId('ops'),
      after_clock: latestClock,
      limit: 250,
    });
  }

  function publishSelection() {
    if (!canPublishPresence()) return;
    const now = Date.now();
    if (now - lastPresenceSentAt < 250) return;
    lastPresenceSentAt = now;
    emit('call_app.presence.publish', {
      request_id: operationId('presence'),
      payload_type: 'selection.update',
      actor_id: actorId,
      payload: {
        actor_id: actorId,
        display_name: participantLabel,
        selected_id: rangeLabel(),
      },
    });
  }

  function setSelection(start, end = start, publish = true) {
    selectedRange = {
      start: { row: start.row, col: start.col },
      end: { row: end.row, col: end.col },
    };
    const firstRef = cellKey(orderedRange().top, orderedRange().left);
    nameBox.value = rangeLabel();
    formulaInput.value = activeSheet().cells.get(firstRef)?.value || '';
    if (publish) publishSelection();
    render();
  }

  function applyAccessState(result = {}) {
    if (typeof result.grant_state === 'string' && result.grant_state.trim() !== '') {
      grantState = result.grant_state.trim().toLowerCase();
    }
    if (Array.isArray(result.permission_actions)) {
      permissionActions = new Set(result.permission_actions.map((entry) => String(entry || '').trim().toLowerCase()));
    }
    if (result.permissions && typeof result.permissions === 'object') permissionMap = result.permissions;
    if (!canRead()) clearInterval(pollTimer);
  }

  function applyEnvelope(envelope) {
    if (!envelope || state.applied.has(envelope.operation_id)) return;
    state.applied.add(envelope.operation_id);
    latestClock = Math.max(latestClock, Number(envelope.logical_clock || 0));
    const payload = envelope.payload && typeof envelope.payload === 'object' ? envelope.payload : {};
    const sheetId = String(payload.sheet_id || activeSheetId || 'sheet_1');
    const sheet = ensureSheet(sheetId, payload.sheet_name || payload.name || 'Sheet');
    const payloadType = String(envelope.payload_type || '');
    if (payloadType === 'sheet.add') {
      ensureSheet(sheetId, payload.name || `Sheet ${state.order.length + 1}`);
      activeSheetId = sheetId;
    } else if (payloadType === 'sheet.rename') {
      sheet.name = String(payload.name || sheet.name || 'Sheet').slice(0, 40);
    } else if (payloadType === 'sheet.delete') {
      if (state.order.length > 1) {
        state.sheets.delete(sheetId);
        state.order = state.order.filter((id) => id !== sheetId);
        if (activeSheetId === sheetId) activeSheetId = state.order[0];
      }
    } else if (payloadType === 'cell.set') {
      const ref = parseCellRef(payload.ref)?.ref;
      if (ref) sheet.cells.set(ref, { ...(sheet.cells.get(ref) || {}), value: String(payload.value ?? '') });
    } else if (payloadType === 'cell.delete') {
      const ref = parseCellRef(payload.ref)?.ref;
      if (ref) sheet.cells.delete(ref);
    } else if (payloadType === 'cell.format') {
      const ref = parseCellRef(payload.ref)?.ref;
      if (ref) mergeCellFormat(sheet, ref, payload.format || {});
    } else if (payloadType === 'range.format') {
      for (const ref of refsFromPayloadRange(payload.range)) mergeCellFormat(sheet, ref, payload.format || {});
    } else if (payloadType === 'range.delete') {
      for (const ref of refsFromPayloadRange(payload.range)) sheet.cells.delete(ref);
    }
    render();
    setStatus(canWrite() ? 'Spreadsheet synchronized.' : 'Read-only spreadsheet synchronized.');
  }

  function mergeCellFormat(sheet, ref, format) {
    const cell = sheet.cells.get(ref) || { value: '' };
    const nextFormat = { ...(cell.format || {}) };
    for (const [key, value] of Object.entries(format)) {
      if (value === '' || value === null || value === false) delete nextFormat[key];
      else nextFormat[key] = value;
    }
    sheet.cells.set(ref, { ...cell, format: nextFormat });
  }

  function refsFromPayloadRange(payloadRange) {
    const text = String(payloadRange || '').trim().toUpperCase();
    const [first, last = first] = text.split(':');
    const start = parseCellRef(first);
    const end = parseCellRef(last);
    if (!start || !end) return [];
    return rangeRefs({ start, end });
  }

  function applySnapshot(snapshot) {
    if (snapshot?.kind !== 'spreadsheet.snapshot.v1') return;
    state.sheets.clear();
    state.order = [];
    for (const sheet of snapshot.sheets || []) {
      const created = ensureSheet(sheet.id, sheet.name);
      for (const cell of sheet.cells || []) {
        const ref = parseCellRef(cell.ref)?.ref;
        if (ref) created.cells.set(ref, { value: String(cell.value ?? ''), format: cell.format || {} });
      }
    }
    activeSheetId = state.order[0] || 'sheet_1';
  }

  function handleCrdtResult(result = {}) {
    applyAccessState(result);
    if (result.document?.snapshot_clock) latestClock = Math.max(latestClock, Number(result.document.snapshot_clock));
    applySnapshot(result.document?.snapshot || result.snapshot || {});
    for (const envelope of result.ops || []) applyEnvelope(envelope);
    ensureSheet();
    render();
    setStatus(canWrite() ? 'Spreadsheet ready.' : (canRead() ? 'Read-only spreadsheet ready.' : 'Access not granted.'));
  }

  function tokenizeFormula(expression) {
    const tokens = [];
    let index = 0;
    while (index < expression.length) {
      const rest = expression.slice(index);
      const space = rest.match(/^\s+/);
      if (space) {
        index += space[0].length;
        continue;
      }
      const number = rest.match(/^(?:\d+\.?\d*|\.\d+)/);
      if (number) {
        tokens.push({ type: 'number', value: Number(number[0]) });
        index += number[0].length;
        continue;
      }
      const cell = rest.match(/^[A-Za-z]+[1-9]\d*/);
      if (cell) {
        tokens.push({ type: 'cell', value: cell[0].toUpperCase() });
        index += cell[0].length;
        continue;
      }
      const ident = rest.match(/^[A-Za-z_]+/);
      if (ident) {
        tokens.push({ type: 'ident', value: ident[0].toUpperCase() });
        index += ident[0].length;
        continue;
      }
      if ('+-*/^():,'.includes(rest[0])) {
        tokens.push({ type: rest[0], value: rest[0] });
        index += 1;
        continue;
      }
      throw new Error('VALUE');
    }
    return tokens;
  }

  function evaluateFormula(expression, sheet, seen) {
    const tokens = tokenizeFormula(expression);
    let pos = 0;
    const peek = () => tokens[pos] || null;
    const take = (type = '') => {
      const token = peek();
      if (!token || (type && token.type !== type)) throw new Error('VALUE');
      pos += 1;
      return token;
    };
    const parseExpression = () => parseAdd();
    const parseAdd = () => {
      let value = parseMultiply();
      while (peek()?.type === '+' || peek()?.type === '-') {
        const op = take().type;
        const rhs = parseMultiply();
        value = op === '+' ? value + rhs : value - rhs;
      }
      return value;
    };
    const parseMultiply = () => {
      let value = parsePower();
      while (peek()?.type === '*' || peek()?.type === '/') {
        const op = take().type;
        const rhs = parsePower();
        value = op === '*' ? value * rhs : value / rhs;
      }
      return value;
    };
    const parsePower = () => {
      let value = parseUnary();
      if (peek()?.type === '^') {
        take('^');
        value = value ** parsePower();
      }
      return value;
    };
    const parseUnary = () => {
      if (peek()?.type === '+') {
        take('+');
        return parseUnary();
      }
      if (peek()?.type === '-') {
        take('-');
        return -parseUnary();
      }
      return parsePrimary();
    };
    const parsePrimary = () => {
      const token = peek();
      if (!token) throw new Error('VALUE');
      if (token.type === 'number') return take('number').value;
      if (token.type === 'cell') return numericCellValue(sheet, take('cell').value, seen);
      if (token.type === '(') {
        take('(');
        const value = parseExpression();
        take(')');
        return value;
      }
      if (token.type === 'ident') {
        const name = take('ident').value;
        take('(');
        const values = [];
        if (peek()?.type !== ')') {
          while (true) {
            values.push(...parseFunctionArgument());
            if (peek()?.type !== ',') break;
            take(',');
          }
        }
        take(')');
        const numeric = values.filter((value) => Number.isFinite(value));
        if (name === 'SUM') return numeric.reduce((total, value) => total + value, 0);
        if (name === 'AVERAGE') return numeric.length ? numeric.reduce((total, value) => total + value, 0) / numeric.length : 0;
        if (name === 'MIN') return numeric.length ? Math.min(...numeric) : 0;
        if (name === 'MAX') return numeric.length ? Math.max(...numeric) : 0;
        throw new Error('NAME');
      }
      throw new Error('VALUE');
    };
    const parseFunctionArgument = () => {
      if (peek()?.type === 'cell' && tokens[pos + 1]?.type === ':' && tokens[pos + 2]?.type === 'cell') {
        const first = take('cell').value;
        take(':');
        const last = take('cell').value;
        return refsFromPayloadRange(`${first}:${last}`).map((ref) => numericCellValue(sheet, ref, seen));
      }
      return [parseExpression()];
    };
    const value = parseExpression();
    if (pos !== tokens.length || !Number.isFinite(value)) throw new Error('VALUE');
    return Math.round(value * 100000000) / 100000000;
  }

  function numericCellValue(sheet, ref, seen) {
    const parsed = parseCellRef(ref);
    if (!parsed) return 0;
    const key = `${sheet.id}:${parsed.ref}`;
    if (seen.has(key)) throw new Error('CYCLE');
    const cell = sheet.cells.get(parsed.ref);
    if (!cell || String(cell.value || '').trim() === '') return 0;
    const raw = String(cell.value || '').trim();
    if (raw.startsWith('=')) {
      seen.add(key);
      const result = calculateCell(sheet, parsed.ref, seen);
      seen.delete(key);
      if (result.error) throw new Error(result.error);
      return Number(result.value) || 0;
    }
    const numeric = Number(raw);
    return Number.isFinite(numeric) ? numeric : 0;
  }

  function calculateCell(sheet, ref, seen = new Set()) {
    const cell = sheet.cells.get(ref);
    const raw = String(cell?.value || '');
    if (!raw.trim()) return { display: '', value: 0, error: '' };
    if (!raw.trim().startsWith('=')) return { display: raw, value: Number(raw), error: '' };
    try {
      const value = evaluateFormula(raw.trim().slice(1), sheet, seen);
      return { display: String(value), value, error: '' };
    } catch (error) {
      const code = String(error?.message || 'VALUE').toUpperCase();
      return { display: `#${code}!`, value: 0, error: code };
    }
  }

  function renderTabs() {
    const buttons = state.order.map((id) => {
      const sheet = state.sheets.get(id);
      const button = document.createElement('button');
      button.type = 'button';
      button.className = `sheet-tab${id === activeSheetId ? ' active' : ''}`;
      button.textContent = sheet?.name || 'Sheet';
      button.addEventListener('click', () => {
        activeSheetId = id;
        setSelection({ row: 1, col: 1 }, { row: 1, col: 1 });
      });
      return button;
    });
    sheetTabs.replaceChildren(...buttons);
  }

  function renderGrid() {
    const sheet = activeSheet();
    const tableHead = document.createElement('thead');
    const headerRow = document.createElement('tr');
    const corner = document.createElement('th');
    corner.className = 'corner';
    headerRow.append(corner);
    for (let col = 1; col <= colCount; col += 1) {
      const th = document.createElement('th');
      th.textContent = columnName(col);
      headerRow.append(th);
    }
    tableHead.append(headerRow);
    const tableBody = document.createElement('tbody');
    const selected = new Set(rangeRefs());
    const anchor = cellKey(orderedRange().top, orderedRange().left);
    const remoteSelected = new Set();
    for (const range of state.remoteSelections.values()) {
      for (const ref of refsFromPayloadRange(range)) remoteSelected.add(ref);
    }
    for (let row = 1; row <= rowCount; row += 1) {
      const tr = document.createElement('tr');
      const rowHead = document.createElement('th');
      rowHead.className = 'row-header';
      rowHead.textContent = String(row);
      tr.append(rowHead);
      for (let col = 1; col <= colCount; col += 1) {
        const ref = cellKey(row, col);
        const cell = sheet.cells.get(ref) || {};
        const result = calculateCell(sheet, ref);
        const td = document.createElement('td');
        td.dataset.row = String(row);
        td.dataset.col = String(col);
        td.textContent = result.display;
        if (selected.has(ref)) td.classList.add(ref === anchor ? 'selected' : 'range-selected');
        if (remoteSelected.has(ref)) td.classList.add('remote-selected');
        if (result.error) td.classList.add('error');
        applyFormatStyle(td, cell.format || {});
        tr.append(td);
      }
      tableBody.append(tr);
    }
    grid.replaceChildren(tableHead, tableBody);
  }

  function applyFormatStyle(td, format) {
    if (format.bold) td.style.fontWeight = '800';
    if (format.italic) td.style.fontStyle = 'italic';
    if (format.fill) td.style.background = format.fill;
    if (format.color) td.style.color = format.color;
  }

  function render() {
    ensureSheet();
    sheetNameInput.value = activeSheet().name;
    renderTabs();
    renderGrid();
    setStatus(statusEl.textContent || 'Spreadsheet ready.');
  }

  function commitCellEdit() {
    const ref = cellKey(orderedRange().top, orderedRange().left);
    appendOperation('cell.set', {
      sheet_id: activeSheetId,
      ref,
      value: formulaInput.value,
    });
  }

  function applyRangeFormat(format) {
    const refs = rangeRefs();
    appendOperation(refs.length === 1 ? 'cell.format' : 'range.format', {
      sheet_id: activeSheetId,
      ref: refs[0],
      range: rangeLabel(),
      format,
    });
  }

  function clearSelection() {
    const refs = rangeRefs();
    appendOperation(refs.length === 1 ? 'cell.delete' : 'range.delete', {
      sheet_id: activeSheetId,
      ref: refs[0],
      range: rangeLabel(),
    });
  }

  function usedBounds(sheet) {
    let maxRow = 6;
    let maxCol = 6;
    for (const [ref, cell] of sheet.cells.entries()) {
      if (String(cell.value || '').trim() === '') continue;
      const parsed = parseCellRef(ref);
      if (!parsed) continue;
      maxRow = Math.max(maxRow, parsed.row);
      maxCol = Math.max(maxCol, parsed.col);
    }
    return { maxRow, maxCol };
  }

  function csvEscape(value) {
    const text = String(value ?? '');
    return /[",\n]/.test(text) ? `"${text.replaceAll('"', '""')}"` : text;
  }

  function exportCsv() {
    if (!canExport()) {
      setStatus('Export permission is disabled for this participant.');
      return;
    }
    const sheet = activeSheet();
    const bounds = usedBounds(sheet);
    const rows = [];
    for (let row = 1; row <= bounds.maxRow; row += 1) {
      const values = [];
      for (let col = 1; col <= bounds.maxCol; col += 1) values.push(csvEscape(calculateCell(sheet, cellKey(row, col)).display));
      rows.push(values.join(','));
    }
    downloadText(rows.join('\n'), 'text/csv', `${safeFilename(sheet.name)}.csv`);
  }

  function exportSpreadsheetXml() {
    if (!canExport()) {
      setStatus('Export permission is disabled for this participant.');
      return;
    }
    const worksheets = state.order.map((id) => {
      const sheet = state.sheets.get(id);
      const bounds = usedBounds(sheet);
      const rows = [];
      for (let row = 1; row <= bounds.maxRow; row += 1) {
        const cells = [];
        for (let col = 1; col <= bounds.maxCol; col += 1) {
          const result = calculateCell(sheet, cellKey(row, col));
          const type = Number.isFinite(Number(result.display)) && result.display !== '' ? 'Number' : 'String';
          cells.push(`<Cell><Data ss:Type="${type}">${xmlEscape(result.display)}</Data></Cell>`);
        }
        rows.push(`<Row>${cells.join('')}</Row>`);
      }
      return `<Worksheet ss:Name="${xmlEscape(sheet.name)}"><Table>${rows.join('')}</Table></Worksheet>`;
    });
    const xml = ['<?xml version="1.0"?>', '<?mso-application progid="Excel.Sheet"?>',
      '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">',
      worksheets.join(''), '</Workbook>'].join('');
    downloadText(xml, 'application/vnd.ms-excel', 'kingrt-spreadsheet.xml');
  }

  function xmlEscape(value) {
    return String(value ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;');
  }

  function safeFilename(value) {
    return String(value || 'sheet').toLowerCase().replace(/[^a-z0-9._-]+/g, '-').replace(/^-+|-+$/g, '') || 'sheet';
  }

  function downloadText(text, mimeType, filename) {
    const blob = new Blob([text], { type: mimeType });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = filename;
    anchor.rel = 'noopener';
    anchor.click();
    URL.revokeObjectURL(url);
    setStatus(`Exported ${filename}.`);
  }

  grid.addEventListener('pointerdown', (event) => {
    const cell = event.target.closest('td');
    if (!cell || !canRead()) return;
    dragging = true;
    const point = { row: Number(cell.dataset.row), col: Number(cell.dataset.col) };
    setSelection(point, point);
  });
  grid.addEventListener('pointerover', (event) => {
    if (!dragging) return;
    const cell = event.target.closest('td');
    if (!cell) return;
    setSelection(selectedRange.start, { row: Number(cell.dataset.row), col: Number(cell.dataset.col) });
  });
  grid.addEventListener('pointerup', () => { dragging = false; });
  grid.addEventListener('pointercancel', () => { dragging = false; });
  grid.addEventListener('dblclick', (event) => {
    const cell = event.target.closest('td');
    if (!cell || !canWrite()) return;
    formulaInput.focus();
    formulaInput.select();
  });
  formulaInput.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') commitCellEdit();
  });
  controls.commit.addEventListener('click', commitCellEdit);
  controls.bold.addEventListener('click', () => applyRangeFormat({ bold: true }));
  controls.italic.addEventListener('click', () => applyRangeFormat({ italic: true }));
  controls.fill.addEventListener('change', () => applyRangeFormat({ fill: controls.fill.value }));
  controls.clear.addEventListener('click', clearSelection);
  controls.addSheet.addEventListener('click', () => {
    const id = operationId('sheet').slice(0, 42);
    appendOperation('sheet.add', { sheet_id: id, name: `Sheet ${state.order.length + 1}` });
  });
  controls.renameSheet.addEventListener('click', () => {
    appendOperation('sheet.rename', { sheet_id: activeSheetId, name: sheetNameInput.value.trim() || activeSheet().name });
  });
  controls.deleteSheet.addEventListener('click', () => {
    appendOperation('sheet.delete', { sheet_id: activeSheetId });
  });
  controls.exportCsv.addEventListener('click', exportCsv);
  controls.exportXml.addEventListener('click', exportSpreadsheetXml);

  window.addEventListener('message', (event) => {
    const message = event.data && typeof event.data === 'object' ? event.data : null;
    if (!message || message.bridge_protocol !== bridgeProtocol) return;
    if (message.type === 'call_app.launch') {
      parentOrigin = event.origin;
      appSessionId = String(message.app_session_id || '');
      callId = String(message.call_id || '');
      documentId = String(message.document_id || '');
      const context = message.launch_context || {};
      grantState = String(context.grant_state || 'denied').trim().toLowerCase();
      actorId = String(context.participant?.actor_id || '');
      participantLabel = String(context.participant?.display_name || actorId || 'User').slice(0, 80);
      capabilities = new Set(Array.isArray(message.capabilities) ? message.capabilities : []);
      permissionActions = new Set(Array.isArray(context.permission_actions)
        ? context.permission_actions.map((entry) => String(entry || '').trim().toLowerCase())
        : []);
      permissionMap = context.permissions && typeof context.permissions === 'object' ? context.permissions : {};
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
        setStatus('Loading spreadsheet.');
      } else {
        setStatus('Access not granted for this spreadsheet.');
      }
      render();
    } else if (message.type === 'call_app.crdt.bootstrap.response') {
      handleCrdtResult(message.result || {});
    } else if (message.type === 'call_app.crdt.ops.response') {
      handleCrdtResult(message.result || {});
    } else if (message.type === 'call_app.crdt.op.appended') {
      if (message.result?.operation) applyEnvelope(message.result.operation);
    } else if (message.type === 'call_app.presence.update') {
      const remoteActor = String(message.actor_id || message.payload?.actor_id || '').trim();
      if (remoteActor && remoteActor !== actorId && message.payload_type === 'selection.update') {
        state.remoteSelections.set(remoteActor, String(message.payload?.selected_id || ''));
        render();
      }
    } else if (message.type === 'call_app.presence.leave') {
      state.remoteSelections.delete(String(message.actor_id || message.payload?.actor_id || '').trim());
      render();
    } else if (message.type === 'call_app.crdt.error') {
      applyAccessState(message);
      setStatus(String(message.reason || '') === 'participant_grant_denied'
        ? 'Access revoked for this spreadsheet.'
        : String(message.message || 'Spreadsheet sync error.'));
      render();
    }
  });

  ensureSheet();
  setSelection({ row: 1, col: 1 }, { row: 1, col: 1 }, false);
  setStatus('Waiting for Call App launch.');
})();
