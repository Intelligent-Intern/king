export default {
  module_key: 'marketplace',
  version: '0.1.0',
  permissions: ['marketplace.admin'],
  routes: [
    {
      path: '/admin/administration/marketplace',
      name: 'admin-administration-marketplace',
      roles: ['admin'],
      pageTitle: 'Marketplace',
      pageTitle_key: 'navigation.administration.marketplace',
      actions: [
        {
          key: 'marketplace.apps.create',
          label_key: 'marketplace.add_app',
          kind: 'create',
          resource_type: 'marketplace_app',
          required_permissions: ['marketplace.admin'],
        },
        {
          key: 'marketplace.apps.tour',
          label_key: 'onboarding.take_the_tour',
          kind: 'tour',
          resource_type: 'marketplace_app',
          required_permissions: ['marketplace.admin'],
        },
      ],
      source_path: 'modules/marketplace/pages/AdminMarketplaceView.vue',
      loader: () => import('./pages/AdminMarketplaceView.vue'),
    },
  ],
  navigation: [
    {
      group: 'administration',
      to: '/admin/administration/marketplace',
      label: 'Marketplace',
      label_key: 'navigation.administration.marketplace',
      order: 10,
      roles: ['admin'],
    },
  ],
  settings_panels: [],
  i18n_namespaces: ['marketplace'],
};
