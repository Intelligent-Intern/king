--TEST--
King inference memory config is explicit without requiring a model artifact
--INI--
king.inference_with_memory=1
--FILE--
<?php
var_dump(ini_get('king.inference_with_memory'));

try {
    king_inference_model_load([
        'artifact' => __FILE__,
        'with_memory' => 'yes',
    ]);
    echo "invalid-type-accepted\n";
} catch (Throwable $e) {
    var_dump(str_contains($e->getMessage(), 'with_memory must be a boolean'));
}

try {
    king_inference_model_load([
        'artifact' => __FILE__,
        'with_memory' => true,
        'with-memory' => false,
    ]);
    echo "duplicate-alias-accepted\n";
} catch (Throwable $e) {
    var_dump(str_contains($e->getMessage(), 'memory config must use either with_memory or with-memory'));
}

try {
    king_inference_model_load([
        'artifact' => __FILE__,
        'with-memory' => [],
    ]);
    echo "invalid-alias-type-accepted\n";
} catch (Throwable $e) {
    var_dump(str_contains($e->getMessage(), 'with-memory must be a boolean'));
}
?>
--EXPECT--
string(1) "1"
bool(true)
bool(true)
bool(true)
