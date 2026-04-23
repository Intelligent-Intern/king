--TEST--
King WLVC WASM wrapper keeps encoder/decoder binding-mismatch recovery
--FILE--
<?php
$root = dirname(__DIR__, 2);

function source_text(string $path): string
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
    if (!str_contains(source_text($path), $needle)) {
        throw new RuntimeException($path . ' must contain ' . $needle);
    }
}

function require_match_count_at_least(string $path, string $pattern, int $minimum): void
{
    $count = preg_match_all($pattern, source_text($path));
    if ($count === false || $count < $minimum) {
        throw new RuntimeException($path . ' must match ' . $pattern . ' at least ' . $minimum . ' times, got ' . var_export($count, true));
    }
}

$wasmCodec = 'demo/video-chat/frontend-vue/src/lib/wasm/wasm-codec.ts';
require_contains($wasmCodec, 'function isBindingMismatchError(error: unknown, className: string): boolean');
require_contains($wasmCodec, "if (!(error instanceof Error)) return false");
require_contains($wasmCodec, "if (!message.includes('Expected null or instance of')) return false");
require_contains($wasmCodec, 'return message.includes(className)');

require_contains($wasmCodec, 'private recreateEncoder(): boolean');
require_contains($wasmCodec, 'if (!this.moduleRef) return false');
require_contains($wasmCodec, 'this.encoder?.delete()');
require_contains($wasmCodec, 'this.encoder = new this.moduleRef.Encoder(');
require_contains($wasmCodec, 'this.config.keyFrameInterval');
require_contains($wasmCodec, "if (!isBindingMismatchError(error, 'Encoder') || !this.recreateEncoder() || !this.encoder) {");
require_match_count_at_least($wasmCodec, '/encoded\s*=\s*this\.encoder\.encode\(imageData\.data,\s*timestampUs\)/', 2);

require_contains($wasmCodec, 'private recreateDecoder(): boolean');
require_contains($wasmCodec, 'this.decoder?.delete()');
require_contains($wasmCodec, 'this.decoder = new this.moduleRef.Decoder(this.config.width, this.config.height, this.config.quality)');
require_contains($wasmCodec, "if (!isBindingMismatchError(error, 'Decoder') || !this.recreateDecoder() || !this.decoder) {");
require_match_count_at_least($wasmCodec, '/rgba\s*=\s*this\.decoder\.decode\(encoded\)/', 2);

require_contains('demo/video-chat/frontend-vue/tests/contract/wlvc-binding-recovery-contract.mjs', '[wlvc-binding-recovery-contract] PASS');
require_contains('demo/video-chat/frontend-vue/package.json', 'node tests/contract/wlvc-wire-contract.mjs && node tests/contract/wlvc-binding-recovery-contract.mjs');

$provenance = 'documentation/experiment-intake-provenance.md';
require_contains($provenance, 'WASM binding-mismatch recovery decision:');
require_contains($provenance, 'The wrapper recognizes stale class-handle errors by checking `Expected null or instance of` plus the target class name.');
require_contains($provenance, 'On encoder mismatch, the wrapper deletes the stale encoder if possible, recreates it from the cached module reference with current width, height, quality, and key-frame interval, then retries the encode exactly through the recreated encoder.');
require_contains($provenance, 'On decoder mismatch, the wrapper deletes the stale decoder if possible, recreates it from the cached module reference with current width, height, and quality, then retries the decode exactly through the recreated decoder.');
require_contains($provenance, 'Non-binding errors still fail closed by rethrowing the original error; this recovery is not a broad catch-all fallback.');

require_contains('SPRINT.md', '- [x] Keep current WASM encoder/decoder binding-mismatch recovery unless disproven by tests.');
require_contains('READYNESS_TRACKER.md', 'Q-15 WLVC binding-mismatch recovery decision');
require_contains('READYNESS_TRACKER.md', 'Added frontend contract `wlvc-binding-recovery-contract.mjs` and PHPT `734-wlvc-binding-recovery-contract.phpt`.');

echo "OK\n";
?>
--EXPECT--
OK
