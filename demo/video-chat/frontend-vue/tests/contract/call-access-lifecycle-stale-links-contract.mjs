import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function requireMatch(source, pattern, message) {
  assert.match(source, pattern, message);
}

const joinView = read('demo/video-chat/frontend-vue/src/domain/calls/access/JoinView.vue');
const apiErrorMessages = read('demo/video-chat/frontend-vue/src/modules/localization/apiErrorMessages.js');
const englishMessages = read('demo/video-chat/frontend-vue/src/modules/localization/englishMessages.js');
const publicMessages = read('demo/video-chat/frontend-vue/src/modules/localization/publicMessages.js');

const staleLifecycleCases = [
  {
    transition: 'rescheduled',
    status: 404,
    code: 'call_access_not_found',
    messageKey: 'errors.api.call_access_not_found',
  },
  {
    transition: 'deleted',
    status: 404,
    code: 'call_access_not_found',
    messageKey: 'errors.api.call_access_not_found',
  },
  {
    transition: 'ended',
    status: 409,
    code: 'call_access_conflict',
    messageKey: 'errors.api.call_access_conflict',
  },
];

for (const lifecycleCase of staleLifecycleCases) {
  assert.ok(
    [404, 409].includes(lifecycleCase.status),
    `${lifecycleCase.transition} stale links must stay in closed HTTP states`,
  );
  requireMatch(
    apiErrorMessages,
    new RegExp(`${lifecycleCase.code}:\\s*'${lifecycleCase.messageKey}'`),
    `${lifecycleCase.transition} stale link code must map to a fixed localized message`,
  );
  requireMatch(
    englishMessages,
    new RegExp(`'${lifecycleCase.messageKey}':\\s*'[^']+'`),
    `${lifecycleCase.transition} stale link fixed message must be translated`,
  );
}

requireMatch(
  publicMessages,
  /'public\.join\.access_invalid': 'Call access id is invalid\.'/,
  'invalid access fallback must stay generic',
);
requireMatch(
  joinView,
  /function showSafeInvalidAccessState\(\)[\s\S]*resetJoinContextDetails\(\);[\s\S]*state\.contextError = safeCallAccessInvalidMessage\(t\);/,
  'join view must clear resolved call details before showing a generic invalid-access state',
);
requireMatch(
  joinView,
  /if \(!response\.ok \|\| !payload \|\| payload\.status !== 'ok'\) \{[\s\S]*resetJoinContextDetails\(\);[\s\S]*state\.contextError = localizedApiErrorMessage\(payload, t\('public\.join\.resolve_failed'\)\);[\s\S]*return;[\s\S]*\}[\s\S]*const call = payload\?\.result\?\.call \|\| \{\};/,
  'join context failures must not hydrate call details from stale lifecycle error payloads',
);
requireMatch(
  apiErrorMessages,
  /export function localizedApiErrorMessage\(payload, fallback = ''\)[\s\S]*const code = apiErrorCode\(payload\);[\s\S]*const key = apiErrorMessageKey\(code\);[\s\S]*return t\(key\);/,
  'localized API errors must be selected by code instead of backend-provided error text',
);
assert.doesNotMatch(
  apiErrorMessages,
  /error\?\.message|payload\?\.error\?\.message|payload\.error\.message/,
  'localized API errors must not render backend error.message fields that can contain private call data',
);

const contextErrorIndex = joinView.indexOf('v-else-if="state.contextError"');
const readyTemplateIndex = joinView.indexOf('<template v-else>');
const joinButtonIndex = joinView.indexOf("t('public.join.join_call')");
assert.ok(contextErrorIndex > -1, 'join view must have an explicit context error branch');
assert.ok(readyTemplateIndex > contextErrorIndex, 'ready join controls must render after the context error branch');
assert.ok(joinButtonIndex > readyTemplateIndex, 'Join call button must only render in the resolved context branch');

process.stdout.write('[call-access-lifecycle-stale-links-contract] PASS\n');
