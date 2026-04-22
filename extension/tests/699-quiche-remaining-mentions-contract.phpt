--TEST--
King remaining Quiche mentions are historical, guard literals, or temporary fixtures
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

function require_active_product_path_quiche_free(string $path): void
{
    $source = source($path);
    if (preg_match('/quiche/i', $source) === 1) {
        throw new RuntimeException($path . ' contains an active product-path Quiche reference.');
    }
}

$activeProductFiles = [
    'stubs/king.php',
    'extension/config.m4',
];

foreach (['extension/src', 'extension/include'] as $directory) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }

        $extension = $file->getExtension();
        if (!in_array($extension, ['c', 'h', 'inc'], true)) {
            continue;
        }

        $activeProductFiles[] = str_replace($root . '/', '', $file->getPathname());
    }
}

foreach ($activeProductFiles as $path) {
    require_active_product_path_quiche_free($path);
}

$activeSurfaceFiles = [
    'stubs/king.php' => [
        'active `quiche` runtime',
    ],
    'extension/src/php_king/lifecycle.inc' => [
        'runtime-loaded libquiche',
    ],
    'extension/src/php_king.c' => [
        'quiche_config',
    ],
    'extension/src/client/index.c' => [
        'quiche-backed HTTP/3',
    ],
    'extension/include/config/config.h' => [
        '<quiche.h>',
        'quiche_config',
        'quiche_cfg',
        'quiche library',
    ],
    'extension/src/config/internal/snapshot.inc' => [
        'quiche_cfg',
    ],
    'extension/src/client/http3/request_response.inc' => [
        '"quiche_h3"',
    ],
    'extension/src/server/http2/hpack_huffman.inc' => [
        'quiche/octets',
    ],
];

foreach ($activeSurfaceFiles as $path => $forbiddenLiterals) {
    $fileSource = source($path);
    foreach ($forbiddenLiterals as $forbidden) {
        require_not_contains($path, $fileSource, $forbidden);
    }
}

require_contains(
    'client HTTP/3 response materialization',
    source('extension/src/client/http3/request_response.inc'),
    '"lsquic_h3"'
);
require_contains(
    'config header',
    source('extension/include/config/config.h'),
    'king_quic_backend_config *quic_backend_config;'
);
require_contains(
    'MINFO backend row',
    source('extension/src/php_king/lifecycle.inc'),
    'not built (LSQUIC required)'
);

$historicalDocs = [
    'README.md' => 'Legacy Quiche build scripts',
    'PROJECT_ASSESSMENT.md' => 'legacy Quiche bootstrap inputs outside the active product path',
    'READYNESS_TRACKER.md' => 'legacy Quiche bootstrap inputs',
    'DEPENDENCY_PROVENANCE.md' => 'Legacy Quiche bootstrap locks are no longer provenance inputs.',
    'benchmarks/README.md' => 'Cargo, or Quiche runtime environment.',
];
foreach ($historicalDocs as $path => $expectedHistoricalNote) {
    require_contains($path, source($path), $expectedHistoricalNote);
}

$classification = require $root . '/extension/tests/http3_rust_peer_classification.inc';
foreach ($classification as $path => $entry) {
    if (($entry['active_product_path'] ?? null) !== false
        || ($entry['product_bootstrap'] ?? null) !== false
        || ($entry['temporary'] ?? null) !== true
        || ($entry['expiry_issue'] ?? null) !== '#Q-9') {
        throw new RuntimeException($path . ' is not marked as a temporary non-product HTTP/3 fixture.');
    }
}

require_contains(
    'Q-9 issue leaf',
    source('ISSUES.md'),
    '- [x] Mark remaining Quiche mentions as historical notes or remove them.'
);
require_contains(
    'Q-9 active product path leaf',
    source('ISSUES.md'),
    '- [x] `rg -n "quiche|QUICHE"` finds no active product-path references.'
);

echo "OK\n";
?>
--EXPECT--
OK
