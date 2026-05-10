import { expect, test } from '@playwright/test';

import { runGossipFramePixelProof } from './helpers/gossipFramePixelProofHarness.js';

test('gossip.media.frame.v1 writes decoded pixels into a remote participant tile', async ({ page }) => {
  await page.goto('/', { waitUntil: 'domcontentloaded' });

  const proof = await runGossipFramePixelProof(page);

  expect(proof.sourceMessageType).toBe('gossip.media.frame.v1');
  expect(proof.blockedPlanAllowsPublish).toBe(false);
  expect(proof.activePlanAllowsPublish).toBe(true);
  expect(proof.avatarSourceKind).toMatch(/^canvas_/);
  expect(proof.publishedContractVersion).toBe('v1.0.0');
  expect(proof.publishedRuntimePath).toBe('gossip_primary_direct');
  expect(proof.publishedCodecId).toBe('wlvc_v1');
  expect(proof.publishedFrameKind).toBe('keyframe');
  expect(proof.publishedPayloadBytes).toBe(33);
  expect(proof.publishedTransportMessageCount).toBeGreaterThan(0);
  expect(proof.gossipControllerDeliveryCount).toBeGreaterThan(0);
  expect(proof.senderStats.sent).toBeGreaterThan(0);
  expect(proof.senderStats.peer_outbound_fanout).toBeGreaterThan(0);
  expect(proof.receiverStats.received).toBeGreaterThan(0);
  expect(proof.senderEvents).toContain('send');
  expect(proof.receiverEvents).toContain('receive');
  expect(proof.trackId).toBe('avatar-canvas-proof-202');
  expect(proof.remotePeerCount).toBe(1);
  expect(proof.decoderInitializerCount).toBe(1);
  expect(proof.decoderInvocations).toBeGreaterThan(0);
  expect(proof.receivedFrameCount).toBeGreaterThan(0);
  expect(proof.frameCount).toBeGreaterThan(0);
  expect(proof.mediaConnectionState).toBe('live');
  expect(proof.canvasWidth).toBe(2);
  expect(proof.canvasHeight).toBe(2);
  expect(proof.tileCanvasParentId).toBe(`proof-mini-slot-${proof.remoteUserId}`);
  expect(proof.canvasDatasetUserId).toBe(String(proof.remoteUserId));
  expect(proof.canvasSurfaceUserId).toBe(String(proof.remoteUserId));
  expect(proof.canvasDatasetSurfaceRole).toBe('mini');
  expect(proof.pixel).toEqual(proof.expectedPixel);
  expect(proof.decoderLastDescriptor).toMatchObject({
    dataBytes: 33,
    height: 2,
    type: 'keyframe',
    width: 2,
  });
  expect(proof.renderLayoutCount).toBeGreaterThan(0);
  expect(proof.mediaRenderVersionBumpCount).toBeGreaterThan(0);
  expect(proof.outputPreferencesAppliedCount).toBeGreaterThan(0);
  expect(proof.activityMarkCount).toBeGreaterThan(0);
  expect(proof.fallbackReasons).toEqual([]);
  expect(proof.diagnosticErrorCodes).toEqual([]);
  expect(proof.diagnosticCodes).not.toContain('sfu_decode_frame_empty');
  expect(proof.diagnosticCodes).not.toContain('sfu_decode_frame_failed');
});
