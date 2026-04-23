--TEST--
King proto float and double bit helpers are consolidated in the shared IIBIN header
--FILE--
<?php
$root = dirname(__DIR__, 2);
$header = file_get_contents($root . '/extension/include/iibin/iibin_internal.h');
$encoding = file_get_contents($root . '/extension/src/iibin/iibin_encoding.c');
$decoding = file_get_contents($root . '/extension/src/iibin/iibin_decoding.c');

if (!is_string($header) || !is_string($encoding) || !is_string($decoding)) {
    throw new RuntimeException('Could not read IIBIN helper sources.');
}

foreach ([
    'static inline uint32_t king_proto_float_to_bits(float value)',
    'static inline float king_proto_bits_to_float(uint32_t bits)',
    'static inline uint64_t king_proto_double_to_bits(double value)',
    'static inline double king_proto_bits_to_double(uint64_t bits)',
] as $literal) {
    if (!str_contains($header, $literal)) {
        throw new RuntimeException('Shared header is missing helper: ' . $literal);
    }
}

foreach ([
    'iibin_encoding.c' => $encoding,
    'iibin_decoding.c' => $decoding,
] as $path => $source) {
    foreach (['king_proto_float_to_bits(', 'king_proto_bits_to_float(', 'king_proto_double_to_bits(', 'king_proto_bits_to_double('] as $needle) {
        if (substr_count($source, $needle) > 0) {
            throw new RuntimeException($path . ' still defines local float/double helper ' . $needle);
        }
    }
}

echo "OK\n";
?>
--EXPECT--
OK
