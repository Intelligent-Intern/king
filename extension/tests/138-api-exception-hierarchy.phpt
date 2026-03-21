--TEST--
King active validation surfaces expose specialized exception subclasses
--FILE--
<?php
$results = [];

foreach ([
    'proto_define_enum' => static function (): void {
        king_proto_define_enum('BadEnum', ['ONE' => '1']);
    },
    'semantic_dns_register_service' => static function (): void {
        king_semantic_dns_register_service([]);
    },
    'object_store_init' => static function (): void {
        king_object_store_init(['primary_backend' => 'unknown']);
    },
    'cdn_cache_object' => static function (): void {
        king_cdn_cache_object('');
    },
] as $label => $callable) {
    try {
        $callable();
        $results[$label] = 'no-exception';
    } catch (Throwable $e) {
        $results[$label] = [
            'class' => get_class($e),
            'is_validation' => $e instanceof King\ValidationException,
            'is_base' => $e instanceof King\Exception,
        ];
    }
}

var_export($results);
echo "\n";
?>
--EXPECT--
array (
  'proto_define_enum' => 
  array (
    'class' => 'King\\ValidationException',
    'is_validation' => true,
    'is_base' => true,
  ),
  'semantic_dns_register_service' => 
  array (
    'class' => 'King\\ValidationException',
    'is_validation' => true,
    'is_base' => true,
  ),
  'object_store_init' => 
  array (
    'class' => 'King\\ValidationException',
    'is_validation' => true,
    'is_base' => true,
  ),
  'cdn_cache_object' => 
  array (
    'class' => 'King\\ValidationException',
    'is_validation' => true,
    'is_base' => true,
  ),
)
