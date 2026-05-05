function normalizeString(value) {
  return String(value || '').trim();
}

function titleFromKey(key) {
  return normalizeString(key)
    .replace(/[._-]+/g, ' ')
    .replace(/\s+/g, ' ')
    .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function moduleRows(registry) {
  return registry.list().map((descriptor) => ({
    entity_key: 'modules',
    id: `module:${descriptor.module_key}`,
    name: titleFromKey(descriptor.module_key),
    key: descriptor.module_key,
    status: 'active',
    description: '',
    description_key: 'governance.catalog.module_description',
    description_params: {
      routes: descriptor.routes.length,
      navigation: descriptor.navigation.length,
      settings: descriptor.settings_panels.length,
      grant_targets: descriptor.access.grant_targets.join(', '),
      permissions: descriptor.permissions.length > 0 ? descriptor.permissions.join(', ') : '',
      permissions_key: descriptor.permissions.length > 0 ? '' : 'governance.catalog.no_permissions',
      time_limited_key: descriptor.access.supports_time_limited_grants
        ? 'governance.catalog.time_limited_supported'
        : 'governance.catalog.time_limited_not_supported',
    },
    updatedAt: '',
    readonly: true,
  }));
}

function permissionRows(registry) {
  return registry.list().flatMap((descriptor) => (
    descriptor.permissions.map((permission) => ({
      entity_key: 'permissions',
      id: `permission:${descriptor.module_key}:${permission}`,
      name: permission,
      key: permission,
      status: 'active',
      description: '',
      description_key: 'governance.catalog.permission_description',
      description_params: {
        module: descriptor.module_key,
      },
      updatedAt: '',
      readonly: true,
    }))
  ));
}

export function buildGovernanceCatalogRows(registry, scope) {
  const normalizedScope = normalizeString(scope).toLowerCase();
  if (normalizedScope.includes('modules')) {
    return moduleRows(registry);
  }
  if (normalizedScope.includes('permissions')) {
    return permissionRows(registry);
  }
  return [];
}
