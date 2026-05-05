import { computed, reactive, ref } from 'vue';
import { GOVERNANCE_CRUD_DESCRIPTORS } from './crudDescriptors.js';

function normalizeString(value) {
  return String(value || '').trim();
}

function rowId(row) {
  return normalizeString(row?.id || row?.key || row?.name);
}

function frameFromRelation(relation) {
  const source = relation && typeof relation === 'object' ? relation : {};
  return {
    key: normalizeString(source.key),
    target_entity: normalizeString(source.target_entity),
    label_key: normalizeString(source.label_key),
    selection_mode: normalizeString(source.selection_mode) || 'single',
  };
}

function normalizeSelectionRows(rows = []) {
  return Array.isArray(rows) ? rows.filter((row) => rowId(row) !== '') : [];
}

export function useCrudRelationNavigator(options = {}) {
  const rowProvider = typeof options.rowProvider === 'function' ? options.rowProvider : () => [];
  const pageSize = Number.isInteger(options.pageSize) && options.pageSize > 0 ? options.pageSize : 6;
  const stack = ref([]);
  const query = ref('');
  const page = ref(1);
  const selectedByFrame = reactive({});
  const nestedSelectionsByFrame = reactive({});

  const currentFrame = computed(() => stack.value[stack.value.length - 1] || null);
  const currentDescriptor = computed(() => (
    currentFrame.value ? GOVERNANCE_CRUD_DESCRIPTORS[currentFrame.value.target_entity] || null : null
  ));
  const currentRows = computed(() => rowsForFrame(currentFrame.value));
  const filteredRows = computed(() => {
    const needle = query.value.trim().toLowerCase();
    if (needle === '') return currentRows.value;
    return currentRows.value.filter((row) => rowSearchText(row).includes(needle));
  });
  const pageCount = computed(() => Math.max(1, Math.ceil(filteredRows.value.length / pageSize)));
  const pagedRows = computed(() => {
    const offset = (page.value - 1) * pageSize;
    return filteredRows.value.slice(offset, offset + pageSize);
  });
  const currentSelectionIds = computed(() => selectedByFrame[currentFrame.value?.key || ''] || []);
  const currentSelectedRows = computed(() => selectedRowsForFrame(currentFrame.value));

  function reset(relation, selectedRows = []) {
    stack.value = [frameFromRelation(relation)].filter((frame) => frame.key !== '' && frame.target_entity !== '');
    query.value = '';
    page.value = 1;
    for (const key of Object.keys(selectedByFrame)) {
      delete selectedByFrame[key];
    }
    for (const key of Object.keys(nestedSelectionsByFrame)) {
      delete nestedSelectionsByFrame[key];
    }
    const frame = currentFrame.value;
    if (frame) {
      selectedByFrame[frame.key] = normalizeSelectionRows(selectedRows).map(rowId);
    }
  }

  function push(relation) {
    const parentFrame = currentFrame.value;
    const frame = frameFromRelation(relation);
    if (frame.key === '' || frame.target_entity === '') return;
    stack.value = [...stack.value, frame];
    selectedByFrame[frame.key] = nestedSelectionIdsForParentSelection(parentFrame, frame.key);
    query.value = '';
    page.value = 1;
  }

  function back() {
    if (stack.value.length <= 1) return;
    stack.value = stack.value.slice(0, -1);
    query.value = '';
    page.value = 1;
  }

  function goToPage(nextPage) {
    const parsed = Number.parseInt(String(nextPage), 10);
    if (!Number.isInteger(parsed)) return;
    page.value = Math.min(pageCount.value, Math.max(1, parsed));
  }

  function toggleRow(row) {
    const frame = currentFrame.value;
    const id = rowId(row);
    if (!frame || id === '') return;

    const existing = Array.isArray(selectedByFrame[frame.key]) ? selectedByFrame[frame.key] : [];
    if (frame.selection_mode === 'multiple') {
      selectedByFrame[frame.key] = existing.includes(id)
        ? existing.filter((candidate) => candidate !== id)
        : [...existing, id];
      return;
    }
    selectedByFrame[frame.key] = existing.includes(id) ? [] : [id];
  }

  function rowSelected(row) {
    const id = rowId(row);
    return id !== '' && currentSelectionIds.value.includes(id);
  }

  function rowSearchText(row) {
    return [
      row?.name,
      row?.key,
      row?.email,
      row?.description,
      row?.status,
    ].map(normalizeString).join(' ').toLowerCase();
  }

  function rowsForFrame(frame) {
    return normalizeSelectionRows(rowProvider(frame?.target_entity || ''));
  }

  function selectedRowsForFrame(frame) {
    if (!frame) return [];
    const selected = new Set(selectedByFrame[frame.key] || []);
    return rowsForFrame(frame)
      .filter((row) => selected.has(rowId(row)))
      .map((row) => rowWithNestedSelections(row, frame));
  }

  function nestedSelectionsForFrame(frame) {
    const key = normalizeString(frame?.key);
    if (key === '') return {};
    if (!nestedSelectionsByFrame[key]) {
      nestedSelectionsByFrame[key] = {};
    }
    return nestedSelectionsByFrame[key];
  }

  function nestedSelectionIdsForParentSelection(parentFrame, relationKey) {
    if (!parentFrame) return [];
    const selectedParentIds = selectedByFrame[parentFrame.key] || [];
    const nestedByParent = nestedSelectionsByFrame[parentFrame.key] || {};
    const ids = new Set();
    for (const parentId of selectedParentIds) {
      const rows = nestedByParent[parentId]?.[relationKey] || [];
      for (const row of normalizeSelectionRows(rows)) {
        ids.add(rowId(row));
      }
    }
    return [...ids];
  }

  function rowWithNestedSelections(row, frame) {
    const id = rowId(row);
    const nested = nestedSelectionsByFrame[frame?.key || '']?.[id];
    const inherited = cloneRelationships(row?.relationships);
    const attached = cloneRelationships(nested);
    const relationships = { ...inherited, ...attached };
    if (Object.keys(relationships).length === 0) return row;
    return { ...row, relationships };
  }

  function cloneRelationships(source = {}) {
    if (!source || typeof source !== 'object') return {};
    return Object.fromEntries(Object.entries(source)
      .filter(([, rows]) => Array.isArray(rows))
      .map(([key, rows]) => [key, normalizeSelectionRows(rows).map((row) => {
        const copy = { ...row };
        const relationships = cloneRelationships(row?.relationships);
        if (Object.keys(relationships).length > 0) {
          copy.relationships = relationships;
        }
        return copy;
      })]));
  }

  function applyCurrentSelectionToParent() {
    if (stack.value.length <= 1) return false;
    const childFrame = currentFrame.value;
    const parentFrame = stack.value[stack.value.length - 2];
    const parentSelectionIds = selectedByFrame[parentFrame?.key || ''] || [];
    if (!childFrame || !parentFrame || parentSelectionIds.length === 0) return false;
    const selectedRows = selectedRowsForFrame(childFrame);
    const nestedByParent = nestedSelectionsForFrame(parentFrame);
    for (const parentId of parentSelectionIds) {
      nestedByParent[parentId] = {
        ...(nestedByParent[parentId] || {}),
        [childFrame.key]: selectedRows,
      };
    }
    back();
    return true;
  }

  return {
    stack,
    query,
    page,
    pageCount,
    pagedRows,
    filteredRows,
    currentFrame,
    currentDescriptor,
    currentSelectionIds,
    currentSelectedRows,
    selectedRowsForFrame,
    reset,
    push,
    back,
    applyCurrentSelectionToParent,
    goToPage,
    toggleRow,
    rowSelected,
    rowId,
  };
}
