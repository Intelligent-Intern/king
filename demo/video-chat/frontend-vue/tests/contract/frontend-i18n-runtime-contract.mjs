import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '../..');

const server = await createServer({
  root,
  logLevel: 'error',
  server: { middlewareMode: true, hmr: false },
});

try {
  const {
    applyI18nResourcePayload,
    escapeTranslationValue,
    i18nState,
    interpolateTranslation,
    mergeI18nMessages,
    normalizeI18nNamespaces,
    t,
  } = await server.ssrLoadModule('/src/modules/localization/i18nRuntime.js');

  assert.deepEqual(normalizeI18nNamespaces(['settings', 'common', 'settings', '../bad']), ['common', 'settings']);
  assert.equal(escapeTranslationValue('<b>&"x\''), '&lt;b&gt;&amp;&quot;x&#39;');
  assert.equal(interpolateTranslation('Hello {name}', { name: '<Alex>' }), 'Hello &lt;Alex&gt;');
  assert.deepEqual(
    mergeI18nMessages({ 'common.save': 'Save', 'common.cancel': 'Cancel' }, { 'common.save': 'Speichern' }),
    { 'common.save': 'Speichern', 'common.cancel': 'Cancel' },
  );

  applyI18nResourcePayload({
    locale: 'de',
    direction: 'ltr',
    namespaces: ['common'],
    resources: {
      'common.save': 'Speichern',
      'common.hello': 'Hallo {name}',
    },
    fallback_resources: {
      'common.save': 'Save',
      'common.cancel': 'Cancel',
      'common.hello': 'Hello {name}',
    },
  });

  assert.equal(i18nState.locale, 'de');
  assert.equal(i18nState.direction, 'ltr');
  assert.equal(t('common.save'), 'Speichern');
  assert.equal(t('common.cancel'), 'Cancel');
  assert.equal(t('common.hello', { name: '<Mila>' }), 'Hallo &lt;Mila&gt;');
  assert.equal(t('common.unknown_key'), 'common.unknown_key');
  assert.ok(i18nState.missingKeys['common.cancel'] >= 1, 'fallback key should be tracked as missing for the active locale');
  assert.ok(i18nState.missingKeys['common.unknown_key'] >= 1, 'unknown key should be tracked in dev/test');

  applyI18nResourcePayload({
    locale: 'ar',
    direction: 'rtl',
    namespaces: ['common'],
    resources: {},
    fallback_resources: {
      'common.save': 'Save',
    },
  });
  assert.equal(i18nState.locale, 'ar');
  assert.equal(i18nState.direction, 'rtl');
  assert.equal(t('common.save'), 'Save');
} finally {
  await server.close();
}

const runtimeSource = await readFile(path.join(root, 'src/modules/localization/i18nRuntime.js'), 'utf8');
const routerSource = await readFile(path.join(root, 'src/http/router.ts'), 'utf8');
const appSource = await readFile(path.join(root, 'src/App.vue'), 'utf8');
const shellSource = await readFile(path.join(root, 'src/layouts/WorkspaceShell.vue'), 'utf8');
assert.match(runtimeSource, /\/api\/localization\/resources/, 'runtime must load backend translation resources');
assert.match(runtimeSource, /document\.documentElement\.lang/, 'runtime must apply document language');
assert.match(runtimeSource, /document\.documentElement\.dir/, 'runtime must apply document direction');
assert.match(runtimeSource, /fallback_resources/, 'runtime must consume backend fallback resources');
assert.match(routerSource, /await ensureI18nResources/, 'router must load i18n resources before protected views render');
assert.match(appSource, /syncI18nDocumentState\(sessionState\.locale, sessionState\.direction\)/, 'app shell must keep document lang and dir in sync');
assert.match(shellSource, /ensureI18nResources\(\{ locale: savedLanguage, force: true \}\)/, 'settings save must refresh runtime translations');
assert.doesNotMatch(shellSource, /ii_videocall_v1_workspace_language/, 'settings language must not persist a separate localStorage locale');

console.log('[frontend-i18n-runtime-contract] PASS');
