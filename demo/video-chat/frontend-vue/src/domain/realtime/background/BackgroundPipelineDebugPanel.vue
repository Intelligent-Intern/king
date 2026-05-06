<template>
  <div class="background-pipeline-debug-panel">
    <div class="background-pipeline-debug-head">
      <span class="background-pipeline-debug-title">{{ title }}</span>
      <button
        class="background-pipeline-debug-btn"
        type="button"
        @click="showCanvases = true"
      >
        Show masks
      </button>
    </div>
    <div class="background-pipeline-debug-presets" role="group" aria-label="Background presets">
      <button
        v-for="preset in presets"
        :key="preset.value"
        class="background-pipeline-debug-preset"
        :class="{ active: activePreset === preset.value }"
        type="button"
        :aria-pressed="activePreset === preset.value"
        @click="emit('selectPreset', preset.value)"
      >
        {{ preset.label }}
      </button>
    </div>
    <span class="background-pipeline-debug-meta">mode {{ normalizedDebug.mode }}</span>
    <span class="background-pipeline-debug-meta">source {{ normalizedDebug.sourceState }}</span>
    <span class="background-pipeline-debug-meta">backend {{ normalizedDebug.backend }}</span>
    <div class="background-pipeline-debug-stages">
      <span
        v-for="stage in normalizedDebug.stages"
        :key="stage.name"
        class="background-pipeline-debug-stage"
        :class="`state-${stage.state}`"
      >
        {{ stage.name }}: {{ stage.state }}
      </span>
    </div>
  </div>

  <Teleport to="body">
    <div
      v-if="showCanvases"
      id="backgroundPipelineDebugDialog"
      class="background-pipeline-debug-dialog"
      role="dialog"
      aria-label="Background pipeline masks"
    >
      <header class="background-pipeline-debug-dialog-head">
        <span class="background-pipeline-debug-title">Background masks</span>
        <button
          class="background-pipeline-debug-btn"
          type="button"
          @click="showCanvases = false"
        >
          Close
        </button>
      </header>
      <div class="background-pipeline-debug-canvases">
        <figure class="background-pipeline-debug-canvas-wrap">
          <figcaption>Mask</figcaption>
          <canvas id="maskDebug"></canvas>
        </figure>
        <figure class="background-pipeline-debug-canvas-wrap">
          <figcaption>Person</figcaption>
          <canvas id="personDebug"></canvas>
        </figure>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { computed, ref } from 'vue';

const emit = defineEmits(['selectPreset']);

const props = defineProps({
  activePreset: {
    type: String,
    default: '',
  },
  debugState: {
    type: Object,
    default: () => ({}),
  },
  title: {
    type: String,
    default: 'Local pipeline',
  },
});

const showCanvases = ref(false);
const activePreset = computed(() => String(props.activePreset || ''));
const presets = Object.freeze([
  { label: 'Off', value: 'off' },
  { label: 'Blur', value: 'light' },
  { label: 'Blur+', value: 'strong' },
  { label: 'Green', value: 'green' },
  { label: 'Books', value: 'image' },
]);

const normalizedDebug = computed(() => {
  const state = props.debugState || {};
  return {
    backend: String(state.backend || 'none'),
    mode: String(state.mode || 'off'),
    sourceState: String(state.sourceState || 'idle'),
    stages: Array.isArray(state.stages)
      ? state.stages.map((stage) => ({
          name: String(stage?.name || ''),
          state: String(stage?.state || 'idle'),
        }))
      : [],
  };
});
</script>

<style scoped>
.background-pipeline-debug-panel {
  display: grid;
  gap: 4px;
}

.background-pipeline-debug-head,
.background-pipeline-debug-dialog-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}

.background-pipeline-debug-title {
  font-size: 12px;
  font-weight: 800;
  color: var(--text-main);
}

.background-pipeline-debug-meta {
  font-size: 11px;
  color: var(--text-secondary);
}

.background-pipeline-debug-stages {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 4px;
}

.background-pipeline-debug-presets {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.background-pipeline-debug-preset {
  min-height: 28px;
  padding: 0 10px;
  border: 1px solid var(--border-subtle);
  border-radius: 999px;
  background: var(--bg-control);
  color: var(--text-main);
  font-size: 11px;
  font-weight: 800;
  cursor: pointer;
}

.background-pipeline-debug-preset:hover {
  background: var(--bg-tab-hover);
}

.background-pipeline-debug-preset.active {
  border-color: var(--brand-blue);
  background: var(--brand-blue);
  color: var(--color-white);
}

.background-pipeline-debug-stage {
  display: inline-flex;
  align-items: center;
  min-height: 22px;
  padding: 0 8px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.08);
  color: var(--text-secondary);
  font-size: 10px;
  font-weight: 700;
  text-transform: lowercase;
}

.background-pipeline-debug-stage.state-running {
  background: rgba(0, 196, 140, 0.2);
  color: var(--color-bdf6cf);
}

.background-pipeline-debug-stage.state-paused,
.background-pipeline-debug-stage.state-idle {
  background: rgba(255, 255, 255, 0.08);
  color: var(--text-muted);
}

.background-pipeline-debug-stage.state-failed,
.background-pipeline-debug-stage.state-stopped {
  background: rgba(224, 50, 50, 0.18);
  color: var(--color-ffd1dc);
}

.background-pipeline-debug-btn {
  min-height: 28px;
  padding: 0 10px;
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  background: var(--bg-control);
  color: var(--text-main);
  font-size: 11px;
  font-weight: 800;
  cursor: pointer;
}

.background-pipeline-debug-btn:hover {
  background: var(--bg-tab-hover);
}

.background-pipeline-debug-dialog {
  position: fixed;
  top: 14px;
  right: 14px;
  z-index: 9999;
  width: min(520px, calc(100vw - 28px));
  display: grid;
  gap: 10px;
  padding: 10px;
  border: 1px solid rgba(255, 255, 255, 0.12);
  border-radius: 8px;
  background: var(--color-rgba-8-20-43-0-94);
  box-shadow: 0 18px 40px var(--color-rgba-0-0-0-0-35);
  backdrop-filter: blur(12px);
}

.background-pipeline-debug-canvases {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}

.background-pipeline-debug-canvas-wrap {
  min-width: 0;
  margin: 0;
  display: grid;
  gap: 6px;
}

.background-pipeline-debug-canvas-wrap figcaption {
  color: var(--text-secondary);
  font-size: 11px;
  font-weight: 800;
}

.background-pipeline-debug-canvas-wrap canvas {
  width: 100%;
  aspect-ratio: 4 / 3;
  background: var(--color-0b1324);
  border: 1px solid rgba(255, 255, 255, 0.12);
  border-radius: 6px;
}

@media (max-width: 640px) {
  .background-pipeline-debug-dialog {
    left: 10px;
    right: 10px;
    top: 10px;
    width: auto;
  }

  .background-pipeline-debug-canvases {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
