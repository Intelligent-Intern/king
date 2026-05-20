import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);
const repoRoot = path.resolve(root, '../../..');

async function read(relativePath) {
  return readFile(path.join(repoRoot, relativePath), 'utf8');
}

const [
  callsViewSource,
  callsTemplateSource,
  listTableSource,
  routerSource,
  reactivateModuleSource,
  reactivateDomainSource,
  messagesSource,
] = await Promise.all([
  read('demo/video-chat/frontend-vue/src/domain/calls/admin/CallsView.vue'),
  read('demo/video-chat/frontend-vue/src/domain/calls/admin/CallsView.template.html'),
  read('demo/video-chat/frontend-vue/src/domain/calls/components/ListTable.vue'),
  read('demo/video-chat/backend-king-php/http/router.php'),
  read('demo/video-chat/backend-king-php/http/module_calls_reactivate.php'),
  read('demo/video-chat/backend-king-php/domain/calls/call_management_reactivate.php'),
  read('demo/video-chat/frontend-vue/src/modules/localization/englishMessages.js'),
]);

assert.match(
  callsViewSource,
  /const canReactivateCalls = computed\(\(\) => Number\(sessionState\.userId \|\| 0\) === 1 && String\(sessionState\.role \|\| ''\)\.toLowerCase\(\) === 'admin'\)/,
  'admin call reactivation UI must be gated to primary admin user #1',
);

assert.match(
  callsViewSource,
  /function isReactivatable\(call\)[\s\S]*terminalCallStatus\(call\)/,
  'reactivate action must only appear for terminal calls',
);

assert.match(
  callsViewSource,
  /apiRequest\(`\/api\/calls\/\$\{encodeURIComponent\(callId\)\}\/reactivate`,[\s\S]*method: 'POST'[\s\S]*confirm: 'reactivate_call'/,
  'reactivate action must call the dedicated POST endpoint with explicit confirmation',
);

assert.match(
  callsTemplateSource,
  /:is-reactivatable="isReactivatable"[\s\S]*:is-reactivate-pending="isReactivatePending"[\s\S]*@reactivate-call="reactivateCall"/,
  'admin calls table must receive reactivation predicates and event handler',
);

assert.match(
  listTableSource,
  /v-if="adminMode && isReactivatable\(call\)"[\s\S]*t\('calls\.actions\.reactivate'\)[\s\S]*\$emit\('reactivate-call', call\)/,
  'calls list must render a dedicated reactivate icon button for eligible terminal calls',
);

assert.match(
  listTableSource,
  /defineEmits\(\[[\s\S]*'reactivate-call'[\s\S]*\]\)/,
  'calls list must expose a reactivate-call event',
);

assert.match(
  routerSource,
  /module_calls_reactivate\.php[\s\S]*'call_reactivate'[\s\S]*videochat_handle_call_reactivate_routes/,
  'backend router must wire the reactivation module before the generic calls module',
);

assert.match(
  reactivateModuleSource,
  /\/api\/calls\/\(\[A-Za-z0-9\._-\]\{1,200\}\)\/reactivate[\s\S]*Use POST for \/api\/calls\/\{id\}\/reactivate[\s\S]*videochat_reactivate_call/,
  'reactivation module must expose POST /api/calls/{id}/reactivate',
);

assert.match(
  reactivateDomainSource,
  /\$authUserId !== 1[\s\S]*videochat_user_has_system_admin_call_rights[\s\S]*confirm' => 'must_equal_reactivate_call'/,
  'reactivation domain contract must restrict authority to primary admin #1 and require explicit confirmation',
);

assert.match(
  reactivateDomainSource,
  /UPDATE calls[\s\S]*SET status = 'active'[\s\S]*cancelled_at = NULL[\s\S]*cancel_reason = NULL[\s\S]*cancel_message = NULL/,
  'reactivation must clear terminal cancellation metadata and move the call back to active',
);

assert.match(
  reactivateDomainSource,
  /event_type' => 'call_reactivated'[\s\S]*raw_access_identifier_logged' => false[\s\S]*raw_session_identifier_logged' => false/,
  'reactivation must record a redacted audit event',
);

assert.match(messagesSource, /'calls\.actions\.reactivate': 'Reactivate'/, 'reactivate action label must be localized');
assert.match(messagesSource, /'calls\.reactivate\.reactivated_notice': 'Call reactivated\.'/, 'reactivate success notice must be localized');

process.stdout.write('[call-reactivate-admin-button-contract] PASS\n');
