import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '..', '..', '..');

function readFrontend(relativePath) {
  return fs.readFileSync(path.resolve(frontendRoot, relativePath), 'utf8');
}

function readRepo(relativePath) {
  return fs.readFileSync(path.resolve(repoRoot, relativePath), 'utf8');
}

function requireContains(source, needle, message) {
  assert.ok(source.includes(needle), message);
}

function main() {
  const sprint = readRepo('SPRINT.md');
  const packageJson = readFrontend('package.json');
  const transportSample = readFrontend('src/lib/sfu/sfuClientTransportSample.ts');
  const sfuClient = readFrontend('src/lib/sfu/sfuClient.ts');
  const frameDecode = readFrontend('src/domain/realtime/sfu/frameDecode.ts');
  const diagnostics = readFrontend('src/support/clientDiagnostics.ts');

  requireContains(sprint, '6. [x] `[end-to-end-media-pressure-observability]`', 'sprint issue 6 must be checked');
  requireContains(packageJson, 'sfu-end-to-end-observability-contract.mjs', 'SFU contract suite includes observability proof');
  requireContains(transportSample, 'resolveSfuFirstOverBudgetStage', 'transport sample helper resolves first over-budget stage');
  requireContains(transportSample, 'buildSfuEndToEndPerformancePayload', 'transport sample helper builds end-to-end report payload');
  requireContains(transportSample, "sfu_performance_report_schema: 'sfu_end_to_end_v1'", 'publisher reports use versioned schema');
  requireContains(transportSample, "media_path_phase: 'publisher_send'", 'publisher report marks media path phase');
  requireContains(transportSample, "return 'source_readback'", 'source readback can be first pressure stage');
  requireContains(transportSample, "return 'encoded_payload'", 'payload budget can be first pressure stage');
  requireContains(transportSample, "return 'outbound_queue_age'", 'queue age can be first pressure stage');
  requireContains(transportSample, "return 'browser_send_buffer'", 'browser send buffer can be first pressure stage');
  requireContains(sfuClient, 'buildSfuEndToEndPerformancePayload(payload, sample)', 'SFU client emits end-to-end performance payload');
  requireContains(frameDecode, "sfu_performance_report_schema: 'sfu_end_to_end_v1'", 'receiver render report uses same schema');
  requireContains(frameDecode, "media_path_phase: 'receiver_render'", 'receiver report marks render phase');
  requireContains(frameDecode, "first_over_budget_stage: receiverRenderLatencyMs >= 900 ? 'receiver_render' : 'within_budget'", 'receiver report identifies render pressure');
  requireContains(diagnostics, "eventType: 'client_console_warning'", 'console warnings are captured as backend diagnostics');
  requireContains(diagnostics, 'if (clientConsoleWarningPassthroughEnabled())', 'console warning passthrough is debug-gated');

  process.stdout.write('[sfu-end-to-end-observability-contract] PASS\n');
}

main();
