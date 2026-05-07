import { computed, ref } from 'vue';

function normalizeString(value) {
  return String(value || '').trim();
}

function normalizeFilterValue(value) {
  const normalized = normalizeString(value).toLowerCase();
  return normalized === '' ? 'all' : normalized;
}

function optionFromValue(value) {
  const normalized = normalizeFilterValue(value);
  if (normalized === 'all') return null;
  return {
    value: normalized,
    label: normalizeString(value) || normalized,
  };
}

function rowScopeValue(row) {
  return normalizeString(row?.scope || row?.scope_type || row?.subject_type || row?.resource_type);
}

function rowSearchText(row, keys, descriptionResolver) {
  return keys
    .map((key) => (key === 'description' ? descriptionResolver(row) : row?.[key] ?? ''))
    .join(' ')
    .toLowerCase();
}

function statusOptionsFromDescriptor(descriptor = {}) {
  const statusField = (descriptor.fields || []).find((field) => field?.key === 'status');
  return (statusField?.options || [])
    .map((option) => ({
      value: normalizeFilterValue(option?.value),
      label: normalizeString(option?.label || option?.value),
      label_key: normalizeString(option?.label_key),
    }))
    .filter((option) => option.value !== 'all');
}

export function useGovernanceCrudFilters(options = {}) {
  const rows = options.rows;
  const tableColumns = options.tableColumns;
  const crudDescriptor = options.crudDescriptor;
  const rowDescription = typeof options.rowDescription === 'function' ? options.rowDescription : () => '';
  const query = ref('');
  const statusFilter = ref('all');
  const scopeFilter = ref('all');

  const statusOptions = computed(() => {
    const byValue = new Map();
    for (const option of statusOptionsFromDescriptor(crudDescriptor?.value || {})) {
      byValue.set(option.value, option);
    }
    for (const row of rows.value || []) {
      const option = optionFromValue(row?.status);
      if (option && !byValue.has(option.value)) byValue.set(option.value, option);
    }
    return [...byValue.values()];
  });

  const scopeOptions = computed(() => {
    const byValue = new Map();
    for (const row of rows.value || []) {
      const option = optionFromValue(rowScopeValue(row));
      if (option && !byValue.has(option.value)) byValue.set(option.value, option);
    }
    return [...byValue.values()];
  });

  const filteredRows = computed(() => {
    const needle = query.value.trim().toLowerCase();
    const statusNeedle = normalizeFilterValue(statusFilter.value);
    const scopeNeedle = normalizeFilterValue(scopeFilter.value);
    const keys = crudDescriptor.value.search_fields || tableColumns.value.map((column) => column.key);

    return rows.value.filter((row) => {
      if (needle !== '' && !rowSearchText(row, keys, rowDescription).includes(needle)) return false;
      if (statusNeedle !== 'all' && normalizeFilterValue(row?.status) !== statusNeedle) return false;
      if (scopeNeedle !== 'all' && normalizeFilterValue(rowScopeValue(row)) !== scopeNeedle) return false;
      return true;
    });
  });

  const hasActiveFilters = computed(() => (
    query.value.trim() !== ''
    || normalizeFilterValue(statusFilter.value) !== 'all'
    || normalizeFilterValue(scopeFilter.value) !== 'all'
  ));

  function resetFilters() {
    query.value = '';
    statusFilter.value = 'all';
    scopeFilter.value = 'all';
  }

  function applyToolbarSearch() {
    query.value = query.value.trim();
  }

  return {
    query,
    statusFilter,
    scopeFilter,
    statusOptions,
    scopeOptions,
    filteredRows,
    hasActiveFilters,
    resetFilters,
    applyToolbarSearch,
  };
}
