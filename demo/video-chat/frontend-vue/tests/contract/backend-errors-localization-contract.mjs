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
    localizedApiErrorMessage,
  } = await server.ssrLoadModule('/src/modules/localization/apiErrorMessages.js');

  const localizationEndpoint = await readFile(
    path.join(repoRoot, 'demo/video-chat/backend-king-php/http/module_localization.php'),
    'utf8',
  );
  const localizationAdminView = await readFile(
    path.join(root, 'src/modules/localization/pages/AdministrationLocalizationView.vue'),
    'utf8',
  );

  const backendCodes = new Set(
    [...localizationEndpoint.matchAll(/\$errorResponse\([^,\n]+,\s*'([^']+)'/g)]
      .map((match) => match[1]),
  );
  assert.ok(backendCodes.size > 0, 'localization endpoint error code scan should find stable codes');

  for (const code of backendCodes) {
    assert.ok(API_ERROR_MESSAGE_KEYS[code], `missing localized frontend error mapping for ${code}`);
    assert.ok(
      Object.prototype.hasOwnProperty.call(ENGLISH_MESSAGES, API_ERROR_MESSAGE_KEYS[code]),
      `missing English fallback for ${API_ERROR_MESSAGE_KEYS[code]}`,
    );
  }

  applyI18nResourcePayload({
    locale: 'de',
    direction: 'ltr',
    namespaces: ['errors'],
    resources: {
      'errors.api.localization_import_validation_failed': 'CSV-Import hat Validierungsfehler.',
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
    /localizedApiErrorMessage\(payload,\s*fallback\)/,
    'localization admin UI must resolve API errors through stable codes',
  );
} finally {
  await server.close();
}

console.log('[backend-errors-localization-contract] PASS');
