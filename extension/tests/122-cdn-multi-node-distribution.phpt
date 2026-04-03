--TEST--
King CDN edge inventory only reports explicit nodes and keeps distribution metadata honest
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/cdn_edge_inventory_helper.inc';

$dir = sys_get_temp_dir() . '/king_cdn_ha_' . getmypid();
if (!is_dir($dir)) mkdir($dir, 0755, true);
$fixture = king_cdn_edge_inventory_listen_nodes(['local_fs', 'distributed', 'cloud_s3']);

try {
    king_object_store_init([
        'storage_root_path' => $dir,
        'cdn_config' => [
            'enabled' => true,
            'edge_node_count' => 3,
            'default_ttl_seconds' => 3600,
            'edge_nodes' => $fixture['edge_nodes'],
        ]
    ]);

    $nodes = king_cdn_get_edge_nodes();
    var_dump(count($nodes));
    echo "Node 0 ID: " . $nodes[0]['node_id'] . "\n";
    echo "Node 2 backend: " . $nodes[2]['backend'] . "\n";
    var_dump($nodes[0]['is_healthy']);
    var_dump($nodes[2]['is_healthy']);

    king_object_store_put('distributed_asset', 'shared across edges');
    king_cdn_cache_object('distributed_asset');

    $meta = king_object_store_get_metadata('distributed_asset');
    var_dump($meta['is_distributed']);
    var_dump($meta['distribution_peer_count']);
} finally {
    king_cdn_edge_inventory_close_nodes($fixture['servers']);
    foreach (scandir($dir) as $f) { if ($f !== '.' && $f !== '..') @unlink("$dir/$f"); }
    @rmdir($dir);
}

?>
--EXPECT--
int(3)
Node 0 ID: edge-live-0
Node 2 backend: cloud_s3
bool(true)
bool(true)
int(1)
int(1)
