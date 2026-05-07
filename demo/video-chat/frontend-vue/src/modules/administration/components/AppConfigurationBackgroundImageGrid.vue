<template>
  <section class="background-grid">
    <button
      class="background-dropzone"
      :class="{ empty: rows.length === 0, 'is-over': dragActive }"
      type="button"
      :disabled="uploading"
      @click="$emit('open-picker')"
      @dragenter.prevent="$emit('drag-active-change', true)"
      @dragover.prevent="$emit('drag-active-change', true)"
      @dragleave.prevent="$emit('drag-active-change', false)"
      @drop.prevent="$emit('drop-files', $event)"
    >
      <span class="background-dropzone-title">{{ t('administration.background_dropzone_title') }}</span>
      <span class="background-dropzone-subtitle">{{ t('administration.background_dropzone_body') }}</span>
    </button>
    <article v-for="image in rows" :key="image.id" class="background-card">
      <img class="background-card-image" :src="image.file_path" :alt="image.label" />
      <section class="background-card-body">
        <strong>{{ image.label }}</strong>
        <span>{{ fileSizeLabel(image.file_size) }}</span>
      </section>
      <footer class="background-card-actions">
        <AppIconButton
          icon="/assets/orgas/kingrt/icons/gear.png"
          :title="t('administration.background_image_edit')"
          :aria-label="t('administration.background_image_edit')"
          @click="$emit('edit-image', image)"
        />
        <AppIconButton
          icon="/assets/orgas/kingrt/icons/remove_user.png"
          :title="t('administration.background_image_delete')"
          :aria-label="t('administration.background_image_delete')"
          danger
          @click="$emit('delete-image', image)"
        />
      </footer>
    </article>
  </section>
</template>

<script setup>
import AppIconButton from '../../../components/AppIconButton.vue';
import { t } from '../../localization/i18nRuntime.js';

defineProps({
  rows: {
    type: Array,
    default: () => [],
  },
  dragActive: {
    type: Boolean,
    default: false,
  },
  uploading: {
    type: Boolean,
    default: false,
  },
});

defineEmits(['open-picker', 'drag-active-change', 'drop-files', 'edit-image', 'delete-image']);

function fileSizeLabel(bytes) {
  const value = Number(bytes || 0);
  if (!Number.isFinite(value) || value <= 0) return t('common.not_available');
  if (value < 1024 * 1024) return `${Math.round(value / 1024)} KB`;
  return `${Math.round((value / 1024 / 1024) * 10) / 10} MB`;
}
</script>

<style scoped>
.background-grid {
  flex: 1 1 auto;
  min-height: 0;
  overflow: auto;
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
  align-content: start;
  gap: 20px;
}

.background-dropzone {
  min-height: 220px;
  border: 1px dashed var(--color-cyan-primary);
  border-radius: 0;
  background: var(--color-surface-navy);
  color: var(--text-primary);
  display: grid;
  place-content: center;
  gap: 8px;
  padding: 20px;
  text-align: center;
  cursor: pointer;
}

.background-dropzone.empty {
  grid-column: 1 / -1;
}

.background-dropzone.is-over,
.background-dropzone:focus-visible {
  outline: 2px solid var(--color-cyan-hover);
  outline-offset: 2px;
}

.background-dropzone-title {
  font-weight: 800;
}

.background-dropzone-subtitle {
  color: var(--text-muted);
}

.background-card {
  min-height: 220px;
  border: 1px solid var(--color-border);
  border-radius: 0;
  background: var(--color-surface-navy);
  display: grid;
  grid-template-rows: 140px auto auto;
  overflow: hidden;
}

.background-card-image {
  width: 100%;
  height: 140px;
  object-fit: cover;
  background: var(--color-border);
}

.background-card-body {
  display: grid;
  gap: 5px;
  padding: 12px;
  color: var(--text-primary);
}

.background-card-body span {
  color: var(--text-muted);
  font-size: 12px;
}

.background-card-actions {
  display: flex;
  gap: 10px;
  justify-content: flex-end;
  padding: 0 12px 12px;
}

@media (max-width: 760px) {
  .background-grid {
    grid-template-columns: 1fr;
  }
}
</style>
