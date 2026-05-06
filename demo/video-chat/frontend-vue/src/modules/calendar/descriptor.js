export default {
  module_key: 'calendar',
  version: '0.1.0',
  permissions: [],
  routes: [
    {
      path: '/calendar',
      name: 'workspace-calendar',
      roles: ['admin', 'user'],
      pageTitle: 'Calendar',
      pageTitle_key: 'navigation.calendar',
      actions: [
        {
          key: 'calendar.create',
          label_key: 'calendar.create_calendar',
          kind: 'create',
          resource_type: 'calendar',
        },
      ],
      source_path: 'modules/calendar/pages/CalendarView.vue',
      loader: () => import('./pages/CalendarView.vue'),
    },
  ],
  navigation: [
    {
      group: null,
      to: '/calendar',
      label: 'Calendar',
      label_key: 'navigation.calendar',
      icon: '/assets/orgas/kingrt/icons/lobby.png',
      order: 15,
      roles: ['admin', 'user'],
    },
  ],
  settings_panels: [],
  i18n_namespaces: ['calendar'],
};
