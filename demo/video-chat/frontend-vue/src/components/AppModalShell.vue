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
    <div :class="backdropClass" @click="$emit('close')"></div>
    <div :class="modalDialogClass">
      <header :class="headerClass">
        <div :class="headerLeftClass">
          <slot name="header-prefix">
            <img v-if="showLogo" :class="logoClass" :src="effectiveLogoSrc" alt="" />
          </slot>
          <div class="app-modal-title-block">
            <slot name="title">
              <h4 :id="titleId || undefined" :class="titleClass">{{ title }}</h4>
              <p v-if="subtitle" :class="subtitleClass">{{ subtitle }}</p>
            </slot>
          </div>
        </div>
        <slot name="close">
          <div class="app-modal-header-actions">
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

      <div :class="bodyClass">
        <slot name="body" />
      </div>
      <slot name="after-body" />
      <footer v-if="$slots.footer" :class="footerClass">
        <slot name="footer" />
      </footer>
    </div>
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
    default: 'calls-modal',
  },
  backdropClass: {
    type: String,
    default: 'calls-modal-backdrop',
  },
  dialogClass: {
    type: String,
    default: 'calls-modal-dialog',
  },
  headerClass: {
    type: String,
    default: 'calls-modal-header calls-modal-header-enter',
  },
  headerLeftClass: {
    type: String,
    default: 'calls-modal-header-enter-left',
  },
  logoClass: {
    type: String,
    default: 'calls-modal-header-enter-logo',
  },
  titleClass: {
    type: String,
    default: 'calls-enter-title',
  },
  subtitleClass: {
    type: String,
    default: '',
  },
  bodyClass: {
    type: String,
    default: 'calls-modal-body',
  },
  footerClass: {
    type: String,
    default: 'calls-modal-footer',
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
const rootClass = computed(() => [props.rootClassName, attrs.class]);
const modalDialogClass = computed(() => [
  'app-modal-dialog',
  props.dialogClass,
  { 'is-maximized': props.maximized },
]);
const effectiveCloseLabel = computed(() => props.closeLabel || t('common.close_modal'));
const effectiveMaximizeLabel = computed(() => props.maximizeLabel || t('common.maximize_modal'));
const effectiveRestoreLabel = computed(() => props.restoreLabel || t('common.restore_modal_size'));

function toggleMaximized() {
  emit('update:maximized', !props.maximized);
}
</script>

<style scoped>
.calls-modal,
.users-modal,
.governance-modal {
  position: fixed;
  inset: 0;
  display: grid;
  place-items: center;
}

.calls-modal,
.governance-modal {
  z-index: 70;
  padding: 12px;
}

.users-modal {
  z-index: 30;
}

.calls-modal[hidden],
.users-modal[hidden],
.governance-modal[hidden] {
  display: none;
}

.calls-modal-backdrop,
.users-modal-backdrop,
.governance-modal-backdrop {
  position: absolute;
  inset: 0;
  background: var(--color-rgba-5-12-23-0-72);
}

.app-modal-dialog {
  position: relative;
  z-index: 1;
  display: grid;
}

.app-modal-dialog.is-maximized {
  width: min(1280px, calc(100vw - 24px));
  height: min(94vh, 980px);
  max-height: calc(100vh - 24px);
}

.app-modal-header-actions {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  flex: 0 0 auto;
}

.calls-modal-dialog {
  --calls-enter-dialog-padding: 12px;
  gap: 12px;
  border: 1px solid var(--border-subtle);
  border-radius: 8px;
  background: var(--bg-surface-strong);
  box-shadow: 0 6px 14px var(--color-rgba-0-0-0-0-28);
  padding: var(--calls-enter-dialog-padding);
}

.users-modal-dialog {
  --users-modal-padding: 16px;
  width: min(980px, calc(100vw - 24px));
  max-height: min(94vh, 980px);
  overflow: auto;
  gap: 14px;
  border: 1px solid var(--border-subtle);
  border-radius: 10px;
  background: var(--color-10203b);
  padding: var(--users-modal-padding);
}

.governance-modal-dialog {
  --governance-modal-padding: 16px;
  width: min(980px, calc(100vw - 24px));
  max-height: min(94vh, 980px);
  overflow: auto;
  gap: 14px;
  border: 1px solid var(--border-subtle);
  border-radius: 10px;
  background: var(--color-10203b);
  padding: var(--governance-modal-padding);
}

.users-modal-dialog.is-maximized {
  width: min(1280px, calc(100vw - 24px));
  height: calc(100vh - 24px);
  max-height: calc(100vh - 24px);
}

.governance-modal-dialog.is-maximized {
  width: min(1280px, calc(100vw - 24px));
  height: calc(100vh - 24px);
  max-height: calc(100vh - 24px);
}

.calls-modal-header,
.users-modal-head,
.governance-modal-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  min-width: 0;
}

.calls-modal-header {
  gap: 10px;
}

.users-modal-head,
.governance-modal-head {
  gap: 12px;
}

.calls-modal-header h4,
.users-modal-head h4,
.governance-modal-head h4 {
  margin: 5px 0 0;
}

.calls-modal-header h4 {
  font-size: 17px;
}

.calls-modal-header .calls-enter-title {
  margin: 8px 0 0;
  font-size: 14px;
  line-height: 1;
}

.calls-modal-header-enter {
  margin: calc(var(--calls-enter-dialog-padding) * -1) calc(var(--calls-enter-dialog-padding) * -1) 0;
  padding: 10px;
  border: 0;
  background: var(--brand-bg);
}

.users-modal-head-brand,
.governance-modal-head-brand {
  margin: calc(var(--users-modal-padding) * -1) calc(var(--users-modal-padding) * -1) 0;
  padding: 10px;
  background: var(--brand-bg);
}

.governance-modal-head-brand {
  margin: calc(var(--governance-modal-padding) * -1) calc(var(--governance-modal-padding) * -1) 0;
}

.calls-modal-header-enter-left,
.users-modal-head-left,
.governance-modal-head-left {
  min-width: 0;
  display: inline-flex;
  align-items: center;
  gap: 10px;
}

.calls-modal-header-enter-left > div,
.app-modal-title-block {
  min-width: 0;
}

.calls-modal-header-enter-logo,
.users-modal-head-logo,
.governance-modal-head-logo {
  width: auto;
  height: 24px;
  display: block;
  flex: 0 0 auto;
}

.calls-modal-body,
.users-modal-body,
.users-avatar-modal-body,
.governance-modal-body {
  display: grid;
}

.calls-modal-body {
  gap: 10px;
}

.users-modal-body {
  gap: 12px;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.users-avatar-modal-body {
  gap: 12px;
}

.calls-modal-footer,
.users-modal-footer,
.governance-modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}
</style>
