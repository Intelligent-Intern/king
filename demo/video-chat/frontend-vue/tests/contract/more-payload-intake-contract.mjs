import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[more-payload-intake-contract] FAIL: ${message}`);
}

function requireExists(root, relativePath) {
  const absolutePath = path.resolve(root, relativePath);
  assert.ok(fs.existsSync(absolutePath), `missing required current hardening file: ${relativePath}`);
}

function requireAbsent(root, relativePath) {
  const absolutePath = path.resolve(root, relativePath);
  assert.ok(!fs.existsSync(absolutePath), `forbidden weaker replacement is present: ${relativePath}`);
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `missing ${label}`);
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');

try {
  const intakeDoc = fs.readFileSync(
    path.resolve(repoRoot, 'documentation/dev/video-chat/more-payload-1.0.7-online-video-call-intake.md'),
    'utf8',
  );

  requireContains(intakeDoc, 'Direct Merge Rejection', 'direct merge rejection section');
  requireContains(intakeDoc, 'do not merge the branch wholesale', 'wholesale merge rejection');
  requireContains(intakeDoc, '`694c2d9`', 'mini-strip source commit');
  requireContains(intakeDoc, '`376426f`', 'mini fallback source commit');
  requireContains(intakeDoc, '`76c356b`', 'publisher stall source commit');
  requireContains(intakeDoc, 'reject runtimeHealth TypeScript conversion', 'runtimeHealth TS rejection');
  requireContains(intakeDoc, 'reject buffer increases and looser thresholds', 'buffer regression rejection');
  requireContains(intakeDoc, 'binary-frame-aware SFU-client tracker', 'binary-aware stall tracker decision');

  const requiredCurrentFiles = [
    'src/domain/realtime/workspace/callWorkspace/publisherBackpressureController.js',
    'src/domain/realtime/workspace/callWorkspace/runtimeHealth.js',
    'src/lib/sfu/outboundFrameBudget.ts',
    'src/lib/sfu/sendFailureDetails.ts',
    'src/lib/sfu/sfuMessageHandler.ts',
    'tests/contract/sfu-throughput-path-contract.mjs',
    'tests/contract/sfu-profile-budget-contract.mjs',
    'tests/contract/sfu-browser-ws-send-drain-contract.mjs',
    'tests/contract/sfu-receiver-feedback-loop-contract.mjs',
    'tests/contract/sfu-online-acceptance-no-critical-pressure-contract.mjs',
    'tests/e2e/online-sfu-pressure-acceptance.mjs',
    'tests/e2e/production-socket-proxy-budget.mjs',
  ];

  for (const relativePath of requiredCurrentFiles) {
    requireExists(frontendRoot, relativePath);
  }

  requireAbsent(frontendRoot, 'src/domain/realtime/workspace/callWorkspace/runtimeHealth.ts');

  process.stdout.write('[more-payload-intake-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
