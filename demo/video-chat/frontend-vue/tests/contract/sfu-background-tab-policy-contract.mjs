import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createLogger, createServer } from 'vite';

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

function createContractLogger() {
  const logger = createLogger('error');
  const originalError = logger.error.bind(logger);
  logger.error = (message, options) => {
    if (String(message || '').includes('WebSocket server error')) return;
    originalError(message, options);
  };
  return logger;
}

async function main() {
  const packageJson = read('package.json');
  const foregroundReconnect = read('src/support/foregroundReconnect.ts');
  const lifecycle = read('src/domain/realtime/workspace/callWorkspace/lifecycle.ts');
  const policy = read('src/domain/realtime/workspace/callWorkspace/backgroundTabPolicy.ts');

  requireContains(packageJson, 'sfu-background-tab-policy-contract.mjs', 'SFU contract suite includes background-tab policy proof');
  requireContains(foregroundReconnect, 'backgroundContextFor', 'foreground helper passes structured background context');
  requireContains(foregroundReconnect, "reason: String(event?.type || 'background')", 'foreground helper records background reason');
  requireContains(foregroundReconnect, "hidden: document.visibilityState === 'hidden'", 'foreground helper records hidden state');
  requireContains(foregroundReconnect, "handleBackground({ type: 'document_hidden' })", 'visibility hidden is explicit');
  requireContains(foregroundReconnect, "handleForeground({ type: 'document_visible' })", 'visibility foreground is explicit');
  requireContains(foregroundReconnect, "window.addEventListener('blur', handleBackground)", 'blur remains tracked for reconnect without necessarily pausing video');
  requireContains(lifecycle, "import { createSfuBackgroundTabPolicy } from './backgroundTabPolicy.ts';", 'lifecycle imports SFU background policy');
  requireContains(lifecycle, 'sfuBackgroundTabPolicy.pauseVideoForBackground(context)', 'background event applies SFU video pause policy');
  requireContains(lifecycle, 'void sfuBackgroundTabPolicy.resumeVideoAfterForeground(context)', 'foreground event resumes SFU video publishing');
  requireContains(policy, 'preserve_remote_publisher_with_keyframe_marker', 'background policy documents remote publisher obligation preservation');
  requireContains(policy, 'pause_local_preview_video_keep_audio_status', 'background policy distinguishes local preview throttling');
  requireContains(policy, 'getRemotePeerCount = () => 0', 'background policy accepts remote peer count from production runtime');
  requireContains(policy, 'requestWlvcFullFrameKeyframe = () => false', 'background policy can request a deliberate background keyframe marker');
  requireContains(policy, "eventType: 'sfu_background_tab_publisher_obligation_preserved'", 'background policy emits remote publisher preservation diagnostic');
  requireContains(policy, 'background_pause_intentional', 'background diagnostics identify whether pause was intentional');
  requireContains(policy, 'active_publisher_layer', 'background diagnostics identify the active publisher layer');
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
    customLogger: createContractLogger(),
    optimizeDeps: { noDiscovery: true },
    server: { middlewareMode: true, hmr: false },
  });

  try {
    const module = await server.ssrLoadModule('/src/domain/realtime/workspace/callWorkspace/backgroundTabPolicy.ts');
    const diagnostics = [];
    const calls = {
      keyframes: [],
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
        getRemotePeerCount: () => 2,
        publishLocalTracks: async () => { calls.publish += 1; return true; },
        requestWlvcFullFrameKeyframe: (reason, details = {}) => {
          calls.keyframes.push({ reason, details });
          return true;
        },
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
    assert.equal(policyInstance.pauseVideoForBackground({ reason: 'document_hidden', hidden: true }), true, 'hidden multi-participant tab preserves publisher obligation');
    assert.equal(calls.stop, 0, 'hidden multi-participant tab must not stop the encoder/readback loop');
    assert.deepEqual(calls.unpublished, [], 'hidden multi-participant tab must not silently unpublish video');
    assert.equal(calls.publishedFlag, null, 'hidden multi-participant tab keeps SFU publish flag intact');
    assert.equal(calls.keyframes[0]?.reason, 'sfu_background_tab_publisher_marker', 'hidden multi-participant tab requests a deliberate keyframe marker');
    assert.equal(policyInstance.pauseVideoForBackground({ reason: 'document_hidden', hidden: true }), true, 'repeated hidden multi-participant event may refresh the marker');
    documentRef.visibilityState = 'visible';
    assert.equal(await policyInstance.resumeVideoAfterForeground({ reason: 'document_visible' }), false, 'foreground has nothing to republish when background publisher stayed active');
    assert.equal(calls.publish, 0, 'foreground does not republish when publisher stayed active');
    assert.equal(diagnostics[0]?.eventType, 'sfu_background_tab_publisher_obligation_preserved', 'remote publisher preservation diagnostic is emitted');
    assert.equal(diagnostics[0]?.payload?.background_video_policy, 'preserve_remote_publisher_with_keyframe_marker', 'diagnostic records remote publisher preservation policy');
    assert.equal(diagnostics[0]?.payload?.background_pause_intentional, false, 'diagnostic records that remote-publisher pause was not intentional');
    assert.equal(diagnostics[0]?.payload?.active_publisher_layer, 'primary_keyframe_marker', 'diagnostic records active publisher layer');

    const previewOnlyDiagnostics = [];
    const previewOnlyCalls = { publish: 0, stop: 0, unpublished: [], publishedFlag: null };
    const previewOnly = module.createSfuBackgroundTabPolicy({
      callbacks: {
        captureClientDiagnostic: (entry) => previewOnlyDiagnostics.push(entry),
        getRemotePeerCount: () => 0,
        publishLocalTracks: async () => { previewOnlyCalls.publish += 1; return true; },
        stopLocalEncodingPipeline: () => { previewOnlyCalls.stop += 1; },
      },
      refs: {
        callMediaPrefs: { outgoingVideoQualityProfile: 'balanced' },
        localStreamRef: { value: new FakeMediaStream() },
        localTracksPublishedToSfuRef: { set: (value) => { previewOnlyCalls.publishedFlag = value; } },
        mediaRuntimePath: { value: 'wlvc_wasm' },
        sfuClientRef: { value: { unpublishTrack: (trackId) => previewOnlyCalls.unpublished.push(trackId) } },
      },
      documentRef: { visibilityState: 'hidden' },
    });
    assert.equal(previewOnly.pauseVideoForBackground({ reason: 'document_hidden', hidden: true }), true, 'preview-only hidden tab pauses SFU video');
    assert.equal(previewOnlyCalls.stop, 1, 'preview-only hidden tab stops the encoder/readback loop');
    assert.deepEqual(previewOnlyCalls.unpublished, ['video-track-1'], 'preview-only hidden tab unpublishes exactly the video track');
    assert.equal(previewOnlyCalls.publishedFlag, false, 'preview-only hidden tab clears SFU publish flag for foreground republish');
    assert.equal(previewOnlyDiagnostics[0]?.eventType, 'sfu_background_tab_video_paused', 'preview-only pause diagnostic is emitted');
    assert.equal(previewOnlyDiagnostics[0]?.payload?.background_video_policy, 'pause_local_preview_video_keep_audio_status', 'preview-only diagnostic records local preview policy');

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
