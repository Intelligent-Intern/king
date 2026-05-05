import { normalizeEntitySummary } from './entitySummaryCache.js';

function normalizeString(value) {
  return String(value || '').trim();
}

function effectiveEntityKey(requestedEntity, row, fallbackEntity) {
  const requested = normalizeString(requestedEntity);
  const sourceEntity = normalizeString(row?.entity_key);
  if (['subjects', 'resources'].includes(requested) && sourceEntity !== '') {
    return sourceEntity;
  }
  return requested || normalizeString(fallbackEntity);
}

function relationshipSnapshot(source = {}, fallbackEntity = '') {
  if (!source || typeof source !== 'object') return {};
  return Object.fromEntries(Object.entries(source)
    .filter(([, rows]) => Array.isArray(rows))
    .map(([key, rows]) => [key, rows.map((row) => relationRowSummary(row, row?.entity_key || '', fallbackEntity))]));
}

export function relationRowSummary(row, entityKey = '', fallbackEntity = '') {
  const summary = normalizeEntitySummary(effectiveEntityKey(entityKey, row, fallbackEntity), row);
  const relationships = relationshipSnapshot(row?.relationships, summary.entity_key);
  if (Object.keys(relationships).length > 0) {
    summary.relationships = relationships;
  }
  return summary;
}

export function relationSelectionSnapshot(relationSelections = {}, fallbackEntity = '') {
  return Object.fromEntries(Object.entries(relationSelections).map(([key, rows]) => [
    key,
    Array.isArray(rows) ? rows.map((row) => relationRowSummary(row, row?.entity_key || '', fallbackEntity)) : [],
  ]));
}
