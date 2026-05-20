--TEST--
king_db_ingest serializes userland database writers behind a host-local lock
--SKIPIF--
<?php
if (!extension_loaded('king')) {
    die('skip king extension not loaded');
}
?>
--FILE--
<?php
$lock = sys_get_temp_dir() . '/king-db-ingestor-contract-' . bin2hex(random_bytes(4)) . '.lock';
$log = sys_get_temp_dir() . '/king-db-ingestor-contract-' . bin2hex(random_bytes(4)) . '.log';

$result = king_db_ingest('contract.sqlite.writer', function () use ($log) {
    file_put_contents($log, "one\n", FILE_APPEND);
    return ['state' => 'written'];
}, [
    'lock_path' => $lock,
    'timeout_ms' => 500,
]);

var_dump(function_exists('king_db_ingest'));
var_dump($result);
echo file_get_contents($log);

$held = fopen($lock, 'c');
flock($held, LOCK_EX);
try {
    king_db_ingest('contract.sqlite.writer', function () use ($log) {
        file_put_contents($log, "blocked\n", FILE_APPEND);
    }, [
        'lock_path' => $lock,
        'timeout_ms' => 10,
        'poll_us' => 1000,
    ]);
    echo "missing timeout\n";
} catch (King\TimeoutException) {
    echo "timeout\n";
}
flock($held, LOCK_UN);
fclose($held);
echo file_get_contents($log);
@unlink($lock);
@unlink($log);
?>
--EXPECT--
bool(true)
array(1) {
  ["state"]=>
  string(7) "written"
}
one
timeout
one
