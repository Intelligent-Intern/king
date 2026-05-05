function normalizeString(value) {
  return String(value || '').trim();
}

function normalizeStringList(value) {
  if (!Array.isArray(value)) return [];
  return [...new Set(value.map(normalizeString).filter(Boolean))].sort();
}

function collectPermissionKeys(value, output, prefix = '') {
  if (Array.isArray(value)) {
    for (const item of value) {
      const key = normalizeString(item);
      if (key !== '') output.add(key);
    }
    return;
  }

  if (!value || typeof value !== 'object') return;

  for (const [rawKey, rawValue] of Object.entries(value)) {
    const key = normalizeString(rawKey);
    if (key === '') continue;

    const fullKey = prefix !== '' ? `${prefix}.${key}` : key;
    if (rawValue === true) {
      output.add(fullKey);
      continue;
    }

    if (Array.isArray(rawValue)) {
      collectPermissionKeys(rawValue, output);
      continue;
    }

    if (rawValue && typeof rawValue === 'object') {
      collectPermissionKeys(rawValue, output, fullKey);
    }
  }
}

export function sessionPermissionKeys(tenantPermissions = {}) {
  const output = new Set();
  collectPermissionKeys(tenantPermissions, output);

  if (tenantPermissions?.manage_users === true) output.add('users.read');
  if (
    tenantPermissions?.tenant_admin === true
    || tenantPermissions?.manage_organizations === true
    || tenantPermissions?.manage_groups === true
    || tenantPermissions?.manage_permission_grants === true
    || tenantPermissions?.export_import === true
  ) {
    output.add('governance.read');
  }
  if (tenantPermissions?.edit_themes === true) output.add('theme_editor.admin');

  return [...output].sort();
}

export function moduleAccessContextFromSession(session = {}) {
  const role = normalizeString(session.role);
  const tenantPermissions = session.tenantPermissions && typeof session.tenantPermissions === 'object'
    ? session.tenantPermissions
    : {};
  const permissionKeys = sessionPermissionKeys(tenantPermissions);

  return {
    role,
    permissions: permissionKeys,
    allPermissions: tenantPermissions.platform_admin === true || (role === 'admin' && permissionKeys.length === 0),
  };
}

export function routeRequiredPermissions(route) {
  const records = Array.isArray(route?.matched) ? route.matched : [];
  const permissions = records.flatMap((record) => normalizeStringList(record?.meta?.required_permissions));
  return [...new Set(permissions)].sort();
}

export function routeAllowedRoles(route) {
  const records = Array.isArray(route?.matched) ? route.matched : [];
  return records.filter((record) => normalizeStringList(record?.meta?.roles).length > 0);
}

export function routeAllowsRole(route, role) {
  const normalizedRole = normalizeString(role);
  if (normalizedRole === '') return false;

  const roleBoundRecords = routeAllowedRoles(route);
  if (roleBoundRecords.length === 0) return true;

  return roleBoundRecords.every((record) => normalizeStringList(record.meta?.roles).includes(normalizedRole));
}

export function routeAllowsRequiredPermissions(route, accessContext = {}) {
  const requiredPermissions = routeRequiredPermissions(route);
  if (requiredPermissions.length === 0 || accessContext.allPermissions === true) return true;

  const permissionSet = new Set(normalizeStringList(accessContext.permissions));
  return requiredPermissions.every((permission) => permissionSet.has(permission));
}

export function routeAllowsSessionAccess(route, session = {}) {
  const accessContext = moduleAccessContextFromSession(session);
  return routeAllowsRole(route, accessContext.role) && routeAllowsRequiredPermissions(route, accessContext);
}
