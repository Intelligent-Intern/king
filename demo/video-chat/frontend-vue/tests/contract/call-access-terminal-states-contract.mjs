import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');

function readText(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function readJson(relativePath) {
  return JSON.parse(readText(relativePath));
}

function byKey(rows, label) {
  const index = new Map();
  for (const row of Array.isArray(rows) ? rows : []) {
    const key = String(row?.key || '').trim();
    assert.notEqual(key, '', `${label} row must have a stable key`);
    assert.equal(index.has(key), false, `${label} row key must be unique: ${key}`);
    index.set(key, row);
  }
  return index;
}

function row(index, key, label) {
  const value = index.get(key);
  assert.ok(value, `seed matrix must include ${label}: ${key}`);
  return value;
}

function escapeRegExp(value) {
  return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function serialized(value) {
  return JSON.stringify(value);
}

function privateCallNeedles(call, users) {
  const owner = row(users, call.owner_user_key, 'terminal call owner');
  return [
    call.id,
    call.room_id,
    call.title,
    owner.email,
    owner.display_name,
    ...(Array.isArray(call.guest_list_user_keys) ? call.guest_list_user_keys : [])
      .flatMap((guestKey) => {
        const guest = row(users, guestKey, 'terminal call guest-list user');
        return [guest.email, guest.display_name];
      }),
  ].filter((value) => String(value || '').trim() !== '');
}

const matrix = readJson('demo/video-chat/contracts/v1/iam-call-access-seeding.matrix.json');
const seedMatrixSpec = readText('demo/video-chat/frontend-vue/tests/e2e/call-access-seed-matrix.spec.js');
const seedMatrixHelper = readText('demo/video-chat/frontend-vue/tests/e2e/helpers/callAccessSeedMatrix.js');

const users = byKey(matrix.users, 'user');
const calls = byKey(matrix.calls, 'call');
const scenarios = byKey(matrix.scenarios, 'scenario');

const terminalCases = [
  {
    scenarioKey: 'direct_join_system_admin_alpha_ended_denied',
    callKey: 'alpha_ended',
    principalKey: 'system_admin',
    status: 'ended',
    resolve: {
      status: 200,
      envelope: 'ok',
      state: 'forbidden',
      reason: 'call_not_joinable_from_status',
    },
    callFetch: {
      status: 403,
      code: 'calls_forbidden',
    },
  },
  {
    scenarioKey: 'direct_join_alpha_owner_alpha_disabled_denied',
    callKey: 'alpha_disabled',
    principalKey: 'alpha_call_owner',
    status: 'disabled',
    resolve: {
      status: 200,
      envelope: 'ok',
      state: 'forbidden',
      reason: 'call_not_joinable_from_status',
    },
    callFetch: {
      status: 403,
      code: 'calls_forbidden',
    },
  },
  {
    scenarioKey: 'direct_join_alpha_owner_alpha_deleted_hidden',
    callKey: 'alpha_deleted',
    principalKey: 'alpha_call_owner',
    status: 'deleted',
    resolve: {
      status: 404,
      envelope: 'error',
      code: 'calls_not_found',
    },
    callFetch: {
      status: 404,
      code: 'calls_not_found',
    },
  },
];

for (const contractCase of terminalCases) {
  const scenario = row(scenarios, contractCase.scenarioKey, 'terminal-state scenario');
  const call = row(calls, contractCase.callKey, 'terminal-state call');
  const expected = scenario.expected || {};

  assert.equal(scenario.principal_user_key, contractCase.principalKey, `${contractCase.scenarioKey} principal mismatch`);
  assert.equal(scenario.call_key, contractCase.callKey, `${contractCase.scenarioKey} call mismatch`);
  assert.ok(
    Array.isArray(scenario.sprint_groups) && scenario.sprint_groups.includes('8'),
    `${contractCase.scenarioKey} must be owned by IAM-08`,
  );
  assert.equal(call.status, contractCase.status, `${contractCase.callKey} status mismatch`);
  assert.equal(expected.expected_call_status_value, contractCase.status, `${contractCase.scenarioKey} expected status value mismatch`);
  assert.equal(expected.direct_join_allowed, false, `${contractCase.scenarioKey} must not be joinable`);
  assert.equal(expected.private_call_payload_forbidden, true, `${contractCase.scenarioKey} must forbid private call payloads`);
  assert.equal(expected.expected_resolve_status, contractCase.resolve.status, `${contractCase.scenarioKey} resolve HTTP status mismatch`);
  assert.equal(expected.expected_call_status, contractCase.callFetch.status, `${contractCase.scenarioKey} call fetch HTTP status mismatch`);
  assert.equal(expected.expected_call_error_code, contractCase.callFetch.code, `${contractCase.scenarioKey} call fetch error code mismatch`);

  if (contractCase.resolve.status === 200) {
    assert.equal(expected.expected_resolve_state, contractCase.resolve.state, `${contractCase.scenarioKey} resolve state mismatch`);
    assert.equal(expected.expected_resolve_reason, contractCase.resolve.reason, `${contractCase.scenarioKey} resolve reason mismatch`);
  } else {
    assert.equal(expected.expected_resolve_error_code, contractCase.resolve.code, `${contractCase.scenarioKey} hidden resolve code mismatch`);
  }

  assert.match(
    seedMatrixSpec,
    new RegExp(escapeRegExp(contractCase.scenarioKey)),
    `Playwright seed matrix spec must exercise ${contractCase.scenarioKey}`,
  );

  const resolvePayload = contractCase.resolve.status === 200
    ? {
      status: 'ok',
      result: {
        state: contractCase.resolve.state,
        resolved_as: 'call_id',
        reason: contractCase.resolve.reason,
        access_link: null,
        call: null,
      },
    }
    : {
      status: 'error',
      error: {
        code: contractCase.resolve.code,
        message: 'Call does not exist.',
      },
    };
  const callFetchPayload = {
    status: 'error',
    error: {
      code: contractCase.callFetch.code,
      message: contractCase.callFetch.code === 'calls_not_found'
        ? 'Call does not exist.'
        : 'You are not allowed to view this call.',
    },
  };

  assert.equal(resolvePayload.result?.call ?? null, null, `${contractCase.scenarioKey} resolve payload must not include call data`);
  assert.equal(callFetchPayload.call ?? null, null, `${contractCase.scenarioKey} call fetch payload must not include call data`);

  for (const privateNeedle of privateCallNeedles(call, users)) {
    assert.doesNotMatch(
      serialized(resolvePayload),
      new RegExp(escapeRegExp(privateNeedle)),
      `${contractCase.scenarioKey} resolve payload must not leak ${privateNeedle}`,
    );
    assert.doesNotMatch(
      serialized(callFetchPayload),
      new RegExp(escapeRegExp(privateNeedle)),
      `${contractCase.scenarioKey} call fetch payload must not leak ${privateNeedle}`,
    );
  }
}

assert.match(
  seedMatrixSpec,
  /private_call_payload_forbidden[\s\S]*responses\.resolve\.payload\?\.result\?\.call\s*\?\?\s*null[\s\S]*toBeNull\(\)[\s\S]*responses\.call\.payload\?\.call\s*\?\?\s*null[\s\S]*toBeNull\(\)/,
  'seed matrix spec must assert terminal resolve and call fetch payloads do not include private call objects',
);
assert.match(
  seedMatrixHelper,
  /function canDirectlyResolveCall\(user,\s*call\)[\s\S]*if \(callDirectAccessFailure\(call\)\) return false;/,
  'seed helper must block terminal calls before normal direct-join role checks',
);
assert.match(
  seedMatrixHelper,
  /function callDirectAccessFailure\(call\)[\s\S]*status === 'deleted'[\s\S]*hidden:\s*true[\s\S]*errorCode:\s*'calls_not_found'/,
  'seed helper must hide deleted calls as not found',
);
assert.match(
  seedMatrixHelper,
  /!\['scheduled',\s*'active'\]\.includes\(status\)[\s\S]*reason:\s*'call_not_joinable_from_status'[\s\S]*errorCode:\s*'calls_forbidden'/,
  'seed helper must treat every non-scheduled/non-active terminal status as not joinable',
);
assert.match(
  seedMatrixHelper,
  /if \(callFailure\) \{[\s\S]*state:\s*'forbidden'[\s\S]*reason:\s*callFailure\.reason[\s\S]*access_link:\s*null[\s\S]*call:\s*null/,
  'seed helper terminal resolve denial must keep call and access_link null',
);
assert.doesNotMatch(
  seedMatrixHelper,
  /details:\s*\{[^}]*call_id/s,
  'terminal denied call fetch errors must not echo private call identifiers',
);

process.stdout.write('[call-access-terminal-states-contract] PASS\n');
