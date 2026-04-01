--TEST--
King semantic-dns local query failures and restart recovery stay honest
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$stateDir = '/tmp/king_semantic_dns_state';
$stateFile = $stateDir . '/durable_state.bin';
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$writerScript = sys_get_temp_dir() . '/king_sdns_query_writer_' . getmypid() . '.php';
$readerScript = sys_get_temp_dir() . '/king_sdns_query_reader_' . getmypid() . '.php';

$stateDirExisted = is_dir($stateDir);
$stateWasFile = is_file($stateFile) && !is_link($stateFile);
$stateBackup = $stateWasFile ? file_get_contents($stateFile) : null;

if (!$stateDirExisted) {
    mkdir($stateDir, 0700, true);
}
chmod($stateDir, 0700);
@unlink($stateFile);

register_shutdown_function(static function () use (
    $stateDir,
    $stateDirExisted,
    $stateFile,
    $stateWasFile,
    $stateBackup,
    $writerScript,
    $readerScript
): void {
    if ($stateWasFile) {
        file_put_contents($stateFile, $stateBackup);
    } else {
        @unlink($stateFile);
    }
    if (!$stateDirExisted) {
        @rmdir($stateDir);
    }

    @unlink($writerScript);
    @unlink($readerScript);
});

try {
    king_semantic_dns_query('status');
} catch (King\Exception $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), 'king_semantic_dns_init'));
}

file_put_contents($writerScript, <<<'PHP'
<?php
var_dump(king_semantic_dns_init([
    'enabled' => true,
    'bind_address' => '127.0.0.1',
    'dns_port' => 5454,
    'service_discovery_max_ips_per_response' => 8,
    'semantic_mode_enable' => true,
    'mothernode_uri' => 'mother://dns-recovery-seed',
]));

$statusBefore = king_semantic_dns_query('status');
var_dump(str_contains($statusBefore, 'active=0'));

var_dump(king_semantic_dns_start_server());
var_dump(king_semantic_dns_register_service([
    'service_id' => 'api-1',
    'service_name' => 'api',
    'service_type' => 'pipeline_orchestrator',
    'status' => 'healthy',
    'hostname' => 'api-1.internal',
    'port' => 8443,
]));

$statusAfter = king_semantic_dns_query('status');
var_dump(str_contains($statusAfter, 'active=1'));
var_dump(str_contains($statusAfter, 'bind=127.0.0.1'));
var_dump(str_contains($statusAfter, 'port=5454'));

try {
    king_semantic_dns_query('status', 8);
} catch (King\Exception $e) {
    var_dump(get_class($e));
    var_dump($e instanceof King\RuntimeException);
    var_dump(str_contains($e->getMessage(), 'full response'));
}

$topology = king_semantic_dns_get_service_topology();
var_dump($topology['statistics']['processed_queries']);
PHP
);

file_put_contents($readerScript, <<<'PHP'
<?php
var_dump(king_semantic_dns_init([
    'enabled' => true,
    'bind_address' => '127.0.0.1',
    'dns_port' => 5454,
    'service_discovery_max_ips_per_response' => 8,
    'semantic_mode_enable' => true,
    'mothernode_uri' => 'mother://dns-recovery-seed',
]));
var_dump(king_semantic_dns_start_server());

$status = king_semantic_dns_query('status');
$route = king_semantic_dns_get_optimal_route('api');
$topology = king_semantic_dns_get_service_topology();

var_dump(str_contains($status, 'active=1'));
var_dump(str_contains($status, 'bind=127.0.0.1'));
var_dump($route['service_id']);
var_dump($topology['statistics']['total_services']);
var_dump($topology['statistics']['start_count']);
var_dump($topology['statistics']['processed_queries']);
PHP
);

$writerCmd = sprintf(
    '%s -d king.security_allow_config_override=1 -d %s %s',
    escapeshellarg(PHP_BINARY),
    escapeshellarg('extension=' . $extensionPath),
    escapeshellarg($writerScript)
);
$readerCmd = sprintf(
    '%s -d king.security_allow_config_override=1 -d %s %s',
    escapeshellarg(PHP_BINARY),
    escapeshellarg('extension=' . $extensionPath),
    escapeshellarg($readerScript)
);

exec($writerCmd, $writerOutput, $writerStatus);
exec($readerCmd, $readerOutput, $readerStatus);

var_dump($writerStatus);
var_dump($readerStatus);
echo implode("\n", $writerOutput), "\n";
echo implode("\n", $readerOutput), "\n";
?>
--EXPECT--
string(21) "King\RuntimeException"
bool(true)
int(0)
int(0)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
string(21) "King\RuntimeException"
bool(true)
bool(true)
int(2)
bool(true)
bool(true)
bool(true)
bool(true)
string(5) "api-1"
int(1)
int(1)
int(1)
