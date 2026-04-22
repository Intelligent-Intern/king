--TEST--
King HTTP/3 main docs describe the LSQUIC path after Quiche removal
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

function require_contains_compacted(string $label, string $source, string $needle): void
{
    require_contains($label, (string) preg_replace('/\s+/', ' ', $source), $needle);
}

$readme = (string) file_get_contents($root . '/README.md');
$assessment = (string) file_get_contents($root . '/documentation/project-assessment.md');
$readiness = (string) file_get_contents($root . '/READYNESS_TRACKER.md');
$provenance = (string) file_get_contents($root . '/documentation/dependency-provenance.md');
$quicDoc = (string) file_get_contents($root . '/documentation/quic-and-tls.md');

require_contains('README', $readme, 'infra/scripts/lsquic-bootstrap.lock');
require_contains_compacted(
    'README',
    $readme,
    'Legacy Quiche build scripts, Cargo locks, and runtime binary fallbacks are no longer part of the active build or release contract.'
);

require_contains('project assessment', $assessment, 'LSQUIC/BoringSSL/ls-qpack/ls-hpack pinset');
require_contains('project assessment', $assessment, 'Quiche tooling/locks');

require_contains_compacted(
    'readiness tracker',
    $readiness,
    'without branch-based fallbacks, legacy Quiche bootstrap inputs, or unlocked Cargo retries.'
);

require_contains_compacted(
    'dependency provenance doc',
    $provenance,
    'active LSQUIC/BoringSSL-based QUIC/HTTP3 stack.'
);
require_contains_compacted(
    'dependency provenance doc',
    $provenance,
    'Legacy Quiche bootstrap locks are no longer provenance inputs.'
);
require_not_contains('dependency provenance doc', $provenance, 'Legacy Quiche Bootstrap Provenance Pins');
require_not_contains('dependency provenance doc', $provenance, 'infra/scripts/quiche-bootstrap.lock');

require_contains('QUIC/TLS documentation', $quicDoc, 'active LSQUIC event loop');
require_contains('QUIC/TLS documentation', $quicDoc, 'live LSQUIC counters');
require_not_contains('QUIC/TLS documentation', $quicDoc, 'active quiche event loop');
require_not_contains('QUIC/TLS documentation', $quicDoc, 'live quiche counters');

require_contains(
    'readiness tracker',
    $readiness,
    'Recent Quiche cleanup closure:'
);

echo "OK\n";
?>
--EXPECT--
OK
