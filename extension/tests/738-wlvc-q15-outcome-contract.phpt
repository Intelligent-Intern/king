--TEST--
King WLVC Q-15 experiment intake outcome is documented and closed
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

$readiness = 'READYNESS_TRACKER.md';
require_contains($readiness, 'Q-15 WLVC final outcome');
require_contains($readiness, 'closed the WLVC/WASM/Kalman experiment intake for the current sprint');
require_contains($readiness, 'Remaining codec experiment diffs are either ported with targeted tests or explicitly classified as superseded by the stronger current implementation.');
require_contains($readiness, 'experiment-diff provenance, WASM MIME/cache-busting, debug-log abstraction, Emscripten binding recovery, SFU room/call compatibility, verified codec correctness/performance deltas, and runtime regressions');
require_contains($readiness, 'C++/WASM generated sources remain at the audited experiment boundary');
require_contains($readiness, 'removed experiment markdown and duplicate legacy frontend trees stay out of active source');
require_contains($readiness, 'no weaker P2P/runtime shortcut was introduced');
require_contains($readiness, 'Added PHPT `738-wlvc-q15-outcome-contract.phpt`.');

$provenance = 'documentation/experiment-intake-provenance.md';
require_contains($provenance, 'Q-15 WLVC outcome:');
require_contains($provenance, 'Remaining codec/WASM/Kalman experiment diffs are either ported with targeted tests or explicitly classified as superseded by the current stronger implementation.');
require_contains($provenance, 'The closed outcome is recorded in `READYNESS_TRACKER.md` and pinned by `extension/tests/738-wlvc-q15-outcome-contract.phpt`.');
require_not_contains($provenance, 'Remaining Q-15 leaves decide');

foreach ([
    'extension/tests/731-wlvc-experiment-diff-audit-contract.phpt',
    'extension/tests/732-wlvc-wasm-mime-cache-buster-contract.phpt',
    'extension/tests/733-wlvc-debug-log-abstraction-contract.phpt',
    'extension/tests/734-wlvc-binding-recovery-contract.phpt',
    'extension/tests/735-wlvc-sfu-compatibility-contract.phpt',
    'extension/tests/736-wlvc-verified-codec-port-contract.phpt',
    'extension/tests/737-wlvc-runtime-regression-contract.phpt',
    'extension/tests/738-wlvc-q15-outcome-contract.phpt',
] as $path) {
    if (!is_file($root . '/' . $path)) {
        throw new RuntimeException('Missing Q-15 proof file: ' . $path);
    }
}

echo "OK\n";
?>
--EXPECT--
OK
