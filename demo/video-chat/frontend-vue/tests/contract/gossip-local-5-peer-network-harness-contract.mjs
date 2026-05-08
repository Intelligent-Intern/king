import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createLogger, createServer } from 'vite';
import { createLocalFivePeerGossipNetworkHarness } from './helpers/local-5-peer-gossip-network-harness.mjs';

function fail(message) {
  throw new Error(`[gossip-local-5-peer-network-harness-contract] FAIL: ${message}`);
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const packageJson = fs.readFileSync(path.join(frontendRoot, 'package.json'), 'utf8');

function assertAllTopologyApplied(result, label) {
  assert.deepEqual(
    Object.values(result),
    [true, true, true, true, true],
    `${label} topology hints must apply to every local browser peer`,
  );
}

function assertAllPeersRendered(harness, publisherPeerId, frameId, label) {
  assert.deepEqual(
    harness.renderedPeerIdsForFrame(publisherPeerId, frameId),
    harness.peerIds,
    `${label} frame must render at all five peers`,
  );
}

async function main() {
  assert.ok(
    packageJson.includes('gossip-local-5-peer-network-harness-contract.mjs'),
    'gossip contract suite must include the local 5-peer network harness contract',
  );

  const logger = createLogger('error');
  const originalLoggerError = logger.error.bind(logger);
  logger.error = (message, options) => {
    if (String(message || '').includes('WebSocket server error')) return;
    originalLoggerError(message, options);
  };

  const server = await createServer({
    root: frontendRoot,
    logLevel: 'error',
    customLogger: logger,
    optimizeDeps: { noDiscovery: true },
    server: { middlewareMode: true, hmr: false },
  });

  try {
    const { GossipController } = await server.ssrLoadModule('/src/lib/gossipmesh/gossipController.ts');
    const harness = createLocalFivePeerGossipNetworkHarness({ GossipController });

    try {
      const initialTopology = harness.ringTopology(1);
      assertAllTopologyApplied(harness.applyTopology(initialTopology), 'server-headed ring');

      for (const peerId of harness.peerIds) {
        const assignedNeighborIds = initialTopology.get(peerId).neighbors.map((neighbor) => neighbor.peer_id);
        assert.deepEqual(
          harness.controllers.get(peerId).getPeer(peerId).neighbor_set,
          assignedNeighborIds,
          `${peerId} must use exactly the server-headed neighbor assignment`,
        );
      }

      const firstFrameId = harness.publishFrame('peer-1', 1);
      assertAllPeersRendered(harness, 'peer-1', firstFrameId, 'initial ring');
      assert.ok(
        harness.maxPeerFanoutForFrame(firstFrameId) <= 2,
        'initial frame propagation must remain bounded by the two-neighbor server topology',
      );
      assert.ok(
        harness.transmissions.filter((entry) => entry.frame_id === firstFrameId).length < 20,
        'initial frame must propagate by peer forwarding rather than server media fanout',
      );

      harness.loseNeighborEdge('peer-2', 'peer-3');
      const isolatedFrameId = harness.publishFrame('peer-2', 2);
      assert.ok(
        harness.droppedTransmissions.some((entry) => (
          entry.frame_id === isolatedFrameId
          && entry.from_peer_id === 'peer-2'
          && entry.target_peer_id === 'peer-3'
          && entry.reason === 'simulated_neighbor_loss'
        )),
        'neighbor loss must drop the direct peer-2 to peer-3 data-channel transmission',
      );
      assert.deepEqual(
        harness.renderedPeerIdsForFrame('peer-2', isolatedFrameId),
        ['peer-1', 'peer-2', 'peer-4', 'peer-5'],
        'pre-repair bounded ring should demonstrate the missed peer after the lost neighbor edge',
      );
      assert.ok(
        harness.controllers.get('peer-2').getEvents().some((event) => (
          event.event === 'reconnect_requested'
          && event.peer_id === 'peer-3'
          && event.carrier_state === 'lost'
        )),
        'lost data-channel neighbor must surface a reconnect request on the affected browser controller',
      );

      const repairedTopology = harness.repairedTopologyAfterPeer2Peer3Loss(2);
      assertAllTopologyApplied(harness.applyTopology(repairedTopology), 'repair');
      assert.deepEqual(
        harness.controllers.get('peer-2').getPeer('peer-2').neighbor_set,
        ['peer-4', 'peer-1'],
        'repair hint must replace peer-2 lost neighbor with a server-assigned alternate',
      );

      const repairedFrameId = harness.publishFrame('peer-2', 3);
      assertAllPeersRendered(harness, 'peer-2', repairedFrameId, 'post-repair');
      assert.ok(
        harness.maxPeerFanoutForFrame(repairedFrameId) <= 2,
        'post-repair propagation must remain bounded by the server topology',
      );
      assert.ok(
        harness.receivedPeerIdsForFrame(repairedFrameId).includes('peer-3'),
        'post-repair frame must reach the peer that missed the pre-repair frame',
      );

      process.stdout.write('[gossip-local-5-peer-network-harness-contract] PASS\n');
    } finally {
      harness.dispose();
    }
  } finally {
    await server.close();
  }
}

main().catch((error) => {
  if (error instanceof Error) {
    fail(error.message);
  }
  fail('unknown failure');
});
