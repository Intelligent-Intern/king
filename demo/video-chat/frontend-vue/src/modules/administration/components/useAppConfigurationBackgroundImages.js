import { onMounted, reactive, ref } from 'vue';
import {
  deleteWorkspaceBackgroundImage,
  listWorkspaceBackgroundImages,
  updateWorkspaceBackgroundImage,
  uploadWorkspaceBackgroundImages,
} from '../../../domain/workspace/administrationApi';

export function useAppConfigurationBackgroundImages(options = {}) {
  const translate = typeof options.t === 'function' ? options.t : (key) => key;
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
      state.error = error instanceof Error ? error.message : translate('administration.background_image_load_failed');
    } finally {
      state.loading = false;
    }
  }

  function openFilePicker() {
    if (state.uploading) return;
    fileInput.value?.click?.();
  }

  function setDragActive(value) {
    dragActive.value = Boolean(value);
  }

  function selectedImageFiles(fileList) {
    const files = Array.from(fileList || []).filter((file) => (
      ['image/png', 'image/jpeg', 'image/webp'].includes(file.type)
    ));
    if (files.length === 0) {
      throw new Error(translate('administration.background_image_type_invalid'));
    }
    if (files.length > 12) {
      throw new Error(translate('administration.background_image_limit'));
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
      state.error = error instanceof Error ? error.message : translate('administration.background_image_upload_failed');
    } finally {
      if (input) input.value = '';
    }
  }

  function handleDrop(event) {
    setDragActive(false);
    state.error = '';
    try {
      openCropModal(selectedImageFiles(event?.dataTransfer?.files || []));
    } catch (error) {
      state.error = error instanceof Error ? error.message : translate('administration.background_image_upload_failed');
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
      state.error = error instanceof Error ? error.message : translate('administration.background_image_upload_failed');
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
      if (!response.ok) throw new Error(translate('administration.background_image_edit_load_failed'));
      const blob = await response.blob();
      const type = blob.type || image.mime_type || 'image/jpeg';
      const label = String(image.label || 'background').replace(/[^A-Za-z0-9._-]+/g, '-').slice(0, 80) || 'background';
      openCropModal([new File([blob], `${label}.jpg`, { type })], image);
    } catch (error) {
      state.error = error instanceof Error ? error.message : translate('administration.background_image_edit_load_failed');
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
      state.error = error instanceof Error ? error.message : translate('administration.background_image_delete_failed');
    }
  }

  onMounted(() => {
    void loadRows();
  });

  return {
    fileInput,
    dragActive,
    rows,
    pagination,
    state,
    cropModal,
    applyListing,
    loadRows,
    openFilePicker,
    setDragActive,
    selectedImageFiles,
    openCropModal,
    handleFileSelection,
    handleDrop,
    closeCropModal,
    uploadCroppedImages,
    editImage,
    goToPage,
    deleteImage,
  };
}
