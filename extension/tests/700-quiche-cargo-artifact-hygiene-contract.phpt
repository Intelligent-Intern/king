--TEST--
King Quiche and Cargo artifact hygiene is enforced by repository gates
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

$hygiene = source('infra/scripts/check-repo-artifact-hygiene.sh');
$requiredHygieneGuards = [
    'Quiche source/vendor trees must not be versioned',
    'Cargo home/vendor caches must not be versioned',
    'Cargo build target directories must not be versioned',
    'Legacy Quiche runtime artifacts must not be versioned',
    'Unclassified Cargo manifests/locks must not be versioned',
    'extension/tests/http3_ticket_server/Cargo.toml',
    'extension/tests/http3_ticket_server/Cargo.lock',
];

foreach ($requiredHygieneGuards as $guard) {
    require_contains('repo artifact hygiene gate', $hygiene, $guard);
}

require_contains(
    'repo artifact hygiene gate',
    $hygiene,
    "'(^|/)quiche(/|$)|(^|/)extension/quiche(/|$)'"
);
require_contains(
    'repo artifact hygiene gate',
    $hygiene,
    "'(^|/)target/'"
);
require_contains(
    'repo artifact hygiene gate',
    $hygiene,
    "'(^|/)Cargo\\.(toml|lock)$'"
);

require_contains(
    'static checks',
    source('infra/scripts/static-checks.sh'),
    'infra/scripts/check-repo-artifact-hygiene.sh'
);
require_contains(
    'GitHub Actions CI',
    source('.github/workflows/ci.yml'),
    'bash ./infra/scripts/check-repo-artifact-hygiene.sh'
);
require_contains(
    'Q-9 issue leaf',
    source('ISSUES.md'),
    '- [x] Extend artifact hygiene gate for Quiche/Cargo artifacts.'
);

echo "OK\n";
?>
--EXPECT--
OK
