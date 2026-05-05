import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import {
  runKingRtThreeUserRegressionHarness,
} from '../standalone/kingrt-three-user-regression-harness.mjs';

function fail(message) {
  throw new Error(`[kingrt-three-user-regression-harness-contract] FAIL: ${message}`);
}

function requireContains(source, needle, label) {
  assert.ok(source.includes(needle), `${label} missing: ${needle}`);
}

try {
  const __filename = fileURLToPath(import.meta.url);
  const __dirname = path.dirname(__filename);
  const frontendRoot = path.resolve(__dirname, '../..');
  const harnessPath = path.resolve(frontendRoot, 'tests/standalone/kingrt-three-user-regression-harness.mjs');
  const packagePath = path.resolve(frontendRoot, 'package.json');
  const planningPath = path.resolve(frontendRoot, '../../../GOSSIP_PLANNING.md');
  const harnessSource = fs.readFileSync(harnessPath, 'utf8');
  const packageJson = fs.readFileSync(packagePath, 'utf8');
  const planning = fs.readFileSync(planningPath, 'utf8');

  requireContains(planning, '### G. Background Tab Publishing Policy Needs Explicit Multi-Participant Semantics', 'planning item G must remain documented');
  requireContains(planning, '### J. Three-User Browser Regression Harness Must Replay These Failure Classes', 'planning item J must remain documented');
  requireContains(packageJson, 'kingrt-three-user-regression-harness-contract.mjs', 'contract suite must include the three-user harness contract');
  requireContains(packageJson, 'kingrt-three-user-regression-harness.mjs', 'package scripts must expose the standalone browser-visible harness');

  for (const productionModule of [
    '/src/domain/realtime/layout/strategies.ts',
    '/src/domain/realtime/workspace/callWorkspace/backgroundTabPolicy.ts',
    '/src/domain/realtime/workspace/callWorkspace/publisherBackpressureController.ts',
    '/src/domain/realtime/media/security.ts',
    '/src/domain/realtime/workspace/callWorkspace/gossipDataLane.ts',
    '/src/lib/gossipmesh/gossipController.ts',
    '/src/domain/realtime/local/protectedBrowserVideoEncoder.ts',
  ]) {
    requireContains(harnessSource, productionModule, `harness must SSR-load production module ${productionModule}`);
  }

  for (const productionHelper of [
    'selectCallLayoutParticipants',
    'createSfuBackgroundTabPolicy',
    'createPublisherBackpressureController',
    'createMediaSecuritySession',
    'createCallWorkspaceGossipDataLane',
    'GossipController',
    'maybeStartProtectedBrowserVideoEncoderPublisher',
  ]) {
    requireContains(harnessSource, productionHelper, `harness must use production helper ${productionHelper}`);
  }

  for (const liveDiagnostic of [
    'sfu_background_tab_publisher_obligation_preserved',
    'sfu_background_tab_video_paused',
    'gossip_assigned_neighbor_pruned',
    'sfu_remote_full_keyframe_requested',
    'sfu_remote_full_keyframe_request_coalesced',
    'sfu_browser_encoder_lifecycle_close',
    'sfu_protected_frame_decrypt_failed',
    'media_security_participant_set_recover',
  ]) {
    requireContains(harnessSource, liveDiagnostic, `harness must cover diagnostic ${liveDiagnostic}`);
  }

  const result = await runKingRtThreeUserRegressionHarness({ frontendRoot });
  assert.equal(result.ok, true, 'harness must pass');
  assert.equal(result.participant_count, 3, 'harness must cover exactly three participants');
  assert.equal(result.churned_participant_id, 303, 'harness must churn the third participant');
  assert.equal(result.initial_main_user_id, 202, 'production active speaker strategy must drive the initial main participant');
  assert.deepEqual(result.churn_visible_user_ids.sort((a, b) => a - b), [101, 202], 'participant churn must leave two visible users');
  assert.ok(result.background_keyframe_marker_requests >= 1, 'background publisher must request a keyframe marker');
  assert.ok(result.stale_prune_events >= 1, 'stale target pruning must be exercised');
  assert.equal(result.keyframe_reset_count, 1, 'duplicate keyframe requests must be coalesced');

  const diagnostics = new Set(result.diagnostics);
  for (const liveDiagnostic of [
    'sfu_background_tab_publisher_obligation_preserved',
    'sfu_background_tab_video_paused',
    'gossip_assigned_neighbor_pruned',
    'sfu_remote_full_keyframe_requested',
    'sfu_remote_full_keyframe_request_coalesced',
    'sfu_browser_encoder_lifecycle_close',
    'sfu_protected_frame_decrypt_failed',
    'media_security_participant_set_recover',
  ]) {
    assert.ok(diagnostics.has(liveDiagnostic), `harness result missing diagnostic ${liveDiagnostic}`);
  }

  process.stdout.write('[kingrt-three-user-regression-harness-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
