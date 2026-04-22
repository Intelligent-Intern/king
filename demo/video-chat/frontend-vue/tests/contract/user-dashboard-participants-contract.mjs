import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[user-dashboard-participants-contract] FAIL: ${message}`);
}

function functionBody(source, name) {
  const marker = `function ${name}`;
  const start = source.indexOf(marker);
  assert.notEqual(start, -1, `missing ${name}`);
  const open = source.indexOf('{', start);
  assert.notEqual(open, -1, `missing ${name} body`);

  let depth = 0;
  for (let index = open; index < source.length; index += 1) {
    const char = source[index];
    if (char === '{') depth += 1;
    if (char === '}') {
      depth -= 1;
      if (depth === 0) {
        return source.slice(open + 1, index);
      }
    }
  }
  fail(`unterminated ${name}`);
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const viewPath = path.resolve(__dirname, '../../src/domain/calls/UserDashboardView.vue');
const stylePath = path.resolve(__dirname, '../../src/domain/calls/UserDashboardView.css');
const source = fs.readFileSync(viewPath, 'utf8');
const styles = fs.readFileSync(stylePath, 'utf8');

try {
  assert.match(
    source,
    /v-model="composeState\.replaceParticipants"[\s\S]*@change="handleReplaceParticipantsToggle"/,
    'user edit modal must expose the participant replacement toggle'
  );
  assert.match(
    source,
    /const shouldSendParticipants = computed\(\s*\(\) => composeState\.mode !== 'edit' \|\| composeState\.replaceParticipants,\s*\);/,
    'user edit modal must send participants only when replacement is enabled'
  );
  assert.match(source, /participantsReady: false,/, 'edit flow must track whether detailed participants are loaded');

  const seedBody = functionBody(source, 'seedComposeParticipantsFromCall');
  assert.match(seedBody, /Array\.isArray\(participants\.internal\)/, 'seed must require detailed internal participants');
  assert.match(seedBody, /composeSelectedUserIds\.value = selectedIds;/, 'seed must preserve existing internal participants');
  assert.match(seedBody, /composeExternalRows\.value = externalRows\.map/, 'seed must preserve existing external participants');
  assert.match(seedBody, /composeState\.participantsReady = true;/, 'seed must mark participants ready after detailed data is loaded');

  const loadBody = functionBody(source, 'loadEditableCallParticipants');
  assert.match(
    loadBody,
    /apiRequest\(`\/api\/calls\/\$\{encodeURIComponent\(normalizedCallId\)\}`\)/,
    'edit flow must load the detailed call before replacing participants'
  );
  assert.match(loadBody, /return seedComposeParticipantsFromCall\(payload\.call\);/, 'loaded details must seed the participant form');

  const toggleBody = functionBody(source, 'handleReplaceParticipantsToggle');
  assert.match(toggleBody, /!composeState\.participantsReady/, 'toggle must detect missing participant details');
  assert.match(toggleBody, /composeState\.replaceParticipants = false;/, 'toggle must disable replacement when details cannot load');

  const submitBody = functionBody(source, 'submitCompose');
  assert.match(
    submitBody,
    /composeState\.mode === 'edit' && !composeState\.participantsReady/,
    'submit must block participant replacement without loaded participant details'
  );
  assert.match(
    submitBody,
    /payload\.internal_participant_user_ids = normalizedInternalParticipantUserIds\(\);/,
    'submit must send selected internal participants'
  );

  assert.match(styles, /\.calls-toggle-row\s*\{/, 'user dashboard must style the edit participant toggle');
  assert.match(styles, /\.calls-checkbox-row\s*\{/, 'user dashboard must style the edit participant checkbox');

  process.stdout.write('[user-dashboard-participants-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
