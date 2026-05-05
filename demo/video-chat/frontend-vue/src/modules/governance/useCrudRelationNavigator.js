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

  const currentFrame = computed(() => stack.value[stack.value.length - 1] || null);
  const currentDescriptor = computed(() => (
    currentFrame.value ? GOVERNANCE_CRUD_DESCRIPTORS[currentFrame.value.target_entity] || null : null
  ));
  const currentRows = computed(() => normalizeSelectionRows(rowProvider(currentFrame.value?.target_entity || '')));
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
  const currentSelectedRows = computed(() => {
    const selected = new Set(currentSelectionIds.value);
    return currentRows.value.filter((row) => selected.has(rowId(row)));
  });

  function reset(relation, selectedRows = []) {
    stack.value = [frameFromRelation(relation)].filter((frame) => frame.key !== '' && frame.target_entity !== '');
    query.value = '';
    page.value = 1;
    for (const key of Object.keys(selectedByFrame)) {
      delete selectedByFrame[key];
    }
    const frame = currentFrame.value;
    if (frame) {
      selectedByFrame[frame.key] = normalizeSelectionRows(selectedRows).map(rowId);
    }
  }

  function push(relation) {
    const frame = frameFromRelation(relation);
    if (frame.key === '' || frame.target_entity === '') return;
    stack.value = [...stack.value, frame];
    if (!Array.isArray(selectedByFrame[frame.key])) {
      selectedByFrame[frame.key] = [];
    }
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
    reset,
    push,
    back,
    goToPage,
    toggleRow,
    rowSelected,
    rowId,
  };
}
