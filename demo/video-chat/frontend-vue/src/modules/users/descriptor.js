export default {
  module_key: 'users',
  version: '0.1.0',
  permissions: ['users.read'],
  routes: [
    {
      path: '/admin/overview',
      name: 'admin-overview',
      roles: ['admin'],
      pageTitle: 'Overview',
      pageTitle_key: 'navigation.overview',
      source_path: 'modules/users/pages/overview/OverviewView.vue',
      loader: () => import('./pages/overview/OverviewView.vue'),
    },
  ],
  navigation: [
    {
      group: null,
      to: '/admin/overview',
      label: 'Overview',
      label_key: 'navigation.overview',
      order: 10,
      roles: ['admin'],
    },
  ],
  settings_panels: [
    {
      key: 'personal.about',
      label: 'About Me',
      label_key: 'settings.about',
      roles: ['admin', 'user'],
      order: 10,
    },
    {
      key: 'personal.credentials',
      label: 'Credentials + E-Mail',
      label_key: 'settings.credentials',
      roles: ['admin', 'user'],
      order: 20,
    },
  ],
  i18n_namespaces: ['users'],
};
