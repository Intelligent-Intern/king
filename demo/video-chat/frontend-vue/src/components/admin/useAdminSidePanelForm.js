import { ref } from 'vue';

export function useAdminSidePanelForm() {
  const open = ref(false);
  const saving = ref(false);
  const error = ref('');

  function openPanel() {
    error.value = '';
    open.value = true;
  }

  function closePanel() {
    if (saving.value) return false;
    open.value = false;
    return true;
  }

  async function runSubmit(action, fallbackMessage = '') {
    if (saving.value) return false;
    saving.value = true;
    error.value = '';

    try {
      await action();
      return true;
    } catch (caught) {
      error.value = caught instanceof Error ? caught.message : fallbackMessage;
      return false;
    } finally {
      saving.value = false;
    }
  }

  return {
    open,
    saving,
    error,
    openPanel,
    closePanel,
    runSubmit,
  };
}
