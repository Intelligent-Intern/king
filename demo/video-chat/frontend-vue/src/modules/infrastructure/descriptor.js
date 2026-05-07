export default {
  module_key: 'infrastructure',
  version: '0.1.0',
  permissions: ['infrastructure.read'],
  routes: [
    {
      path: '/admin/infrastructure',
      name: 'admin-infrastructure',
      roles: ['admin'],
      pageTitle: 'Infrastruktur',
      pageTitle_key: 'navigation.infrastructure',
      required_permissions: ['infrastructure.read'],
      actions: [
        {
          key: 'infrastructure.tour',
          label_key: 'onboarding.take_the_tour',
          kind: 'tour',
          resource_type: 'infrastructure',
          required_permissions: ['infrastructure.read'],
        },
      ],
      source_path: 'modules/infrastructure/pages/InfrastructureView.vue',
      loader: () => import('./pages/InfrastructureView.vue'),
    },
  ],
  navigation: [
    {
      group: null,
      to: '/admin/infrastructure',
      label: 'Infrastruktur',
      label_key: 'navigation.infrastructure',
      icon: '/assets/orgas/kingrt/icons/desktop.png',
      order: 18,
      roles: ['admin'],
      required_permissions: ['infrastructure.read'],
    },
  ],
  settings_panels: [],
  i18n_namespaces: ['infrastructure'],
};
