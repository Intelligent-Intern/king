--TEST--
King HTTP/3 build tooling no longer carries Quiche scripts or locks
--FILE--
<?php
$root = dirname(__DIR__, 2);

function require_contains(string $label, string $source, string $needle): void
{
    if (!str_contains($source, $needle)) {
        throw new RuntimeException($label . ' must contain ' . $needle);
    }
}

function require_not_contains(string $label, string $source, string $needle): void
{
    if (str_contains($source, $needle)) {
        throw new RuntimeException($label . ' must not contain ' . $needle);
    }
}

foreach ([
    'infra/scripts/cargo-build-compat.sh',
    'infra/scripts/quiche-bootstrap.lock',
    'infra/scripts/quiche-workspace.Cargo.lock',
    'extension/config.m4.full',
] as $path) {
    if (file_exists($root . '/' . $path)) {
        throw new RuntimeException('Removed Quiche build tooling still exists: ' . $path);
    }
}

$benchmarkWrapper = (string) file_get_contents($root . '/benchmarks/run-canonical.sh');
$benchmarkDoc = (string) file_get_contents($root . '/documentation/dev/benchmarks.md');
$flakeClassifier = (string) file_get_contents($root . '/infra/scripts/classify-phpt-flakes.sh');
$provenanceDoc = (string) file_get_contents($root . '/documentation/dependency-provenance.md');
$provenanceCheck = (string) file_get_contents($root . '/infra/scripts/check-dependency-provenance-doc.sh');
$contribute = (string) file_get_contents($root . '/CONTRIBUTE');
$runtimeDockerfile = (string) file_get_contents($root . '/infra/php-runtime.Dockerfile');
$demoDockerfile = (string) file_get_contents($root . '/infra/demo-server/Dockerfile');
$configHeader = (string) file_get_contents($root . '/extension/config.h.in')
    . "\n"
    . (string) file_get_contents($root . '/extension/config.h');
$readiness = (string) file_get_contents($root . '/READYNESS_TRACKER.md');

foreach ([
    'benchmark wrapper' => $benchmarkWrapper,
    'flake classifier' => $flakeClassifier,
    'dependency provenance checker' => $provenanceCheck,
    'PHP runtime Dockerfile' => $runtimeDockerfile,
    'demo server Dockerfile' => $demoDockerfile,
    'generated config header template' => $configHeader,
] as $label => $source) {
    foreach ([
        'KING_QUICHE_LIBRARY',
        'KING_QUICHE_SERVER',
        'KING_QUICHE_TOOLCHAIN_CONFIRM',
        'libquiche.so',
        'quiche-server',
        'quiche-bootstrap.lock',
        'quiche-workspace.Cargo.lock',
    ] as $forbidden) {
        require_not_contains($label, $source, $forbidden);
    }
}

require_contains('benchmark documentation', $benchmarkDoc, 'does not require a Rust,');
require_contains('benchmark documentation', $benchmarkDoc, 'Cargo, or Quiche runtime environment.');
require_contains('contribution guide', $contribute, 'infra/scripts/lsquic-bootstrap.lock');
require_contains('contribution guide', $contribute, 'unlocked Cargo fallbacks');
require_not_contains('contribution guide', $contribute, 'infra/scripts/quiche-bootstrap.lock');
require_not_contains('dependency provenance doc', $provenanceDoc, 'Legacy Quiche Bootstrap Provenance Pins');
require_not_contains('dependency provenance doc', $provenanceDoc, 'infra/scripts/quiche-bootstrap.lock');
require_contains('dependency provenance checker', $provenanceCheck, 'check-lsquic-bootstrap.sh');
require_not_contains('dependency provenance checker', $provenanceCheck, 'QUICHE_LOCK');
require_contains('PHP runtime Dockerfile', $runtimeDockerfile, 'KING_LSQUIC_LIBRARY=/opt/king/package/runtime/liblsquic.so');
require_contains('demo server Dockerfile', $demoDockerfile, 'KING_LSQUIC_LIBRARY=/opt/king/package/runtime/liblsquic.so');
require_not_contains('generated config header template', $configHeader, 'HAVE_KING_QUICHE');
require_not_contains('generated config header template', $configHeader, 'HAVE_LIBQUICHE');
require_contains(
    'Quiche cleanup closure',
    $readiness,
    'Quiche build scripts/locks'
);

echo "OK\n";
?>
--EXPECT--
OK
