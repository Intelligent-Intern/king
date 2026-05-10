const SUMMARY_ENTITIES = Object.freeze([
  'users',
  'groups',
  'organizations',
  'roles',
  'grants',
  'policies',
  'data-portability',
]);

function normalizeString(value) {
  return String(value || '').trim();
}

function relationMap(relationships = []) {
  return new Map((Array.isArray(relationships) ? relationships : [])
    .map((relation) => [normalizeString(relation?.key), relation])
    .filter(([key]) => key !== ''));
}

function relationEntityKey(relation, row = {}) {
  const target = normalizeString(relation?.target_entity);
  const rowEntity = normalizeString(row?.entity_key);
  if (['subjects', 'resources'].includes(target) && rowEntity !== '') {
    return rowEntity;
  }
  if (target === 'governance_roles') return 'roles';
  return target;
}

function rowSummaryId(row = {}) {
  return normalizeString(row?.id || row?.key);
}

export function relationSelectionSummaryRequests(selections = {}, relationships = []) {
  const relations = relationMap(relationships);
  const requestsByEntity = new Map();
  for (const [relationKey, rows] of Object.entries(selections || {})) {
    if (!Array.isArray(rows)) continue;
    const relation = relations.get(normalizeString(relationKey));
    for (const row of rows) {
      const entityKey = relationEntityKey(relation, row);
      const id = rowSummaryId(row);
      if (!SUMMARY_ENTITIES.includes(entityKey) || id === '') continue;
      if (!requestsByEntity.has(entityKey)) requestsByEntity.set(entityKey, new Set());
      requestsByEntity.get(entityKey).add(id);
    }
  }

  return [...requestsByEntity.entries()].map(([entityKey, ids]) => ({
    entity_key: entityKey,
    ids: [...ids],
  }));
}

export async function hydrateRelationSelectionSummaries({
  selections,
  relationships,
  entitySummaryCache,
  fetchSummaryBatch,
} = {}) {
  if (!selections || typeof selections !== 'object' || !entitySummaryCache) return [];
  const requests = relationSelectionSummaryRequests(selections, relationships);
  if (requests.length === 0) return [];

  await entitySummaryCache.loadMissingSummaryRequests(requests, fetchSummaryBatch);
  const relations = relationMap(relationships);
  for (const [relationKey, rows] of Object.entries(selections)) {
    if (!Array.isArray(rows)) continue;
    const relation = relations.get(normalizeString(relationKey));
    selections[relationKey] = rows.map((row) => {
      const entityKey = relationEntityKey(relation, row);
      const summary = entitySummaryCache.getSummary(entityKey, rowSummaryId(row));
      if (!summary) return row;
      const relationshipsValue = row?.relationships || summary.relationships;
      return relationshipsValue ? { ...row, ...summary, relationships: relationshipsValue } : { ...row, ...summary };
    });
  }

  return requests;
}
