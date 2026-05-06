import { entryAllowsAccess } from './navigationBuilder.js';

function routeMeta(routeOrMeta = {}) {
  return routeOrMeta?.meta && typeof routeOrMeta.meta === 'object' ? routeOrMeta.meta : routeOrMeta;
}

export function routeActions(routeOrMeta = {}) {
  const meta = routeMeta(routeOrMeta);
  return Array.isArray(meta?.actions) ? meta.actions : [];
}

export function routeActionsForContext(routeOrMeta = {}, context = {}) {
  return routeActions(routeOrMeta).filter((action) => (
    entryAllowsAccess(action, context, action.required_permissions)
  ));
}

export function firstRouteActionByKind(actions = [], kind = '') {
  const normalizedKind = String(kind || '').trim();
  if (normalizedKind === '') return null;
  return actions.find((action) => action.kind === normalizedKind) || null;
}

export function routeActionLabel(action = null, translate, fallback = '') {
  const key = String(action?.label_key || '').trim();
  if (key !== '' && typeof translate === 'function') {
    return translate(key);
  }
  const label = String(action?.label || '').trim();
  return label !== '' ? label : fallback;
}
