export default {
  module_key: 'theme_editor',
  version: '0.1.0',
  permissions: ['theme_editor.admin'],
  routes: [
    {
      path: '/admin/administration/theme-editor',
      name: 'admin-administration-theme-editor',
      roles: ['admin'],
      pageTitle: 'Theme Editor',
      pageTitle_key: 'navigation.administration.theme_editor',
      actions: [
        {
          key: 'theme_editor.themes.create',
          label_key: 'theme_settings.new_theme',
          kind: 'create',
          resource_type: 'workspace_theme',
          required_permissions: ['theme_editor.admin'],
        },
        {
          key: 'theme_editor.themes.tour',
          label_key: 'onboarding.take_the_tour',
          kind: 'tour',
          resource_type: 'workspace_theme',
          required_permissions: ['theme_editor.admin'],
        },
      ],
      source_path: 'modules/theme_editor/pages/ThemeEditorView.vue',
      loader: () => import('./pages/ThemeEditorView.vue'),
    },
  ],
  navigation: [
    {
      group: 'administration',
      to: '/admin/administration/theme-editor',
      label: 'Theme Editor',
      label_key: 'navigation.administration.theme_editor',
      order: 40,
      roles: ['admin'],
    },
  ],
  settings_panels: [],
  i18n_namespaces: ['theme_editor'],
};
