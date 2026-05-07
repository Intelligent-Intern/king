import { computed, onBeforeUnmount, watch } from 'vue';
import { driver } from 'driver.js';
import 'driver.js/dist/driver.css';
import './onboardingDriver.css';
import { completeOnboardingTour, sessionState } from '../../domain/auth/session';
import { moduleAccessContextFromSession } from '../../http/routeAccess.js';
import { t } from '../localization/i18nRuntime.js';
import {
  isTourCompleted,
  routeTourAction,
  routeTourDefinition,
  tourDefinitionLabel,
  tourStepBody,
  tourStepTitle,
} from './tourRuntime.js';

function normalizeSelector(value) {
  return String(value || '').trim();
}

function routeContext(route) {
  return route && typeof route === 'object' && 'value' in route ? route.value : route;
}

function titleValue(title) {
  return title && typeof title === 'object' && 'value' in title ? title.value : title;
}

function firstMatchingElement(selector) {
  const normalized = normalizeSelector(selector);
  if (normalized === '' || typeof document === 'undefined') return null;
  try {
    return document.querySelector(normalized);
  } catch {
    return null;
  }
}

function fallbackElement() {
  if (typeof document === 'undefined') return null;
  return (
    document.querySelector('.workspace')
    || document.querySelector('.main')
    || document.body
  );
}

function stepElement(step) {
  return () => firstMatchingElement(step.selector) || fallbackElement();
}

const FALLBACK_STEP = Object.freeze({
  key: 'overview',
  selector: '',
  title_key: 'onboarding.default.overview_title',
  body_key: 'onboarding.default.overview_body',
  side: 'bottom',
  align: 'center',
});

function stepHasTarget(step) {
  const selector = normalizeSelector(step.selector);
  return selector === '' || Boolean(firstMatchingElement(selector));
}

function availableTourSteps(steps = []) {
  const visibleSteps = steps.filter(stepHasTarget);
  return visibleSteps.length > 0 ? visibleSteps : [FALLBACK_STEP];
}

function translatedDriverStep(step, index) {
  return {
    element: stepElement(step),
    disableActiveInteraction: step.disable_active_interaction !== false,
    popover: {
      title: tourStepTitle(step, t, t('onboarding.step', { number: index + 1 })),
      description: tourStepBody(step, t),
      side: step.side || 'bottom',
      align: step.align || 'start',
    },
  };
}

export function useOnboardingDriver(options = {}) {
  const route = options.route;
  const title = options.title;
  let tourDriver = null;
  let completionPending = false;

  const routeActionContext = computed(() => moduleAccessContextFromSession(sessionState));
  const tourAction = computed(() => routeTourAction(routeContext(route), routeActionContext.value));
  const tourDefinition = computed(() => routeTourDefinition(routeContext(route), tourAction.value));
  const tourButtonLabel = computed(() => t('onboarding.take_the_tour'));
  const tourCompleted = computed(() => (
    isTourCompleted(tourDefinition.value?.key || '', sessionState.onboardingCompletedTours)
  ));
  const tourModalTitle = computed(() => (
    tourDefinitionLabel(tourDefinition.value, t, titleValue(title) || '')
  ));

  async function finishTour(activeDriver) {
    const tourKey = tourDefinition.value?.key || '';
    if (tourKey === '' || completionPending) return;
    completionPending = true;
    try {
      await completeOnboardingTour(tourKey);
    } finally {
      completionPending = false;
      if (activeDriver?.isActive?.()) {
        activeDriver.destroy();
      }
    }
  }

  function openTour() {
    if (!tourDefinition.value || typeof document === 'undefined') return;
    if (tourDriver?.isActive?.()) tourDriver.destroy();

    tourDriver = driver({
      animate: false,
      allowClose: true,
      allowKeyboardControl: true,
      disableActiveInteraction: true,
      doneBtnText: t('onboarding.complete_tour'),
      nextBtnText: t('common.next'),
      overlayColor: '#000010',
      overlayOpacity: 0.72,
      popoverClass: 'kingrt-tour-popover',
      popoverOffset: 12,
      prevBtnText: t('common.previous'),
      progressText: '{{current}} / {{total}}',
      showButtons: ['next', 'previous', 'close'],
      showProgress: true,
      stagePadding: 8,
      stageRadius: 8,
      steps: availableTourSteps(tourDefinition.value.steps).map(translatedDriverStep),
      onDestroyed: () => {
        tourDriver = null;
        completionPending = false;
      },
      onNextClick: (_element, _step, { driver: activeDriver }) => {
        if (activeDriver.isLastStep()) {
          void finishTour(activeDriver);
          return;
        }
        activeDriver.moveNext();
      },
    });

    tourDriver.drive();
  }

  watch(() => routeContext(route)?.fullPath, () => {
    if (tourDriver?.isActive?.()) tourDriver.destroy();
  });

  onBeforeUnmount(() => {
    if (tourDriver?.isActive?.()) tourDriver.destroy();
  });

  return {
    openTour,
    tourButtonLabel,
    tourCompleted,
    tourDefinition,
    tourModalTitle,
  };
}
