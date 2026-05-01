import assert from 'node:assert/strict';

import {
  gridVideoSlotId,
  layoutModeOptionsFor,
  layoutStrategyLabel,
  layoutStrategyOptionsFor,
} from '../../src/domain/realtime/layout/uiOptions.js';

function fail(message) {
  throw new Error(`[call-layout-ui-options-contract] FAIL: ${message}`);
}

try {
  assert.deepEqual(
    layoutModeOptionsFor(['main_only', 'grid']).map((option) => option.mode),
    ['grid', 'main_only'],
    'layout mode options must keep canonical UI order while filtering supported modes',
  );
  assert.deepEqual(
    layoutModeOptionsFor(['grid'])[0],
    { mode: 'grid', label: 'Grid', icon: 'G' },
    'grid mode option must expose the sidebar label and icon',
  );
  assert.equal(layoutStrategyLabel('manual_pinned'), 'Manual / pinned', 'manual strategy label must stay stable');
  assert.equal(
    layoutStrategyLabel('round_robin_active'),
    'Round robin active',
    'round-robin strategy label must stay stable',
  );
  assert.equal(
    layoutStrategyLabel('future_strategy'),
    'future_strategy',
    'unknown strategy labels must degrade to their normalized identifier',
  );
  assert.deepEqual(
    layoutStrategyOptionsFor(['manual_pinned', 'active_speaker_main']),
    [
      { strategy: 'manual_pinned', label: 'Manual / pinned' },
      { strategy: 'active_speaker_main', label: 'Active speaker main' },
    ],
    'strategy options must preserve backend strategy order and expose labels',
  );
  assert.equal(gridVideoSlotId(42), 'workspace-grid-video-slot-42', 'valid grid user ids must be stable');
  assert.equal(gridVideoSlotId('abc'), 'workspace-grid-video-slot-unknown', 'invalid grid user ids must be guarded');

  process.stdout.write('[call-layout-ui-options-contract] PASS\n');
} catch (error) {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
}
