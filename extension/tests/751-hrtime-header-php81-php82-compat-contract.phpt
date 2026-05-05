--TEST--
King native timing helpers avoid direct zend_hrtime private header dependency
--FILE--
<?php
$root = dirname(__DIR__, 2);
$paths = [
    'extension/src/pipeline_orchestrator/orchestrator.c',
    'extension/src/mcp/mcp.c',
    'extension/src/php_king/mcp.inc',
];

foreach ($paths as $path) {
    $source = (string) file_get_contents($root . '/' . $path);
    var_dump(str_contains($source, 'include/king_hrtime.h'));
    var_dump(!str_contains($source, 'Zend/zend_hrtime.h'));
    var_dump(!str_contains($source, 'zend_hrtime()'));
}

$helper = (string) file_get_contents($root . '/extension/include/king_hrtime.h');
var_dump(str_contains($helper, '__has_include'));
var_dump(str_contains($helper, 'king_hrtime_ms'));
var_dump(str_contains($helper, 'clock_gettime(CLOCK_MONOTONIC'));
var_dump(str_contains($helper, 'gettimeofday'));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
