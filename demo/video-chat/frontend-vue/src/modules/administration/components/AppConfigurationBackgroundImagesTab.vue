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

    <AppConfigurationBackgroundImageGrid
      :rows="rows"
      :drag-active="dragActive"
      :uploading="state.uploading"
      @open-picker="openFilePicker"
      @drag-active-change="setDragActive"
      @drop-files="handleDrop"
      @edit-image="editImage"
      @delete-image="deleteImage"
    />

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
import AppPagination from '../../../components/AppPagination.vue';
import BackgroundImageUploadModal from './BackgroundImageUploadModal.vue';
import AppConfigurationBackgroundImageGrid from './AppConfigurationBackgroundImageGrid.vue';
import { t } from '../../localization/i18nRuntime.js';
import { useAppConfigurationBackgroundImages } from './useAppConfigurationBackgroundImages.js';

const {
  fileInput,
  dragActive,
  rows,
  pagination,
  state,
  cropModal,
  openFilePicker,
  setDragActive,
  handleFileSelection,
  handleDrop,
  closeCropModal,
  uploadCroppedImages,
  editImage,
  goToPage,
  deleteImage,
} = useAppConfigurationBackgroundImages({ t });
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

.app-config-pagination {
  display: flex;
  justify-content: center;
}

.settings-upload-status.error {
  color: var(--color-heading);
}

</style>
