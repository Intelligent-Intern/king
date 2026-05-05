const DEFAULT_ICON = '/assets/orgas/kingrt/icons/users.png';

const GROUPS = {
  administration: {
    key: 'administration',
    to: '/admin/administration',
    label: 'Administration',
    icon: '/assets/orgas/kingrt/icons/gear.png',
    order: 20,
    roles: ['admin'],
  },
  governance: {
    key: 'governance',
    to: '/admin/governance',
    label: 'Governance',
    icon: '/assets/orgas/kingrt/icons/adminon.png',
    order: 30,
    roles: ['admin'],
  },
};

function normalizeString(value) {
  return String(value || '').trim();
}

function normalizeStringList(value) {
  if (!Array.isArray(value)) return [];
  return [...new Set(value.map(normalizeString).filter(Boolean))].sort();
}

function sortByOrderThenLabel(a, b) {
  const orderA = Number.isFinite(Number(a.order)) ? Number(a.order) : 0;
  const orderB = Number.isFinite(Number(b.order)) ? Number(b.order) : 0;
  if (orderA !== orderB) return orderA - orderB;
  return String(a.label || a.key || a.to || '').localeCompare(String(b.label || b.key || b.to || ''));
}

function routeChildPath(path) {
  return normalizeString(path).replace(/^\/+/, '');
}

function modulePermissionsByKey(registry) {
  const permissions = new Map();
  for (const descriptor of registry.list()) {
    permissions.set(descriptor.module_key, normalizeStringList(descriptor.permissions));
  }
  return permissions;
}

function accessContext(rawContext = {}) {
  const value = typeof rawContext === 'string' ? { role: rawContext } : (rawContext || {});
  const permissionInput = Array.isArray(value.permissions) ? value.permissions : value.permissionKeys;
  const moduleInput = Array.isArray(value.modules) ? value.modules : value.moduleKeys;

  return {
    role: normalizeString(value.role),
    permissions: new Set(normalizeStringList(permissionInput)),
    modules: new Set(normalizeStringList(moduleInput)),
    allPermissions: value.allPermissions === true || value.platformAdmin === true,
    enforcePermissions: Array.isArray(permissionInput),
    enforceModules: Array.isArray(moduleInput),
  };
}

function rolesAllow(entry, context) {
  const roles = normalizeStringList(entry.roles);
  return roles.length === 0 || (context.role !== '' && roles.includes(context.role));
}

function permissionsAllow(requiredPermissions, context) {
  if (context.allPermissions || !context.enforcePermissions || requiredPermissions.length === 0) return true;
  return requiredPermissions.every((permission) => context.permissions.has(permission));
}

function modulesAllow(moduleKey, context) {
  if (!context.enforceModules || moduleKey === '') return true;
  return context.modules.has(moduleKey);
}

export function entryRequiredPermissions(entry, modulePermissions = []) {
  const ownPermissions = normalizeStringList(entry.required_permissions);
  return ownPermissions.length > 0 ? ownPermissions : normalizeStringList(modulePermissions);
}

export function entryAllowsAccess(entry, contextInput = {}, modulePermissions = []) {
  const context = accessContext(contextInput);
  const moduleKey = normalizeString(entry.module_key);
  return (
    rolesAllow(entry, context)
    && modulesAllow(moduleKey, context)
    && permissionsAllow(entryRequiredPermissions(entry, modulePermissions), context)
  );
}

export function buildModuleRouteRecords(registry) {
  const modulePermissions = modulePermissionsByKey(registry);

  return registry.routes().map((route) => {
    const requiredPermissions = entryRequiredPermissions(route, modulePermissions.get(route.module_key));

    return {
      path: routeChildPath(route.path),
      name: route.name,
      component: route.loader,
      meta: {
        requiresAuth: true,
        roles: normalizeStringList(route.roles),
        pageTitle: route.pageTitle,
        entitySingular: route.entitySingular,
        entityPlural: route.entityPlural,
        module_key: route.module_key,
        source_path: route.source_path,
        required_permissions: requiredPermissions,
      },
    };
  });
}

export function buildWorkspaceNavigation(registry, contextInput = {}) {
  const modulePermissions = modulePermissionsByKey(registry);
  const grouped = new Map();
  const flat = [];

  for (const rawItem of registry.navigation()) {
    const requiredPermissions = entryRequiredPermissions(rawItem, modulePermissions.get(rawItem.module_key));
    const item = {
      key: rawItem.key || `${rawItem.module_key}:${rawItem.to}`,
      to: rawItem.to,
      label: rawItem.label,
      icon: rawItem.icon || DEFAULT_ICON,
      order: rawItem.order,
      roles: normalizeStringList(rawItem.roles),
      module_key: rawItem.module_key,
      required_permissions: requiredPermissions,
    };

    if (!entryAllowsAccess(item, contextInput, requiredPermissions)) continue;

    const groupKey = normalizeString(rawItem.group);
    if (!groupKey) {
      flat.push(item);
      continue;
    }

    if (!grouped.has(groupKey)) {
      const definition = GROUPS[groupKey] || {
        key: groupKey,
        to: `/${groupKey}`,
        label: groupKey,
        icon: DEFAULT_ICON,
        order: 100,
        roles: item.roles,
      };
      grouped.set(groupKey, { ...definition, children: [] });
    }
    grouped.get(groupKey).children.push(item);
  }

  const groups = [...grouped.values()]
    .map((group) => ({ ...group, children: [...group.children].sort(sortByOrderThenLabel) }))
    .filter((group) => entryAllowsAccess(group, contextInput) && group.children.length > 0);

  return [...flat.sort(sortByOrderThenLabel), ...groups.sort(sortByOrderThenLabel)];
}

export function buildSettingsPanels(registry, contextInput = {}) {
  const modulePermissions = modulePermissionsByKey(registry);

  return registry.settingsPanels()
    .map((panel) => ({
      ...panel,
      required_permissions: entryRequiredPermissions(panel, modulePermissions.get(panel.module_key)),
    }))
    .filter((panel) => entryAllowsAccess(panel, contextInput, panel.required_permissions))
    .sort(sortByOrderThenLabel);
}
