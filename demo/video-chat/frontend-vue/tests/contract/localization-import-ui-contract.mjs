import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);
const source = await readFile(path.join(root, 'src/modules/localization/pages/AdministrationLocalizationView.vue'), 'utf8');

assert.match(source, /sessionState\.sessionToken/, 'localization editor must authenticate resource writes with the current session');
assert.match(source, /\/api\/admin\/localization\/locales/, 'supported locale catalog endpoint must be wired');
assert.match(source, /\/api\/localization\/resources\?locale=/, 'resource editor must load current locale resources');
assert.match(source, /\/api\/admin\/localization\/resources/, 'resource editor save endpoint must be wired');
assert.match(source, /method:\s*'PUT'/, 'resource editor must save resources through the write endpoint');
assert.match(source, /ENGLISH_MESSAGES/, 'resource editor must use English messages as the complete default key set');
assert.match(source, /editorLeftLocale = ref\('en'\)/, 'resource editor must default the left comparison pane to English');
assert.match(source, /const editorRightLocale = ref\('de'\)/, 'resource editor must keep a non-English comparison pane available');
assert.match(source, /editorOriginalValues/, 'resource editor must only submit changed translations');
assert.doesNotMatch(source, /\/api\/admin\/localization\/imports\/preview/, 'retired CSV preview endpoint must not be wired in the frontend');
assert.doesNotMatch(source, /\/api\/admin\/localization\/imports\/commit/, 'retired CSV commit endpoint must not be wired in the frontend');
assert.doesNotMatch(source, /FileReader/, 'frontend localization editor must not depend on client CSV upload parsing');

console.log('[localization-import-ui-contract] PASS');
