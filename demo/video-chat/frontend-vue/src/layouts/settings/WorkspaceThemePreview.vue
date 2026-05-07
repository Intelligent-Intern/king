<template>
  <section class="theme-preview" :class="{ compact, interactive }" :aria-label="t('theme_preview.aria')">
    <iframe
      v-if="interactive && !compact"
      ref="frameRef"
      class="theme-preview-frame"
      :title="t('theme_preview.frame_title')"
      src="/theme-preview-sandbox"
      sandbox="allow-scripts allow-same-origin"
      @load="sendPreviewState"
    ></iframe>
    <WorkspaceThemePreviewApp
      v-else
      class="theme-preview-app-snapshot"
      :colors="colors"
      :sidebar-logo-src="sidebarLogoSrc"
      :compact="compact"
      :interactive="false"
    />
  </section>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { t } from '../../modules/localization/i18nRuntime.js';
import WorkspaceThemePreviewApp from './WorkspaceThemePreviewApp.vue';

const props = defineProps({
  colors: {
    type: Object,
    default: () => ({}),
  },
  sidebarLogoSrc: {
    type: String,
    required: true,
  },
  compact: {
    type: Boolean,
    default: false,
  },
  interactive: {
    type: Boolean,
    default: false,
  },
});

const frameRef = ref(null);

const normalizedColors = computed(() => {
  const source = props.colors && typeof props.colors === 'object' ? props.colors : {};
  return Object.fromEntries(
    Object.entries(source).filter(([key, value]) => key.startsWith('--') && typeof value === 'string'),
  );
});

function sendPreviewState() {
  const target = frameRef.value?.contentWindow;
  if (!target) return;
  target.postMessage({
    type: 'kingrt-theme-preview-state',
    colors: normalizedColors.value,
    sidebarLogoSrc: props.sidebarLogoSrc,
  }, window.location.origin);
}

function handlePreviewMessage(event) {
  if (event.origin !== window.location.origin) return;
  if (event.data?.type === 'kingrt-theme-preview-ready') {
    sendPreviewState();
  }
}

watch(
  () => [normalizedColors.value, props.sidebarLogoSrc],
  () => sendPreviewState(),
  { deep: true },
);

onMounted(() => {
  window.addEventListener('message', handlePreviewMessage);
  sendPreviewState();
});

onBeforeUnmount(() => {
  window.removeEventListener('message', handlePreviewMessage);
});
</script>

<style scoped>
.theme-preview {
  min-width: 0;
  min-height: 420px;
  display: grid;
  overflow: hidden;
  border: 1px solid var(--border-subtle);
  border-radius: 8px;
  background: var(--bg-shell);
}

.theme-preview-frame,
.theme-preview-app-snapshot {
  width: 100%;
  height: 100%;
  min-width: 0;
  min-height: 0;
  border: 0;
}

.theme-preview.compact {
  min-height: 270px;
}

.theme-preview.compact .theme-preview-app-snapshot {
  pointer-events: none;
}
</style>
