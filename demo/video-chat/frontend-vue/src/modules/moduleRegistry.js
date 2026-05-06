export function normalizeModuleDescriptor(descriptor) {
  const source = descriptor && typeof descriptor === 'object' ? descriptor : {};
  const moduleKey = String(source.module_key || '').trim();
  const version = String(source.version || '').trim();

  if (!moduleKey || !version) {
    throw new Error('Module descriptors require module_key and version.');
  }

  return Object.freeze({
    module_key: moduleKey,
    version,
    permissions: Array.isArray(source.permissions) ? [...source.permissions] : [],
    access: normalizeAccessMetadata(source.access),
    routes: Array.isArray(source.routes) ? [...source.routes] : [],
    navigation: Array.isArray(source.navigation) ? [...source.navigation] : [],
    settings_panels: Array.isArray(source.settings_panels) ? [...source.settings_panels] : [],
    i18n_namespaces: Array.isArray(source.i18n_namespaces) ? [...source.i18n_namespaces] : [],
  });
}

function normalizeAccessMetadata(access) {
  const source = access && typeof access === 'object' ? access : {};
  const grantTargets = Array.isArray(source.grant_targets)
    ? source.grant_targets.map((target) => String(target || '').trim()).filter(Boolean)
    : ['organization', 'group', 'user'];

  return Object.freeze({
    grant_targets: [...new Set(grantTargets)].sort(),
    supports_time_limited_grants: source.supports_time_limited_grants !== false,
    default_expires_at: typeof source.default_expires_at === 'string' ? source.default_expires_at.trim() : null,
  });
}

export function createModuleRegistry(descriptors = []) {
  const modules = descriptors.map(normalizeModuleDescriptor);
  const byKey = new Map();

  for (const descriptor of modules) {
    if (byKey.has(descriptor.module_key)) {
      throw new Error(`Duplicate module descriptor: ${descriptor.module_key}`);
    }
    byKey.set(descriptor.module_key, descriptor);
  }

  return Object.freeze({
    list() {
      return [...modules];
    },
    get(moduleKey) {
      return byKey.get(String(moduleKey || '').trim()) || null;
    },
    routes() {
      return modules.flatMap((descriptor) => (
        descriptor.routes.map((route) => ({
          ...route,
          module_key: descriptor.module_key,
          i18n_namespaces: [...descriptor.i18n_namespaces],
        }))
      ));
    },
    navigation() {
      return modules.flatMap((descriptor) => (
        descriptor.navigation.map((item) => ({ ...item, module_key: descriptor.module_key }))
      ));
    },
    settingsPanels() {
      return modules.flatMap((descriptor) => (
        descriptor.settings_panels.map((panel) => ({ ...panel, module_key: descriptor.module_key }))
      ));
    },
    i18nNamespaces() {
      return [...new Set(modules.flatMap((descriptor) => descriptor.i18n_namespaces))].sort();
    },
  });
}
