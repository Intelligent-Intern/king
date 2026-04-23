--TEST--
King proto batch internals use bounded IIBIN helpers with per-record error context
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

function require_not_contains(string $path, string $needle): void
{
    if (str_contains(source($path), $needle)) {
        throw new RuntimeException($path . ' must not contain ' . $needle);
    }
}

$expectations = [
    'extension/include/iibin/iibin_internal.h' => [
        '#define KING_IIBIN_BATCH_MAX_RECORDS 65536',
        'zend_result king_iibin_encode_batch(',
        'zend_result king_iibin_decode_batch(',
    ],
    'extension/src/iibin/iibin_encoding.c' => [
        'zend_result king_iibin_encode_batch(',
        'record_count = zend_hash_num_elements(Z_ARRVAL_P(records))',
        'record_count > KING_IIBIN_BATCH_MAX_RECORDS',
        'Batch encoding failed: record count %zu exceeds the maximum of %u.',
        'array_init_size(encoded_records, record_count)',
        'ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(records), record)',
        'king_proto_runtime_encode_schema_payload(',
        'zval_ptr_dtor(encoded_records)',
        'ZVAL_UNDEF(encoded_records)',
        'Batch encoding failed at record index %zu',
        'zend_exception_set_previous(EG(exception), previous_exception)',
        'record_index++',
    ],
    'extension/src/iibin/iibin_decoding.c' => [
        'zend_result king_iibin_decode_batch(',
        'record_count = zend_hash_num_elements(Z_ARRVAL_P(binary_records))',
        'record_count > KING_IIBIN_BATCH_MAX_RECORDS',
        'Batch decoding failed: record count %zu exceeds the maximum of %u.',
        'king_iibin_decode_mode_init(schema_name, decode_mode_input, &decode_mode)',
        'array_init_size(decoded_records, record_count)',
        'ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(binary_records), binary_data)',
        'king_proto_runtime_decode_schema_payload(',
        'king_iibin_hydrate_schema_result(',
        'must contain only strings; invalid record index %zu',
        'zval_ptr_dtor(decoded_records)',
        'ZVAL_UNDEF(decoded_records)',
        'Batch decoding failed at record index %zu',
        'zend_exception_set_previous(EG(exception), previous_exception)',
        'record_index++',
    ],
    'extension/src/core/introspection/proto_api/codec.inc' => [
        'king_iibin_encode_batch(schema_name, records, &encoded_records)',
        'king_iibin_decode_batch(',
    ],
];

foreach ($expectations as $path => $needles) {
    foreach ($needles as $needle) {
        require_contains($path, $needle);
    }
}

require_not_contains(
    'extension/src/core/introspection/proto_api/codec.inc',
    'ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(records), record)'
);
require_not_contains(
    'extension/src/core/introspection/proto_api/codec.inc',
    'ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(binary_records), binary_data)'
);

echo "OK\n";
?>
--EXPECT--
OK
