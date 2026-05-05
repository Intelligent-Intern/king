import administration from './administration/descriptor.js';
import governance from './governance/descriptor.js';
import localization from './localization/descriptor.js';
import marketplace from './marketplace/descriptor.js';
import themeEditor from './theme_editor/descriptor.js';
import users from './users/descriptor.js';
import workspaceSettings from './workspace_settings/descriptor.js';
import { createModuleRegistry } from './moduleRegistry.js';
import { buildModuleRouteRecords } from './navigationBuilder.js';

export const workspaceModuleDescriptors = [
  administration,
  governance,
  localization,
  marketplace,
  themeEditor,
  users,
  workspaceSettings,
];

export const workspaceModuleRegistry = createModuleRegistry(workspaceModuleDescriptors);
export const workspaceModuleRouteRecords = buildModuleRouteRecords(workspaceModuleRegistry);

export { createModuleRegistry, normalizeModuleDescriptor } from './moduleRegistry.js';
