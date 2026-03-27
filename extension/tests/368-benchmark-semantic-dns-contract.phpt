--TEST--
King benchmark semantic DNS case stays on the active v1 init surface
--FILE--
<?php
$runPhp = dirname(__DIR__, 2) . '/benchmarks/run.php';
$source = (string) file_get_contents($runPhp);

var_dump($source !== '');
var_dump(str_contains($source, "'semantic_dns' => ["));
var_dump(str_contains($source, "'semantic_mode_enable' => true"));
var_dump(str_contains($source, "'mothernode_uri' => 'mother://bench-node'"));
var_dump(!str_contains($source, "'mothernode_sync_interval_sec' => 60"));
var_dump(str_contains($source, "delete_flat_directory(\$stateDir);"));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
