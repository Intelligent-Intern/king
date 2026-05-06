import { computed, ref, watch } from 'vue';

function fallbackTranslate(key, params = {}) {
  return String(key || '').replace(/\{([A-Za-z0-9_.-]+)\}/g, (_match, name) => String(params?.[name] ?? ''));
}

function createTranslator(t) {
  return typeof t === 'function' ? t : fallbackTranslate;
}

export function useUserEditorModal(options = {}) {
  const props = options.props || {};
  const emit = typeof options.emit === 'function' ? options.emit : () => {};
  const t = createTranslator(options.t);
  const editorMaximized = ref(false);

  watch(() => props.open, (open) => {
    if (!open) {
      editorMaximized.value = false;
    }
  });

  const emailDraftModel = computed({
    get: () => props.userEmailDraft,
    set: (value) => emit('update:userEmailDraft', value),
  });

  const roleAutomaticallyEditsThemes = computed(() => String(props.form?.role || '').trim() === 'admin');
  const themeEditorChecked = computed({
    get: () => roleAutomaticallyEditsThemes.value || props.form.theme_editor_enabled === true,
    set: (value) => {
      if (roleAutomaticallyEditsThemes.value) return;
      props.form.theme_editor_enabled = value === true;
    },
  });
  const themeEditorDisabled = computed(() => !props.canEditThemeEditor || roleAutomaticallyEditsThemes.value);
  const themeEditorLabel = computed(() => (
    roleAutomaticallyEditsThemes.value
      ? t('users.theme_editor_admin_auto')
      : t('users.theme_editor_allow')
  ));

  return {
    editorMaximized,
    emailDraftModel,
    themeEditorChecked,
    themeEditorDisabled,
    themeEditorLabel,
  };
}
