--TEST--
King WLVC active codec hotpaths keep debug-log abstraction instead of direct console noise
--FILE--
<?php
$root = dirname(__DIR__, 2);

function read_source(string $path): string
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
    if (!str_contains(read_source($path), $needle)) {
        throw new RuntimeException($path . ' must contain ' . $needle);
    }
}

function require_not_contains(string $path, string $needle): void
{
    if (str_contains(read_source($path), $needle)) {
        throw new RuntimeException($path . ' must not contain ' . $needle);
    }
}

function require_no_direct_console_in_hotpath(string $relativePath): void
{
    $source = read_source($relativePath);
    if (preg_match('/\bconsole\.(?:log|warn|error|debug|info)\b/', $source, $match) === 1) {
        throw new RuntimeException($relativePath . ' must use debugLogs abstraction instead of ' . $match[0]);
    }
}

$debugLogs = 'demo/video-chat/frontend-vue/src/support/debugLogs.js';
require_contains($debugLogs, 'export const VIDEOCHAT_DEBUG_LOGS = parseEnvFlag(import.meta.env.VITE_VIDEOCHAT_DEBUG_LOGS, false);');
require_contains($debugLogs, 'export function debugLog(...args)');
require_contains($debugLogs, 'export function debugWarn(...args)');
require_contains($debugLogs, 'export function debugError(...args)');
require_contains($debugLogs, 'if (!VIDEOCHAT_DEBUG_LOGS) return;');

$hotpathDirs = [
    'demo/video-chat/frontend-vue/src/lib/wasm',
    'demo/video-chat/frontend-vue/src/lib/wavelet',
    'demo/video-chat/frontend-vue/src/lib/kalman',
];
$generatedExceptions = [
    'demo/video-chat/frontend-vue/src/lib/wasm/wlvc.js' => true,
];

foreach ($hotpathDirs as $dir) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir));
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $extension = strtolower($file->getExtension());
        if (!in_array($extension, ['js', 'ts'], true)) {
            continue;
        }
        $relativePath = str_replace($root . '/', '', $file->getPathname());
        if (isset($generatedExceptions[$relativePath])) {
            continue;
        }
        require_no_direct_console_in_hotpath($relativePath);
    }
}

require_contains('demo/video-chat/frontend-vue/src/lib/wasm/wasm-codec.ts', "import { debugWarn } from '../../support/debugLogs.js'");
require_contains('demo/video-chat/frontend-vue/src/lib/wasm/wasm-codec.ts', "debugWarn('[WASM Codec] Failed to load:', err)");
require_contains('demo/video-chat/frontend-vue/src/lib/wavelet/webrtc-shim.ts', "import { debugLog, debugWarn } from '../../support/debugLogs.js'");
require_contains('demo/video-chat/frontend-vue/src/lib/wavelet/webrtc-shim.ts', "debugLog('[WaveletCodec] Starting WASM init...')");
require_contains('demo/video-chat/frontend-vue/src/lib/wavelet/webrtc-shim.ts', "debugWarn('[WaveletCodec] Decode error:', error)");
require_contains('demo/video-chat/frontend-vue/src/lib/wavelet/transform.ts', "import { debugLog, debugWarn } from '../../support/debugLogs.js'");
require_contains('demo/video-chat/frontend-vue/src/lib/wavelet/processor-pipeline.ts', "import { debugWarn } from '../../support/debugLogs.js'");
require_contains('demo/video-chat/frontend-vue/src/lib/wavelet/processor-pipeline.ts', "debugWarn('[Wavelet] Frame error:', e)");
require_contains('demo/video-chat/frontend-vue/src/lib/kalman/processor.ts', "import { debugLog, debugWarn } from '../../support/debugLogs.js'");
require_contains('demo/video-chat/frontend-vue/src/lib/kalman/processor.ts', "debugLog('[VideoProcessor]', {");
require_contains('demo/video-chat/frontend-vue/src/lib/kalman/processor.ts', "debugWarn('Failed to decode frame:', error)");

require_contains('demo/video-chat/frontend-vue/src/lib/wasm/wlvc.js', 'console.log.bind(console)');
require_contains('demo/video-chat/frontend-vue/codec-test.html', "console.log('[WASM] Loaded from'");

$provenance = 'documentation/experiment-intake-provenance.md';
require_contains($provenance, 'Debug-log abstraction decision:');
require_contains($provenance, 'Active TypeScript codec, WASM wrapper, wavelet transform, wavelet processor, and Kalman processor files must use `debugLog`/`debugWarn`');
require_contains($provenance, 'Direct `console.*` calls are forbidden in active `src/lib/wasm`, `src/lib/wavelet`, and `src/lib/kalman` JavaScript/TypeScript hotpath files, except generated Emscripten glue `wlvc.js`.');
require_contains($provenance, '`codec-test.html` remains a standalone manual diagnostic page and may keep browser-console diagnostics; it is not the production media hotpath.');

require_contains('READYNESS_TRACKER.md', 'Q-15 WLVC debug-log abstraction decision');
require_contains('READYNESS_TRACKER.md', 'Added `733-wlvc-debug-log-abstraction-contract.phpt`.');

echo "OK\n";
?>
--EXPECT--
OK
