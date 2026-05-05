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
</template>

<script setup>
import { computed, inject, toRef, useAttrs } from 'vue';
import { useRoute } from 'vue-router';
import { t } from '../modules/localization/i18nRuntime.js';
import { useOnboardingDriver } from '../modules/onboarding/useOnboardingDriver.js';

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
const { openTour, tourButtonLabel, tourDefinition } = useOnboardingDriver({
  route,
  title: toRef(props, 'title'),
});
</script>

<style scoped>
.app-page-header {
  width: 100%;
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
  flex-wrap: wrap;
  box-sizing: border-box;
  padding: 12px 10px;
  background: transparent;
}

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
</style>
