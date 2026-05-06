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
      pageTitle_key: 'navigation.administration.localization',
      actions: [
        {
          key: 'localization.resources.refresh',
          label_key: 'localization.admin.refresh',
          kind: 'inspect',
          resource_type: 'translation_resource',
          required_permissions: ['localization.admin'],
        },
        {
          key: 'localization.resources.upload_csv',
          label_key: 'localization.admin.upload_csv',
          kind: 'import',
          resource_type: 'translation_resource',
          required_permissions: ['localization.admin'],
        },
        {
          key: 'localization.resources.tour',
          label_key: 'onboarding.take_the_tour',
          kind: 'tour',
          resource_type: 'translation_resource',
          required_permissions: ['localization.admin'],
        },
      ],
      source_path: 'modules/localization/pages/AdministrationLocalizationView.vue',
      loader: () => import('./pages/AdministrationLocalizationView.vue'),
    },
  ],
  navigation: [
    {
      group: 'administration',
      to: '/admin/administration/localization',
      label: 'Localization',
      label_key: 'navigation.administration.localization',
      order: 20,
      roles: ['admin'],
    },
  ],
  settings_panels: [
    {
      key: 'personal.localization',
      label: 'Localization',
      label_key: 'settings.localization',
      roles: ['admin', 'user'],
      order: 40,
    },
  ],
  i18n_namespaces: ['localization'],
};
