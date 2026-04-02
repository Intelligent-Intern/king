--TEST--
King autoscaling reconciles partial Hetzner state loss and preserves bootstrap rollout for fresh nodes
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
]);

$config = [
    'provider' => 'hetzner',
    'api_endpoint' => 'http://127.0.0.1:' . $server['port'] . '/v1',
    'state_path' => $statePath,
    'server_name_prefix' => 'king-partial',
    'instance_type' => 'cx22',
    'instance_image_id' => '98765',
    'region' => 'nbg1',
    'prepared_release_url' => 'https://releases.example/king.tar.gz',
    'join_endpoint' => 'https://controller.example/join',
    'max_nodes' => 4,
    'max_scale_step' => 2,
];

try {
    var_dump(king_autoscaling_init($config));
    var_dump(king_autoscaling_scale_up(2));

    $nodes = king_autoscaling_get_nodes();
    var_dump(count($nodes));
    var_dump(king_autoscaling_register_node($nodes[0]['server_id'], 'worker-ready'));
    var_dump(king_autoscaling_mark_node_ready($nodes[0]['server_id']));

    $lines = file($statePath, FILE_IGNORE_NEW_LINES) ?: [];
    file_put_contents(
        $statePath,
        $lines[0] . "\n" . $lines[1] . "\n" . "node\t" . $nodes[1]['server_id'] . "\t0\t"
    );

    var_dump(king_autoscaling_init($config));
    $status = king_autoscaling_get_status();
    var_dump($status['managed_nodes']);
    var_dump($status['active_managed_nodes']);
    var_dump($status['provisioned_managed_nodes']);
    var_dump(str_contains($status['last_warning'], 'Recovered prefix-matched Hetzner nodes'));

    $recoveredNodes = king_autoscaling_get_nodes();
    var_dump(count($recoveredNodes));
    var_dump($recoveredNodes[0]['lifecycle']);
    var_dump($recoveredNodes[1]['lifecycle']);
    var_dump(substr_count(file_get_contents($statePath), "\nnode\t"));

    var_dump(king_autoscaling_register_node($recoveredNodes[1]['server_id'], 'worker-recovered'));
    var_dump(king_autoscaling_mark_node_ready($recoveredNodes[1]['server_id']));

    var_dump(king_autoscaling_scale_up(1));
    $requests = king_autoscaling_hetzner_mock_requests($logFile);
    var_dump(count($requests));
    var_dump($requests[2]['method']);
    var_dump($requests[2]['path']);
    $bootstrap = $requests[3]['body'] ?? '';
    var_dump(str_contains($bootstrap, '#cloud-config\nruncmd:'));
    var_dump(str_contains(
        $bootstrap,
        "king-agent join --controller 'https://controller.example/join' --release 'https://releases.example/king.tar.gz'"
    ));
} finally {
    king_autoscaling_hetzner_mock_stop($server);
    @unlink($server['state_file'] ?? '');
    @unlink($statePath);
    @unlink($logFile);
}
?>
--EXPECT--
bool(true)
bool(true)
int(2)
bool(true)
bool(true)
bool(true)
int(2)
int(1)
int(1)
bool(true)
int(2)
string(5) "ready"
string(11) "provisioned"
int(2)
bool(true)
bool(true)
bool(true)
int(4)
string(3) "GET"
string(11) "/v1/servers"
bool(true)
bool(true)
