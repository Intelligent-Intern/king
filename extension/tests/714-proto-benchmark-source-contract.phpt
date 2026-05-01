--TEST--
King proto batch and integer encoding benchmarks are source-only canonical cases
--FILE--
<?php
$root = dirname(__DIR__, 2);

function source(string $path): string
{
    global $root;
    $source = file_get_contents($root . '/' . $path);
    if (!is_string($source)) {
        throw new RuntimeException('Could not read ' . $path);
    }
    return $source;
}

function require_contains(string $path, string $needle): void
{
    if (!str_contains(source($path), $needle)) {
        throw new RuntimeException($path . ' must contain ' . $needle);
    }
}

$expectations = [
    'benchmarks/run.php' => [
        "require_once __DIR__ . '/cases/proto_batch.php';",
        "require_once __DIR__ . '/cases/proto_integer_encodings.php';",
        "'proto_batch' => king_benchmark_proto_batch_case(),",
        "'proto_varint' => king_benchmark_proto_varint_case(),",
        "'proto_omega' => king_benchmark_proto_omega_case(),",
    ],
    'benchmarks/cases/proto_batch.php' => [
        'function king_benchmark_proto_batch_case(): array',
        'king_proto_encode_batch($schemaName, $records)',
        'king_proto_decode_batch($schemaName, $encodedRecords)',
        'function king_benchmark_proto_batch_records(int $count): array',
    ],
    'benchmarks/cases/proto_integer_encodings.php' => [
        'function king_benchmark_proto_varint_case(): array',
        'function king_benchmark_proto_omega_case(): array',
        'king_proto_encode($schemaName, [',
        'king_proto_decode($schemaName, $encoded)',
        'function king_benchmark_elias_omega_encode(int $value): string',
        'function king_benchmark_elias_omega_decode(string $encoded): int',
    ],
    'benchmarks/budgets/canonical-ci.json' => [
        '"proto_batch"',
        '"proto_varint"',
        '"proto_omega"',
    ],
    'documentation/dev/benchmarks.md' => [
        '`proto_batch`',
        '`proto_varint`',
        '`proto_omega`',
        '`--case=proto_varint,proto_omega`',
    ],
    'infra/scripts/static-checks.sh' => [
        'php_lint benchmarks/cases/proto_batch.php',
        'php_lint benchmarks/cases/proto_integer_encodings.php',
    ],
];

foreach ($expectations as $path => $needles) {
    foreach ($needles as $needle) {
        require_contains($path, $needle);
    }
}

echo "OK\n";
?>
--EXPECT--
OK
