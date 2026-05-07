export default {
  module_key: 'calls',
  version: '0.1.0',
  permissions: [
    'calls.read',
    'calls.create',
    'calls.update',
    'calls.delete',
    'calls.invite',
    'calls.join',
    'calls.moderate',
    'calls.chat',
    'calls.screen_share',
    'calls.backgrounds',
  ],
  routes: [],
  navigation: [],
  settings_panels: [],
  i18n_namespaces: ['calls'],
  catalog: {
    name: 'Video Calls',
    preview_kind: 'calls',
  },
};
