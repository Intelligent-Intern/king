function normalizeString(value) {
  return String(value || '').trim();
}

function titleFromKey(key) {
  return normalizeString(key)
    .replace(/[._-]+/g, ' ')
    .replace(/\s+/g, ' ')
    .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function moduleNameMetadata(descriptor) {
  const nameKey = normalizeString(descriptor.catalog?.name_key);
  return {
    key: nameKey,
    fallback: normalizeString(descriptor.catalog?.name) || titleFromKey(descriptor.module_key),
  };
}

function moduleRows(registry) {
  return registry.list().map((descriptor) => {
    const moduleName = moduleNameMetadata(descriptor);
    return {
      entity_key: 'modules',
      id: `module:${descriptor.module_key}`,
      name: moduleName.fallback,
      name_key: moduleName.key,
      key: descriptor.module_key,
      status: 'active',
      description: '',
      preview_kind: normalizeString(descriptor.catalog?.preview_kind) || descriptor.module_key,
      screenshot_path: normalizeString(descriptor.catalog?.screenshot_path),
      route_count: descriptor.routes.length,
      navigation_count: descriptor.navigation.length,
      settings_count: descriptor.settings_panels.length,
      updatedAt: '',
      readonly: true,
    };
  });
}

function permissionRows(registry) {
  return registry.list().flatMap((descriptor) => (
    descriptor.permissions.map((permission) => {
      const moduleName = moduleNameMetadata(descriptor);
      return {
        entity_key: 'permissions',
        id: `permission:${descriptor.module_key}:${permission}`,
        name: permission,
        key: permission,
        module_key: descriptor.module_key,
        module_name: moduleName.fallback,
        module_name_key: moduleName.key,
        status: 'active',
        description: '',
        description_key: 'governance.catalog.permission_description',
        description_params: moduleName.key !== '' ? { module_key: moduleName.key } : { module: moduleName.fallback },
        updatedAt: '',
        readonly: true,
      };
    })
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
