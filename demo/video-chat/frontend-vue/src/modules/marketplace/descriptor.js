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
