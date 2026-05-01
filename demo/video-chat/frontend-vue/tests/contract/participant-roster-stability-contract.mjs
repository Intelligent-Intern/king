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
  const closeParams = source.indexOf(')', start);
  assert.notEqual(closeParams, -1, `missing ${name} parameters`);
  const open = source.indexOf('{', closeParams);
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
const roomStatePath = path.resolve(__dirname, '../../src/domain/realtime/workspace/callWorkspace/roomState.js');
const rosterPath = path.resolve(__dirname, '../../src/domain/realtime/workspace/roster.js');
const roomStateSource = fs.readFileSync(roomStatePath, 'utf8');
const rosterSource = fs.readFileSync(rosterPath, 'utf8');

try {
  const signatureBody = functionBody(rosterSource, 'participantSnapshotSignature');
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

  const snapshotBody = functionBody(roomStateSource, 'applyRoomSnapshot');
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

  const liveMediaPeerMergeBody = functionBody(rosterSource, 'mergeLiveMediaPeerIntoRoster');
  const normalizeParticipantBody = functionBody(rosterSource, 'normalizeParticipantRow');
  assert.match(
    normalizeParticipantBody,
    /raw\?\.connected_at \|\| raw\?\.connectedAt/,
    'participant rows must normalize snake_case and camelCase connected timestamps'
  );
  assert.match(
    normalizeParticipantBody,
    /hasConnection: connectionId !== '' \|\| connectedAt !== '' \|\| connectionCount > 0/,
    'participant rows must treat non-empty connectedAt and connection counts as live connection evidence'
  );
  assert.match(
    normalizeParticipantBody,
    /raw\?\.display_name \|\| raw\?\.displayName/,
    'participant rows must accept top-level display-name fields from call fixtures'
  );
  assert.match(
    liveMediaPeerMergeBody,
    /peerUserId === currentUserId/,
    'live media peer roster merge must never add the local user as a remote peer'
  );
  assert.match(
    liveMediaPeerMergeBody,
    /existing\.connections = Math\.max\(1, Number\(existing\.connections \|\| 0\)\);/,
    'live media peer roster merge may mark an existing server row connected but must not replace it'
  );
  assert.match(
    liveMediaPeerMergeBody,
    /if \(String\(existing\.displayName \|\| ''\)\.trim\(\) === ''\)/,
    'live media peer roster merge must preserve server display names when present'
  );
  assert.match(
    liveMediaPeerMergeBody,
    /allowMissingSnapshotSupplement = options\.allowMissingSnapshotSupplement === true/,
    'live media peer roster merge must require an explicit pre-snapshot supplement gate'
  );
  assert.match(
    liveMediaPeerMergeBody,
    /if \(!allowMissingSnapshotSupplement && Number\(existing\.connections \|\| 0\) <= 0\) return;/,
    'live media peers must not revive server rows that the synced room snapshot marks disconnected'
  );
  assert.match(
    liveMediaPeerMergeBody,
    /if \(!allowMissingSnapshotSupplement\) return;/,
    'live media peers must not invent missing participants after realtime room sync is authoritative'
  );
  assert.match(
    liveMediaPeerMergeBody,
    /aggregate\.set\(peerUserId, \{[\s\S]*connections: 1,[\s\S]*mediaPeerSource:/,
    'pre-snapshot live media peers may temporarily supplement a missing snapshot row'
  );
  assert.match(
    roomStateSource,
    /mergeLiveMediaPeerIntoRoster\(aggregate, peer, \{[\s\S]*allowMissingSnapshotSupplement: !hasRealtimeRoomSync\.value,[\s\S]*source: 'sfu',[\s\S]*\}\);/,
    'SFU remote peers may only supplement the roster before the authoritative room snapshot arrives'
  );
  assert.match(
    roomStateSource,
    /mergeLiveMediaPeerIntoRoster\(aggregate, peer, \{[\s\S]*allowMissingSnapshotSupplement: !hasRealtimeRoomSync\.value,[\s\S]*source: 'native',[\s\S]*\}\);/,
    'native WebRTC peers may only supplement the roster before the authoritative room snapshot arrives'
  );

  process.stdout.write('[participant-roster-stability-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
