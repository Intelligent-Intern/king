import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[sfu-multi-participant-render-contract] FAIL: ${message}`);
}

function functionBody(source, name) {
  const asyncMarker = `async function ${name}`;
  const syncMarker = `function ${name}`;
  const marker = source.indexOf(asyncMarker) !== -1 ? asyncMarker : syncMarker;
  const start = source.indexOf(marker);
  assert.notEqual(start, -1, `missing function ${name}`);

  // Find the opening paren of the parameter list
  const parenOpen = source.indexOf('(', start);
  let bodyStart;
  if (parenOpen !== -1) {
    // Find the closing paren
    let depth = 0;
    let pi = parenOpen;
    while (pi < source.length) {
      if (source[pi] === '(') depth += 1;
      if (source[pi] === ')') { depth -= 1; if (depth === 0) break; }
      pi += 1;
    }
    // Find first { after )
    bodyStart = source.indexOf('{', pi + 1);
  } else {
    bodyStart = source.indexOf('{', start);
  }
  assert.notEqual(bodyStart, -1, `missing function ${name} body`);

  let depth = 0;
  for (let i = bodyStart; i < source.length; i += 1) {
    if (source[i] === '{') depth += 1;
    if (source[i] === '}') {
      depth -= 1;
      if (depth === 0) {
        return source.slice(bodyStart + 1, i);
      }
    }
  }
  fail(`unterminated function ${name}`);
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const workspacePath = path.resolve(__dirname, '../../src/domain/realtime/CallWorkspaceView.vue');
const source = fs.readFileSync(workspacePath, 'utf8');

try {
  const handleFrameBody = functionBody(source, 'handleSFUEncodedFrame');
  const createOrUpdateBody = functionBody(source, 'createOrUpdateSfuRemotePeer');

  // Bug 1: updateSfuRemotePeerUserId return value must be captured and assigned back
  assert.ok(
    /const\s+updatedPeer\s*=\s*updateSfuRemotePeerUserId/.test(handleFrameBody),
    'handleSFUEncodedFrame must capture return of updateSfuRemotePeerUserId'
  );
  assert.ok(
    /if\s*\(\s*updatedPeer\s*!==\s*peer\s*\)\s*\{[^}]*peer\s*=\s*updatedPeer/.test(handleFrameBody),
    'handleSFUEncodedFrame must assign updatedPeer back to peer when they differ'
  );

  // Bug 2: renderCallVideoLayout called when canvas has no parent
  assert.ok(
    /const canvas = peer/.test(handleFrameBody),
    'handleSFUEncodedFrame must capture peer decodedCanvas'
  );
  assert.ok(
    /canvas.*parentElement.*instanceof.*HTMLElement/.test(handleFrameBody) &&
    /nextTick\(\)[^}]*renderCallVideoLayout/.test(handleFrameBody),
    'handleSFUEncodedFrame must call renderCallVideoLayout via nextTick when canvas has no parent'
  );

  // Bug 3: null check on createdPeer before decode
  assert.ok(
    /\.then\s*\([^)]+\)\s*=>\s*\{[^}]*if\s*\(\s*!\s*createdPeer\s*\)\s*return/.test(handleFrameBody),
    'handleSFUEncodedFrame must check if createdPeer is null before decoding'
  );

  // Bug 5: nextTick must wrap decodeSfuFrameForPeer in the .then() chain
  const nextTickBeforeDecode = /\.then\s*\([^)]+\)\s*=>\s*\{[\s\S]*?nextTick\(\)[\s\S]*?decodeSfuFrameForPeer/.test(handleFrameBody);
  assert.ok(
    nextTickBeforeDecode,
    'handleSFUEncodedFrame must wait for nextTick before calling decodeSfuFrameForPeer'
  );

  // decodeSfuFrameForPeer must NOT be called synchronously before nextTick
  const syncThenNextTick = /\.then\s*\([^)]+\)\s*=>\s*\{[^}]*decodeSfuFrameForPeer[^}]*nextTick\(\)/.test(handleFrameBody);
  assert.ok(
    !syncThenNextTick,
    'handleSFUEncodedFrame must NOT call decodeSfuFrameForPeer synchronously before nextTick'
  );

  // Bug 4: createOrUpdateSfuRemotePeer must short-circuit when no update needed
  assert.ok(
    /const\s+needsUpdate\s*=/.test(createOrUpdateBody),
    'createOrUpdateSfuRemotePeer must define needsUpdate'
  );
  assert.ok(
    /if\s*\(\s*!\s*needsUpdate\b.*?Promise\s*\.\s*resolve\s*\(\s*existingPeer\s*\)/s.test(createOrUpdateBody),
    'createOrUpdateSfuRemotePeer must short-circuit when needsUpdate is false'
  );

  process.stdout.write('[sfu-multi-participant-render-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}