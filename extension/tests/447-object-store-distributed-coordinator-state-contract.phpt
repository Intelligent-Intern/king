--TEST--
King object-store distributed backend persists a private coordinator-state contract even while data operations stay simulated
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$root = sys_get_temp_dir() . '/king_object_store_distributed_state_447_' . getmypid();

$cleanupTree = static function (string $path) use (&$cleanupTree): void {
    if ($path === '' || !file_exists($path)) {
        return;
    }

    if (is_dir($path) && !is_link($path)) {
        $entries = scandir($path);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $cleanupTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
        return;
    }

    @unlink($path);
};

$cleanupTree($root);
mkdir($root, 0700, true);

$config = [
    'storage_root_path' => $root,
    'primary_backend' => 'distributed',
];

var_dump(king_object_store_init($config));
$stats = king_object_store_get_stats()['object_store'];

$firstPath = $stats['runtime_distributed_coordinator_state_path'];
$firstVersion = $stats['runtime_distributed_coordinator_state_version'];
$firstGeneration = $stats['runtime_distributed_coordinator_generation'];
$firstCreatedAt = $stats['runtime_distributed_coordinator_created_at'];

var_dump($stats['runtime_primary_backend'] === 'distributed');
var_dump($stats['runtime_primary_backend_contract'] === 'simulated');
var_dump($stats['runtime_primary_adapter_status'] === 'simulated');
var_dump($stats['runtime_simulated_backends'] === 'distributed');
var_dump($stats['runtime_distributed_coordinator_state_status'] === 'initialized');
var_dump($stats['runtime_distributed_coordinator_state_present'] === true);
var_dump($stats['runtime_distributed_coordinator_state_recovered'] === false);
var_dump($firstVersion === 1);
var_dump(is_string($firstPath) && str_starts_with($firstPath, $root . '/.king-distributed/'));
var_dump(is_file($firstPath));
var_dump($firstGeneration > 0);
var_dump($firstCreatedAt > 0);
var_dump($stats['runtime_distributed_coordinator_last_loaded_at'] >= $firstCreatedAt);
var_dump($stats['runtime_distributed_coordinator_state_error'] === '');

var_dump(king_object_store_init($config));
$stats = king_object_store_get_stats()['object_store'];

var_dump($stats['runtime_distributed_coordinator_state_status'] === 'recovered');
var_dump($stats['runtime_distributed_coordinator_state_present'] === true);
var_dump($stats['runtime_distributed_coordinator_state_recovered'] === true);
var_dump($stats['runtime_distributed_coordinator_state_version'] === $firstVersion);
var_dump($stats['runtime_distributed_coordinator_generation'] === $firstGeneration);
var_dump($stats['runtime_distributed_coordinator_created_at'] === $firstCreatedAt);
var_dump($stats['runtime_distributed_coordinator_state_path'] === $firstPath);
var_dump($stats['runtime_distributed_coordinator_last_loaded_at'] >= $firstCreatedAt);
var_dump($stats['runtime_distributed_coordinator_state_error'] === '');

$cleanupTree($root);
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
