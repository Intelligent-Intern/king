--TEST--
King Hetzner autoscaling retries transient failures while deleting servers.
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
    'delete_status' => [503, 204],
]);

try {
    var_dump(king_autoscaling_init([
        'provider' => 'hetzner',
        'api_endpoint' => 'http://127.0.0.1:' . $server['port'] . '/v1',
        'state_path' => $statePath,
        'server_name_prefix' => 'king-delete-retry',
        'instance_type' => 'cx22',
        'instance_image_id' => '123456',
        'region' => 'nbg1',
        'max_nodes' => 3,
    ]));

    var_dump(king_autoscaling_scale_up(1));
    var_dump(king_autoscaling_scale_down(1));

    $status = king_autoscaling_get_status();
    var_dump($status['active_managed_nodes']);
    var_dump($status['provisioned_managed_nodes']);
    var_dump($status['last_error']);

    $requests = king_autoscaling_hetzner_mock_requests($logFile);
    var_dump(count($requests));
    var_dump($requests[1]['method']);
    var_dump($requests[2]['method']);
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
int(0)
int(0)
string(0) ""
int(3)
string(6) "DELETE"
string(6) "DELETE"
