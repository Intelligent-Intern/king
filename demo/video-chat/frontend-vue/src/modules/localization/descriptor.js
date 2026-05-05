export default {
  module_key: 'localization',
  version: '0.1.0',
  permissions: ['localization.admin'],
  routes: [
    {
      path: '/admin/administration/localization',
      name: 'admin-administration-localization',
      roles: ['admin'],
      pageTitle: 'Localization',
      source_path: 'modules/localization/pages/AdministrationLocalizationView.vue',
      loader: () => import('./pages/AdministrationLocalizationView.vue'),
    },
  ],
  navigation: [
    {
      group: 'administration',
      to: '/admin/administration/localization',
      label: 'Localization',
      order: 20,
      roles: ['admin'],
    },
  ],
  settings_panels: [
    {
      key: 'personal.localization',
      label: 'Localization',
      roles: ['admin', 'user'],
      order: 40,
    },
  ],
  i18n_namespaces: ['localization'],
};
