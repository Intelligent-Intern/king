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
      source_path: 'domain/administration/ThemeEditorView.vue',
      loader: () => import('../../domain/administration/ThemeEditorView.vue'),
    },
  ],
  navigation: [
    {
      group: 'administration',
      to: '/admin/administration/theme-editor',
      label: 'Theme Editor',
      order: 40,
      roles: ['admin'],
    },
  ],
  settings_panels: [],
  i18n_namespaces: ['theme_editor'],
};
