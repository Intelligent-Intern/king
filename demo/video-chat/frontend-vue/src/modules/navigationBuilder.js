import { compareLocalizedStrings } from '../support/localeCollation.js';

const DEFAULT_ICON = '/assets/orgas/kingrt/icons/users.png';

const GROUPS = {
  administration: {
    key: 'administration',
    to: '/admin/administration',
    label: 'Administration',
    label_key: 'navigation.administration',
    icon: '/assets/orgas/kingrt/icons/gear.png',
    order: 20,
    roles: ['admin'],
  },
  governance: {
    key: 'governance',
    to: '/admin/governance',
    label: 'Governance',
    label_key: 'navigation.governance',
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

function sortByOrderThenLabel(a, b, locale = 'en') {
  const orderA = Number.isFinite(Number(a.order)) ? Number(a.order) : 0;
  const orderB = Number.isFinite(Number(b.order)) ? Number(b.order) : 0;
  if (orderA !== orderB) return orderA - orderB;
  return compareLocalizedStrings(a.label || a.key || a.to || '', b.label || b.key || b.to || '', { locale });
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

const ACTION_KINDS = new Set(['create', 'edit', 'delete', 'import', 'export', 'configure', 'inspect', 'tour', 'custom']);

export function normalizeActionMetadata(action = {}, fallbackPermissions = []) {
  const key = normalizeString(action.key);
  if (key === '') return null;

  const rawKind = normalizeString(action.kind).toLowerCase();
  const kind = ACTION_KINDS.has(rawKind) ? rawKind : 'custom';

  return {
    key,
    label: normalizeString(action.label),
    label_key: normalizeString(action.label_key),
    icon: normalizeString(action.icon),
    kind,
    resource_type: normalizeString(action.resource_type),
    required_permissions: entryRequiredPermissions(action, fallbackPermissions),
    readonly_reason_key: normalizeString(action.readonly_reason_key),
    enabled_when: normalizeString(action.enabled_when),
  };
}

export function routeActionMetadata(route = {}, fallbackPermissions = []) {
  if (!Array.isArray(route.actions)) return [];
  return route.actions
    .map((action) => normalizeActionMetadata(action, fallbackPermissions))
    .filter(Boolean);
}

function normalizeTourMetadata(route = {}, actions = []) {
  const source = route.tour && typeof route.tour === 'object' ? route.tour : {};
  const tourAction = actions.find((action) => action.kind === 'tour') || null;
  const key = normalizeString(source.key || tourAction?.key);
  if (key === '') return null;

  const steps = Array.isArray(source.steps)
    ? source.steps
        .map((step, index) => {
          const stepSource = step && typeof step === 'object' ? step : {};
          return {
            key: normalizeString(stepSource.key) || `step-${index + 1}`,
            title: normalizeString(stepSource.title),
            title_key: normalizeString(stepSource.title_key),
            body: normalizeString(stepSource.body),
            body_key: normalizeString(stepSource.body_key),
          };
        })
        .filter((step) => step.title !== '' || step.title_key !== '' || step.body !== '' || step.body_key !== '')
    : [];

  return {
    key,
    title: normalizeString(source.title),
    title_key: normalizeString(source.title_key),
    badge_key: normalizeString(source.badge_key),
    steps,
  };
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
    const actions = routeActionMetadata(route, requiredPermissions);

    return {
      path: routeChildPath(route.path),
      name: route.name,
      component: route.loader,
      meta: {
        requiresAuth: true,
        roles: normalizeStringList(route.roles),
        pageTitle: route.pageTitle,
        pageTitle_key: route.pageTitle_key || '',
        entitySingular: route.entitySingular,
        entitySingular_key: route.entitySingular_key || '',
        entityPlural: route.entityPlural,
        entityPlural_key: route.entityPlural_key || '',
        module_key: route.module_key,
        source_path: route.source_path,
        required_permissions: requiredPermissions,
        i18nNamespaces: normalizeStringList(route.i18n_namespaces),
        actions,
        tour: normalizeTourMetadata(route, actions),
        readonly_reason_key: normalizeString(route.readonly_reason_key),
      },
    };
  });
}

export function buildWorkspaceNavigation(registry, contextInput = {}) {
  const modulePermissions = modulePermissionsByKey(registry);
  const sortLocale = normalizeString(contextInput.locale);
  const grouped = new Map();
  const flat = [];

  for (const rawItem of registry.navigation()) {
    const requiredPermissions = entryRequiredPermissions(rawItem, modulePermissions.get(rawItem.module_key));
    const item = {
      key: rawItem.key || `${rawItem.module_key}:${rawItem.to}`,
      to: rawItem.to,
      label: rawItem.label,
      label_key: rawItem.label_key || '',
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
        label_key: '',
        icon: DEFAULT_ICON,
        order: 100,
        roles: item.roles,
      };
      grouped.set(groupKey, { ...definition, label_key: definition.label_key || '', children: [] });
    }
    grouped.get(groupKey).children.push(item);
  }

  const groups = [...grouped.values()]
    .map((group) => ({ ...group, children: [...group.children].sort((a, b) => sortByOrderThenLabel(a, b, sortLocale)) }))
    .filter((group) => entryAllowsAccess(group, contextInput) && group.children.length > 0);

  return [
    ...flat.sort((a, b) => sortByOrderThenLabel(a, b, sortLocale)),
    ...groups.sort((a, b) => sortByOrderThenLabel(a, b, sortLocale)),
  ];
}

export function buildSettingsPanels(registry, contextInput = {}) {
  const modulePermissions = modulePermissionsByKey(registry);

  return registry.settingsPanels()
    .map((panel) => ({
      ...panel,
      label_key: panel.label_key || '',
      required_permissions: entryRequiredPermissions(panel, modulePermissions.get(panel.module_key)),
    }))
    .filter((panel) => entryAllowsAccess(panel, contextInput, panel.required_permissions))
    .sort((a, b) => sortByOrderThenLabel(a, b, normalizeString(contextInput.locale)));
}
