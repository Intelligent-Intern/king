--TEST--
King semantic-dns durable state save stays inside the private runtime directory
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$state_dir = '/tmp/king_semantic_dns_state';
$state_file = $state_dir . '/durable_state.bin';
$target_file = sys_get_temp_dir() . '/king_sdns_state_target_' . getmypid();
$marker = 'ORIGINAL';

$state_dir_existed = is_dir($state_dir);
$state_backup = null;
$state_was_file = is_file($state_file) && !is_link($state_file);
if ($state_was_file) {
    $state_backup = file_get_contents($state_file);
}

$extension_path = dirname(__DIR__) . '/modules/king.so';
$child_script = sys_get_temp_dir() . '/king_sdns_state_child_' . getmypid() . '.php';

file_put_contents($target_file, $marker);
if (!$state_dir_existed) {
    mkdir($state_dir, 0700, true);
}
chmod($state_dir, 0700);
@unlink($state_file);
symlink($target_file, $state_file);

file_put_contents(
    $child_script,
    "<?php\nking_semantic_dns_init([\n    'enabled' => true,\n    'dns_port' => 5353,\n    'bind_address' => '127.0.0.1',\n    'semantic_mode_enable' => true,\n]);\n"
);

$cmd = sprintf(
    '%s -d king.security_allow_config_override=1 -d %s %s',
    escapeshellarg(PHP_BINARY),
    escapeshellarg('extension=' . $extension_path),
    escapeshellarg($child_script)
);

exec($cmd, $cmd_output, $cmd_status);
var_dump($cmd_status);
var_dump(file_exists($target_file));
var_dump(file_get_contents($target_file) === $marker);
var_dump(is_file($state_file));
var_dump(!is_link($state_file));

if (is_link($state_file)) {
    unlink($state_file);
}
if ($state_was_file) {
    file_put_contents($state_file, $state_backup);
} else {
    @unlink($state_file);
}
if (!$state_dir_existed) {
    @rmdir($state_dir);
}

@unlink($child_script);
@unlink($target_file);
?>
--EXPECT--
int(0)
bool(true)
bool(true)
bool(true)
bool(true)
