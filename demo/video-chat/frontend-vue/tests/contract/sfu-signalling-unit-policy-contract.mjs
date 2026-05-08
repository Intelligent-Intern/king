import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[sfu-signalling-unit-policy-contract] FAIL: ${message}`);
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `${label} missing: ${needle}`);
}

function requireNotContains(source, needle, label) {
  assert.ok(!source.includes(needle), `${label} must not contain: ${needle}`);
}

function requireRegex(source, regex, label) {
  assert.ok(regex.test(source), `${label} missing pattern: ${regex}`);
}

function extractBetween(source, startNeedle, endNeedle, label) {
  const start = source.indexOf(startNeedle);
  assert.notEqual(start, -1, `${label} start missing: ${startNeedle}`);
  const end = source.indexOf(endNeedle, start + startNeedle.length);
  assert.notEqual(end, -1, `${label} end missing: ${endNeedle}`);
  return source.slice(start, end);
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '..', '..', '..');

function readRepo(relativePath) {
  return fs.readFileSync(path.resolve(repoRoot, relativePath), 'utf8');
}

try {
  const edge = readRepo('demo/video-chat/edge/edge.php');
  const sfuClient = readRepo('demo/video-chat/frontend-vue/src/lib/sfu/sfuClient.ts');
  const carrierState = readRepo('demo/video-chat/frontend-vue/src/lib/sfu/carrierState.ts');
  const packageJson = readRepo('demo/video-chat/frontend-vue/package.json');

  const readFailurePolicy = extractBetween(
    edge,
    '$chunk = @fread($stream, 16384);',
    'if ($stream === $client) {',
    'websocket read failure close policy',
  );
  requireContains(readFailurePolicy, 'if ($isWebSocket) {', 'read failure policy checks websocket tunnel');
  requireContains(readFailurePolicy, '$closeWebSocketUpstream();', 'read failure policy keeps buffered upstream rejection flushable');
  requireContains(readFailurePolicy, '$closeWebSocketTunnel();', 'read failure policy closes websocket tunnel');
  requireRegex(
    readFailurePolicy,
    /if \(\$stream === \$upstreamStream\) \{\s*\$closeWebSocketUpstream\(\);\s*\$madeProgress = true;\s*continue;\s*\}/,
    'read failure policy preserves upstream websocket rejection before closing the client side',
  );
  requireContains(readFailurePolicy, '$closeWebSocketTunnel();', 'read failure policy still closes client-side websocket failures');
  requireNotContains(readFailurePolicy, '$stream === $client && $toUpstream !== \'\'', 'read failure policy does not keep client half-open for buffered upstream flush');

  const eofPolicy = extractBetween(
    edge,
    'if (feof($stream)) {',
    'if ($stream === $client) {',
    'websocket EOF close policy',
  );
  requireContains(eofPolicy, 'if ($isWebSocket) {', 'EOF policy checks websocket tunnel');
  requireContains(eofPolicy, '$closeWebSocketUpstream();', 'EOF policy keeps buffered upstream rejection flushable');
  requireContains(eofPolicy, '$closeWebSocketTunnel();', 'EOF policy closes websocket tunnel');
  requireRegex(
    eofPolicy,
    /if \(\$stream === \$upstreamStream\) \{\s*\$closeWebSocketUpstream\(\);\s*\$madeProgress = true;\s*continue;\s*\}/,
    'EOF policy preserves upstream websocket rejection before closing the client side',
  );
  requireContains(eofPolicy, '$closeWebSocketTunnel();', 'EOF policy still closes client-side websocket EOF');
  requireNotContains(eofPolicy, '$stream === $client && $toUpstream !== \'\'', 'EOF policy does not keep client half-open for buffered upstream flush');

  requireContains(
    sfuClient,
    'Promise.resolve(handleAssetVersionConnectionFailure())',
    'asset-version connection-failure probe is normalized through Promise.resolve',
  );
  requireContains(
    sfuClient,
    '.then((handled) => {',
    'asset-version connection-failure probe handles async result',
  );
  requireContains(
    sfuClient,
    '.catch(() => {\n          failToNextCandidate()\n        })',
    'asset-version connection-failure probe falls through to the next candidate on error',
  );
  requireNotContains(
    sfuClient,
    'const assetVersionProbe = handleAssetVersionConnectionFailure()',
    'asset-version probe does not reintroduce the sync/thenable branch split',
  );

  requireContains(sfuClient, 'private opsEpoch = 0', 'SFU client tracks ops epoch');
  requireContains(sfuClient, 'private opsSignalSequence = 0', 'SFU client tracks ops signal sequence');
  requireContains(sfuClient, 'this.opsEpoch += 1\n    this.opsSignalSequence = 0', 'connect/leave resets ops signal sequence for a new epoch');
  requireContains(
    sfuClient,
    'if (typeof opsMsg.type === \'string\' && !String(opsMsg.type).startsWith(\'sfu/frame\'))',
    'SFU client stamps only non-frame ops messages',
  );
  requireContains(sfuClient, 'opsMsg.ops_epoch = this.opsEpoch', 'ops messages include epoch');
  requireContains(sfuClient, 'opsMsg.signal_sequence = ++this.opsSignalSequence', 'ops messages include ordered signal sequence');

  requireContains(sfuClient, 'lane: \'ops\'', 'SFU client emits ops-lane diagnostics');
  requireContains(sfuClient, 'lane: \'data\'', 'SFU client emits data-lane diagnostics');
  requireContains(carrierState, 'lane: \'ops\'', 'carrier diagnostics are ops-lane tagged');
  requireContains(carrierState, 'Reconnect is an ops-lane-only decision.', 'carrier state owns reconnect policy');

  requireContains(
    packageJson,
    'sfu-signalling-unit-policy-contract.mjs',
    'SFU contract script includes signalling-unit policy contract',
  );

  process.stdout.write('[sfu-signalling-unit-policy-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
