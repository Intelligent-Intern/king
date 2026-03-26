--TEST--
King Hetzner autoscaling rolls back stale provisioned and registered nodes before another monitor decision
--INI--
king.cluster_autoscale_hetzner_api_token=test-token
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/autoscaling_hetzner_mock_helper.inc';

function king_autoscaling_run_pending_rollback_case(
    array $config,
    ?callable $prepareNode = null
): array {
    $statePath = tempnam(sys_get_temp_dir(), 'king-autoscale-state-');
    $logFile = tempnam(sys_get_temp_dir(), 'king-hetzner-log-');
    $server = king_autoscaling_hetzner_mock_start([
        'log_file' => $logFile,
    ]);

    try {
        $config = $config + [
            'provider' => 'hetzner',
            'api_endpoint' => 'http://127.0.0.1:' . $server['port'] . '/v1',
            'state_path' => $statePath,
            'server_name_prefix' => 'king-rollback',
            'instance_type' => 'cx22',
            'instance_image_id' => '123456',
            'region' => 'nbg1',
            'max_nodes' => 3,
            'idle_node_timeout_sec' => 1,
            'cooldown_period_sec' => 300,
        ];

        var_dump(king_autoscaling_init($config));
        var_dump(king_autoscaling_scale_up(1));

        $nodes = king_autoscaling_get_nodes();
        if ($prepareNode !== null) {
            $prepareNode($nodes[0]);
        }

        sleep(2);
        var_dump(king_autoscaling_start_monitoring());

        $status = king_autoscaling_get_status();
        $nodes = king_autoscaling_get_nodes();
        var_dump($status['last_action_kind']);
        var_dump($status['provisioned_managed_nodes']);
        var_dump($status['registered_managed_nodes']);
        var_dump($nodes[0]['lifecycle']);
        var_dump($nodes[0]['provider_status']);
        var_dump(king_autoscaling_init($config));
        $reloaded = king_autoscaling_get_nodes();
        var_dump($reloaded[0]['lifecycle']);
        var_dump($reloaded[0]['provider_status']);

        $requests = king_autoscaling_hetzner_mock_requests($logFile);
        var_dump(count($requests));
        var_dump($requests[0]['method']);
        var_dump($requests[1]['method']);
        var_dump(str_starts_with($requests[1]['path'], '/v1/servers/'));

        return [$server, $statePath, $logFile, $status];
    } finally {
        king_autoscaling_hetzner_mock_stop($server);
        @unlink($statePath);
        @unlink($logFile);
    }
}

[, , , $bootstrapStatus] = king_autoscaling_run_pending_rollback_case([
    'bootstrap_user_data' => "#cloud-config\nruncmd:\n  - echo ready\n",
]);
var_dump(str_contains($bootstrapStatus['last_warning'], 'bootstrap'));

[, , , $registrationStatus] = king_autoscaling_run_pending_rollback_case([]);
var_dump(str_contains($registrationStatus['last_warning'], 'registration'));

[, , , $readinessStatus] = king_autoscaling_run_pending_rollback_case(
    [],
    static function (array $node): void {
        var_dump(king_autoscaling_register_node($node['server_id'], 'worker-ready'));
    }
);
var_dump(str_contains($readinessStatus['last_warning'], 'readiness'));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
string(21) "rollback_pending_node"
int(0)
int(0)
string(7) "deleted"
string(18) "rollback_bootstrap"
bool(true)
string(7) "deleted"
string(18) "rollback_bootstrap"
int(2)
string(4) "POST"
string(6) "DELETE"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
string(21) "rollback_pending_node"
int(0)
int(0)
string(7) "deleted"
string(21) "rollback_registration"
bool(true)
string(7) "deleted"
string(21) "rollback_registration"
int(2)
string(4) "POST"
string(6) "DELETE"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
string(21) "rollback_pending_node"
int(0)
int(0)
string(7) "deleted"
string(18) "rollback_readiness"
bool(true)
string(7) "deleted"
string(18) "rollback_readiness"
int(2)
string(4) "POST"
string(6) "DELETE"
bool(true)
bool(true)
