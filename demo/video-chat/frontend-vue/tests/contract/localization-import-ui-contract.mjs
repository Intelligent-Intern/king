import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);
const source = await readFile(path.join(root, 'src/modules/localization/pages/AdministrationLocalizationView.vue'), 'utf8');

assert.match(source, /sessionState\.userId/, 'localization import UI must gate writes on the current user id');
assert.match(source, /Number\(sessionState\.userId \|\| 0\) === 1/, 'CSV import controls must be superadmin-only');
assert.match(source, /\/api\/admin\/localization\/imports\/preview/, 'CSV preview endpoint must be wired');
assert.match(source, /\/api\/admin\/localization\/imports\/commit/, 'CSV commit endpoint must be wired');
assert.match(source, /\/api\/admin\/localization\/bundles/, 'translation bundle list endpoint must be wired');
assert.match(source, /\/api\/admin\/localization\/imports/, 'import history endpoint must be wired');
assert.match(source, /FileReader/, 'CSV upload must read client file content before preview');
assert.match(source, /canCommitCsv/, 'CSV commit must depend on a clean preview state');
assert.match(source, /preview\.value = payload\.result\?\.preview/, 'preview response must drive the preview state');

console.log('[localization-import-ui-contract] PASS');
