function normalizeString(value) {
  return String(value || '').trim();
}

function titleFromKey(key) {
  return normalizeString(key)
    .replace(/[._-]+/g, ' ')
    .replace(/\s+/g, ' ')
    .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function moduleDescription(descriptor) {
  const parts = [
    `${descriptor.routes.length} routes`,
    `${descriptor.navigation.length} navigation entries`,
    `${descriptor.settings_panels.length} settings panels`,
  ];
  if (descriptor.permissions.length > 0) {
    parts.push(`permissions: ${descriptor.permissions.join(', ')}`);
  }
  return parts.join('; ');
}

function moduleRows(registry) {
  return registry.list().map((descriptor) => ({
    id: `module:${descriptor.module_key}`,
    name: titleFromKey(descriptor.module_key),
    key: descriptor.module_key,
    status: 'active',
    description: moduleDescription(descriptor),
    updatedAt: '',
    readonly: true,
  }));
}

function permissionRows(registry) {
  return registry.list().flatMap((descriptor) => (
    descriptor.permissions.map((permission) => ({
      id: `permission:${descriptor.module_key}:${permission}`,
      name: permission,
      key: permission,
      status: 'active',
      description: `Module: ${descriptor.module_key}`,
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
