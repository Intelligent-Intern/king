--TEST--
King WLVC WASM delivery keeps production-safe MIME and cache-buster handling
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

$wasmCodec = 'demo/video-chat/frontend-vue/src/lib/wasm/wasm-codec.ts';
require_contains($wasmCodec, "const WASM_MIME_CACHE_BUSTER = 'application-wasm-20260421'");
require_contains($wasmCodec, "const createModule = (await import('./wlvc.js')).default");
require_contains($wasmCodec, 'locateFile: (path: string) => {');
require_contains($wasmCodec, "if (path.endsWith('.wasm')) {");
require_contains($wasmCodec, "const url = new URL('./wlvc.wasm', import.meta.url)");
require_contains($wasmCodec, "url.searchParams.set('v', WASM_MIME_CACHE_BUSTER)");
require_contains($wasmCodec, 'return url.href');
require_contains($wasmCodec, 'return path');
require_not_contains($wasmCodec, 'cdn.jsdelivr.net');
require_not_contains($wasmCodec, "fetch('./wlvc.wasm')");
require_not_contains($wasmCodec, 'application/octet-stream');

$edge = 'demo/video-chat/edge/edge.php';
require_contains($edge, "'wasm' => 'application/wasm',");
require_contains($edge, "'data', 'tflite', 'binarypb' => 'application/octet-stream',");
require_not_contains($edge, "'wasm' => 'application/octet-stream'");
require_not_contains($edge, "'wasm' => 'text/plain'");

$provenance = 'documentation/experiment-intake-provenance.md';
require_contains($provenance, 'WASM MIME/cache-buster decision:');
require_contains($provenance, 'Keep the current production-safe handling for this sprint leaf.');
require_contains($provenance, '`demo/video-chat/frontend-vue/src/lib/wasm/wasm-codec.ts` imports the bundled `wlvc.js`, resolves `wlvc.wasm` through Emscripten `locateFile`, and appends `?v=application-wasm-20260421` through `WASM_MIME_CACHE_BUSTER`.');
require_contains($provenance, '`demo/video-chat/edge/edge.php` serves `.wasm` as `application/wasm`; the cache-buster only invalidates stale cached responses after MIME fixes and is not a MIME workaround by itself.');
require_contains($provenance, 'The audited experiment boundary has no better production-safe replacement for this handling.');

require_contains('READYNESS_TRACKER.md', 'Q-15 WLVC WASM MIME/cache-buster decision');
require_contains('READYNESS_TRACKER.md', 'Added `732-wlvc-wasm-mime-cache-buster-contract.phpt`.');

echo "OK\n";
?>
--EXPECT--
OK
