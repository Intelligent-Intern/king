function normalizeBoolean(value, fallback = false) {
  if (typeof value === 'boolean') return value;
  return fallback;
}

export function deriveAdminUserPermissions(user, currentUserId = 0) {
  if (!user || typeof user !== 'object') {
    return {
      isSelf: false,
      isPrimaryAdmin: false,
      canChangeRole: true,
      canChangeStatus: true,
      canToggleStatus: true,
      canDelete: true,
    };
  }

  const userPermissions = user.permissions && typeof user.permissions === 'object'
    ? user.permissions
    : {};
  const userId = Number(user.id || 0);
  const fallbackIsSelf = userId > 0 && userId === Number(currentUserId || 0);
  const isSelf = normalizeBoolean(user.is_self, fallbackIsSelf);
  const isPrimaryAdmin = normalizeBoolean(user.is_primary_admin, false);
  const fallbackAllowed = !isSelf && !isPrimaryAdmin;

  return {
    isSelf,
    isPrimaryAdmin,
    canChangeRole: normalizeBoolean(userPermissions.can_change_role, fallbackAllowed),
    canChangeStatus: normalizeBoolean(userPermissions.can_change_status, fallbackAllowed),
    canToggleStatus: normalizeBoolean(userPermissions.can_toggle_status, fallbackAllowed),
    canDelete: normalizeBoolean(userPermissions.can_delete, fallbackAllowed),
  };
}

export function applyAdminUserPermissions(target, user, currentUserId = 0) {
  const permissions = deriveAdminUserPermissions(user, currentUserId);
  target.isSelf = permissions.isSelf;
  target.isPrimaryAdmin = permissions.isPrimaryAdmin;
  target.canChangeRole = permissions.canChangeRole;
  target.canChangeStatus = permissions.canChangeStatus;
  target.canToggleStatus = permissions.canToggleStatus;
  target.canDelete = permissions.canDelete;
}

export function resetAdminUserPermissions(target) {
  target.isSelf = false;
  target.isPrimaryAdmin = false;
  target.canChangeRole = true;
  target.canChangeStatus = true;
  target.canToggleStatus = true;
  target.canDelete = true;
}

export function canToggleAdminUserStatus(user, currentUserId = 0) {
  return deriveAdminUserPermissions(user, currentUserId).canToggleStatus;
}

export function canDeleteAdminUser(user, currentUserId = 0) {
  return deriveAdminUserPermissions(user, currentUserId).canDelete;
}
