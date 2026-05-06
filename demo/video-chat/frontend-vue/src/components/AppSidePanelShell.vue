<template>
  <div
    v-bind="rootAttrs"
    :class="rootClass"
    :hidden="!open"
    role="dialog"
    aria-modal="true"
    :aria-labelledby="titleId || undefined"
    :aria-label="!titleId ? ariaLabel || title : undefined"
  >
    <div :class="panelBackdropClass" @click="$emit('close')"></div>
    <aside :class="panelDialogClass">
      <header :class="panelHeaderClass">
        <div :class="panelHeaderLeftClass">
          <slot name="header-prefix">
            <img v-if="showLogo" :class="panelLogoClass" :src="effectiveLogoSrc" alt="" />
          </slot>
          <div class="app-side-panel-title-block">
            <slot name="title">
              <h4 :id="titleId || undefined" :class="titleClass">{{ title }}</h4>
              <p v-if="subtitle" :class="subtitleClass">{{ subtitle }}</p>
            </slot>
          </div>
        </div>
        <slot name="close">
          <div class="app-side-panel-header-actions">
            <AppIconButton
              v-if="maximizable"
              :icon="maximized ? restoreIcon : maximizeIcon"
              :aria-label="maximized ? effectiveRestoreLabel : effectiveMaximizeLabel"
              :title="maximized ? effectiveRestoreLabel : effectiveMaximizeLabel"
              @click="toggleMaximized"
            />
            <AppIconButton
              :icon="closeIcon"
              :aria-label="effectiveCloseLabel"
              @click="$emit('close')"
            />
          </div>
        </slot>
      </header>

      <div :class="panelBodyClass">
        <slot name="body" />
      </div>
      <slot name="after-body" />
      <footer v-if="$slots.footer" :class="panelFooterClass">
        <slot name="footer" />
      </footer>
    </aside>
  </div>
</template>

<script setup>
import { computed, useAttrs } from 'vue';
import AppIconButton from './AppIconButton.vue';
import { appearanceState } from '../domain/workspace/appearance';
import { t } from '../modules/localization/i18nRuntime.js';

defineOptions({ inheritAttrs: false });

const props = defineProps({
  open: {
    type: Boolean,
    default: false,
  },
  title: {
    type: String,
    default: '',
  },
  subtitle: {
    type: String,
    default: '',
  },
  ariaLabel: {
    type: String,
    default: '',
  },
  titleId: {
    type: String,
    default: '',
  },
  rootClassName: {
    type: String,
    default: '',
  },
  backdropClass: {
    type: String,
    default: '',
  },
  dialogClass: {
    type: String,
    default: '',
  },
  headerClass: {
    type: String,
    default: '',
  },
  headerLeftClass: {
    type: String,
    default: '',
  },
  logoClass: {
    type: String,
    default: 'app-side-panel-logo',
  },
  titleClass: {
    type: String,
    default: '',
  },
  subtitleClass: {
    type: String,
    default: '',
  },
  bodyClass: {
    type: String,
    default: '',
  },
  footerClass: {
    type: String,
    default: '',
  },
  logoSrc: {
    type: String,
    default: '',
  },
  closeIcon: {
    type: String,
    default: '/assets/orgas/kingrt/icons/cancel.png',
  },
  closeLabel: {
    type: String,
    default: '',
  },
  showLogo: {
    type: Boolean,
    default: true,
  },
  maximizable: {
    type: Boolean,
    default: false,
  },
  maximized: {
    type: Boolean,
    default: false,
  },
  maximizeIcon: {
    type: String,
    default: '/assets/orgas/kingrt/icons/forward.png',
  },
  restoreIcon: {
    type: String,
    default: '/assets/orgas/kingrt/icons/backward.png',
  },
  maximizeLabel: {
    type: String,
    default: '',
  },
  restoreLabel: {
    type: String,
    default: '',
  },
});

const emit = defineEmits(['close', 'update:maximized']);

const attrs = useAttrs();
const effectiveLogoSrc = computed(() => {
  const configured = String(props.logoSrc || '').trim();
  return configured !== '' ? configured : appearanceState.modalLogoPath;
});
const rootAttrs = computed(() => {
  const { class: _class, ...rest } = attrs;
  return rest;
});
const rootClass = computed(() => [
  'app-side-panel',
  props.rootClassName,
  attrs.class,
  { 'is-side-panel-maximized': props.maximized },
]);
const panelBackdropClass = computed(() => ['app-side-panel-backdrop', props.backdropClass]);
const panelDialogClass = computed(() => [
  'app-side-panel-dialog',
  props.dialogClass,
  { 'is-maximized': props.maximized },
]);
const panelHeaderClass = computed(() => ['app-side-panel-head', props.headerClass]);
const panelHeaderLeftClass = computed(() => ['app-side-panel-head-left', props.headerLeftClass]);
const panelLogoClass = computed(() => ['app-side-panel-logo', props.logoClass]);
const panelBodyClass = computed(() => ['app-side-panel-body', props.bodyClass]);
const panelFooterClass = computed(() => ['app-side-panel-footer', props.footerClass]);
const effectiveCloseLabel = computed(() => props.closeLabel || t('common.close_panel'));
const effectiveMaximizeLabel = computed(() => props.maximizeLabel || t('common.maximize_panel'));
const effectiveRestoreLabel = computed(() => props.restoreLabel || t('common.restore_panel_size'));

function toggleMaximized() {
  emit('update:maximized', !props.maximized);
}
</script>

<style scoped>
.app-side-panel {
  position: fixed;
  inset: 0;
  z-index: 70;
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: stretch;
  justify-items: end;
}

.app-side-panel[hidden] {
  display: none;
}

.app-side-panel-backdrop {
  position: absolute;
  inset: 0;
  background: color-mix(in srgb, var(--color-primary-navy) 72%, transparent);
}

.app-side-panel-dialog {
  --app-side-panel-padding: 16px;
  position: relative;
  z-index: 1;
  width: min(620px, 100vw);
  height: 100vh;
  margin: 0;
  display: grid;
  grid-template-rows: auto minmax(0, 1fr) auto;
  gap: 14px;
  overflow: hidden;
  border: 0;
  border-left: 1px solid var(--border-subtle);
  border-radius: 0;
  background: var(--bg-surface);
  box-shadow: -18px 0 42px color-mix(in srgb, var(--color-primary-navy) 42%, transparent);
  padding: var(--app-side-panel-padding);
}

.app-side-panel-dialog.is-maximized {
  width: 100vw;
  height: 100vh;
  margin: 0;
  border-left: 1px solid var(--border-subtle);
  border-radius: 0;
}

.app-side-panel-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin: calc(var(--app-side-panel-padding) * -1) calc(var(--app-side-panel-padding) * -1) 0;
  padding: 10px;
  background: var(--brand-bg);
}

.app-side-panel-head h4 {
  margin: 5px 0 0;
}

.app-side-panel-head p {
  margin: 4px 0 0;
  color: var(--text-muted);
}

.app-side-panel-head-left {
  min-width: 0;
  display: inline-flex;
  align-items: center;
  gap: 10px;
}

.app-side-panel-title-block {
  min-width: 0;
}

.app-side-panel-logo {
  width: auto;
  height: 24px;
  display: block;
  flex: 0 0 auto;
}

.app-side-panel-header-actions {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  flex: 0 0 auto;
}

.app-side-panel-body {
  min-height: 0;
  display: grid;
  gap: 12px;
  overflow: auto;
}

.users-side-panel-body,
.marketplace-side-panel-body {
  grid-template-columns: minmax(0, 1fr);
}

.governance-side-panel-body-relation,
.users-side-panel-body-relation {
  align-content: stretch;
}

.app-side-panel-dialog.is-maximized .users-side-panel-body,
.app-side-panel-dialog.is-maximized .marketplace-side-panel-body {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.app-side-panel-footer {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}

@media (max-width: 760px) {
  .app-side-panel-dialog {
    width: 100vw;
    height: 100vh;
    border-left: 0;
    border-radius: 0;
  }

  .app-side-panel-dialog.is-maximized .users-side-panel-body,
  .app-side-panel-dialog.is-maximized .marketplace-side-panel-body {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
