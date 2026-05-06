<template>
  <section v-if="shouldRenderPicker" class="call-left-settings-block call-background-picker" :aria-label="effectiveTitle">
    <div class="call-left-settings-title">{{ effectiveTitle }}</div>

    <section v-if="state.loading" class="call-background-picker-status">
      {{ t('calls.enter.background_images_loading') }}
    </section>
    <section v-else-if="state.error" class="call-background-picker-status error">
      {{ state.error }}
    </section>
    <section v-else class="call-background-picker-grid" role="list">
      <button
        class="call-background-picker-tile empty"
        :class="{ active: isNoBackgroundActive }"
        type="button"
        role="listitem"
        :aria-pressed="isNoBackgroundActive"
        :title="t('calls.enter.no_background_image')"
        @click="clearBackground"
      >
        <span>{{ t('calls.enter.no_background_image_short') }}</span>
      </button>
      <button
        v-for="image in rows"
        :key="image.id"
        class="call-background-picker-tile"
        :class="{ active: isImageActive(image) }"
        type="button"
        role="listitem"
        :aria-pressed="isImageActive(image)"
        :title="image.label"
        @click="selectImage(image)"
      >
        <img :src="image.file_path" :alt="image.label" loading="lazy" />
      </button>
      <section v-if="rows.length === 0 && !props.hideWhenEmpty" class="call-background-picker-empty">
        {{ t('calls.enter.background_images_empty') }}
      </section>
    </section>
  </section>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { listPublicWorkspaceBackgroundImages } from '../../workspace/administrationApi';
import {
  callMediaPrefs,
  setCallBackgroundApplyOutgoing,
  setCallBackgroundBackdropMode,
  setCallBackgroundFilterMode,
  setCallBackgroundReplacementImageUrl,
} from '../media/preferences';
import { t } from '../../../modules/localization/i18nRuntime.js';

const props = defineProps({
  title: {
    type: String,
    default: '',
  },
  pageSize: {
    type: Number,
    default: 100,
  },
  hideWhenEmpty: {
    type: Boolean,
    default: false,
  },
});

const rows = ref([]);
const state = reactive({ loading: false, error: '' });
const effectiveTitle = computed(() => props.title || t('calls.enter.background_images'));
const shouldRenderPicker = computed(() => !props.hideWhenEmpty || state.loading || state.error || rows.value.length > 0);
const isNoBackgroundActive = computed(() => (
  String(callMediaPrefs.backgroundFilterMode || 'off') === 'off'
    || !callMediaPrefs.backgroundApplyOutgoing
    || String(callMediaPrefs.backgroundReplacementImageUrl || '').trim() === ''
));

function normalizePath(value) {
  return String(value || '').trim();
}

function isImageActive(image) {
  return String(callMediaPrefs.backgroundFilterMode || '') === 'replace'
    && String(callMediaPrefs.backgroundBackdropMode || '') === 'image'
    && Boolean(callMediaPrefs.backgroundApplyOutgoing)
    && normalizePath(callMediaPrefs.backgroundReplacementImageUrl) === normalizePath(image?.file_path);
}

function clearBackground() {
  setCallBackgroundReplacementImageUrl('');
  setCallBackgroundFilterMode('off');
  setCallBackgroundApplyOutgoing(false);
}

function clearUnavailableImageBackground() {
  const mode = String(callMediaPrefs.backgroundFilterMode || '').trim();
  const backdrop = String(callMediaPrefs.backgroundBackdropMode || '').trim();
  if (mode !== 'replace' || backdrop !== 'image') return;
  clearBackground();
}

function selectImage(image) {
  const path = normalizePath(image?.file_path);
  if (path === '') return;
  setCallBackgroundReplacementImageUrl(path);
  setCallBackgroundBackdropMode('image');
  setCallBackgroundFilterMode('replace');
  setCallBackgroundApplyOutgoing(true);
}

async function loadRows() {
  state.loading = true;
  state.error = '';
  try {
    const result = await listPublicWorkspaceBackgroundImages({
      page: 1,
      page_size: Math.max(1, Math.min(100, Number(props.pageSize) || 100)),
    });
    rows.value = Array.isArray(result?.rows) ? result.rows : [];
    if (props.hideWhenEmpty && rows.value.length === 0) {
      clearUnavailableImageBackground();
    }
  } catch (error) {
    state.error = error instanceof Error ? error.message : t('calls.enter.background_images_load_failed');
  } finally {
    state.loading = false;
  }
}

onMounted(() => {
  void loadRows();
});
</script>

<style scoped>
.call-background-picker {
  width: 100%;
}

.call-background-picker-status,
.call-background-picker-empty {
  min-height: 28px;
  color: var(--text-muted);
  font-size: 12px;
  line-height: 1.35;
}

.call-background-picker-status.error {
  color: var(--color-error);
}

.call-background-picker-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 8px;
}

.call-background-picker-tile {
  min-width: 0;
  aspect-ratio: 16 / 9;
  border: 1px solid var(--color-border);
  border-radius: 0;
  background: var(--color-surface-navy);
  color: var(--text-primary);
  padding: 0;
  overflow: hidden;
  display: grid;
  place-items: center;
  cursor: pointer;
}

.call-background-picker-tile.active {
  border-color: var(--color-cyan-primary);
  outline: 2px solid var(--color-cyan-primary);
  outline-offset: -2px;
}

.call-background-picker-tile img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.call-background-picker-tile.empty span {
  font-size: 11px;
  font-weight: 800;
}

.call-background-picker-empty {
  grid-column: 1 / -1;
}
</style>
