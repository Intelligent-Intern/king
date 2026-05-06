import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);
const packageJson = JSON.parse(await readFile(path.join(root, 'package.json'), 'utf8'));
const packageLock = JSON.parse(await readFile(path.join(root, 'package-lock.json'), 'utf8'));
const mainSource = await readFile(path.join(root, 'src/main.ts'), 'utf8');

assert.match(
  packageJson.dependencies?.pinia || '',
  /^\^?3\./,
  'frontend dependencies must include Pinia 3 for descriptor runtime stores',
);

assert.ok(
  packageLock.packages?.['node_modules/pinia'],
  'package-lock must pin the installed Pinia package',
);

assert.match(
  mainSource,
  /import\s+\{\s*createPinia\s*\}\s+from\s+['"]pinia['"]/,
  'Vue entry must import createPinia',
);

assert.match(
  mainSource,
  /app\.use\(createPinia\(\)\);[\s\S]*app\.use\(router\);/,
  'Pinia must be installed before the router so route/UI modules can use stores',
);

console.log('[pinia-installation-contract] PASS');
