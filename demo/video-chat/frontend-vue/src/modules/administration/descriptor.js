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
      pageTitle_key: 'navigation.administration.app_configuration',
      actions: [
        {
          key: 'administration.app_configuration.save',
          label_key: 'administration.save_configuration',
          kind: 'configure',
          resource_type: 'app_configuration',
          required_permissions: ['administration.update'],
        },
        {
          key: 'administration.app_configuration.tour',
          label_key: 'onboarding.take_the_tour',
          kind: 'tour',
          resource_type: 'app_configuration',
          required_permissions: ['administration.read'],
        },
      ],
      source_path: 'modules/administration/pages/AppConfigurationView.vue',
      loader: () => import('./pages/AppConfigurationView.vue'),
    },
  ],
  navigation: [
    {
      group: 'administration',
      to: '/admin/administration/app-configuration',
      label: 'App Configuration',
      label_key: 'navigation.administration.app_configuration',
      order: 30,
      roles: ['admin'],
    },
  ],
  settings_panels: [],
  i18n_namespaces: ['administration'],
};
