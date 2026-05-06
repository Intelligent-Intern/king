import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);
const storeSource = await readFile(path.join(root, 'src/stores/callAppsCatalogStore.js'), 'utf8');
const composableSource = await readFile(path.join(root, 'src/domain/realtime/callApps/useCallAppsCatalog.js'), 'utf8');
const workspaceSource = await readFile(path.join(root, 'src/domain/realtime/CallWorkspaceView.vue'), 'utf8');

assert.match(
  storeSource,
  /defineStore\(['"]callAppsCatalog['"]/,
  'Call App availability must use a dedicated Pinia store for sidebar state',
);

assert.match(
  storeSource,
  /\/api\/calls\/\$\{encodeURIComponent\(normalizedCallId\)\}\/call-apps\/available/,
  'Call App sidebar catalog must load the backend call availability endpoint',
);

assert.match(
  storeSource,
  /availability\?\.installed\s*===\s*true[\s\S]*availability\?\.healthy\s*===\s*true[\s\S]*installation\?\.status\s*===\s*['"]enabled['"][\s\S]*health_status\s*===\s*['"]healthy['"]/,
  'Call App sidebar catalog must expose only installed, enabled, healthy apps',
);

assert.doesNotMatch(
  storeSource,
  /whiteboard/,
  'Call App sidebar catalog must not hard-code the initial whiteboard app',
);

assert.match(
  composableSource,
  /useCallAppsCatalogStore/,
  'Call App availability composable must wrap the dedicated store',
);

assert.doesNotMatch(
  workspaceSource,
  /useCallAppsCatalogStore|callAppsCatalogStore/,
  'CAP-06 must not add Call App catalog implementation weight to CallWorkspaceView.vue',
);

console.log('[call-app-availability-frontend-contract] PASS');
