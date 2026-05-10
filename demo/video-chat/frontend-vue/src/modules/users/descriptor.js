export default {
  module_key: 'users',
  version: '0.1.0',
  permissions: ['users.read', 'users.create', 'users.update', 'users.delete'],
  routes: [
    {
      path: '/admin/overview',
      name: 'admin-overview',
      roles: ['admin'],
      pageTitle_key: 'navigation.overview',
      required_permissions: ['users.read'],
      actions: [
        {
          key: 'users.overview.tour',
          label_key: 'onboarding.take_the_tour',
          kind: 'tour',
          resource_type: 'overview',
          required_permissions: ['users.read'],
        },
      ],
      source_path: 'modules/users/pages/overview/OverviewView.vue',
      loader: () => import('./pages/overview/OverviewView.vue'),
    },
  ],
  navigation: [
    {
      group: null,
      to: '/admin/overview',
      label_key: 'navigation.overview',
      order: 10,
      roles: ['admin'],
      required_permissions: ['users.read'],
    },
  ],
  settings_panels: [
    {
      key: 'personal.about',
      label_key: 'settings.about',
      roles: ['admin', 'user'],
      order: 10,
      required_permissions: [],
      source_path: 'layouts/settings/WorkspaceAboutSettings.vue',
      loader: () => import('../../layouts/settings/WorkspaceAboutSettings.vue'),
      profile_field_groups: [
        {
          key: 'about',
          label: 'About',
          label_key: 'settings.profile_about_section',
          fields: ['display_name', 'avatar_path', 'about_me'],
        },
        {
          key: 'social',
          label: 'Social',
          label_key: 'settings.profile_social_section',
          fields: ['linkedin_url', 'x_url', 'youtube_url'],
        },
        {
          key: 'contact',
          label: 'Contact',
          label_key: 'settings.profile_contact_section',
          fields: ['profile_contact_email', 'profile_contact_phone'],
        },
      ],
    },
    {
      key: 'personal.credentials',
      label_key: 'settings.credentials',
      roles: ['admin', 'user'],
      order: 20,
      required_permissions: [],
      source_path: 'layouts/settings/WorkspaceCredentialsSettings.vue',
      loader: () => import('../../layouts/settings/WorkspaceCredentialsSettings.vue'),
    },
    {
      key: 'personal.notifications',
      label_key: 'settings.notifications',
      roles: ['admin', 'user'],
      order: 30,
      required_permissions: [],
      source_path: 'layouts/settings/WorkspaceNotificationSettings.vue',
      loader: () => import('../../layouts/settings/WorkspaceNotificationSettings.vue'),
    },
  ],
  i18n_namespaces: ['users'],
  catalog: {
    name_key: 'modules.users',
    preview_kind: 'users',
  },
};
