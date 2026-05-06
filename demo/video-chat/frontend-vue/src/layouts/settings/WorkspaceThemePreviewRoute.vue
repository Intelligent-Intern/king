<template>
  <WorkspaceThemePreviewApp
    class="theme-preview-route"
    :colors="colors"
    :sidebar-logo-src="sidebarLogoSrc"
    interactive
  />
</template>

<script setup>
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import WorkspaceThemePreviewApp from './WorkspaceThemePreviewApp.vue';

const DEFAULT_LOGO = '/assets/orgas/kingrt/logo.svg';

const colors = reactive({});
const sidebarLogoSrc = ref(DEFAULT_LOGO);

function applyPreviewState(payload) {
  if (!payload || typeof payload !== 'object') return;
  for (const key of Object.keys(colors)) {
    delete colors[key];
  }
  const nextColors = payload.colors && typeof payload.colors === 'object' ? payload.colors : {};
  for (const [key, value] of Object.entries(nextColors)) {
    if (key.startsWith('--') && typeof value === 'string') {
      colors[key] = value;
    }
  }
  const logo = String(payload.sidebarLogoSrc || '').trim();
  sidebarLogoSrc.value = logo || DEFAULT_LOGO;
}

function handleMessage(event) {
  if (event.origin !== window.location.origin) return;
  if (event.data?.type === 'kingrt-theme-preview-state') {
    applyPreviewState(event.data);
  }
}

function notifyReady() {
  window.parent?.postMessage({ type: 'kingrt-theme-preview-ready' }, window.location.origin);
}

onMounted(() => {
  window.addEventListener('message', handleMessage);
  notifyReady();
});

onBeforeUnmount(() => {
  window.removeEventListener('message', handleMessage);
});
</script>

<style scoped>
.theme-preview-route {
  width: 100vw;
  height: 100vh;
  min-height: 0;
}
</style>
