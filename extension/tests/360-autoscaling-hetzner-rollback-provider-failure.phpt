--TEST--
King Hetzner autoscaling reports provider delete failures while rolling back stale pending nodes
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
    'delete_status' => 503,
]);

try {
    var_dump(king_autoscaling_init([
        'provider' => 'hetzner',
        'api_endpoint' => 'http://127.0.0.1:' . $server['port'] . '/v1',
        'state_path' => $statePath,
        'server_name_prefix' => 'king-rollback-fail',
        'instance_type' => 'cx22',
        'instance_image_id' => '123456',
        'region' => 'nbg1',
        'max_nodes' => 3,
        'idle_node_timeout_sec' => 1,
        'cooldown_period_sec' => 300,
    ]));
    var_dump(king_autoscaling_scale_up(1));
    sleep(2);
    var_dump(king_autoscaling_start_monitoring());

    $status = king_autoscaling_get_status();
    $nodes = king_autoscaling_get_nodes();
    var_dump(str_contains($status['last_error'], 'rollback delete server request'));
    var_dump(str_contains($status['last_error'], 'HTTP 503'));
    var_dump($status['provisioned_managed_nodes']);
    var_dump($nodes[0]['lifecycle']);
    var_dump($nodes[0]['deleted_at']);

    $requests = king_autoscaling_hetzner_mock_requests($logFile);
    var_dump(count($requests));
    var_dump($requests[0]['method']);
    var_dump($requests[1]['method']);
} finally {
    king_autoscaling_hetzner_mock_stop($server);
    @unlink($statePath);
    @unlink($logFile);
}
?>
--EXPECT--
bool(true)
bool(true)
bool(false)
bool(true)
bool(true)
int(1)
string(11) "provisioned"
int(0)
int(2)
string(4) "POST"
string(6) "DELETE"
