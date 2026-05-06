<template>
  <section class="app-config-backgrounds">
    <section class="app-config-toolbar">
      <button class="btn btn-cyan" type="button" :disabled="state.uploading" @click="openSingleUpload">
        {{ t('administration.background_image_upload') }}
      </button>
      <button class="btn" type="button" :disabled="state.uploading" @click="openBulkUpload">
        {{ t('administration.background_image_bulk_upload') }}
      </button>
      <input
        ref="singleInput"
        class="app-config-file-input"
        type="file"
        accept="image/png,image/jpeg,image/webp"
        @change="handleUpload"
      />
      <input
        ref="bulkInput"
        class="app-config-file-input"
        type="file"
        accept="image/png,image/jpeg,image/webp"
        multiple
        @change="handleUpload"
      />
    </section>

    <section v-if="state.loading" class="settings-upload-status">{{ t('common.loading') }}</section>
    <section v-if="state.uploading" class="settings-upload-status">{{ t('administration.background_image_uploading') }}</section>
    <section v-if="state.error" class="settings-upload-status error">{{ state.error }}</section>

    <section class="background-grid">
      <article v-for="image in rows" :key="image.id" class="background-card">
        <img class="background-card-image" :src="image.file_path" :alt="image.label" />
        <section class="background-card-body">
          <strong>{{ image.label }}</strong>
          <span>{{ fileSizeLabel(image.file_size) }}</span>
        </section>
        <footer class="background-card-actions">
          <AppIconButton
            icon="/assets/orgas/kingrt/icons/remove_user.png"
            :title="t('administration.background_image_delete')"
            :aria-label="t('administration.background_image_delete')"
            danger
            @click="deleteImage(image)"
          />
        </footer>
      </article>
      <section v-if="!state.loading && rows.length === 0" class="background-empty">
        {{ t('administration.background_image_empty') }}
      </section>
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
  </section>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import AppIconButton from '../../../components/AppIconButton.vue';
import AppPagination from '../../../components/AppPagination.vue';
import {
  deleteWorkspaceBackgroundImage,
  listWorkspaceBackgroundImages,
  uploadWorkspaceBackgroundImages,
} from '../../../domain/workspace/administrationApi';
import { t } from '../../localization/i18nRuntime.js';

const singleInput = ref(null);
const bulkInput = ref(null);
const rows = ref([]);
const pagination = reactive({ page: 1, page_size: 12, total: 0, page_count: 1 });
const state = reactive({ loading: false, uploading: false, error: '' });

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

function openSingleUpload() {
  singleInput.value?.click?.();
}

function openBulkUpload() {
  bulkInput.value?.click?.();
}

function readFileAsDataUrl(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(typeof reader.result === 'string' ? reader.result : '');
    reader.onerror = () => reject(new Error(t('theme_settings.image_read_failed')));
    reader.readAsDataURL(file);
  });
}

async function filesToPayload(fileList) {
  const files = Array.from(fileList || []).filter((file) => (
    ['image/png', 'image/jpeg', 'image/webp'].includes(file.type)
  ));
  if (files.length === 0) {
    throw new Error(t('administration.background_image_type_invalid'));
  }
  const selected = files.slice(0, 50);
  return Promise.all(selected.map(async (file) => ({
    file_name: file.name,
    label: file.name.replace(/\.[^.]+$/, ''),
    data_url: await readFileAsDataUrl(file),
  })));
}

async function handleUpload(event) {
  const input = event?.target || null;
  state.error = '';
  state.uploading = true;
  try {
    const files = await filesToPayload(input?.files || []);
    await uploadWorkspaceBackgroundImages(files);
    pagination.page = 1;
    await loadRows();
  } catch (error) {
    state.error = error instanceof Error ? error.message : t('administration.background_image_upload_failed');
  } finally {
    state.uploading = false;
    if (input) input.value = '';
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

.app-config-toolbar {
  display: flex;
  align-items: center;
  justify-content: flex-start;
  flex-wrap: wrap;
  gap: 20px;
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
  justify-content: flex-end;
  padding: 0 12px 12px;
}

.background-empty {
  min-height: 220px;
  border: 1px solid var(--color-border);
  display: grid;
  place-items: center;
  color: var(--text-muted);
  grid-column: 1 / -1;
}

.app-config-pagination {
  display: flex;
  justify-content: center;
}

.settings-upload-status.error {
  color: var(--color-heading);
}

@media (max-width: 760px) {
  .app-config-toolbar .btn,
  .app-config-toolbar .icon-mini-btn {
    flex: 1 1 100%;
    width: 100%;
    margin-inline-start: 0;
  }
}
</style>
