function normalizeString(value) {
  return String(value || '').trim();
}

function entityId(row) {
  return normalizeString(row?.id || row?.key || row?.email || row?.name);
}

export function normalizeEntitySummary(entityKey, row = {}) {
  const id = entityId(row);
  return {
    entity_key: normalizeString(entityKey),
    id,
    name: normalizeString(row.name || row.display_name || row.email || row.key || id),
    key: normalizeString(row.key || row.email || id),
    status: normalizeString(row.status),
    description: normalizeString(row.description),
    readonly: row.readonly === true,
  };
}

function includedRows(included = {}) {
  if (Array.isArray(included)) {
    return included.flatMap((entry) => {
      const entityKey = normalizeString(entry?.entity_key || entry?.entity || entry?.type);
      const rows = Array.isArray(entry?.rows) ? entry.rows : [];
      return rows.map((row) => [entityKey, row]);
    });
  }

  if (!included || typeof included !== 'object') return [];

  return Object.entries(included).flatMap(([entityKey, value]) => {
    const rows = Array.isArray(value)
      ? value
      : (Array.isArray(value?.rows) ? value.rows : value?.items);
    return Array.isArray(rows) ? rows.map((row) => [entityKey, row]) : [];
  });
}

export function createEntitySummaryCache() {
  const summariesByEntity = new Map();

  function entityMap(entityKey) {
    const normalized = normalizeString(entityKey);
    if (!summariesByEntity.has(normalized)) {
      summariesByEntity.set(normalized, new Map());
    }
    return summariesByEntity.get(normalized);
  }

  function upsertSummary(entityKey, row) {
    const summary = normalizeEntitySummary(entityKey, row);
    if (summary.entity_key === '' || summary.id === '') return null;
    entityMap(summary.entity_key).set(summary.id, summary);
    return summary;
  }

  function upsertRows(entityKey, rows = []) {
    if (!Array.isArray(rows)) return [];
    return rows.map((row) => upsertSummary(entityKey, row)).filter(Boolean);
  }

  function hydrateIncluded(included = {}) {
    return includedRows(included).map(([entityKey, row]) => upsertSummary(entityKey, row)).filter(Boolean);
  }

  function getSummary(entityKey, id) {
    return entityMap(entityKey).get(normalizeString(id)) || null;
  }

  function getSummaries(entityKey, ids = []) {
    return ids.map((id) => getSummary(entityKey, id)).filter(Boolean);
  }

  function rows(entityKey) {
    return [...entityMap(entityKey).values()];
  }

  function missingIds(entityKey, ids = []) {
    const entitySummaries = entityMap(entityKey);
    return [...new Set(ids.map(normalizeString).filter(Boolean))]
      .filter((id) => !entitySummaries.has(id));
  }

  function buildBatchSummaryRequest(entityKey, ids = []) {
    return {
      entity_key: normalizeString(entityKey),
      ids: missingIds(entityKey, ids),
    };
  }

  async function loadMissingSummaries(entityKey, ids = [], fetchBatch) {
    const request = buildBatchSummaryRequest(entityKey, ids);
    if (request.ids.length === 0 || typeof fetchBatch !== 'function') {
      return getSummaries(entityKey, ids);
    }

    const payload = await fetchBatch(request);
    hydrateIncluded(payload?.included || payload);
    return getSummaries(entityKey, ids);
  }

  return {
    upsertSummary,
    upsertRows,
    hydrateIncluded,
    getSummary,
    getSummaries,
    rows,
    missingIds,
    buildBatchSummaryRequest,
    loadMissingSummaries,
  };
}
