import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[participant-roster-stability-contract] FAIL: ${message}`);
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
const workspacePath = path.resolve(__dirname, '../../src/domain/realtime/CallWorkspaceView.vue');
const source = fs.readFileSync(workspacePath, 'utf8');

try {
  const signatureBody = functionBody(source, 'participantSnapshotSignature');
  assert.match(signatureBody, /row\.userId/, 'roster signature must include user id');
  assert.match(signatureBody, /row\.displayName/, 'roster signature must include display name');
  assert.match(signatureBody, /row\.callRole/, 'roster signature must include call role');
  assert.doesNotMatch(
    signatureBody,
    /row\.connectionId,\s*\n/,
    'roster signature must ignore transport connection ids'
  );
  assert.doesNotMatch(
    signatureBody,
    /row\.connectedAt/,
    'roster signature must ignore reconnect timestamps'
  );

  const snapshotBody = functionBody(source, 'applyRoomSnapshot');
  assert.match(
    snapshotBody,
    /const participantsChanged = applyParticipantsSnapshot\(payload\?\.participants\);/,
    'room snapshots must expose whether the roster actually changed'
  );
  assert.match(
    snapshotBody,
    /if \(participantsChanged\) \{\s*refreshUsersDirectoryPresentation\(\);\s*\}/,
    'directory presentation refresh must be gated by roster changes'
  );
  const snapshotBodyWithoutGate = snapshotBody.replace(
    /if \(participantsChanged\) \{\s*refreshUsersDirectoryPresentation\(\);\s*\}/,
    ''
  );
  assert.doesNotMatch(
    snapshotBodyWithoutGate,
    /refreshUsersDirectoryPresentation\(\);/,
    'room snapshots must not refresh the roster presentation unconditionally'
  );

  const existingPeerGuardMatches = source.match(/if \(existing\) \{\s*continue;\s*\}/g) || [];
  assert.ok(
    existingPeerGuardMatches.length >= 2,
    'local SFU/native peer maps must not overwrite server-authoritative roster rows'
  );
  const fallbackGuardMatches = source.match(/if \(!shouldUseLocalPeerRosterFallback\(\)\) \{\s*continue;\s*\}/g) || [];
  assert.ok(
    fallbackGuardMatches.length >= 2,
    'local SFU/native peer maps must not create roster rows after the authoritative snapshot is active'
  );

  const fallbackBody = functionBody(source, 'shouldUseLocalPeerRosterFallback');
  assert.match(
    fallbackBody,
    /hasRealtimeRoomSync\.value !== true/,
    'local peer fallback must stop after the first server-authoritative room snapshot'
  );

  process.stdout.write('[participant-roster-stability-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
