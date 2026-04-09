--TEST--
King Hetzner autoscaling tolerates idempotent delete outcomes during scale-down.
--INI--
king.cluster_autoscale_hetzner_api_token=test-token
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/autoscaling_hetzner_mock_helper.inc';

$statePath = tempnam(sys_get_temp_dir(), 'king-autoscale-state-');
$logFile = tempnam(sys_get_temp_dir(), 'king-hetzner-log-');
$server = king_autoscaling_hetzner_mock_start([
    'log_file' => $logFile,
    'delete_status' => 404,
]);

try {
    var_dump(king_autoscaling_init([
        'provider' => 'hetzner',
        'api_endpoint' => 'http://127.0.0.1:' . $server['port'] . '/v1',
        'state_path' => $statePath,
        'server_name_prefix' => 'king-idempotent-delete',
        'instance_type' => 'cx22',
        'instance_image_id' => '123456',
        'region' => 'nbg1',
        'max_nodes' => 3,
        'max_scale_step' => 2,
    ]));

    var_dump(king_autoscaling_scale_up(2));

    $nodes = king_autoscaling_get_nodes();
    var_dump(king_autoscaling_register_node($nodes[0]['server_id'], 'idempotent-a'));
    var_dump(king_autoscaling_mark_node_ready($nodes[0]['server_id']));
    var_dump(king_autoscaling_register_node($nodes[1]['server_id'], 'idempotent-b'));
    var_dump(king_autoscaling_mark_node_ready($nodes[1]['server_id']));
    var_dump(king_autoscaling_drain_node($nodes[0]['server_id']));
    var_dump(king_autoscaling_scale_down(1));

    $status = king_autoscaling_get_status();
    var_dump($status['active_managed_nodes']);
    var_dump($status['draining_managed_nodes']);
    var_dump($status['last_error']);

    $requests = king_autoscaling_hetzner_mock_requests($logFile);
    var_dump(count($requests));
    var_dump($requests[1]['path']);
} finally {
    king_autoscaling_hetzner_mock_stop($server);
    @unlink($statePath);
    @unlink($logFile);
}
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
int(2)
int(0)
string(0) ""
int(3)
string(10) "/v1/servers"
