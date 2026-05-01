--TEST--
King proto varint helpers stay architecture-neutral until a guarded ARM64 profile is proven
--FILE--
<?php
$root = dirname(__DIR__, 2);
$headerPath = $root . '/extension/include/iibin/iibin_internal.h';
$provenancePath = $root . '/documentation/experiment-intake-provenance.md';
$header = file_get_contents($headerPath);
$provenance = file_get_contents($provenancePath);

if (!is_string($header) || !is_string($provenance)) {
    throw new RuntimeException('Could not read varint policy inputs.');
}

foreach (['__aarch64__', '__arm64__', '__ARM_NEON', '__ARM_FEATURE'] as $literal) {
    if (str_contains($header, $literal)) {
        throw new RuntimeException('IIBIN varint helper contains unguarded architecture-specific branch: ' . $literal);
    }
}

foreach ([
    'KING_PROTO_VARINT_MAX_BYTES',
    '__builtin_clzll',
    'uint64 overflow-safe decode behavior without architecture-specific unaligned reads',
    'ARM64-specific varint decode unrolling',
    'remains out of the production path',
] as $literal) {
    $source = str_starts_with($literal, 'ARM64') || str_contains($literal, 'uint64 overflow-safe') ? $provenance : $header . "\n" . $provenance;
    if (!str_contains($source, $literal)) {
        throw new RuntimeException('Missing varint architecture policy literal: ' . $literal);
    }
}

echo "OK\n";
?>
--EXPECT--
OK
