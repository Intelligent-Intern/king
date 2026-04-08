--TEST--
King object-store failover harness captures central lifecycle snapshots across local_fs primary outage and heal from cloud_s3 backup
--SKIPIF--
<?php
if (!function_exists('proc_open') || !function_exists('stream_socket_server')) {
    echo "skip proc_open and stream_socket_server are required";
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/object_store_failover_harness.inc';

$harness = king_object_store_failover_harness_create([
    'bucket' => 'object-store-failover-harness',
    'object_id' => 'coord-doc',
]);
$stopCapture = [];
$history = [];

try {
    $initialized = king_object_store_failover_harness_start($harness);
    var_dump(($initialized['system_init_result'] ?? false) === true);
    var_dump(($initialized['system_status']['lifecycle'] ?? null) === 'ready');
    var_dump(($initialized['object_store_stats']['runtime_initialized'] ?? null) === true);
    var_dump(($initialized['object_store_stats']['runtime_primary_backend'] ?? null) === 'local_fs');
    var_dump(($initialized['system_status']['components']['object_store']['status'] ?? null) === 'running');

    $primed = king_object_store_failover_harness_prime_object($harness, 'alpha');
    var_dump(($primed['put_result'] ?? false) === true);
    var_dump(($primed['metadata']['object_id'] ?? null) === 'coord-doc');
    var_dump(($primed['metadata']['content_length'] ?? null) === 5);
    var_dump(($primed['metadata']['is_backed_up'] ?? null) === 1);
    var_dump(($primed['object_store_stats']['object_count'] ?? null) === 1);

    $outage = king_object_store_failover_harness_simulate_primary_outage($harness);
    var_dump(($outage['root_exists'] ?? true) === false);
    var_dump(($outage['offline_root_exists'] ?? false) === true);
    var_dump(($outage['offline_payload_present'] ?? false) === true);
    var_dump(($outage['offline_meta_present'] ?? false) === true);
    var_dump(($outage['metadata']['object_id'] ?? null) === 'coord-doc');
    var_dump(($outage['metadata']['is_backed_up'] ?? null) === 1);
    var_dump(is_array($outage['system_status'] ?? null));
    var_dump(isset($outage['system_status']['components']['object_store']));

    $healed = king_object_store_failover_harness_heal_from_backup($harness);
    var_dump(($healed['payload'] ?? null) === 'alpha');
    var_dump(($healed['root_exists'] ?? false) === true);
    var_dump(($healed['payload_present'] ?? false) === true);
    var_dump(($healed['meta_present'] ?? false) === true);
    var_dump(($healed['metadata']['object_id'] ?? null) === 'coord-doc');
    var_dump(($healed['object_store_stats']['runtime_primary_adapter_status'] ?? null) === 'ok');
    var_dump(($healed['object_store_stats']['runtime_backup_adapter_status'] ?? null) === 'ok');
} finally {
    $stopCapture = king_object_store_failover_harness_shutdown($harness);
    $history = king_object_store_failover_harness_capture_history($harness);
    king_object_store_failover_harness_destroy($harness);
}

var_dump(($stopCapture['system_shutdown_result'] ?? false) === true);
var_dump(($stopCapture['system_stopped'] ?? false) === true);
var_dump(($stopCapture['system_status']['initialized'] ?? true) === false);

$targets = array_map(
    static fn(array $event): string => $event['method'] . ' ' . $event['target'],
    $stopCapture['mock_capture']['events'] ?? []
);
var_dump(in_array('PUT /object-store-failover-harness/coord-doc', $targets, true));
var_dump(in_array('GET /object-store-failover-harness/coord-doc', $targets, true));
var_dump(array_column($history, 'phase'));
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
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
array(5) {
  [0]=>
  string(11) "initialized"
  [1]=>
  string(6) "primed"
  [2]=>
  string(14) "primary_outage"
  [3]=>
  string(6) "healed"
  [4]=>
  string(7) "stopped"
}
