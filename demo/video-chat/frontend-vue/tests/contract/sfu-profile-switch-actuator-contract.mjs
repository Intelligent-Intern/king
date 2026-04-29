import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function fail(message) {
  throw new Error(`[sfu-profile-switch-actuator-contract] FAIL: ${message}`);
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `${label} missing: ${needle}`);
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');

function read(relativePath) {
  return fs.readFileSync(path.resolve(frontendRoot, relativePath), 'utf8');
}

try {
  const callWorkspaceView = read('src/domain/realtime/CallWorkspaceView.vue');
  const lifecycle = read('src/domain/realtime/workspace/callWorkspace/lifecycle.js');
  const publisherPipeline = read('src/domain/realtime/local/publisherPipeline.js');
  const runtimeSwitching = read('src/domain/realtime/workspace/callWorkspace/runtimeSwitching.js');
  const sfuClient = read('src/lib/sfu/sfuClient.ts');
  const framePayload = read('src/lib/sfu/framePayload.ts');

  requireContains(sfuClient, 'resetOutboundMediaAfterProfileSwitch', 'SFU client exposes an explicit profile-switch media reset');
  requireContains(sfuClient, 'outboundMediaGeneration', 'SFU client tags outgoing media generation');
  requireContains(sfuClient, 'sfu_profile_switch_outbound_reset', 'SFU client reports profile-switch queue reset');
  requireContains(sfuClient, 'sfu_profile_switch_generation_mismatch', 'SFU client drops old-generation frames after a switch');
  requireContains(sfuClient, 'post_drain_generation_guard', 'SFU client rechecks generation after websocket drain wait');
  requireContains(framePayload, 'outbound_media_generation', 'binary metadata preserves outbound media generation');
  requireContains(callWorkspaceView, 'resetSfuOutboundMediaAfterProfileSwitch', 'workspace wires the SFU reset into runtime switching');
  requireContains(lifecycle, 'callMediaPrefs.outgoingVideoQualityProfile', 'profile select changes reconfigure local capture constraints');
  requireContains(lifecycle, 'resetSfuOutboundMediaForProfileSelect', 'manual quality select flushes old SFU media generations');
  requireContains(lifecycle, "reason: 'manual_profile_select'", 'manual quality select reset reason is explicit');
  requireContains(lifecycle, 'void reconfigureLocalTracksFromSelectedDevices();', 'profile switch watcher reapplies local tracks');
  requireContains(publisherPipeline, 'stopLocalEncodingPipeline();', 'publisher recreates encoders when the pipeline restarts');

  const resetIndex = runtimeSwitching.indexOf('resetSfuOutboundMediaAfterProfileSwitch({');
  const stopIndex = runtimeSwitching.indexOf('stopLocalEncodingPipeline();', resetIndex);
  const setIndex = runtimeSwitching.indexOf('setCallOutgoingVideoQualityProfile(nextProfile);', resetIndex);
  assert.ok(resetIndex > 0, 'runtime must reset outbound SFU media before profile switch');
  assert.ok(stopIndex > resetIndex, 'runtime must stop the current encoder after flushing old-profile media');
  assert.ok(setIndex > stopIndex, 'runtime must apply the lower profile only after reset and encoder stop');
  const manualResetIndex = lifecycle.indexOf('resetSfuOutboundMediaForProfileSelect(nextValue, previousValue);');
  const manualReconfigureIndex = lifecycle.indexOf('void reconfigureLocalTracksFromSelectedDevices();', manualResetIndex);
  assert.ok(manualResetIndex > 0, 'manual profile select must reset outbound media before track reconfigure');
  assert.ok(manualReconfigureIndex > manualResetIndex, 'manual profile select must reconfigure tracks only after outbound media reset');

  process.stdout.write('[sfu-profile-switch-actuator-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
