export default {
  module_key: 'workspace_settings',
  version: '0.1.0',
  permissions: ['workspace_settings.read', 'workspace_settings.update'],
  routes: [],
  navigation: [],
  settings_panels: [
    {
      key: 'personal.theme',
      label: 'Options',
      label_key: 'settings.options',
      roles: ['admin', 'user'],
      order: 90,
      required_permissions: [],
      source_path: 'layouts/settings/WorkspaceThemeSettings.vue',
      loader: () => import('../../layouts/settings/WorkspaceThemeSettings.vue'),
    },
  ],
  i18n_namespaces: ['workspace_settings'],
};
