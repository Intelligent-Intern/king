import assert from 'node:assert/strict';

import {
  CALL_APP_PERMISSION_ACTIONS,
  buildCallAppGrantPatch,
  callAppGrantPermissionsForUser,
  supportedCallAppPermissionActions,
} from '../../src/domain/realtime/callApps/callAppParticipantGrantHelpers.js';

assert.deepEqual(CALL_APP_PERMISSION_ACTIONS, ['read', 'write', 'delete']);

const session = {
  id: 'cas_demo',
  status: 'active',
  default_app_policy: 'blocked_by_default',
  permission_actions: ['read', 'write', 'delete'],
  grants: [{
    subject_type: 'user',
    user_id: 42,
    grant_state: 'allowed',
    permissions: { read: true, write: false, delete: false },
    permission_actions: ['read'],
  }],
};

assert.deepEqual(supportedCallAppPermissionActions(session), ['read', 'write', 'delete']);
assert.deepEqual(callAppGrantPermissionsForUser(session, 42), { read: true, write: false, delete: false });

assert.deepEqual(
  buildCallAppGrantPatch({
    session,
    userId: 42,
    permissionAction: 'write',
    enabled: true,
  }),
  {
    subject_type: 'user',
    user_id: 42,
    grant_state: 'allowed',
    permissions: { read: true, write: true, delete: false },
    permission_actions: ['read', 'write'],
  },
);

assert.deepEqual(
  buildCallAppGrantPatch({
    session,
    userId: 42,
    permissionAction: 'read',
    enabled: false,
  }),
  {
    subject_type: 'user',
    user_id: 42,
    grant_state: 'denied',
    permissions: { read: false, write: false, delete: false },
    permission_actions: [],
  },
);

assert.deepEqual(
  buildCallAppGrantPatch({
    session: { id: 'cas_binary', status: 'active', default_app_policy: 'blocked_by_default' },
    userId: 77,
    permissionAction: 'access',
    enabled: true,
  }),
  {
    subject_type: 'user',
    user_id: 77,
    grant_state: 'allowed',
    permissions: { read: true, write: true, delete: true },
    permission_actions: ['read', 'write', 'delete'],
  },
);

assert.deepEqual(
  callAppGrantPermissionsForUser({
    id: 'cas_binary_allowed',
    status: 'active',
    default_app_policy: 'blocked_by_default',
    grants: [{ subject_type: 'user', user_id: 88, grant_state: 'allowed' }],
  }, 88),
  { read: true, write: true, delete: true },
);

console.log('[call-app-participant-grant-helpers-contract] PASS');
