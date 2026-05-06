<template>
  <div class="calls-modal background-upload-modal" :hidden="!open" role="dialog" aria-modal="true" :aria-label="t('administration.background_crop_dialog')">
    <div class="calls-modal-backdrop" @click="emitClose"></div>
    <section class="calls-modal-dialog background-upload-dialog">
      <header class="calls-modal-header calls-modal-header-enter">
        <div class="calls-modal-header-enter-left">
          <h4 class="calls-enter-title">{{ t('administration.background_crop_title') }}</h4>
          <p class="background-upload-progress">{{ progressLabel }}</p>
        </div>
        <button class="icon-mini-btn" type="button" :aria-label="t('common.close')" :disabled="uploading" @click="emitClose">
          <img src="/assets/orgas/kingrt/icons/cancel.png" alt="" />
        </button>
      </header>

      <section class="calls-modal-body background-upload-body">
        <section
          ref="stageRef"
          class="background-crop-stage"
          :class="{ dragging: drag.active }"
          @pointerdown="startDrag"
          @pointermove="moveDrag"
          @pointerup="endDrag"
          @pointercancel="endDrag"
          @wheel.prevent="zoomWithWheel"
        >
          <img
            v-if="state.imageUrl"
            class="background-crop-image"
            :src="state.imageUrl"
            :style="imageStyle"
            alt=""
            @dragstart.prevent
          />
        </section>

        <section class="background-crop-controls">
          <label class="settings-field">
            <span>{{ t('administration.background_image_label') }}</span>
            <input v-model.trim="state.label" class="input" type="text" maxlength="120" />
          </label>

          <label class="settings-field">
            <span>{{ t('administration.background_crop_zoom') }}</span>
            <input
              v-model.number="state.zoom"
              class="call-left-range"
              type="range"
              min="1"
              max="3"
              step="0.01"
              @input="clampCrop"
            />
          </label>

          <section class="background-filter-grid" :aria-label="t('administration.background_crop_filters')">
            <button
              v-for="filter in filterOptions"
              :key="filter.key"
              class="background-filter-btn"
              :class="{ active: state.filterKey === filter.key }"
              type="button"
              :aria-pressed="state.filterKey === filter.key"
              @click="state.filterKey = filter.key"
            >
              {{ filter.label }}
            </button>
          </section>
        </section>
      </section>

      <footer class="calls-modal-footer background-upload-footer">
        <button class="btn" type="button" :disabled="uploading" @click="emitClose">{{ t('common.cancel') }}</button>
        <button class="btn btn-cyan" type="button" :disabled="uploading || !state.ready" @click="saveCurrent">
          {{ saveLabel }}
        </button>
      </footer>
    </section>
  </div>
</template>

<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue';
import { t } from '../../localization/i18nRuntime.js';

const OUTPUT_WIDTH = 1600;
const OUTPUT_HEIGHT = 900;
const JPEG_QUALITY = 0.88;
const MIN_ZOOM = 1;
const MAX_ZOOM = 3;

const filterOptions = Object.freeze([
  { key: 'none', label: 'Clean', css: 'none' },
  { key: 'warm', label: 'Warm', css: 'saturate(1.08) sepia(0.12) brightness(1.04)' },
  { key: 'cool', label: 'Cool', css: 'saturate(1.05) hue-rotate(188deg) brightness(1.02)' },
  { key: 'vivid', label: 'Vivid', css: 'saturate(1.28) contrast(1.12)' },
  { key: 'soft', label: 'Soft', css: 'saturate(0.94) contrast(0.92) brightness(1.08)' },
  { key: 'mono', label: 'Mono', css: 'grayscale(1) contrast(1.08)' },
  { key: 'sepia', label: 'Sepia', css: 'sepia(0.55) saturate(1.08)' },
  { key: 'bright', label: 'Bright', css: 'brightness(1.15) contrast(1.05)' },
]);

const props = defineProps({
  open: {
    type: Boolean,
    default: false,
  },
  files: {
    type: Array,
    default: () => [],
  },
  uploading: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(['close', 'upload']);
const stageRef = ref(null);
const imageElement = ref(null);
const processed = ref([]);
const state = reactive({
  index: 0,
  imageUrl: '',
  label: '',
  zoom: 1,
  offsetX: 0,
  offsetY: 0,
  naturalWidth: 0,
  naturalHeight: 0,
  filterKey: 'none',
  ready: false,
});
const drag = reactive({
  active: false,
  pointerId: 0,
  x: 0,
  y: 0,
});

const sourceFiles = computed(() => props.files.filter((file) => file instanceof File));
const currentFile = computed(() => sourceFiles.value[state.index] || null);
const progressLabel = computed(() => t('administration.background_crop_progress', {
  current: Math.min(sourceFiles.value.length, state.index + 1),
  total: sourceFiles.value.length,
}));
const saveLabel = computed(() => (
  state.index + 1 >= sourceFiles.value.length
    ? t('administration.background_crop_upload')
    : t('administration.background_crop_next')
));
const activeFilter = computed(() => filterOptions.find((filter) => filter.key === state.filterKey) || filterOptions[0]);

function fileStem(file) {
  return String(file?.name || 'background').replace(/\.[^.]+$/, '').slice(0, 120) || 'background';
}

function revokeImageUrl() {
  if (state.imageUrl !== '') {
    URL.revokeObjectURL(state.imageUrl);
  }
  state.imageUrl = '';
}

function resetCropForFile(file) {
  state.label = fileStem(file);
  state.zoom = 1;
  state.offsetX = 0;
  state.offsetY = 0;
  state.naturalWidth = 0;
  state.naturalHeight = 0;
  state.filterKey = 'none';
  state.ready = false;
}

function loadCurrentFile() {
  revokeImageUrl();
  imageElement.value = null;
  const file = currentFile.value;
  if (!file) return;
  resetCropForFile(file);
  const url = URL.createObjectURL(file);
  const image = new Image();
  image.onload = () => {
    imageElement.value = image;
    state.naturalWidth = image.naturalWidth || image.width || 0;
    state.naturalHeight = image.naturalHeight || image.height || 0;
    state.ready = state.naturalWidth > 0 && state.naturalHeight > 0;
    void nextTick(clampCrop);
  };
  image.src = url;
  state.imageUrl = url;
}

function cropMetrics() {
  const naturalWidth = Math.max(1, Number(state.naturalWidth || 1));
  const naturalHeight = Math.max(1, Number(state.naturalHeight || 1));
  const baseScale = Math.max(OUTPUT_WIDTH / naturalWidth, OUTPUT_HEIGHT / naturalHeight);
  const zoom = Math.max(MIN_ZOOM, Math.min(MAX_ZOOM, Number(state.zoom || 1)));
  const scale = baseScale * zoom;
  const drawW = naturalWidth * scale;
  const drawH = naturalHeight * scale;
  return {
    drawW,
    drawH,
    x: (OUTPUT_WIDTH - drawW) / 2 + state.offsetX,
    y: (OUTPUT_HEIGHT - drawH) / 2 + state.offsetY,
  };
}

function clampCrop() {
  state.zoom = Math.max(MIN_ZOOM, Math.min(MAX_ZOOM, Number(state.zoom || 1)));
  const { drawW, drawH } = cropMetrics();
  const maxX = Math.max(0, (drawW - OUTPUT_WIDTH) / 2);
  const maxY = Math.max(0, (drawH - OUTPUT_HEIGHT) / 2);
  state.offsetX = Math.max(-maxX, Math.min(maxX, Number(state.offsetX || 0)));
  state.offsetY = Math.max(-maxY, Math.min(maxY, Number(state.offsetY || 0)));
}

const imageStyle = computed(() => {
  const metrics = cropMetrics();
  return {
    width: `${(metrics.drawW / OUTPUT_WIDTH) * 100}%`,
    height: `${(metrics.drawH / OUTPUT_HEIGHT) * 100}%`,
    left: `${(metrics.x / OUTPUT_WIDTH) * 100}%`,
    top: `${(metrics.y / OUTPUT_HEIGHT) * 100}%`,
    filter: activeFilter.value.css,
  };
});

function stageScale() {
  const rect = stageRef.value?.getBoundingClientRect?.();
  if (!rect || rect.width <= 0 || rect.height <= 0) {
    return { x: 1, y: 1 };
  }
  return {
    x: OUTPUT_WIDTH / rect.width,
    y: OUTPUT_HEIGHT / rect.height,
  };
}

function startDrag(event) {
  if (!state.ready || props.uploading) return;
  drag.active = true;
  drag.pointerId = event.pointerId;
  drag.x = event.clientX;
  drag.y = event.clientY;
  event.currentTarget?.setPointerCapture?.(event.pointerId);
}

function moveDrag(event) {
  if (!drag.active || event.pointerId !== drag.pointerId) return;
  const scale = stageScale();
  state.offsetX += (event.clientX - drag.x) * scale.x;
  state.offsetY += (event.clientY - drag.y) * scale.y;
  drag.x = event.clientX;
  drag.y = event.clientY;
  clampCrop();
}

function endDrag(event) {
  if (event?.pointerId && event.pointerId !== drag.pointerId) return;
  drag.active = false;
  drag.pointerId = 0;
}

function zoomWithWheel(event) {
  if (!state.ready || props.uploading) return;
  const delta = event.deltaY > 0 ? -0.08 : 0.08;
  state.zoom = Math.max(MIN_ZOOM, Math.min(MAX_ZOOM, Number(state.zoom || 1) + delta));
  clampCrop();
}

function renderCroppedPayload() {
  const image = imageElement.value;
  if (!image || !state.ready) return null;
  const canvas = document.createElement('canvas');
  canvas.width = OUTPUT_WIDTH;
  canvas.height = OUTPUT_HEIGHT;
  const ctx = canvas.getContext('2d');
  if (!ctx) return null;
  const { drawW, drawH, x, y } = cropMetrics();
  ctx.fillStyle = '#000010';
  ctx.fillRect(0, 0, OUTPUT_WIDTH, OUTPUT_HEIGHT);
  ctx.filter = activeFilter.value.css;
  ctx.drawImage(image, x, y, drawW, drawH);
  return {
    file_name: `${fileStem(currentFile.value)}.jpg`,
    label: state.label || fileStem(currentFile.value),
    data_url: canvas.toDataURL('image/jpeg', JPEG_QUALITY),
  };
}

function saveCurrent() {
  const payload = renderCroppedPayload();
  if (!payload) return;
  processed.value.push(payload);
  if (state.index + 1 < sourceFiles.value.length) {
    state.index += 1;
    loadCurrentFile();
    return;
  }
  emit('upload', processed.value.slice());
}

function emitClose() {
  if (props.uploading) return;
  emit('close');
}

watch(
  () => props.open,
  (open) => {
    if (!open) {
      revokeImageUrl();
      imageElement.value = null;
      processed.value = [];
      state.index = 0;
      return;
    }
    processed.value = [];
    state.index = 0;
    loadCurrentFile();
  },
);

watch(
  () => props.files,
  () => {
    if (props.open) {
      processed.value = [];
      state.index = 0;
      loadCurrentFile();
    }
  },
);
</script>

<style scoped>
.background-upload-modal[hidden] {
  display: none;
}

.background-upload-dialog {
  width: min(980px, calc(100vw - 28px));
  max-height: calc(100vh - 28px);
  display: grid;
  grid-template-rows: auto minmax(0, 1fr) auto;
}

.background-upload-progress {
  margin: 2px 0 0;
  color: var(--text-muted);
  font-size: 12px;
}

.background-upload-body {
  min-height: 0;
  display: grid;
  grid-template-columns: minmax(0, 1fr) 260px;
  gap: 20px;
  overflow: auto;
}

.background-crop-stage {
  width: 100%;
  aspect-ratio: 16 / 9;
  align-self: start;
  position: relative;
  overflow: hidden;
  border: 1px solid var(--color-border);
  background: var(--color-primary-navy);
  cursor: grab;
  touch-action: none;
}

.background-crop-stage.dragging {
  cursor: grabbing;
}

.background-crop-stage::after {
  content: '';
  position: absolute;
  inset: 0;
  box-shadow: inset 0 0 0 2px var(--color-cyan-primary);
  pointer-events: none;
}

.background-crop-image {
  position: absolute;
  display: block;
  max-width: none;
  transform-origin: top left;
  user-select: none;
  pointer-events: none;
  -webkit-user-drag: none;
}

.background-crop-controls {
  display: grid;
  align-content: start;
  gap: 16px;
}

.background-filter-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
}

.background-filter-btn {
  min-height: 36px;
  border: 1px solid var(--color-border);
  border-radius: 0;
  background: var(--color-surface-navy);
  color: var(--text-primary);
  font: inherit;
  font-weight: 700;
  cursor: pointer;
}

.background-filter-btn.active {
  background: var(--color-cyan-primary);
  border-color: var(--color-cyan-primary);
}

.background-upload-footer {
  justify-content: flex-end;
}

@media (max-width: 820px) {
  .background-upload-dialog {
    width: 100vw;
    height: 100vh;
    max-height: 100vh;
  }

  .background-upload-body {
    grid-template-columns: 1fr;
  }
}
</style>
