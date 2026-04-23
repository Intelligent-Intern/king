--TEST--
King gossip-mesh foundation artifacts are present and wired into the extension build contract
--FILE--
<?php
$extensionRoot = dirname(__DIR__);

$headerPath = $extensionRoot . '/include/gossip_mesh.h';
$sourcePath = $extensionRoot . '/src/gossip_mesh/gossip_mesh.c';
$phpMeshPath = $extensionRoot . '/src/gossip_mesh/gossip_mesh.php';
$phpSfuPath = $extensionRoot . '/src/gossip_mesh/sfu_signaling.php';
$jsClientPath = $extensionRoot . '/src/gossip_mesh/gossip_mesh_client.js';
$configPath = $extensionRoot . '/config.m4';

var_dump(is_file($headerPath));
var_dump(is_file($sourcePath));
var_dump(is_file($phpMeshPath));
var_dump(is_file($phpSfuPath));
var_dump(is_file($jsClientPath));

$configSource = (string) file_get_contents($configPath);
var_dump(str_contains($configSource, 'src/gossip_mesh/gossip_mesh.c'));

$meshSource = (string) file_get_contents($sourcePath);
var_dump(str_contains($meshSource, 'gossip_mesh_create'));
var_dump(str_contains($meshSource, 'gossip_mesh_receive_frame'));
var_dump(str_contains($meshSource, 'gossip_mesh_compute_forwards'));

$meshHeader = (string) file_get_contents($headerPath);
var_dump(str_contains($meshHeader, 'GOSSIP_MESH_DEFAULT_TTL'));
var_dump(str_contains($meshHeader, 'gossip_mesh_estimate_ttl'));
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
