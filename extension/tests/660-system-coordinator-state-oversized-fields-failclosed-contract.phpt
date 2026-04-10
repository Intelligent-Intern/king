--TEST--
King coordinator state parser fails closed on oversized persisted node identity fields
--INI--
king.security_allow_config_override=1
--FILE--
<?php

function king_system_660_cleanup(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir . '/' . $entry;
        if (is_dir($path)) {
            king_system_660_cleanup($path);
            @rmdir($path);
            continue;
        }

        @unlink($path);
    }

    @rmdir($dir);
}

$root = sys_get_temp_dir() . '/king_system_coordinator_state_660_' . getmypid();
$stateDir = $root . '/.king-system';
$statePath = $stateDir . '/coordinator.state';

king_system_660_cleanup($root);
@mkdir($stateDir, 0700, true);

$statePayload = implode("\n", [
    'version=1',
    'generation=1',
    'created_at=1',
    'updated_at=1',
    'cluster_id=cluster-a',
    'active_node_id=' . str_repeat('node-x', 20),
    'clean_shutdown=1',
]) . "\n";
file_put_contents($statePath, $statePayload);

var_dump(king_system_init([
    'component_timeout_seconds' => 1,
    'state_root_path' => $root,
    'cluster_id' => 'cluster-a',
    'node_id' => 'node-b',
]));

$status = king_system_get_status();
var_dump(($status['initialized'] ?? null) === false);
var_dump(($status['recovery']['coordinator_state_status'] ?? null) === 'inactive');
var_dump(($status['recovery']['coordinator_state_error'] ?? null) === '');
var_dump(($status['recovery']['coordinator_state_present'] ?? null) === false);
var_dump(($status['recovery']['source_node_id'] ?? null) === null);

king_system_660_cleanup($root);
?>
--EXPECT--
bool(false)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
