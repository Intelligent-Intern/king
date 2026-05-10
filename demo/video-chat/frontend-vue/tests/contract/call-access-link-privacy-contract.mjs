import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const videoChatRoot = path.resolve(frontendRoot, '..');
const repoRoot = path.resolve(videoChatRoot, '../..');

function readText(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

const joinView = readText('demo/video-chat/frontend-vue/src/domain/calls/access/JoinView.vue');
const admissionGate = readText('demo/video-chat/frontend-vue/src/domain/calls/access/admissionGate.ts');
const e2eSpec = readText('demo/video-chat/frontend-vue/tests/e2e/call-access-join.spec.js');

assert.match(
  admissionGate,
  /export function safeCallAccessInvalidMessage/,
  'call-access UI must expose a safe invalid-link message helper',
);
assert.match(
  joinView,
  /function resetJoinContextDetails\(\)[\s\S]*state\.callId = ''[\s\S]*state\.roomId = ''[\s\S]*state\.callTitle = ''[\s\S]*state\.guestName = ''/s,
  'invalid link states must clear call-specific UI details before rendering',
);
assert.match(
  joinView,
  /requiresGuestName:\s*false[\s\S]*state\.requiresGuestName = false/s,
  'public join state must reset the guest-name requirement across link changes',
);
assert.match(
  joinView,
  /state\.requiresGuestName = Boolean\(payload\?\.result\?\.requires_guest_name\) \|\| state\.linkKind === 'open'/,
  'public join must honor backend-required guest names for external personal guest links',
);
assert.match(
  joinView,
  /v-if="state\.requiresGuestName"[\s\S]*v-model\.trim="state\.guestName"/,
  'public join must show name entry whenever the access link requires a guest name',
);
assert.match(
  joinView,
  /guestName:\s*state\.requiresGuestName \? state\.guestName : ''/,
  'public join session creation must send guest_name for external personal guest links as well as open links',
);
assert.match(
  joinView,
  /if \(!response\.ok \|\| !payload \|\| payload\.status !== 'ok'\) \{[\s\S]*payload = payload && typeof payload === 'object'[\s\S]*\? payload[\s\S]*: \{ error: \{ code: 'call_access_validation_failed' \} \};[\s\S]*state\.contextError = localizedApiErrorMessage\(payload,\s*t\('public\.join\.resolve_failed'\)\);[\s\S]*return;[\s\S]*\}/s,
  'failed public join resolution must preserve backend stable error codes before localization',
);
assert.match(
  joinView,
  /catch \(error\) \{[\s\S]*showSafeInvalidAccessState\(\);[\s\S]*\} finally/s,
  'unexpected join resolution errors must render the safe invalid-link state instead of raw exception text',
);
assert.match(
  e2eSpec,
  /invalid call-access link renders safe state without foreign call data/,
  'call-access E2E must cover invalid link privacy',
);
assert.match(
  e2eSpec,
  /Private Foreign Call[\s\S]*not\.toContainText\(foreignTitle\)[\s\S]*not\.toContainText\(foreignEmail\)/s,
  'invalid link E2E must prove foreign call title and email are not rendered',
);
assert.match(
  e2eSpec,
  /getByRole\('button', \{ name: \/\^Join call\$\/ \}\)\)\.toHaveCount\(0\)/,
  'invalid link E2E must prove the join action is not shown',
);

process.stdout.write('[call-access-link-privacy-contract] PASS\n');
