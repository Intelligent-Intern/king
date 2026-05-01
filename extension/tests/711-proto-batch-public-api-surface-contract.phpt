--TEST--
King proto batch public API is wired across stubs arginfo function table and IIBIN facade
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
    'stubs/king.php' => [
        'function king_proto_encode_batch(string $schema_name, array $records): array {}',
        'function king_proto_decode_batch(string $schema_name, array $binary_records, bool|string|array $decode_as_object = false): array {}',
        'public static function encodeBatch(string $schema, array $records): array {}',
        'public static function decodeBatch(string $schema, array $records, bool|string|array $decodeAsObject = false): array {}',
        'whole batch fails',
    ],
    'extension/src/php_king/arginfo.inc' => [
        'arginfo_king_proto_encode_batch',
        'arginfo_king_proto_decode_batch',
        'ZEND_ARG_TYPE_INFO(0, records, IS_ARRAY, 0)',
        'ZEND_ARG_TYPE_INFO(0, binary_records, IS_ARRAY, 0)',
    ],
    'extension/src/php_king/externals.inc' => [
        'PHP_FUNCTION(king_proto_encode_batch);',
        'PHP_FUNCTION(king_proto_decode_batch);',
    ],
    'extension/src/php_king/function_table.inc' => [
        'PHP_FE(king_proto_encode_batch, arginfo_king_proto_encode_batch)',
        'PHP_FE(king_proto_decode_batch, arginfo_king_proto_decode_batch)',
    ],
    'extension/include/iibin/iibin.h' => [
        'PHP_FUNCTION(king_proto_encode_batch);',
        'PHP_FUNCTION(king_proto_decode_batch);',
    ],
    'extension/src/iibin/iibin_api.c' => [
        'ZEND_ME_MAPPING(encodeBatch, king_proto_encode_batch, arginfo_class_King_IIBIN_encodeBatch, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)',
        'ZEND_ME_MAPPING(decodeBatch, king_proto_decode_batch, arginfo_class_King_IIBIN_decodeBatch, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)',
    ],
    'extension/src/core/introspection/proto_api/codec.inc' => [
        'PHP_FUNCTION(king_proto_encode_batch)',
        'PHP_FUNCTION(king_proto_decode_batch)',
        'king_iibin_encode_batch(schema_name, records, &encoded_records)',
        'king_iibin_decode_batch(',
        'RETURN_ZVAL(&encoded_records, 0, 1)',
        'RETURN_ZVAL(&decoded_records, 0, 1)',
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
