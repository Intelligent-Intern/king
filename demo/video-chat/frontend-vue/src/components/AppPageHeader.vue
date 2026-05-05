<template>
  <header v-bind="rootAttrs" :class="rootClass">
    <div class="app-page-header-main">
      <button
        v-if="showLeftSidebarRestoreButton"
        class="show-sidebar-overlay show-sidebar-inline show-left-sidebar-overlay app-page-header-sidebar-btn"
        type="button"
        :title="t('common.show_sidebar')"
        :aria-label="t('common.show_sidebar')"
        @click="showLeftSidebarFromHeader"
      >
        <img class="arrow-icon-image" src="/assets/orgas/kingrt/icons/forward.png" alt="" />
      </button>
      <slot name="before-title" />
      <div class="app-page-header-title">
        <h1>{{ title }}</h1>
        <p v-if="subtitle">{{ subtitle }}</p>
        <slot />
      </div>
    </div>
    <div v-if="$slots.actions || tourDefinition" class="actions app-page-header-actions">
      <slot name="actions" />
      <button
        v-if="tourDefinition"
        class="icon-mini-btn app-page-header-tour-btn"
        type="button"
        :title="tourButtonLabel"
        :aria-label="tourButtonLabel"
        @click="openTour"
      >
        <span aria-hidden="true">?</span>
      </button>
    </div>
  </header>

  <AppModalShell
    :open="tourState.open"
    root-class-name="governance-modal"
    backdrop-class="governance-modal-backdrop"
    dialog-class="governance-modal-dialog onboarding-tour-dialog"
    header-class="governance-modal-head governance-modal-head-brand"
    header-left-class="governance-modal-head-left"
    logo-class="governance-modal-head-logo"
    body-class="governance-modal-body onboarding-tour-body"
    footer-class="governance-modal-footer onboarding-tour-footer"
    :title="tourModalTitle"
    :subtitle="tourCompleted ? t('onboarding.completed_badge_earned') : ''"
    :close-label="t('common.close_modal')"
    @close="closeTour"
  >
    <template #body>
      <ol class="onboarding-tour-steps">
        <li v-for="(step, index) in tourSteps" :key="step.key" class="onboarding-tour-step">
          <span class="onboarding-tour-step-index">{{ index + 1 }}</span>
          <div class="onboarding-tour-step-copy">
            <strong>{{ tourStepTitle(step, t, t('onboarding.step', { number: index + 1 })) }}</strong>
            <p>{{ tourStepBody(step, t) }}</p>
          </div>
        </li>
      </ol>
      <p v-if="tourState.message" class="onboarding-tour-message" :class="{ error: tourState.error }">
        {{ tourState.message }}
      </p>
    </template>

    <template #footer>
      <button class="btn" type="button" :disabled="tourState.saving" @click="closeTour">
        {{ t('common.close') }}
      </button>
      <button
        class="btn btn-cyan"
        type="button"
        :disabled="tourState.saving || tourCompleted"
        @click="completeTour"
      >
        {{ tourCompleted ? t('onboarding.completed') : (tourState.saving ? t('settings.saving') : t('onboarding.complete_tour')) }}
      </button>
    </template>
  </AppModalShell>
</template>

<script setup>
import { computed, inject, reactive, useAttrs } from 'vue';
import { useRoute } from 'vue-router';
import AppModalShell from './AppModalShell.vue';
import { completeOnboardingTour, sessionState } from '../domain/auth/session';
import { moduleAccessContextFromSession } from '../http/routeAccess.js';
import { t } from '../modules/localization/i18nRuntime.js';
import {
  isTourCompleted,
  routeTourAction,
  routeTourDefinition,
  tourDefinitionLabel,
  tourStepBody,
  tourStepTitle,
} from '../modules/onboarding/tourRuntime.js';

defineOptions({ inheritAttrs: false });

const props = defineProps({
  title: {
    type: String,
    required: true,
  },
  subtitle: {
    type: String,
    default: '',
  },
});

const attrs = useAttrs();
const route = useRoute();
const workspaceSidebarState = inject('workspaceSidebarState', null);
const tourState = reactive({
  open: false,
  saving: false,
  message: '',
  error: false,
});

function refBoolean(candidate) {
  if (candidate && typeof candidate === 'object' && 'value' in candidate) {
    return Boolean(candidate.value);
  }

  return Boolean(candidate);
}

const showLeftSidebarRestoreButton = computed(() => (
  refBoolean(workspaceSidebarState?.leftSidebarCollapsed)
  && !refBoolean(workspaceSidebarState?.isTabletViewport)
  && !refBoolean(workspaceSidebarState?.isMobileViewport)
  && typeof workspaceSidebarState?.showLeftSidebar === 'function'
));

function showLeftSidebarFromHeader() {
  if (typeof workspaceSidebarState?.showLeftSidebar === 'function') {
    workspaceSidebarState.showLeftSidebar();
  }
}

const rootAttrs = computed(() => {
  const { class: _class, ...rest } = attrs;
  return rest;
});
const rootClass = computed(() => ['app-page-header', attrs.class]);
const routeActionContext = computed(() => moduleAccessContextFromSession(sessionState));
const tourAction = computed(() => routeTourAction(route, routeActionContext.value));
const tourDefinition = computed(() => routeTourDefinition(route, tourAction.value));
const tourSteps = computed(() => tourDefinition.value?.steps || []);
const tourButtonLabel = computed(() => t('onboarding.take_the_tour'));
const tourModalTitle = computed(() => tourDefinitionLabel(tourDefinition.value, t, props.title));
const tourCompleted = computed(() => (
  isTourCompleted(tourDefinition.value?.key || '', sessionState.onboardingCompletedTours)
));

function openTour() {
  if (!tourDefinition.value) return;
  tourState.open = true;
  tourState.message = '';
  tourState.error = false;
}

function closeTour() {
  if (tourState.saving) return;
  tourState.open = false;
  tourState.message = '';
  tourState.error = false;
}

async function completeTour() {
  const tourKey = tourDefinition.value?.key || '';
  if (tourKey === '' || tourState.saving || tourCompleted.value) return;
  tourState.saving = true;
  tourState.message = '';
  tourState.error = false;
  try {
    const result = await completeOnboardingTour(tourKey);
    tourState.error = !result.ok;
    tourState.message = result.ok
      ? t('onboarding.completed_badge_earned')
      : (result.message || t('onboarding.complete_failed'));
  } finally {
    tourState.saving = false;
  }
}
</script>

<style scoped>
.app-page-header-main {
  flex: 1 1 auto;
  min-width: 0;
  display: inline-flex;
  align-items: flex-start;
  gap: 10px;
}

.app-page-header-title {
  min-width: 0;
}

.app-page-header-sidebar-btn {
  margin-top: 2px;
}

.app-page-header h1 {
  margin: 0;
  font-size: 22px;
  font-weight: 700;
}

.app-page-header p {
  margin: 4px 0 0;
  color: var(--text-muted);
}

.app-page-header-actions {
  flex: 0 0 auto;
  min-height: 40px;
  margin-inline-start: auto;
  align-items: center;
  justify-content: flex-end;
}

.app-page-header-tour-btn {
  width: 40px;
  height: 40px;
}

.app-page-header-tour-btn span {
  display: inline-grid;
  place-items: center;
  width: 100%;
  height: 100%;
  font-weight: 700;
}

:global(.onboarding-tour-dialog) {
  width: min(720px, calc(100vw - 24px));
}

:global(.onboarding-tour-body) {
  display: grid;
  gap: 14px;
}

.onboarding-tour-steps {
  display: grid;
  gap: 12px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.onboarding-tour-step {
  display: grid;
  grid-template-columns: 34px minmax(0, 1fr);
  gap: 10px;
  align-items: start;
}

.onboarding-tour-step-index {
  display: inline-grid;
  place-items: center;
  width: 30px;
  height: 30px;
  border-radius: 999px;
  background: var(--accent);
  color: var(--bg);
  font-weight: 700;
}

.onboarding-tour-step-copy {
  min-width: 0;
}

.onboarding-tour-step-copy strong,
.onboarding-tour-step-copy p {
  overflow-wrap: anywhere;
}

.onboarding-tour-step-copy p {
  margin: 4px 0 0;
  color: var(--text-muted);
}

.onboarding-tour-message {
  margin: 0;
  color: var(--accent);
}

.onboarding-tour-message.error {
  color: var(--color-ffb5b5);
}

:global(.onboarding-tour-footer) {
  justify-content: flex-end;
}
</style>
