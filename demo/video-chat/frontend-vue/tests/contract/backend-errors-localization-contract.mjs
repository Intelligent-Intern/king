import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import { ENGLISH_MESSAGES } from '../../src/modules/localization/englishMessages.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(__dirname, '../../../../..');
const root = path.resolve(__dirname, '../..');

const server = await createServer({
  root,
  logLevel: 'error',
  server: { middlewareMode: true, hmr: false },
});

try {
  const { applyI18nResourcePayload } = await server.ssrLoadModule('/src/modules/localization/i18nRuntime.js');
  const {
    API_ERROR_MESSAGE_KEYS,
    apiErrorCode,
    apiErrorMessageKey,
    buildLocalizedApiError,
    localizedApiErrorMessage,
  } = await server.ssrLoadModule('/src/modules/localization/apiErrorMessages.js');

  const backendErrorModulePaths = [
    'demo/video-chat/backend-king-php/http/module_localization.php',
    'demo/video-chat/backend-king-php/http/module_marketplace.php',
    'demo/video-chat/backend-king-php/http/module_users.php',
    'demo/video-chat/backend-king-php/http/module_users_admin_accounts.php',
    'demo/video-chat/backend-king-php/http/module_workspace_administration.php',
  ];
  const backendSources = await Promise.all(
    backendErrorModulePaths.map((relativePath) => readFile(path.join(repoRoot, relativePath), 'utf8')),
  );
  const localizationAdminView = await readFile(
    path.join(root, 'src/modules/localization/pages/AdministrationLocalizationView.vue'),
    'utf8',
  );
  const marketplaceApiSource = await readFile(
    path.join(root, 'src/modules/marketplace/pages/adminMarketplaceApi.js'),
    'utf8',
  );
  const usersApiSource = await readFile(
    path.join(root, 'src/modules/users/pages/admin/api.js'),
    'utf8',
  );
  const workspaceAdministrationApiSource = await readFile(
    path.join(root, 'src/domain/workspace/administrationApi.js'),
    'utf8',
  );

  const backendCodes = new Set(
    backendSources.flatMap((source) => [...source.matchAll(/\$errorResponse\([^,\n]+,\s*'([^']+)'/g)])
      .map((match) => match[1]),
  );
  assert.ok(backendCodes.size > 0, 'admin endpoint error code scan should find stable codes');

  for (const code of backendCodes) {
    const key = apiErrorMessageKey(code);
    assert.ok(key, `missing localized frontend error mapping for ${code}`);
    assert.ok(
      Object.prototype.hasOwnProperty.call(ENGLISH_MESSAGES, key),
      `missing English fallback for ${key}`,
    );
  }
  for (const code of ['localization_import_validation_failed', 'localization_superadmin_required']) {
    assert.ok(API_ERROR_MESSAGE_KEYS[code], `localization code ${code} must keep a precise frontend mapping`);
  }

  applyI18nResourcePayload({
    locale: 'de',
    direction: 'ltr',
    namespaces: ['errors'],
    resources: {
      'errors.api.localization_import_validation_failed': 'CSV-Import hat Validierungsfehler.',
      'errors.api.validation_failed': 'Die gesendeten Daten sind ungueltig.',
    },
    fallback_resources: ENGLISH_MESSAGES,
  });

  const validationPayload = {
    error: {
      code: 'localization_import_validation_failed',
      message: 'Backend English message must not drive UI text.',
    },
  };
  assert.equal(apiErrorCode(validationPayload), 'localization_import_validation_failed');
  assert.equal(
    localizedApiErrorMessage(validationPayload, 'Fallback text'),
    'CSV-Import hat Validierungsfehler.',
    'known API error codes must resolve through the active frontend locale',
  );
  assert.equal(apiErrorMessageKey('admin_user_validation_failed'), 'errors.api.validation_failed');
  const genericError = buildLocalizedApiError(
    { error: { code: 'admin_user_validation_failed', message: 'Backend English validation text.' } },
    'Fallback text',
    422,
  );
  assert.equal(genericError.message, 'Die gesendeten Daten sind ungueltig.');
  assert.equal(genericError.apiErrorCode, 'admin_user_validation_failed');
  assert.equal(genericError.responseStatus, 422);
  assert.equal(
    localizedApiErrorMessage({ error: { code: 'unknown_error', message: 'Raw backend text' } }, 'Fallback text'),
    'Fallback text',
    'unknown API error codes must use the caller fallback instead of raw backend message text',
  );
  assert.doesNotMatch(
    localizationAdminView,
    /payload\?\.error\?\.message/,
    'localization admin UI must not display backend English error messages directly',
  );
  assert.match(
    localizationAdminView,
    /buildLocalizedApiError\(payload,\s*fallback,\s*response\.status\)/,
    'localization admin UI must resolve API errors through stable codes',
  );
  for (const [label, source] of [
    ['marketplace admin API', marketplaceApiSource],
    ['users admin API', usersApiSource],
    ['workspace administration API', workspaceAdministrationApiSource],
  ]) {
    assert.doesNotMatch(source, /payload\?\.error\?\.message/, `${label} must not display backend English error messages directly`);
    assert.match(source, /buildLocalizedApiError\(payload,\s*`Request failed/, `${label} must resolve API errors through stable codes`);
  }
} finally {
  await server.close();
}

console.log('[backend-errors-localization-contract] PASS');
