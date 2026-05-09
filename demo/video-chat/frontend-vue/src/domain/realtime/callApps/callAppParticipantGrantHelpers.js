export const CALL_APP_PERMISSION_ACTIONS = Object.freeze(['read', 'write', 'delete']);

export function normalizeCallAppGrantState(value) {
  const state = String(value || '').trim().toLowerCase();
  return state === 'allowed' || state === 'denied' ? state : '';
}

function normalizePermissionAction(value) {
  const action = String(value || '').trim().toLowerCase();
  return CALL_APP_PERMISSION_ACTIONS.includes(action) ? action : '';
}

function permissionActionsFromValue(value) {
  if (Array.isArray(value)) {
    return value.map((entry) => normalizePermissionAction(entry)).filter(Boolean);
  }
  if (value && typeof value === 'object') {
    return Object.entries(value)
      .filter(([, enabled]) => enabled === true || enabled === 1 || enabled === '1' || String(enabled || '').toLowerCase() === 'true')
      .map(([key]) => normalizePermissionAction(key))
      .filter(Boolean);
  }
  return [];
}

export function normalizeCallAppPermissionActions(value) {
  return [...new Set(permissionActionsFromValue(value))];
}

export function supportedCallAppPermissionActions(session = {}) {
  const explicitActions = normalizeCallAppPermissionActions(session?.permission_actions || session?.permissionActions);
  if (explicitActions.length > 0) return explicitActions;

  const grants = Array.isArray(session?.grants) ? session.grants : [];
  const grantActions = [];
  for (const grant of grants) {
    grantActions.push(...normalizeCallAppPermissionActions(grant?.permission_actions || grant?.permissionActions));
    grantActions.push(...normalizeCallAppPermissionActions(grant?.permissions));
  }
  if (grantActions.length > 0) return [...new Set(grantActions)];

  const model = String(session?.grant_model || session?.grantModel || '').trim().toLowerCase();
  return model === 'permissions' || model === 'read_write_delete' ? [...CALL_APP_PERMISSION_ACTIONS] : [];
}

export function callAppGrantForUser(session = {}, userId = 0) {
  const normalizedUserId = Number(userId || 0);
  if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return null;
  const grants = Array.isArray(session?.grants) ? session.grants : [];
  return grants.find((grant) => (
    String(grant?.subject_type || grant?.subjectType || '').trim().toLowerCase() === 'user'
    && Number(grant?.user_id || grant?.userId || 0) === normalizedUserId
  )) || null;
}

export function defaultCallAppGrantState(session = {}) {
  return String(session?.default_app_policy || session?.defaultAppPolicy || '').trim().toLowerCase() === 'allowed_by_default'
    ? 'allowed'
    : 'denied';
}

export function callAppGrantPermissionsForUser(session = {}, userId = 0) {
  const grant = callAppGrantForUser(session, userId);
  const grantState = normalizeCallAppGrantState(grant?.grant_state || grant?.grantState) || defaultCallAppGrantState(session);
  const supportedActions = supportedCallAppPermissionActions(session);
  const explicitActions = new Set([
    ...normalizeCallAppPermissionActions(grant?.permission_actions || grant?.permissionActions),
    ...normalizeCallAppPermissionActions(grant?.permissions),
  ]);
  const permissions = {};
  for (const action of CALL_APP_PERMISSION_ACTIONS) {
    permissions[action] = explicitActions.size > 0
      ? explicitActions.has(action)
      : grantState === 'allowed' && (supportedActions.length === 0 || supportedActions.includes(action));
  }
  return permissions;
}

export function buildCallAppGrantPatch({ session = {}, userId = 0, permissionAction = 'access', enabled = false }) {
  const normalizedUserId = Number(userId || 0);
  const action = normalizePermissionAction(permissionAction);
  const permissions = callAppGrantPermissionsForUser(session, normalizedUserId);

  if (permissionAction === 'access') {
    const supportedActions = supportedCallAppPermissionActions(session);
    const accessActions = supportedActions.length > 0 ? supportedActions : CALL_APP_PERMISSION_ACTIONS;
    for (const key of accessActions) {
      permissions[key] = enabled === true;
    }
  } else if (action !== '') {
    permissions[action] = enabled === true;
  }

  const permissionActions = CALL_APP_PERMISSION_ACTIONS.filter((key) => permissions[key]);
  const grantState = permissionAction === 'access'
    ? (enabled === true ? 'allowed' : 'denied')
    : (permissionActions.length > 0 ? 'allowed' : 'denied');

  return {
    subject_type: 'user',
    user_id: normalizedUserId,
    grant_state: grantState,
    permissions,
    permission_actions: permissionActions,
  };
}
