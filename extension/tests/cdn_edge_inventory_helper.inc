<?php

function king_cdn_edge_inventory_listen_nodes(array $backends): array
{
    $servers = [];
    $edgeNodes = [];

    foreach ($backends as $index => $backend) {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!is_resource($server)) {
            throw new RuntimeException('failed to create edge listener: ' . $errstr);
        }

        $name = stream_socket_get_name($server, false);
        if (!is_string($name) || !preg_match('/:(\d+)$/', $name, $matches)) {
            fclose($server);
            throw new RuntimeException('failed to resolve edge listener port');
        }

        $servers[] = $server;
        $edgeNodes[] = [
            'node_id' => 'edge-live-' . $index,
            'hostname' => '127.0.0.1',
            'port' => (int) $matches[1],
            'backend' => $backend,
        ];
    }

    return [
        'servers' => $servers,
        'edge_nodes' => $edgeNodes,
    ];
}

function king_cdn_edge_inventory_close_nodes(array $servers): void
{
    foreach ($servers as $server) {
        if (is_resource($server)) {
            fclose($server);
        }
    }
}
