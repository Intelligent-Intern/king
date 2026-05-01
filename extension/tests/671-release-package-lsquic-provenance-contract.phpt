--TEST--
King release manifests carry LSQUIC/BoringSSL artifact and provenance metadata
--FILE--
<?php
$root = dirname(__DIR__, 2);
$packageScript = (string) file_get_contents($root . '/infra/scripts/package-release.sh');
$packageVerifier = (string) file_get_contents($root . '/infra/scripts/verify-release-package.sh');
$supplyChainVerifier = (string) file_get_contents($root . '/infra/scripts/verify-release-supply-chain.sh');

var_dump(str_contains($packageScript, "'http3_stack' => ["));
var_dump(str_contains($packageScript, "'transport' => 'lsquic'"));
var_dump(str_contains($packageScript, "'tls' => 'boringssl'"));
var_dump(str_contains($packageScript, "'dependency_provenance' => \$dependencyProvenance"));
var_dump(str_contains($packageScript, "'lsquic_archive_sha256'"));
var_dump(str_contains($packageScript, "'boringssl_archive_sha256'"));
var_dump(str_contains($packageScript, "'ls_qpack_archive_sha256'"));
var_dump(str_contains($packageScript, "'ls_hpack_archive_sha256'"));

var_dump(str_contains($packageVerifier, "Manifest HTTP/3 stack metadata is invalid."));
var_dump(str_contains($packageVerifier, "Manifest dependency_provenance metadata is missing."));
var_dump(str_contains($packageVerifier, "Manifest dependency provenance archive hash is invalid"));

var_dump(str_contains($supplyChainVerifier, "'lsquic_archive_sha256' => \$readLock('KING_LSQUIC_ARCHIVE_SHA256')"));
var_dump(str_contains($supplyChainVerifier, "'boringssl_archive_sha256' => \$readLock('KING_LSQUIC_BORINGSSL_ARCHIVE_SHA256')"));
var_dump(str_contains($supplyChainVerifier, "'ls_qpack_archive_sha256' => \$readLock('KING_LSQUIC_LS_QPACK_ARCHIVE_SHA256')"));
var_dump(str_contains($supplyChainVerifier, "'ls_hpack_archive_sha256' => \$readLock('KING_LSQUIC_LS_HPACK_ARCHIVE_SHA256')"));
var_dump(str_contains($supplyChainVerifier, "'license_files' => preg_split('/\s+/', \$readLock('KING_LSQUIC_LICENSE_FILES')) ?: []"));
var_dump(str_contains($supplyChainVerifier, "'license_files' => preg_split('/\s+/', \$readLock('KING_LSQUIC_BORINGSSL_LICENSE_FILES')) ?: []"));
var_dump(str_contains($supplyChainVerifier, "'license_files' => preg_split('/\s+/', \$readLock('KING_LSQUIC_LS_QPACK_LICENSE_FILES')) ?: []"));
var_dump(str_contains($supplyChainVerifier, "'license_files' => preg_split('/\s+/', \$readLock('KING_LSQUIC_LS_HPACK_LICENSE_FILES')) ?: []"));
var_dump(!str_contains($packageScript, 'runtime/libquiche.so'));
var_dump(!str_contains($packageScript, 'runtime/quiche-server'));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
