<template>
  <section class="app-config-backgrounds">
    <input
      ref="fileInput"
      class="app-config-file-input"
      type="file"
      accept="image/png,image/jpeg,image/webp"
      multiple
      @change="handleFileSelection"
    />

    <section v-if="state.loading" class="settings-upload-status">{{ t('common.loading') }}</section>
    <section v-if="state.uploading" class="settings-upload-status">{{ t('administration.background_image_uploading') }}</section>
    <section v-if="state.error" class="settings-upload-status error">{{ state.error }}</section>

    <section class="background-grid">
      <button
        class="background-dropzone"
        :class="{ empty: rows.length === 0, 'is-over': dragActive }"
        type="button"
        :disabled="state.uploading"
        @click="openFilePicker"
        @dragenter.prevent="dragActive = true"
        @dragover.prevent="dragActive = true"
        @dragleave.prevent="dragActive = false"
        @drop.prevent="handleDrop"
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
            @click="editImage(image)"
          />
          <AppIconButton
            icon="/assets/orgas/kingrt/icons/remove_user.png"
            :title="t('administration.background_image_delete')"
            :aria-label="t('administration.background_image_delete')"
            danger
            @click="deleteImage(image)"
          />
        </footer>
      </article>
    </section>

    <footer class="app-config-pagination">
      <AppPagination
        :page="pagination.page"
        :page-count="pagination.page_count"
        :total="pagination.total"
        :total-label="t('administration.background_images_total')"
        :has-prev="pagination.page > 1"
        :has-next="pagination.page < pagination.page_count"
        :disabled="state.loading || state.uploading"
        @page-change="goToPage"
      />
    </footer>

    <BackgroundImageUploadModal
      :open="cropModal.open"
      :files="cropModal.files"
      :uploading="state.uploading"
      :submit-label="cropModal.editImage ? t('administration.background_crop_save') : ''"
      @close="closeCropModal"
      @upload="uploadCroppedImages"
    />
  </section>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import AppIconButton from '../../../components/AppIconButton.vue';
import AppPagination from '../../../components/AppPagination.vue';
import BackgroundImageUploadModal from './BackgroundImageUploadModal.vue';
import {
  deleteWorkspaceBackgroundImage,
  listWorkspaceBackgroundImages,
  updateWorkspaceBackgroundImage,
  uploadWorkspaceBackgroundImages,
} from '../../../domain/workspace/administrationApi';
import { t } from '../../localization/i18nRuntime.js';

const fileInput = ref(null);
const dragActive = ref(false);
const rows = ref([]);
const pagination = reactive({ page: 1, page_size: 12, total: 0, page_count: 1 });
const state = reactive({ loading: false, uploading: false, error: '' });
const cropModal = reactive({ open: false, files: [], editImage: null });

function applyListing(result) {
  rows.value = Array.isArray(result?.rows) ? result.rows : [];
  const next = result?.pagination || {};
  pagination.page = Number(next.page || 1);
  pagination.page_size = Number(next.page_size || 12);
  pagination.total = Number(next.total || rows.value.length);
  pagination.page_count = Math.max(1, Number(next.page_count || 1));
}

async function loadRows() {
  state.loading = true;
  state.error = '';
  try {
    applyListing(await listWorkspaceBackgroundImages({
      page: pagination.page,
      page_size: pagination.page_size,
    }));
  } catch (error) {
    state.error = error instanceof Error ? error.message : t('administration.background_image_load_failed');
  } finally {
    state.loading = false;
  }
}

function openFilePicker() {
  if (state.uploading) return;
  fileInput.value?.click?.();
}

function selectedImageFiles(fileList) {
  const files = Array.from(fileList || []).filter((file) => (
    ['image/png', 'image/jpeg', 'image/webp'].includes(file.type)
  ));
  if (files.length === 0) {
    throw new Error(t('administration.background_image_type_invalid'));
  }
  if (files.length > 12) {
    throw new Error(t('administration.background_image_limit'));
  }
  return files;
}

function openCropModal(files, editImageRow = null) {
  cropModal.files = files;
  cropModal.editImage = editImageRow;
  cropModal.open = true;
}

function handleFileSelection(event) {
  const input = event?.target || null;
  state.error = '';
  try {
    openCropModal(selectedImageFiles(input?.files || []));
  } catch (error) {
    state.error = error instanceof Error ? error.message : t('administration.background_image_upload_failed');
  } finally {
    if (input) input.value = '';
  }
}

function handleDrop(event) {
  dragActive.value = false;
  state.error = '';
  try {
    openCropModal(selectedImageFiles(event?.dataTransfer?.files || []));
  } catch (error) {
    state.error = error instanceof Error ? error.message : t('administration.background_image_upload_failed');
  }
}

function closeCropModal() {
  if (state.uploading) return;
  cropModal.open = false;
  cropModal.files = [];
  cropModal.editImage = null;
}

async function uploadCroppedImages(files) {
  state.error = '';
  state.uploading = true;
  try {
    if (cropModal.editImage?.id) {
      await updateWorkspaceBackgroundImage(cropModal.editImage.id, Array.isArray(files) ? files[0] : null);
    } else {
      await uploadWorkspaceBackgroundImages(files);
      pagination.page = 1;
    }
    cropModal.open = false;
    cropModal.files = [];
    cropModal.editImage = null;
    await loadRows();
  } catch (error) {
    state.error = error instanceof Error ? error.message : t('administration.background_image_upload_failed');
  } finally {
    state.uploading = false;
  }
}

async function editImage(image) {
  if (!image?.id || state.uploading) return;
  state.error = '';
  try {
    const sourcePath = String(image.original_file_path || image.file_path || '');
    const response = await fetch(sourcePath, { credentials: 'include' });
    if (!response.ok) throw new Error(t('administration.background_image_edit_load_failed'));
    const blob = await response.blob();
    const type = blob.type || image.mime_type || 'image/jpeg';
    const label = String(image.label || 'background').replace(/[^A-Za-z0-9._-]+/g, '-').slice(0, 80) || 'background';
    openCropModal([new File([blob], `${label}.jpg`, { type })], image);
  } catch (error) {
    state.error = error instanceof Error ? error.message : t('administration.background_image_edit_load_failed');
  }
}

function goToPage(page) {
  pagination.page = Math.max(1, Number(page) || 1);
  void loadRows();
}

async function deleteImage(image) {
  if (!image?.id) return;
  state.error = '';
  try {
    await deleteWorkspaceBackgroundImage(image.id);
    await loadRows();
  } catch (error) {
    state.error = error instanceof Error ? error.message : t('administration.background_image_delete_failed');
  }
}

function fileSizeLabel(bytes) {
  const value = Number(bytes || 0);
  if (!Number.isFinite(value) || value <= 0) return t('common.not_available');
  if (value < 1024 * 1024) return `${Math.round(value / 1024)} KB`;
  return `${Math.round((value / 1024 / 1024) * 10) / 10} MB`;
}

onMounted(() => {
  void loadRows();
});
</script>

<style scoped>
.app-config-backgrounds {
  height: 100%;
  min-height: 0;
  display: flex;
  flex-direction: column;
  gap: 20px;
  overflow: hidden;
}

.app-config-file-input {
  display: none;
}

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

.app-config-pagination {
  display: flex;
  justify-content: center;
}

.settings-upload-status.error {
  color: var(--color-heading);
}

@media (max-width: 760px) {
  .background-grid {
    grid-template-columns: 1fr;
  }
}
</style>
