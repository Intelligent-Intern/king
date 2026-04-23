--TEST--
King WLVC runtime regressions are pinned for parity failure switching and remote render
--FILE--
<?php
$root = dirname(__DIR__, 2);

function source_file(string $path): string
{
    global $root;
    $source = file_get_contents($root . '/' . $path);
    if (!is_string($source)) {
        throw new RuntimeException('Could not read ' . $path);
    }
    return $source;
}

function require_contains(string $path, string $needle): void
{
    if (!str_contains(source_file($path), $needle)) {
        throw new RuntimeException($path . ' must contain ' . $needle);
    }
}

function require_count(string $path, string $needle, int $expected): void
{
    $actual = substr_count(source_file($path), $needle);
    if ($actual !== $expected) {
        throw new RuntimeException($path . ' must contain ' . $needle . ' exactly ' . $expected . ' times, got ' . $actual);
    }
}

$frontendContract = 'demo/video-chat/frontend-vue/tests/contract/wlvc-runtime-regression-contract.mjs';
require_contains($frontendContract, '[wlvc-runtime-regression-contract] PASS');
require_contains($frontendContract, 're-encode bytes must stay stable');
require_contains($frontendContract, 'null input');
require_contains($frontendContract, 'payload_length_mismatch');
require_contains($frontendContract, 'channel_too_large');
require_contains($frontendContract, 'runtime path allow-list');
require_contains($frontendContract, 'native switch tears down SFU peers');
require_contains($frontendContract, 'WLVC switch tears down native peers');
require_contains($frontendContract, 'remote frame can create peer before tracks');
require_contains($frontendContract, 'remote decoded canvas paint');
require_contains($frontendContract, 'render version must bump exactly once per peer-map mutation');

require_contains('demo/video-chat/frontend-vue/package.json', 'node tests/contract/wlvc-runtime-regression-contract.mjs');

$workspace = 'demo/video-chat/frontend-vue/src/domain/realtime/CallWorkspaceView.vue';
require_contains($workspace, "await switchMediaRuntimePath('wlvc_wasm', 'capability_probe_stage_a')");
require_contains($workspace, "await switchMediaRuntimePath('webrtc_native', 'capability_probe_stage_b')");
require_contains($workspace, "setMediaRuntimePath('unsupported', 'capability_probe_unsupported')");
require_contains($workspace, 'appendMediaRuntimeTransitionEvent({');
require_contains($workspace, 'teardownSfuRemotePeers();');
require_contains($workspace, 'teardownNativePeerConnections();');
require_contains($workspace, 'const init = ensureSfuRemotePeerForFrame(frame);');
require_contains($workspace, 'void decodeSfuFrameForPeer(publisherId, nextPeer, frame);');
require_contains($workspace, 'ctx.putImageData(imageData, 0, 0);');
require_contains($workspace, 'markRemoteFrameActivity(publisherUserId);');
require_count($workspace, 'mediaRenderVersion.value = mediaRenderVersion.value >= 1_000_000 ? 0 : mediaRenderVersion.value + 1;', 1);

require_contains('documentation/experiment-intake-provenance.md', 'Explicit WLVC regression checks:');
require_contains('documentation/experiment-intake-provenance.md', '`demo/video-chat/frontend-vue/tests/contract/wlvc-runtime-regression-contract.mjs` verifies encode/decode parity with a re-encode byte match and crash-free decode failure results for malformed inputs.');
require_contains('documentation/experiment-intake-provenance.md', 'The same guard pins runtime-path switching: capability probing prefers `wlvc_wasm`, falls back to `webrtc_native`, records transition telemetry, tears down the inactive transport, and fails closed to `unsupported` when neither path is available.');
require_contains('documentation/experiment-intake-provenance.md', 'The remote-render guard pins SFU frame continuity: frames can create a remote peer before track metadata arrives, decoded canvases are rendered into primary/mini/grid slots through user-id mapping, activity is marked from remote frames, and render-version changes happen once per peer-map mutation.');

require_contains('SPRINT.md', '- [x] Add explicit regression checks for encode/decode parity, crash-free decode failure, runtime-path switching, and remote render continuity.');
require_contains('READYNESS_TRACKER.md', 'Q-15 WLVC regression coverage');
require_contains('READYNESS_TRACKER.md', 'Added `wlvc-runtime-regression-contract.mjs` and PHPT `737-wlvc-runtime-regression-contract.phpt`.');

echo "OK\n";
?>
--EXPECT--
OK
