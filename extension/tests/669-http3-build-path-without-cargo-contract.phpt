--TEST--
King HTTP/3 product build path does not bootstrap Cargo or Quiche runtime artifacts
--FILE--
<?php
$root = dirname(__DIR__, 2);
$files = [
    'infra/scripts/build-profile.sh' => (string) file_get_contents($root . '/infra/scripts/build-profile.sh'),
    'extension/Makefile.frag' => (string) file_get_contents($root . '/extension/Makefile.frag'),
    'infra/scripts/package-release.sh' => (string) file_get_contents($root . '/infra/scripts/package-release.sh'),
    'infra/scripts/smoke-profile.sh' => (string) file_get_contents($root . '/infra/scripts/smoke-profile.sh'),
    '.github/workflows/ci.yml' => (string) file_get_contents($root . '/.github/workflows/ci.yml'),
    '.github/workflows/release-merge-publish.yml' => (string) file_get_contents($root . '/.github/workflows/release-merge-publish.yml'),
];

foreach ($files as $name => $source) {
    var_dump(!str_contains($source, 'cargo build'));
    var_dump(!str_contains($source, 'Setup Rust pinned toolchain'));
    var_dump(!str_contains($source, 'Cache cargo registry'));
}

var_dump(!str_contains($files['infra/scripts/build-profile.sh'], 'toolchain-lock.sh --verify-rust'));
var_dump(!str_contains($files['infra/scripts/package-release.sh'], 'runtime/libquiche.so'));
var_dump(!str_contains($files['infra/scripts/package-release.sh'], 'runtime/quiche-server'));
var_dump(str_contains($files['infra/scripts/package-release.sh'], 'lsquic_bootstrap_lock_sha256'));
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
