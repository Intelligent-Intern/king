--TEST--
King CDN: multi-node edge state and distribution metadata
--INI--
king.security_allow_config_override=1
--FILE--
<?php

$dir = sys_get_temp_dir() . '/king_cdn_ha_' . getmypid();
if (!is_dir($dir)) mkdir($dir, 0755, true);

// Init with 3 edge nodes
king_object_store_init([
    'storage_root_path' => $dir,
    'cdn_config' => [
        'enabled' => true,
        'edge_node_count' => 3,
        'default_ttl_seconds' => 3600
    ]
]);

// 1. Verify get_edge_nodes returns 3 nodes
$nodes = king_cdn_get_edge_nodes();
var_dump(count($nodes)); // 3
echo "Node 0 ID: " . $nodes[0]['node_id'] . "\n";
echo "Node 2 ID: " . $nodes[2]['node_id'] . "\n";

// 2. Cache an object and check distribution metadata
king_object_store_put('distributed_asset', 'shared across edges');
king_cdn_cache_object('distributed_asset');

$meta = king_object_store_get_metadata('distributed_asset');
var_dump($meta['is_distributed']); // 1
var_dump($meta['distribution_peer_count']); // 1 (in the skeleton we distribute to 1 local registry, metadata tracks it)

// Cleanup
foreach (scandir($dir) as $f) { if ($f !== '.' && $f !== '..') @unlink("$dir/$f"); }
@rmdir($dir);
?>
--EXPECT--
int(3)
Node 0 ID: edge-0
Node 2 ID: edge-2
int(1)
int(1)
