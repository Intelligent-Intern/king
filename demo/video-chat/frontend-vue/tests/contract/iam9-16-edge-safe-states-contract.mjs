import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

const accountConfirmation = read('demo/video-chat/backend-king-php/domain/calls/call_access_account_confirmation.php');
const accountConfirmationRoute = read('demo/video-chat/backend-king-php/http/module_calls_access.php');
const emailConfirmationContract = read('demo/video-chat/backend-king-php/tests/call-access-email-confirmation-contract.php');
const websocketCommands = read('demo/video-chat/backend-king-php/http/module_realtime_websocket_commands.php');
const websocketLobby = read('demo/video-chat/backend-king-php/http/module_realtime_websocket_lobby.php');
const lobbyConcurrencyContract = read('demo/video-chat/backend-king-php/tests/realtime-lobby-concurrency-contract.php');
const iamGate = read('demo/video-chat/scripts/iam-call-access-ci-gate.sh');

assert.match(
  accountConfirmation,
  /function videochat_call_access_account_confirmation_origin\(\)[\s\S]*VIDEOCHAT_CALL_ACCESS_ACCOUNT_CONFIRMATION_ORIGIN[\s\S]*VIDEOCHAT_FRONTEND_ORIGIN[\s\S]*https:\/\/app\.kingrt\.com/s,
  'account-update confirmation must centralize a safe fallback origin',
);
assert.match(
  accountConfirmation,
  /preg_match\('#\^\[A-Za-z\]\[A-Za-z0-9\+\.\-\]\*:\/\/#'[\s\S]*return 'https:\/\/app\.kingrt\.com'/s,
  'configured non-HTTP schemes must be rejected before confirmation URLs are built',
);
assert.match(
  accountConfirmation,
  /function videochat_call_access_account_confirmation_url\(string \$token\)[\s\S]*account-update-confirmation\?token=/s,
  'confirmation URLs must carry only the account-update token on the safe origin',
);
assert.match(
  accountConfirmationRoute,
  /debug_confirmation_url[\s\S]*VIDEOCHAT_KING_ENV[\s\S]*production[\s\S]*null/s,
  'route debug confirmation URLs must remain hidden in production',
);
assert.match(
  emailConfirmationContract,
  /invalidConfiguredOrigin[\s\S]*javascript:\/\/invalid-email-config\.example[\s\S]*VIDEOCHAT_CALL_ACCESS_ACCOUNT_CONFIRMATION_ORIGIN[\s\S]*https:\/\/app\.kingrt\.com\/account-update-confirmation\?/s,
  'backend email-confirmation contract must prove invalid configured origins use the safe fallback',
);
assert.match(
  emailConfirmationContract,
  /invalid email configuration confirmation url[\s\S]*invalid email configuration must leave account unchanged before confirmation[\s\S]*confirmation storage[\s\S]*confirmation audit/s,
  'backend email-confirmation contract must prove no call/link/session data leaks and no early account mutation',
);

assert.match(
  websocketCommands,
  /require_once __DIR__ \. '\/module_realtime_websocket_lobby\.php';/,
  'websocket command router must load the focused lobby safe-state module',
);
assert.match(
  websocketLobby,
  /deferredLobbySender[\s\S]*videochat_realtime_apply_successful_lobby_command[\s\S]*foreach \(\$deferredLobbyFrames as \$frame\)/s,
  'lobby websocket handler must defer success frames until persistence succeeds',
);
assert.match(
  websocketLobby,
  /videochat_realtime_apply_lobby_admission_result[\s\S]*'allowed'[\s\S]*\['pending'\][\s\S]*lobby_admission_persist_failed/s,
  'lobby admission must use pending-only persistence and expose deterministic failure',
);
assert.match(
  websocketLobby,
  /function videochat_realtime_restore_failed_lobby_admission[\s\S]*unset\(\$admittedByUser\[\$normalizedUserId\]\)[\s\S]*\$queuedByUser\[\$normalizedUserId\]/s,
  'failed lobby admission persistence must restore queued in-memory state',
);
assert.match(
  lobbyConcurrencyContract,
  /timeout during lobby admission should fail safely[\s\S]*lobby_admission_persist_failed[\s\S]*timeout during lobby admission must leave durable invite state pending[\s\S]*timeout durable canonical state should have no admitted handoff/s,
  'backend lobby contract must prove timeout-safe durable and in-memory state',
);
assert.match(
  iamGate,
  /iam9-16-edge-safe-states-contract\.mjs/,
  'IAM static gate must include the IAM9-16 edge safe states proof',
);

process.stdout.write('[iam9-16-edge-safe-states-contract] PASS\n');
