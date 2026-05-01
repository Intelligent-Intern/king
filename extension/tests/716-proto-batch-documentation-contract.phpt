--TEST--
King proto batch documentation pins behavior limits and performance expectations
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
    'documentation/iibin.md' => [
        'king_proto_encode_batch()',
        'king_proto_decode_batch()',
        'King\\IIBIN::encodeBatch()',
        'King\\IIBIN::decodeBatch()',
        'Batch output keeps input iteration order.',
        'fails and no partial result is returned',
        'preserve that original exception as `previous`',
        'The current hard safety bound is `65536` records per batch.',
        'Batch APIs are not streaming APIs',
        './benchmarks/run-canonical.sh --case=proto_batch',
        './benchmarks/run-canonical.sh --case=proto_varint,proto_omega',
        'architecture-neutral',
    ],
    'documentation/procedural-api.md' => [
        '| `king_proto_encode_batch()` | Encodes a bounded list of same-schema records',
        '| `king_proto_decode_batch()` | Decodes a bounded list of same-schema binary records',
    ],
    'documentation/object-api.md' => [
        '| `encodeBatch(string $schema, array $records)` | Encodes a bounded list of same-schema records',
        '| `decodeBatch(string $schema, array $records, bool|string|array $decodeAsObject = false)` | Decodes a bounded list of same-schema binary records',
    ],
    'stubs/king.php' => [
        'rejects batches above 65536 records before',
        'reports the failing batch record index',
        'preserving lower-level encode errors as the previous exception',
        'preserving lower-level decode errors',
        '@throws \\King\\ValidationException|\\King\\RuntimeException|\\ValueError',
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
