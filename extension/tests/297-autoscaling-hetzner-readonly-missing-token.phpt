--TEST--
King autoscaling keeps Hetzner worker mode readable when the controller token is absent
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$statePath = tempnam(sys_get_temp_dir(), 'king-autoscale-state-');

try {
    var_dump(king_autoscaling_init([
        'provider' => 'hetzner',
        'api_endpoint' => 'http://127.0.0.1:65535/v1',
        'state_path' => $statePath,
        'instance_type' => 'cx11',
        'instance_image_id' => '12345',
        'max_nodes' => 3,
        'max_scale_step' => 1,
    ]));

    $status = king_autoscaling_get_status();
    var_dump($status['provider_mode']);
    var_dump($status['controller_token_configured']);
    var_dump($status['current_instances']);

    var_dump(king_autoscaling_scale_up(1));
    $status = king_autoscaling_get_status();
    var_dump($status['current_instances']);
    var_dump($status['active_managed_nodes']);
    var_dump(str_starts_with(
        $status['last_error'],
        'Hetzner autoscaling requires king.cluster_autoscale_hetzner_api_token'
    ));
} finally {
    @unlink($statePath);
}
?>
--EXPECT--
bool(true)
string(16) "hetzner_readonly"
bool(false)
int(1)
bool(false)
int(1)
int(0)
bool(true)
