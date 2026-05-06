import { firstRouteActionByKind, routeActionsForContext, routeActionLabel } from '../routeActions.js';

const DEFAULT_TOUR_STEPS = Object.freeze([
  {
    key: 'header',
    selector: '.app-page-header',
    title_key: 'onboarding.default.header_title',
    body_key: 'onboarding.default.header_body',
    side: 'bottom',
    align: 'start',
  },
  {
    key: 'actions',
    selector: '.app-page-header-actions',
    title_key: 'onboarding.default.actions_title',
    body_key: 'onboarding.default.actions_body',
    side: 'bottom',
    align: 'end',
  },
  {
    key: 'filters',
    selector: '.admin-page-frame-toolbar, .toolbar',
    title_key: 'onboarding.default.filters_title',
    body_key: 'onboarding.default.filters_body',
    side: 'bottom',
    align: 'start',
  },
  {
    key: 'content',
    selector: '.admin-table-frame, .table-wrap, .view-card',
    title_key: 'onboarding.default.content_title',
    body_key: 'onboarding.default.content_body',
    side: 'top',
    align: 'center',
  },
  {
    key: 'pagination',
    selector: '.admin-page-frame-footer, .pagination, .footer',
    title_key: 'onboarding.default.pagination_title',
    body_key: 'onboarding.default.pagination_body',
    side: 'top',
    align: 'center',
  },
]);

function normalizeString(value) {
  return typeof value === 'string' ? value.trim() : '';
}

function normalizeTourStep(step = {}, index = 0) {
  const source = step && typeof step === 'object' ? step : {};
  const key = normalizeString(source.key) || `step-${index + 1}`;
  return {
    key,
    selector: normalizeString(source.selector || source.element),
    title: normalizeString(source.title),
    title_key: normalizeString(source.title_key),
    body: normalizeString(source.body),
    body_key: normalizeString(source.body_key),
    side: normalizeString(source.side),
    align: normalizeString(source.align),
    disable_active_interaction: source.disable_active_interaction !== false,
  };
}

export function routeTourAction(routeOrMeta = {}, context = {}) {
  return firstRouteActionByKind(routeActionsForContext(routeOrMeta, context), 'tour');
}

export function routeTourDefinition(routeOrMeta = {}, action = null) {
  const meta = routeOrMeta?.meta && typeof routeOrMeta.meta === 'object' ? routeOrMeta.meta : routeOrMeta;
  const explicitTour = meta?.tour && typeof meta.tour === 'object' ? meta.tour : {};
  const tourKey = normalizeString(explicitTour.key || action?.key);
  if (tourKey === '') return null;

  const configuredSteps = Array.isArray(explicitTour.steps) && explicitTour.steps.length > 0
    ? explicitTour.steps
    : DEFAULT_TOUR_STEPS;

  return {
    key: tourKey,
    title: normalizeString(explicitTour.title || meta?.pageTitle),
    title_key: normalizeString(explicitTour.title_key || meta?.pageTitle_key),
    label: normalizeString(action?.label),
    label_key: normalizeString(action?.label_key),
    badge_key: normalizeString(explicitTour.badge_key || explicitTour.title_key || meta?.pageTitle_key),
    steps: configuredSteps.map(normalizeTourStep),
  };
}

export function tourDefinitionLabel(tourDefinition = null, translate, fallback = '') {
  if (!tourDefinition) return fallback;
  const titleKey = normalizeString(tourDefinition.title_key);
  if (titleKey !== '' && typeof translate === 'function') {
    return translate(titleKey);
  }
  const title = normalizeString(tourDefinition.title);
  if (title !== '') return title;
  return routeActionLabel(tourDefinition, translate, fallback);
}

export function tourStepTitle(step = {}, translate, fallback = '') {
  const titleKey = normalizeString(step.title_key);
  if (titleKey !== '' && typeof translate === 'function') return translate(titleKey);
  return normalizeString(step.title) || fallback;
}

export function tourStepBody(step = {}, translate) {
  const bodyKey = normalizeString(step.body_key);
  if (bodyKey !== '' && typeof translate === 'function') return translate(bodyKey);
  return normalizeString(step.body);
}

export function isTourCompleted(tourKey, completedTours = []) {
  const normalizedTourKey = normalizeString(tourKey).toLowerCase();
  return normalizedTourKey !== ''
    && Array.isArray(completedTours)
    && completedTours.map((key) => normalizeString(key).toLowerCase()).includes(normalizedTourKey);
}
