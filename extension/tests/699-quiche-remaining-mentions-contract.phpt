--TEST--
King remaining Quiche mentions are classified and outside active product paths
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

function tracked_files(): array
{
    global $root;

    $command = 'git -C ' . escapeshellarg($root) . ' ls-files -z 2>/dev/null';
    $output = shell_exec($command);
    if (!is_string($output) || $output === '') {
        throw new RuntimeException('Could not list tracked files.');
    }

    $files = array_values(array_filter(explode("\0", $output), static fn (string $path): bool => $path !== ''));
    sort($files);

    return $files;
}

function remaining_quiche_match_category(string $path, array $temporaryFixtures): ?string
{
    if (isset($temporaryFixtures[$path])) {
        return 'temporary_test_fixture';
    }

    if (in_array($path, [
        'README.md',
        'PROJECT_ASSESSMENT.md',
        'READYNESS_TRACKER.md',
        'DEPENDENCY_PROVENANCE.md',
        'benchmarks/README.md',
        'ISSUES.md',
    ], true)) {
        return 'historical_migration_note';
    }

    if (in_array($path, [
        '.dockerignore',
        '.gitignore',
        'Makefile',
        'extension/Makefile.frag',
        'infra/scripts/check-ci-http3-contract-suites.rb',
        'infra/scripts/check-ci-linux-reproducible-builds.rb',
        'infra/scripts/check-ci-migration-guardrails.rb',
        'infra/scripts/check-dependency-provenance-doc.sh',
        'infra/scripts/check-http3-lsquic-loader-contract.php',
        'infra/scripts/check-http3-product-build-path.rb',
        'infra/scripts/check-repo-artifact-hygiene.sh',
        'infra/scripts/package-pie-source.sh',
    ], true)) {
        return 'guard_or_packaging_literal';
    }

    if (in_array($path, [
        'extension/tests/http3_peer_replacement_strategy.inc',
        'extension/tests/http3_rust_peer_classification.inc',
    ], true)) {
        return 'temporary_fixture_migration_contract';
    }

    if (preg_match('#^extension/tests/[0-9]+-[^/]+\.phpt$#', $path) === 1
        || in_array($path, [
            'extension/tests/http3_skip_rule_audit.inc',
        ], true)) {
        return 'contract_test_literal';
    }

    return null;
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

$unclassifiedMatches = [];
$categoryCounts = [];
foreach (tracked_files() as $path) {
    if (!is_file($root . '/' . $path)) {
        continue;
    }

    $source = file_get_contents($root . '/' . $path);
    if (!is_string($source) || stripos($source, 'quiche') === false) {
        continue;
    }

    $category = remaining_quiche_match_category($path, $classification);
    if ($category === null) {
        $unclassifiedMatches[] = $path;
        continue;
    }

    $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;
}

if ($unclassifiedMatches !== []) {
    throw new RuntimeException(
        "Unclassified remaining Quiche matches:\n" .
        implode("\n", $unclassifiedMatches)
    );
}

foreach ([
    'historical_migration_note',
    'guard_or_packaging_literal',
    'contract_test_literal',
    'temporary_fixture_migration_contract',
    'temporary_test_fixture',
] as $expectedCategory) {
    if (($categoryCounts[$expectedCategory] ?? 0) < 1) {
        throw new RuntimeException('Remaining Quiche match category not exercised: ' . $expectedCategory);
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
require_contains(
    'Q-9 remaining match classification leaf',
    source('ISSUES.md'),
    '- [x] Remaining `rg -n "quiche|QUICHE"` matches are classified as historical migration notes, release history, guard literals, contract-test literals, or temporary test fixtures.'
);

echo "OK\n";
?>
--EXPECT--
OK
