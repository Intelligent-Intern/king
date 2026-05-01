--TEST--
King HTTP/3 migration closure keeps docs, tests, CI, and release manifests on the LSQUIC stack
--FILE--
<?php
$root = dirname(__DIR__, 2);

function source(string $path): string
{
    global $root;
    $contents = file_get_contents($root . '/' . $path);
    if (!is_string($contents)) {
        throw new RuntimeException('Could not read ' . $path);
    }
    return $contents;
}

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

function require_file(string $path): void
{
    global $root;
    if (!is_file($root . '/' . $path)) {
        throw new RuntimeException('Missing required migration closure file: ' . $path);
    }
}

foreach ([
    'extension/tests/698-quiche-main-docs-update-contract.phpt',
    'extension/tests/699-quiche-remaining-mentions-contract.phpt',
    'extension/tests/700-quiche-cargo-artifact-hygiene-contract.phpt',
    'extension/tests/701-ci-builds-http3-stack-contract.phpt',
    'extension/tests/702-ci-http3-contract-suite-coverage-contract.phpt',
    'extension/tests/703-ci-migration-guardrails-contract.phpt',
    'extension/tests/704-release-supply-chain-provenance-pins-contract.phpt',
    'extension/tests/705-release-package-manifest-dependency-hashes-contract.phpt',
    'extension/tests/706-http3-release-regression-matrix-contract.phpt',
    'documentation/http3-regression-evidence.md',
] as $path) {
    require_file($path);
}

$projectAssessment = source('documentation/project-assessment.md');
$readiness = source('READYNESS_TRACKER.md');
$readme = source('README.md');
$regressionEvidence = source('documentation/http3-regression-evidence.md');
$dependencyProvenance = source('documentation/dependency-provenance.md');
$staticChecks = source('infra/scripts/static-checks.sh');
$packageRelease = source('infra/scripts/package-release.sh');
$verifyReleasePackage = source('infra/scripts/verify-release-package.sh');
$releaseManifestCheck = source('infra/scripts/check-release-package-manifest-contract.rb');
$releaseSupplyChainCheck = source('infra/scripts/check-release-supply-chain-provenance.rb');

foreach ([
    'documentation/project-assessment.md' => $projectAssessment,
    'READYNESS_TRACKER.md' => $readiness,
] as $path => $contents) {
    require_contains($path, $contents, 'HTTP/3 Q-12 migration closure');
    require_contains($path, $contents, 'LSQUIC/BoringSSL');
    require_contains($path, $contents, 'Quiche is removed from the active product path');
}

require_contains('README', $readme, 'infra/scripts/lsquic-bootstrap.lock');
require_contains_compacted('README', $readme, 'Legacy Quiche build scripts, Cargo locks, and runtime binary fallbacks are no longer part of the active build or release contract.');

foreach ([
    'regression evidence' => $regressionEvidence,
    'dependency provenance' => $dependencyProvenance,
] as $label => $contents) {
    require_contains($label, $contents, 'LSQUIC');
    require_contains($label, $contents, 'BoringSSL');
}

foreach ([
    'check-ci-builds-http3-stack.rb',
    'check-http3-product-build-path.rb',
    'check-ci-http3-contract-suites.rb',
    'check-ci-migration-guardrails.rb',
    'check-release-supply-chain-provenance.rb',
    'check-release-package-manifest-contract.rb',
    'check-dependency-provenance-doc.sh',
    'check-repo-artifact-hygiene.sh',
] as $scriptNeedle) {
    require_contains('static checks', $staticChecks, $scriptNeedle);
}

foreach ([
    'package-release' => $packageRelease,
    'verify-release-package' => $verifyReleasePackage,
    'release manifest check' => $releaseManifestCheck,
] as $label => $contents) {
    require_contains($label, $contents, 'lsquic');
    require_contains($label, $contents, 'boringssl');
    require_contains($label, $contents, 'lsquic-bootstrap.lock');
    require_not_contains($label, $contents, 'runtime/libquiche.so');
    require_not_contains($label, $contents, 'runtime/quiche-server');
}

foreach ([
    'lsquic_bootstrap_lock_sha256',
    'KING_LSQUIC_ARCHIVE_SHA256',
    'KING_LSQUIC_BORINGSSL_ARCHIVE_SHA256',
] as $needle) {
    require_contains('release supply-chain check', $releaseSupplyChainCheck, $needle);
}

foreach ([
    'extension/src/client/http3.c',
    'extension/src/server/http3.c',
] as $path) {
    $contents = source($path);
    require_contains($path, $contents, 'KING_HTTP3_BACKEND_LSQUIC');
    require_contains($path, $contents, '<lsquic.h>');
    require_not_contains($path, $contents, '<quiche.h>');
    require_not_contains($path, $contents, 'king_http3_ensure_quiche_ready');
}

echo "OK\n";
?>
--EXPECT--
OK
