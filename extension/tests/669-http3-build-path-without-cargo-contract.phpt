--TEST--
King HTTP/3 product build path does not bootstrap Cargo or Quiche runtime artifacts
--FILE--
<?php
$root = dirname(__DIR__, 2);
$guard = $root . '/infra/scripts/check-http3-product-build-path.rb';
$files = [
    'infra/scripts/build-profile.sh' => (string) file_get_contents($root . '/infra/scripts/build-profile.sh'),
    'extension/Makefile.frag' => (string) file_get_contents($root . '/extension/Makefile.frag'),
    'infra/scripts/package-release.sh' => (string) file_get_contents($root . '/infra/scripts/package-release.sh'),
    'infra/scripts/smoke-profile.sh' => (string) file_get_contents($root . '/infra/scripts/smoke-profile.sh'),
    'documentation/pie-install.md' => (string) file_get_contents($root . '/documentation/pie-install.md'),
    '.github/workflows/ci.yml' => (string) file_get_contents($root . '/.github/workflows/ci.yml'),
    '.github/workflows/release-merge-publish.yml' => (string) file_get_contents($root . '/.github/workflows/release-merge-publish.yml'),
];
$pieDocNormalized = preg_replace('/\s+/', ' ', $files['documentation/pie-install.md']);

foreach ($files as $name => $source) {
    var_dump(!str_contains($source, 'cargo build'));
    var_dump(!str_contains($source, 'Setup Rust pinned toolchain'));
    var_dump(!str_contains($source, 'Cache cargo registry'));
}

var_dump(!str_contains($files['infra/scripts/build-profile.sh'], 'toolchain-lock.sh --verify-rust'));
var_dump(!str_contains($files['infra/scripts/package-release.sh'], 'runtime/libquiche.so'));
var_dump(!str_contains($files['infra/scripts/package-release.sh'], 'runtime/quiche-server'));
var_dump(str_contains($files['infra/scripts/package-release.sh'], 'lsquic_bootstrap_lock_sha256'));
var_dump(str_contains($pieDocNormalized, 'No Rust or Cargo toolchain is required for this PIE path.'));
var_dump(!str_contains($files['documentation/pie-install.md'], 'KING_QUICHE_TOOLCHAIN_CONFIRM'));

$output = [];
$status = 1;
exec('ruby ' . escapeshellarg($guard) . ' 2>&1', $output, $status);
if ($status !== 0) {
    echo implode("\n", $output), "\n";
}
var_dump($status === 0);
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
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
