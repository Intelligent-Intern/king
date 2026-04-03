--TEST--
King CDN edge-node inventory reports explicit live nodes and drops health when a node disappears
--SKIPIF--
<?php
if (!function_exists('stream_socket_server')) {
    echo "skip stream_socket_server is required";
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/cdn_edge_inventory_helper.inc';

function king_cdn_edge_inventory_557_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$dir = sys_get_temp_dir() . '/king_cdn_edge_inventory_557_' . getmypid();
$fixture = king_cdn_edge_inventory_listen_nodes(['local_fs', 'distributed', 'cloud_azure']);

@mkdir($dir, 0700, true);

try {
    king_cdn_edge_inventory_557_assert(
        king_object_store_init([
            'storage_root_path' => $dir,
            'cdn_config' => [
                'enabled' => true,
                'edge_nodes' => $fixture['edge_nodes'],
                'cache_size_mb' => 96,
                'default_ttl_seconds' => 180,
            ],
        ]) === true,
        'object-store init failed'
    );

    $nodes = king_cdn_get_edge_nodes();
    king_cdn_edge_inventory_557_assert(array_is_list($nodes), 'edge inventory must be a list');
    king_cdn_edge_inventory_557_assert(count($nodes) === 3, 'expected three explicit edge nodes');
    king_cdn_edge_inventory_557_assert($nodes[0]['node_id'] === 'edge-live-0', 'first node id mismatch');
    king_cdn_edge_inventory_557_assert($nodes[1]['backend'] === 'distributed', 'second node backend mismatch');
    king_cdn_edge_inventory_557_assert($nodes[2]['backend'] === 'cloud_azure', 'third node backend mismatch');
    king_cdn_edge_inventory_557_assert($nodes[0]['is_healthy'] === true, 'first node should be healthy');
    king_cdn_edge_inventory_557_assert($nodes[1]['is_healthy'] === true, 'second node should be healthy');
    king_cdn_edge_inventory_557_assert($nodes[2]['is_healthy'] === true, 'third node should be healthy');
    king_cdn_edge_inventory_557_assert($nodes[0]['cache_size_mb'] === 96, 'cache size did not round-trip');
    king_cdn_edge_inventory_557_assert($nodes[0]['default_ttl_sec'] === 180, 'ttl did not round-trip');

    fclose($fixture['servers'][1]);
    $fixture['servers'][1] = null;

    $nodes = king_cdn_get_edge_nodes();
    king_cdn_edge_inventory_557_assert($nodes[0]['is_healthy'] === true, 'healthy peer regressed');
    king_cdn_edge_inventory_557_assert($nodes[1]['is_healthy'] === false, 'closed peer still reports healthy');
    king_cdn_edge_inventory_557_assert($nodes[2]['is_healthy'] === true, 'unrelated peer regressed');

    echo "OK\n";
} finally {
    king_cdn_edge_inventory_close_nodes($fixture['servers']);
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        @unlink($dir . '/' . $entry);
    }
    @rmdir($dir);
}
?>
--EXPECT--
OK
