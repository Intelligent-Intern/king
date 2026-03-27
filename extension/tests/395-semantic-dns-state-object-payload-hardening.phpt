--TEST--
King semantic-dns durable state rejects object payloads without running wakeup handlers
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$state_dir = '/tmp/king_semantic_dns_state';
$state_file = $state_dir . '/durable_state.bin';
$marker_file = sys_get_temp_dir() . '/king_sdns_state_object_marker_' . getmypid();
$extension_path = dirname(__DIR__) . '/modules/king.so';
$child_script = sys_get_temp_dir() . '/king_sdns_state_object_child_' . getmypid() . '.php';

$state_dir_existed = is_dir($state_dir);
$state_backup = null;
$state_was_file = is_file($state_file) && !is_link($state_file);
if ($state_was_file) {
    $state_backup = file_get_contents($state_file);
}

if (!$state_dir_existed) {
    mkdir($state_dir, 0700, true);
}
chmod($state_dir, 0700);
@unlink($state_file);
@unlink($marker_file);

class KingSemanticDnsStateWakeupBomb
{
    public string $marker = '';
}

$bomb = new KingSemanticDnsStateWakeupBomb();
$bomb->marker = $marker_file;
$serialized_payload = serialize($bomb);
$state_blob = pack('LLL', 0x53444e53, 1, 0)
    . str_repeat("\0", PHP_INT_SIZE * 2)
    . pack('L', strlen($serialized_payload))
    . $serialized_payload;
file_put_contents($state_file, $state_blob);

file_put_contents(
    $child_script,
    <<<'PHP'
<?php
class KingSemanticDnsStateWakeupBomb
{
    public string $marker = '';

    public function __wakeup(): void
    {
        file_put_contents($this->marker, 'wakeup-ran');
    }
}

var_dump(king_semantic_dns_init([
    'enabled' => true,
    'dns_port' => 5354,
    'bind_address' => '127.0.0.1',
    'semantic_mode_enable' => true,
]));
var_dump(king_semantic_dns_start_server());
$topology = king_semantic_dns_get_service_topology();
var_dump($topology['statistics']['total_services']);
var_dump($topology['statistics']['mother_nodes']);
PHP
);

$cmd = sprintf(
    '%s -d king.security_allow_config_override=1 -d %s %s',
    escapeshellarg(PHP_BINARY),
    escapeshellarg('extension=' . $extension_path),
    escapeshellarg($child_script)
);

exec($cmd, $cmd_output, $cmd_status);
var_dump($cmd_status);
var_dump(count(array_filter($cmd_output, static fn($line) => $line === 'bool(true)')) === 2);
var_dump(in_array('int(0)', $cmd_output, true));
var_dump(!file_exists($marker_file));

if ($state_was_file) {
    file_put_contents($state_file, $state_backup);
} else {
    @unlink($state_file);
}
if (!$state_dir_existed) {
    @rmdir($state_dir);
}

@unlink($child_script);
@unlink($marker_file);
?>
--EXPECT--
int(0)
bool(true)
bool(true)
bool(true)
