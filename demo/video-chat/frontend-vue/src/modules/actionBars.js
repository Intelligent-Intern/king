import { entryAllowsAccess } from './navigationBuilder.js';

export const DEFAULT_PAGE_ACTION_KINDS = Object.freeze(['create', 'import', 'export']);

function normalizeKind(kind = '') {
  return String(kind || '').trim();
}

function descriptorAllowedKinds(descriptor = {}) {
  return new Set(Array.isArray(descriptor?.allowed_actions)
    ? descriptor.allowed_actions.map(normalizeKind).filter(Boolean)
    : []);
}

export function descriptorActionsForContext(actions = [], context = {}) {
  return (Array.isArray(actions) ? actions : []).filter((action) => (
    entryAllowsAccess(action, context, action?.required_permissions)
  ));
}

export function descriptorPageActions(descriptor = {}, actions = [], pageKinds = DEFAULT_PAGE_ACTION_KINDS) {
  const allowedKinds = descriptorAllowedKinds(descriptor);
  const pageKindSet = new Set((Array.isArray(pageKinds) ? pageKinds : []).map(normalizeKind).filter(Boolean));
  return (Array.isArray(actions) ? actions : []).filter((action) => {
    const kind = normalizeKind(action?.kind);
    return pageKindSet.has(kind) && allowedKinds.has(kind);
  });
}

export function firstActionByKind(actions = [], kind = '') {
  const normalizedKind = normalizeKind(kind);
  if (normalizedKind === '') return null;
  return (Array.isArray(actions) ? actions : []).find((action) => action?.kind === normalizedKind) || null;
}

export function descriptorSubmitActionForMode(mode = '', pageActions = [], formActions = []) {
  const normalizedMode = normalizeKind(mode);
  if (normalizedMode === 'edit') return firstActionByKind(formActions, 'save');
  return firstActionByKind(pageActions, normalizedMode);
}

export function actionBarLabel(action = null, translate, fallback = '', params = {}) {
  const key = String(action?.label_key || '').trim();
  if (key !== '' && typeof translate === 'function') {
    return translate(key, params);
  }
  const label = String(action?.label || '').trim();
  return label !== '' ? label : fallback;
}
