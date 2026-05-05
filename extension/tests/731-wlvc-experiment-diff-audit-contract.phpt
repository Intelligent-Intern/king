--TEST--
King WLVC/WASM/Kalman Q-15 experiment diff audit is documented and keeps stronger current codec contracts
--FILE--
<?php
$root = dirname(__DIR__, 2);

function source(string $path): string
{
    global $root;
    $absolutePath = $root . '/' . $path;
    if (!is_file($absolutePath)) {
        throw new RuntimeException('Could not read ' . $path);
    }
    $source = file_get_contents($absolutePath);
    if (!is_string($source)) {
        throw new RuntimeException('Could not read ' . $path);
    }
    return $source;
}

function require_contains(string $path, string $needle): void
{
    if (!str_contains(source($path), $needle)) {
        throw new RuntimeException($path . ' must contain ' . $needle);
    }
}

function require_not_contains(string $path, string $needle): void
{
    if (str_contains(source($path), $needle)) {
        throw new RuntimeException($path . ' must not contain ' . $needle);
    }
}

function tracked_files(): array
{
    global $root;
    $output = [];
    $status = 0;
    exec('cd ' . escapeshellarg($root) . ' && git ls-files', $output, $status);
    if ($status !== 0) {
        throw new RuntimeException('Could not list tracked files');
    }
    return $output;
}

$provenanceNeedles = [
    '## Q-15 WLVC/WASM/Kalman Experiment Diff Audit',
    'Audited boundary: `4e58bef77420c03df379f2fe159a694c4d40493a`',
    'Compared paths: `codec-test.html`, `codec-test.md`, `src/lib/wasm/**`, `src/lib/wavelet/**`, `src/lib/kalman/**`, and `mediaRuntime*`.',
    'The C++/WASM codec sources and generated `wlvc.*` assets are unchanged from the audited experiment boundary for this checkbox.',
    'The current TypeScript WASM wrapper is stronger than the experiment boundary because it keeps the WASM MIME cache-buster, uses `debugWarn`, and recreates stale encoder/decoder bindings after Emscripten class-mismatch errors.',
    'The current wavelet decoder is stronger because it bounds the V-channel payload slice by the declared byte count and rejects payload-length mismatch.',
    'The current Kalman filter is stronger because it multiplies the Kalman gain by `SInv`, computes process-noise `dt4` locally, and removes stale module-level `dt2`/`dt3`/`dt4` constants.',
    '`codec-test.md` and `src/lib/wavelet/README.md` were removed from the active frontend tree and replaced by canonical docs under `documentation/dev/`.',
    '`mediaRuntimeCapabilities.js` and `mediaRuntimeTelemetry.js` are present in the audited experiment boundary and remain as TypeScript modules in the current frontend.',
    'The duplicate legacy `demo/video-chat/frontend/src/lib/**` experiment tree is not reintroduced; the active tree is `demo/video-chat/frontend-vue/**`.',
];
foreach ($provenanceNeedles as $needle) {
    require_contains('documentation/experiment-intake-provenance.md', $needle);
}

$wasmCodec = 'demo/video-chat/frontend-vue/src/lib/wasm/wasm-codec.ts';
require_contains($wasmCodec, "const WASM_MIME_CACHE_BUSTER = 'application-wasm-20260421'");
require_contains($wasmCodec, 'function isBindingMismatchError(error: unknown, className: string): boolean');
require_contains($wasmCodec, 'private recreateEncoder(): boolean');
require_contains($wasmCodec, 'private recreateDecoder(): boolean');
require_contains($wasmCodec, 'debugWarn(\'[WASM Codec] Failed to load:\', err)');
require_not_contains($wasmCodec, 'console.log');
require_not_contains($wasmCodec, 'console.warn');
require_not_contains($wasmCodec, 'console.error');

$waveletCodec = 'demo/video-chat/frontend-vue/src/lib/wavelet/codec.ts';
require_contains($waveletCodec, 'const vEnd    = vStart + vBytes');
require_contains($waveletCodec, "throw new Error('[WaveletDecoder] Invalid frame: payload length mismatch')");

require_contains('demo/video-chat/frontend-vue/codec-test.html', 'payload.subarray(HEAD + yB + uB, HEAD + yB + uB + vB);');
require_contains('demo/video-chat/frontend-vue/src/lib/wavelet/processor-pipeline.ts', "debugWarn('[Wavelet] Frame error:', e)");
require_contains('demo/video-chat/frontend-vue/src/lib/wavelet/transform.ts', "debugWarn('[WaveletTransform] createEncodedStreams not supported')");
require_contains('demo/video-chat/frontend-vue/src/lib/wavelet/webrtc-shim.ts', "debugLog('[WaveletCodec] Starting WASM init...')");
require_contains('demo/video-chat/frontend-vue/src/lib/kalman/processor.ts', "debugWarn('Failed to decode frame:', error)");

$kalmanFilter = 'demo/video-chat/frontend-vue/src/lib/kalman/filter.ts';
require_contains($kalmanFilter, 'const K = this.multiplyMatrix(this.multiplyMatrix(this.P, this.transpose(H)), SInv)');
require_contains($kalmanFilter, 'const dt4 = dt2 * dt2');
require_not_contains($kalmanFilter, 'const dt2 = 1');
require_not_contains($kalmanFilter, 'const dt3 = 1');
require_not_contains($kalmanFilter, 'const dt4 = 1');

require_contains('demo/video-chat/frontend-vue/src/domain/realtime/mediaRuntimeCapabilities.ts', 'export async function detectMediaRuntimeCapabilities()');
require_contains('demo/video-chat/frontend-vue/src/domain/realtime/mediaRuntimeTelemetry.ts', 'export function appendMediaRuntimeTransitionEvent(event = {})');
require_contains('documentation/dev/video-chat-codec-test.md', 'cd demo/video-chat/frontend-vue');
require_not_contains('documentation/dev/video-chat-codec-test.md', '/Users/sasha');
require_contains('documentation/dev/video-chat-wavelet-codec.md', 'Pure TypeScript Haar DWT wavelet codec');

$tracked = tracked_files();
$forbiddenTracked = [
    'demo/video-chat/frontend-vue/codec-test.md',
    'demo/video-chat/frontend-vue/src/lib/wavelet/README.md',
    'demo/video-chat/frontend/src/lib/wasm/wasm-codec.ts',
    'demo/video-chat/frontend/src/lib/wavelet/codec.ts',
    'demo/video-chat/frontend/src/lib/kalman/filter.ts',
];
foreach ($forbiddenTracked as $path) {
    if (in_array($path, $tracked, true)) {
        throw new RuntimeException('Superseded experiment path is still tracked: ' . $path);
    }
}

require_contains('READYNESS_TRACKER.md', 'Q-15 WLVC/WASM/Kalman diff audit');

echo "OK\n";
?>
--EXPECT--
OK
