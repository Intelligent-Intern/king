import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';

function fail(message) {
  throw new Error(`[sfu-background-tab-policy-contract] FAIL: ${message}`);
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

async function main() {
  const packageJson = read('package.json');
  const foregroundReconnect = read('src/support/foregroundReconnect.js');
  const lifecycle = read('src/domain/realtime/workspace/callWorkspace/lifecycle.js');
  const policy = read('src/domain/realtime/workspace/callWorkspace/backgroundTabPolicy.js');

  requireContains(packageJson, 'sfu-background-tab-policy-contract.mjs', 'SFU contract suite includes background-tab policy proof');
  requireContains(foregroundReconnect, 'backgroundContextFor', 'foreground helper passes structured background context');
  requireContains(foregroundReconnect, "reason: String(event?.type || 'background')", 'foreground helper records background reason');
  requireContains(foregroundReconnect, "hidden: document.visibilityState === 'hidden'", 'foreground helper records hidden state');
  requireContains(foregroundReconnect, "handleBackground({ type: 'document_hidden' })", 'visibility hidden is explicit');
  requireContains(foregroundReconnect, "handleForeground({ type: 'document_visible' })", 'visibility foreground is explicit');
  requireContains(foregroundReconnect, "window.addEventListener('blur', handleBackground)", 'blur remains tracked for reconnect without necessarily pausing video');
  requireContains(lifecycle, "import { createSfuBackgroundTabPolicy } from './backgroundTabPolicy.js';", 'lifecycle imports SFU background policy');
  requireContains(lifecycle, 'sfuBackgroundTabPolicy.pauseVideoForBackground(context)', 'background event applies SFU video pause policy');
  requireContains(lifecycle, 'void sfuBackgroundTabPolicy.resumeVideoAfterForeground(context)', 'foreground event resumes SFU video publishing');
  requireContains(policy, 'pause_sfu_video_keep_audio_status', 'background policy documents audio/status fallback');
  requireContains(policy, "reason === 'pagehide' || reason === 'document_hidden'", 'background policy pauses only true hidden/pagehide states');
  requireContains(policy, "String(mediaRuntimePath.value || '').trim() === 'wlvc_wasm'", 'background policy is scoped to SFU/WLVC video publishing');
  requireContains(policy, 'stopLocalEncodingPipeline();', 'background policy stops the source readback and encode loop');
  requireContains(policy, 'sfuClientRef.value?.unpublishTrack?.(videoTrack.id)', 'background policy unpublishes the SFU video track');
  requireContains(policy, 'localTracksPublishedToSfuRef?.set?.(false)', 'background policy forces foreground republish');
  requireContains(policy, "eventType: 'sfu_background_tab_video_paused'", 'background pause diagnostic is backend visible');
  requireContains(policy, "eventType: 'sfu_background_tab_video_resumed'", 'foreground resume diagnostic is backend visible');
  requireContains(policy, 'await publishLocalTracks();', 'foreground path restarts publishing without manual UI action');

  const server = await createServer({
    root: frontendRoot,
    logLevel: 'error',
    server: { middlewareMode: true, hmr: false },
  });

  try {
    const module = await server.ssrLoadModule('/src/domain/realtime/workspace/callWorkspace/backgroundTabPolicy.js');
    const diagnostics = [];
    const calls = {
      publish: 0,
      stop: 0,
      unpublished: [],
      publishedFlag: null,
    };
    class FakeMediaStream {
      getVideoTracks() {
        return [{ id: 'video-track-1', readyState: 'live' }];
      }
    }
    const documentRef = { visibilityState: 'visible' };
    const policyInstance = module.createSfuBackgroundTabPolicy({
      callbacks: {
        captureClientDiagnostic: (entry) => diagnostics.push(entry),
        publishLocalTracks: async () => { calls.publish += 1; return true; },
        stopLocalEncodingPipeline: () => { calls.stop += 1; },
      },
      refs: {
        callMediaPrefs: { outgoingVideoQualityProfile: 'balanced' },
        localStreamRef: { value: new FakeMediaStream() },
        localTracksPublishedToSfuRef: { set: (value) => { calls.publishedFlag = value; } },
        mediaRuntimePath: { value: 'wlvc_wasm' },
        sfuClientRef: { value: { unpublishTrack: (trackId) => calls.unpublished.push(trackId) } },
      },
      documentRef,
    });

    assert.equal(policyInstance.pauseVideoForBackground({ reason: 'blur', hidden: false }), false, 'visible blur must not pause SFU video');
    documentRef.visibilityState = 'hidden';
    assert.equal(policyInstance.pauseVideoForBackground({ reason: 'document_hidden', hidden: true }), true, 'hidden tab pauses SFU video');
    assert.equal(calls.stop, 1, 'hidden tab stops the encoder/readback loop');
    assert.deepEqual(calls.unpublished, ['video-track-1'], 'hidden tab unpublishes exactly the video track');
    assert.equal(calls.publishedFlag, false, 'hidden tab clears SFU publish flag for foreground republish');
    assert.equal(policyInstance.pauseVideoForBackground({ reason: 'document_hidden', hidden: true }), false, 'repeated hidden event is idempotent');
    documentRef.visibilityState = 'visible';
    assert.equal(await policyInstance.resumeVideoAfterForeground({ reason: 'document_visible' }), true, 'foreground resumes video publishing');
    assert.equal(calls.publish, 1, 'foreground republishes local tracks');
    assert.equal(diagnostics[0]?.eventType, 'sfu_background_tab_video_paused', 'pause diagnostic is emitted');
    assert.equal(diagnostics[1]?.eventType, 'sfu_background_tab_video_resumed', 'resume diagnostic is emitted');
    assert.equal(diagnostics[0]?.payload?.background_video_policy, 'pause_sfu_video_keep_audio_status', 'diagnostic records background policy');

    process.stdout.write('[sfu-background-tab-policy-contract] PASS\n');
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
