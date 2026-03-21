--TEST--
King runtime-state paths expose RuntimeException instead of the generic base class
--FILE--
<?php
$results = [];

try {
    king_new_config(['foo' => 'bar']);
} catch (Throwable $e) {
    $results['config_policy'] = [
        'class' => get_class($e),
        'is_runtime' => $e instanceof King\RuntimeException,
        'is_base' => $e instanceof King\Exception,
    ];
}

var_export($results);
echo "\n";
?>
--EXPECT--
array (
  'config_policy' => 
  array (
    'class' => 'King\\RuntimeException',
    'is_runtime' => true,
    'is_base' => true,
  ),
)
