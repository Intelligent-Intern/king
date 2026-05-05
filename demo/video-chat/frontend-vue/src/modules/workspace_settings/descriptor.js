export default {
  module_key: 'workspace_settings',
  version: '0.1.0',
  permissions: ['workspace_settings.read'],
  routes: [],
  navigation: [],
  settings_panels: [
    {
      key: 'personal.theme',
      label: 'Theme',
      label_key: 'settings.theme',
      roles: ['admin', 'user'],
      order: 30,
      source_path: 'layouts/settings/WorkspaceThemeSettings.vue',
      loader: () => import('../../layouts/settings/WorkspaceThemeSettings.vue'),
    },
    {
      key: 'personal.regional',
      label: 'Regional Time',
      label_key: 'settings.regional_time',
      roles: ['admin', 'user'],
      order: 50,
    },
  ],
  i18n_namespaces: ['workspace_settings'],
};
