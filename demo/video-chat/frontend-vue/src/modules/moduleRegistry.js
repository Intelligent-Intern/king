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
    routes: Array.isArray(source.routes) ? [...source.routes] : [],
    navigation: Array.isArray(source.navigation) ? [...source.navigation] : [],
    settings_panels: Array.isArray(source.settings_panels) ? [...source.settings_panels] : [],
    i18n_namespaces: Array.isArray(source.i18n_namespaces) ? [...source.i18n_namespaces] : [],
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
        descriptor.routes.map((route) => ({ ...route, module_key: descriptor.module_key }))
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
