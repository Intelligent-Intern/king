import { onMounted, reactive, ref } from 'vue';
import {
  deleteWorkspaceEmailText,
  listWorkspaceEmailTexts,
  updateWorkspaceEmailText,
} from '../../../domain/workspace/administrationApi';

export function useAppConfigurationEmailTexts(options = {}) {
  const translate = typeof options.t === 'function' ? options.t : (key) => key;
  const rows = ref([]);
  const query = ref('');
  const pagination = reactive({ page: 1, page_size: 10, total: 0, page_count: 1 });
  const state = reactive({ loading: false, error: '' });
  const editor = reactive({ open: false, saving: false, error: '' });
  const form = reactive({
    id: '',
    label: '',
    template_key: '',
    subject_template: '',
    body_template: '',
    status: 'active',
    is_system: false,
  });

  function applyListing(result) {
    rows.value = Array.isArray(result?.rows) ? result.rows : [];
    const next = result?.pagination || {};
    pagination.page = Number(next.page || 1);
    pagination.page_size = Number(next.page_size || 10);
    pagination.total = Number(next.total || rows.value.length);
    pagination.page_count = Math.max(1, Number(next.page_count || 1));
  }

  async function loadRows() {
    state.loading = true;
    state.error = '';
    try {
      applyListing(await listWorkspaceEmailTexts({
        query: query.value,
        page: pagination.page,
        page_size: pagination.page_size,
      }));
    } catch (error) {
      state.error = error instanceof Error ? error.message : translate('administration.email_text_load_failed');
    } finally {
      state.loading = false;
    }
  }

  function applySearch() {
    pagination.page = 1;
    void loadRows();
  }

  function goToPage(page) {
    pagination.page = Math.max(1, Number(page) || 1);
    void loadRows();
  }

  function resetForm(row = null) {
    form.id = String(row?.id || '');
    form.label = String(row?.label || '');
    form.template_key = String(row?.template_key || '');
    form.subject_template = String(row?.subject_template || '');
    form.body_template = String(row?.body_template || '');
    form.status = String(row?.status || 'active');
    form.is_system = Boolean(row?.is_system);
  }

  function openEdit(row) {
    resetForm(row);
    editor.error = '';
    editor.open = true;
  }

  function closeEditor() {
    editor.open = false;
    editor.error = '';
  }

  function payloadFromForm() {
    return {
      label: form.label,
      template_key: form.template_key,
      subject_template: form.subject_template,
      body_template: form.body_template,
      status: form.status,
    };
  }

  async function saveEditor() {
    if (editor.saving) return;
    editor.saving = true;
    editor.error = '';
    try {
      await updateWorkspaceEmailText(form.id, payloadFromForm());
      editor.open = false;
      await loadRows();
    } catch (error) {
      editor.error = error instanceof Error ? error.message : translate('administration.email_text_save_failed');
    } finally {
      editor.saving = false;
    }
  }

  async function deleteRow(row) {
    if (!row || row.is_system) return;
    state.error = '';
    try {
      await deleteWorkspaceEmailText(row.id);
      await loadRows();
    } catch (error) {
      state.error = error instanceof Error ? error.message : translate('administration.email_text_delete_failed');
    }
  }

  onMounted(() => {
    void loadRows();
  });

  return {
    rows,
    query,
    pagination,
    state,
    editor,
    form,
    applyListing,
    loadRows,
    applySearch,
    goToPage,
    resetForm,
    openEdit,
    closeEditor,
    payloadFromForm,
    saveEditor,
    deleteRow,
  };
}
