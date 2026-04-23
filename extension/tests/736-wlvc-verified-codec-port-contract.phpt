--TEST--
King WLVC ports only verified codec correctness/performance deltas with targeted frontend tests
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

function require_not_contains(string $path, string $needle): void
{
    if (str_contains(source_file($path), $needle)) {
        throw new RuntimeException($path . ' must not contain ' . $needle);
    }
}

function require_tracked_absent(string $path): void
{
    global $root;
    $output = [];
    $status = 0;
    exec('cd ' . escapeshellarg($root) . ' && git ls-files --error-unmatch ' . escapeshellarg($path) . ' 2>/dev/null', $output, $status);
    if ($status === 0) {
        throw new RuntimeException('Path must not be tracked: ' . $path);
    }
}

$frontendContract = 'demo/video-chat/frontend-vue/tests/contract/wlvc-codec-port-contract.mjs';
require_contains($frontendContract, '[wlvc-codec-port-contract] PASS');
require_contains($frontendContract, 'const vEnd    = vStart + vBytes');
require_contains($frontendContract, "throw new Error('[WaveletDecoder] Invalid frame: payload length mismatch')");
require_contains($frontendContract, 'payload.subarray(HEAD + yB + uB, HEAD + yB + uB + vB);');
require_contains($frontendContract, 'const K = this.multiplyMatrix(this.multiplyMatrix(this.P, this.transpose(H)), SInv)');
require_contains($frontendContract, 'const dt4 = dt2 * dt2');
require_contains($frontendContract, 'documentation/dev/video-chat-codec-test.md');

$waveletCodec = 'demo/video-chat/frontend-vue/src/lib/wavelet/codec.ts';
require_contains($waveletCodec, 'const vEnd    = vStart + vBytes');
require_contains($waveletCodec, "throw new Error('[WaveletDecoder] Invalid frame: payload length mismatch')");
require_contains($waveletCodec, 'const vRle    = payload.subarray(vStart, vEnd)');

$kalmanFilter = 'demo/video-chat/frontend-vue/src/lib/kalman/filter.ts';
require_contains($kalmanFilter, 'const K = this.multiplyMatrix(this.multiplyMatrix(this.P, this.transpose(H)), SInv)');
require_contains($kalmanFilter, 'const dt4 = dt2 * dt2');
require_not_contains($kalmanFilter, 'const dt2 = 1');
require_not_contains($kalmanFilter, 'const dt3 = 1');
require_not_contains($kalmanFilter, 'const dt4 = 1');

require_contains('demo/video-chat/frontend-vue/codec-test.html', 'payload.subarray(HEAD + yB + uB, HEAD + yB + uB + vB);');
require_contains('demo/video-chat/frontend-vue/package.json', 'node tests/contract/wlvc-codec-port-contract.mjs');

require_tracked_absent('demo/video-chat/frontend-vue/codec-test.md');
require_tracked_absent('demo/video-chat/frontend-vue/src/lib/wavelet/README.md');
require_tracked_absent('demo/video-chat/frontend/src/lib/wasm/wasm-codec.ts');
require_tracked_absent('demo/video-chat/frontend/src/lib/wavelet/codec.ts');
require_tracked_absent('demo/video-chat/frontend/src/lib/kalman/filter.ts');

$provenance = 'documentation/experiment-intake-provenance.md';
require_contains($provenance, 'Verified codec port decision:');
require_contains($provenance, 'No additional experiment code is ported by this leaf without a targeted frontend contract.');
require_contains($provenance, 'The verified active correctness deltas are kept: V-channel decode uses the declared `vBytes` slice, decode rejects payload-length mismatch, `codec-test.html` uses the same bounded V-channel slice, Kalman gain multiplication includes `SInv`, and process-noise `dt4` is computed from `dt2` locally.');
require_contains($provenance, 'The verified active performance boundary is unchanged: C++/WASM generated sources remain at the audited experiment boundary, while WASM production delivery and stale-binding recovery are pinned by earlier Q-15 leaves.');
require_contains($provenance, 'Removed experiment markdown and the duplicate legacy frontend tree are not reintroduced; canonical developer docs stay under `documentation/dev/`.');
require_contains($provenance, '`demo/video-chat/frontend-vue/tests/contract/wlvc-codec-port-contract.mjs` is the targeted frontend guard for this decision.');

require_contains('SPRINT.md', '- [x] Port only verified codec correctness or performance improvements with targeted frontend tests.');
require_contains('READYNESS_TRACKER.md', 'Q-15 WLVC verified codec port decision');
require_contains('READYNESS_TRACKER.md', 'Added frontend contract `wlvc-codec-port-contract.mjs` and PHPT `736-wlvc-verified-codec-port-contract.phpt`.');

echo "OK\n";
?>
--EXPECT--
OK
