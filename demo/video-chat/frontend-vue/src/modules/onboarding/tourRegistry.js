import { routeTourAction, routeTourDefinition } from './tourRuntime.js';

function normalizeString(value) {
  return String(value || '').trim();
}

function routePath(route = {}) {
  const path = normalizeString(route.path);
  if (path === '') return '';
  return path.startsWith('/') ? path : `/${path}`;
}

function translatedLabel(entry, translate) {
  const labelKey = normalizeString(entry.label_key);
  if (labelKey !== '' && typeof translate === 'function') {
    const translated = normalizeString(translate(labelKey));
    if (translated !== '') return translated;
  }
  return normalizeString(entry.label) || normalizeString(entry.key);
}

function normalizeBadge(badge) {
  const source = badge && typeof badge === 'object' ? badge : {};
  const tourKey = normalizeString(source.tour_key || source.key).toLowerCase();
  if (tourKey === '') return null;
  return {
    tour_key: tourKey,
    completed_at: normalizeString(source.completed_at),
  };
}

export function buildOnboardingTourRegistry(routeRecords = [], context = {}) {
  return routeRecords
    .map((route) => {
      const action = routeTourAction(route, context);
      const definition = routeTourDefinition(route, action);
      if (!definition) return null;
      const meta = route?.meta && typeof route.meta === 'object' ? route.meta : {};
      return {
        key: definition.key,
        label: normalizeString(definition.title || meta.pageTitle || action?.label),
        label_key: normalizeString(definition.badge_key || definition.title_key || meta.pageTitle_key || action?.label_key),
        module_key: normalizeString(meta.module_key),
        path: routePath(route),
        resource_type: normalizeString(action?.resource_type),
      };
    })
    .filter(Boolean)
    .sort((a, b) => a.key.localeCompare(b.key));
}

export function completedTourBadgeRows(badges = [], registry = [], translate = null) {
  const toursByKey = new Map(registry.map((tour) => [normalizeString(tour.key).toLowerCase(), tour]));
  return badges
    .map(normalizeBadge)
    .filter(Boolean)
    .map((badge) => {
      const registryEntry = toursByKey.get(badge.tour_key) || {
        key: badge.tour_key,
        label: badge.tour_key,
        label_key: '',
        module_key: '',
        path: '',
        resource_type: '',
      };
      return {
        ...registryEntry,
        tour_key: badge.tour_key,
        completed_at: badge.completed_at,
        label: translatedLabel(registryEntry, translate),
      };
    })
    .sort((a, b) => a.label.localeCompare(b.label));
}
