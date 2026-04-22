--TEST--
King HTTP/3 Rust peers and Cargo locks are classified as temporary test-only fixtures
--FILE--
<?php
$root = dirname(__DIR__, 2);
$testsDir = $root . '/extension/tests';
$classificationPath = $testsDir . '/http3_rust_peer_classification.inc';
$classification = require $classificationPath;

function king_http3_classification_fail(string $message): void
{
    echo "FAIL: {$message}\n";
    exit(1);
}

function king_http3_relative_path(string $root, string $path): string
{
    $relative = substr($path, strlen($root) + 1);
    if (!is_string($relative) || $relative === '') {
        king_http3_classification_fail("path is outside repository root: {$path}");
    }

    return str_replace('\\', '/', $relative);
}

function king_http3_glob_paths(string $root, string $pattern): array
{
    $paths = glob($pattern);
    if (!is_array($paths)) {
        return [];
    }

    return array_map(
        static fn (string $path): string => king_http3_relative_path($root, $path),
        array_filter($paths, 'is_file')
    );
}

$inventory = array_merge(
    king_http3_glob_paths($root, $testsDir . '/http3_*.rs'),
    king_http3_glob_paths($root, $testsDir . '/http3_ticket_server/src/*.rs')
);

foreach ([
    $testsDir . '/http3_ticket_server/Cargo.toml',
    $testsDir . '/http3_ticket_server/Cargo.lock',
    $root . '/infra/scripts/quiche-workspace.Cargo.lock',
] as $path) {
    if (is_file($path)) {
        $inventory[] = king_http3_relative_path($root, $path);
    }
}

sort($inventory);
$classified = array_keys($classification);
sort($classified);

if ($inventory !== $classified) {
    king_http3_classification_fail(
        "HTTP/3 Rust/Cargo fixture classification drifted\n" .
        'inventory=' . json_encode($inventory, JSON_UNESCAPED_SLASHES) . "\n" .
        'classified=' . json_encode($classified, JSON_UNESCAPED_SLASHES)
    );
}

$validKinds = [
    'rust_test_peer' => true,
    'cargo_manifest' => true,
    'cargo_lock' => true,
];

foreach ($classification as $relativePath => $entry) {
    if (!is_array($entry)) {
        king_http3_classification_fail("{$relativePath} classification is not an object");
    }

    if (!is_file($root . '/' . $relativePath)) {
        king_http3_classification_fail("{$relativePath} classification points at a missing file");
    }

    $kind = $entry['kind'] ?? null;
    if (!is_string($kind) || !isset($validKinds[$kind])) {
        king_http3_classification_fail("{$relativePath} has invalid kind");
    }

    foreach (['context', 'purpose', 'expiry_issue'] as $field) {
        if (!isset($entry[$field]) || !is_string($entry[$field]) || trim($entry[$field]) === '') {
            king_http3_classification_fail("{$relativePath} has no {$field}");
        }
    }

    if (($entry['product_bootstrap'] ?? null) !== false) {
        king_http3_classification_fail("{$relativePath} is not explicitly excluded from product bootstrap");
    }

    if (($entry['active_product_path'] ?? null) !== false) {
        king_http3_classification_fail("{$relativePath} is not explicitly excluded from the active product path");
    }

    if (($entry['temporary'] ?? null) !== true) {
        king_http3_classification_fail("{$relativePath} is not marked temporary");
    }

    if (!preg_match('/^#Q-\d+$/', $entry['expiry_issue'])) {
        king_http3_classification_fail("{$relativePath} has no sprint expiry issue");
    }

    if ($kind === 'rust_test_peer' && !str_ends_with($relativePath, '.rs')) {
        king_http3_classification_fail("{$relativePath} rust peer is not a Rust source file");
    }

    if ($kind === 'cargo_manifest' && !str_ends_with($relativePath, 'Cargo.toml')) {
        king_http3_classification_fail("{$relativePath} cargo manifest is not Cargo.toml");
    }

    if ($kind === 'cargo_lock' && !str_ends_with($relativePath, 'Cargo.lock')) {
        king_http3_classification_fail("{$relativePath} cargo lock is not a Cargo.lock file");
    }
}

echo 'Classified ' . count($classification) . " HTTP/3 Rust/Cargo fixtures.\n";
?>
--EXPECT--
Classified 8 HTTP/3 Rust/Cargo fixtures.
