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
      source_path: 'domain/users/overview/OverviewView.vue',
      loader: () => import('../../domain/users/overview/OverviewView.vue'),
    },
  ],
  navigation: [
    {
      group: null,
      to: '/admin/overview',
      label: 'Overview',
      order: 10,
      roles: ['admin'],
    },
  ],
  settings_panels: [
    {
      key: 'personal.about',
      label: 'About Me',
      roles: ['admin', 'user'],
      order: 10,
    },
    {
      key: 'personal.credentials',
      label: 'Credentials + E-Mail',
      roles: ['admin', 'user'],
      order: 20,
    },
  ],
  i18n_namespaces: ['users'],
};
