<template>
  <div class="governance-module-preview" :class="previewClass" role="img" :aria-label="ariaLabel">
    <img v-if="screenshotPath" class="module-shot-image" :src="screenshotPath" alt="" />
    <template v-else>
      <div class="module-shot-sidebar">
        <span></span>
        <span></span>
        <span></span>
      </div>
      <div class="module-shot-main">
        <header class="module-shot-header">
          <span></span>
          <i></i>
        </header>

        <section v-if="previewKind === 'calls'" class="module-shot-calls">
          <div class="module-shot-video"></div>
          <div class="module-shot-strip">
            <span></span>
            <span></span>
            <span></span>
          </div>
        </section>
        <section v-else-if="previewKind === 'calendar'" class="module-shot-calendar">
          <span v-for="day in 10" :key="day"></span>
        </section>
        <section v-else-if="previewKind === 'localization'" class="module-shot-translate">
          <span v-for="row in 8" :key="row"></span>
        </section>
        <section v-else-if="previewKind === 'theme_editor'" class="module-shot-swatches">
          <span v-for="swatch in 8" :key="swatch"></span>
        </section>
        <section v-else-if="previewKind === 'marketplace'" class="module-shot-marketplace">
          <span v-for="app in 4" :key="app"></span>
        </section>
        <section v-else-if="previewKind === 'administration'" class="module-shot-form">
          <span v-for="field in 5" :key="field"></span>
        </section>
        <section v-else class="module-shot-table">
          <span v-for="row in 5" :key="row"></span>
        </section>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { t } from '../../localization/i18nRuntime.js';

const props = defineProps({
  row: { type: Object, default: () => ({}) },
});

const KNOWN_PREVIEWS = new Set([
  'administration',
  'calendar',
  'calls',
  'governance',
  'localization',
  'marketplace',
  'theme_editor',
  'users',
  'workspace_settings',
]);

function normalizePreviewKind(value) {
  const normalized = String(value || '').trim().toLowerCase().replace(/[^a-z0-9_-]+/g, '_');
  return KNOWN_PREVIEWS.has(normalized) ? normalized : 'governance';
}

const previewKind = computed(() => normalizePreviewKind(props.row?.preview_kind || props.row?.key));
const previewClass = computed(() => `is-${previewKind.value}`);
const screenshotPath = computed(() => String(props.row?.screenshot_path || '').trim());
const ariaLabel = computed(() => `${props.row?.name || props.row?.key || t('governance.entity.module')} ${t('governance.screenshot')}`);
</script>

<style scoped>
.governance-module-preview {
  width: min(220px, 100%);
  aspect-ratio: 16 / 10;
  display: grid;
  grid-template-columns: 28px 1fr;
  overflow: hidden;
  border: 1px solid var(--color-border);
  background: var(--color-primary-navy);
}

.module-shot-image {
  grid-column: 1 / -1;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.module-shot-sidebar {
  display: grid;
  align-content: start;
  gap: 7px;
  padding: 10px 7px;
  background: var(--color-cyan-primary);
}

.module-shot-sidebar span,
.module-shot-header span,
.module-shot-header i,
.module-shot-table span,
.module-shot-form span,
.module-shot-marketplace span,
.module-shot-calendar span,
.module-shot-translate span,
.module-shot-swatches span,
.module-shot-strip span {
  display: block;
  background: var(--color-border);
}

.module-shot-sidebar span {
  width: 14px;
  height: 4px;
}

.module-shot-main {
  min-width: 0;
  display: grid;
  grid-template-rows: 26px 1fr;
  gap: 8px;
  padding: 9px;
  background: var(--color-surface-navy);
}

.module-shot-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.module-shot-header span {
  width: 48%;
  height: 8px;
  background: var(--color-heading);
}

.module-shot-header i {
  width: 18px;
  height: 18px;
  background: var(--color-cyan-primary);
}

.module-shot-table,
.module-shot-form,
.module-shot-translate {
  display: grid;
  gap: 6px;
}

.module-shot-table span,
.module-shot-form span,
.module-shot-translate span {
  height: 9px;
}

.module-shot-calls {
  min-height: 0;
  display: grid;
  grid-template-columns: minmax(0, 1fr) 32px;
  gap: 8px;
}

.module-shot-video {
  min-height: 0;
  background: var(--color-primary-navy);
  border: 1px solid var(--color-border);
}

.module-shot-strip {
  display: grid;
  gap: 5px;
}

.module-shot-strip span {
  background: var(--color-cyan-hover);
}

.module-shot-calendar {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 5px;
}

.module-shot-calendar span,
.module-shot-marketplace span,
.module-shot-swatches span {
  min-height: 0;
}

.module-shot-marketplace,
.module-shot-swatches {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 7px;
}

.module-shot-swatches span:nth-child(2n),
.is-theme_editor .module-shot-sidebar,
.is-calls .module-shot-header i,
.is-marketplace .module-shot-marketplace span:nth-child(3) {
  background: var(--color-cyan-hover);
}

.is-localization .module-shot-translate {
  grid-template-columns: 1fr 1fr;
}

.is-administration .module-shot-form span:nth-child(2n),
.is-users .module-shot-table span:nth-child(2n),
.is-workspace_settings .module-shot-table span:nth-child(2n) {
  background: var(--color-primary-navy);
}
</style>
