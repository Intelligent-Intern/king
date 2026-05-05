export default {
  module_key: 'administration',
  version: '0.1.0',
  permissions: ['administration.read'],
  routes: [
    {
      path: '/admin/administration/app-configuration',
      name: 'admin-administration-app-configuration',
      roles: ['admin'],
      pageTitle: 'App Configuration',
      source_path: 'domain/administration/AppConfigurationView.vue',
      loader: () => import('../../domain/administration/AppConfigurationView.vue'),
    },
  ],
  navigation: [
    {
      group: 'administration',
      to: '/admin/administration/app-configuration',
      label: 'App Configuration',
      order: 30,
      roles: ['admin'],
    },
  ],
  settings_panels: [],
  i18n_namespaces: ['administration'],
};
