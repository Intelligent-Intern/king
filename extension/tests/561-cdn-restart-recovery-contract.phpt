--TEST--
King CDN restart recovery stays honest across local and real cloud object-store backends
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
require __DIR__ . '/object_store_s3_mock_helper.inc';

function king_cdn_restart_561_cleanup_tree(string $path): void
{
    if ($path === '' || !file_exists($path)) {
        return;
    }

    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            king_cdn_restart_561_cleanup_tree($path . '/' . $entry);
        }

        @chmod($path, 0700);
        @rmdir($path);
        return;
    }

    @chmod($path, 0600);
    @unlink($path);
}

function king_cdn_restart_561_write_child_script(): string
{
    $scriptPath = tempnam(sys_get_temp_dir(), 'king-cdn-restart-child-');
    $helperPath = var_export(__DIR__ . '/object_store_s3_mock_helper.inc', true);
    if ($scriptPath === false) {
        throw new RuntimeException('failed to allocate CDN restart child script path');
    }

    file_put_contents($scriptPath, "<?php\nrequire " . $helperPath . ";\n\n" . <<<'PHP'
function king_cdn_restart_561_child_start_cloud(
    array &$config,
    string $stateDirectory,
    string $provider
): ?array {
    $server = null;
    $mockOptions = ['provider' => $provider];

    if ($provider === 'gcs') {
        $mockOptions['expected_access_token'] = 'gcs-token';
    } elseif ($provider === 'azure') {
        $mockOptions['expected_access_token'] = 'azure-token';
    }

    if (str_starts_with((string) ($config['primary_backend'] ?? ''), 'cloud_')) {
        $server = king_object_store_s3_mock_start_server($stateDirectory, '127.0.0.1', $mockOptions);
        $config['cloud_credentials']['api_endpoint'] = $server['endpoint'];
    }

    return $server;
}

function king_cdn_restart_561_child_stop_cloud(?array $server): void
{
    if ($server !== null) {
        king_object_store_s3_mock_stop_server($server);
    }
}

function king_cdn_restart_561_child_fail_backend(array &$config, ?array &$server): string
{
    $backend = (string) ($config['primary_backend'] ?? '');
    $goneRoot = $config['storage_root_path'] . '.gone';

    if (str_starts_with($backend, 'cloud_')) {
        king_cdn_restart_561_child_stop_cloud($server);
        $server = null;
        return $goneRoot;
    }

    if (!rename($config['storage_root_path'], $goneRoot)) {
        throw new RuntimeException('failed to take the local backend offline');
    }
    clearstatcache();

    return $goneRoot;
}

function king_cdn_restart_561_child_restore_backend(
    array &$config,
    string $stateDirectory,
    string $provider,
    ?array &$server,
    string $goneRoot
): void {
    $backend = (string) ($config['primary_backend'] ?? '');

    if (str_starts_with($backend, 'cloud_')) {
        $server = king_cdn_restart_561_child_start_cloud($config, $stateDirectory, $provider);
        king_object_store_init($config);
        return;
    }

    if (is_dir($goneRoot) && !is_dir($config['storage_root_path'])) {
        if (!rename($goneRoot, $config['storage_root_path'])) {
            throw new RuntimeException('failed to restore the local backend');
        }
        clearstatcache();
    }
}

$mode = $argv[1] ?? '';
$payload = $argv[2] ?? '';
$objectId = $argv[3] ?? '';
$config = json_decode((string) ($argv[4] ?? ''), true, 512, JSON_THROW_ON_ERROR);
$stateDirectory = $argv[5] ?? '';
$provider = $argv[6] ?? '';
$server = null;
$goneRoot = $config['storage_root_path'] . '.gone';

try {
    $server = king_cdn_restart_561_child_start_cloud($config, $stateDirectory, $provider);
    king_object_store_init($config);

    if ($mode === 'warm') {
        $put = king_object_store_put($objectId, $payload, [
            'content_type' => 'text/plain',
            'object_type' => 'cache_entry',
            'cache_policy' => 'smart_cdn',
        ]);
        $read = king_object_store_get($objectId);
        $stats = king_object_store_get_stats()['cdn'];
        echo json_encode([
            'put' => $put,
            'read_matches' => $read === $payload,
            'cached_object_count' => $stats['cached_object_count'] ?? null,
            'cached_bytes' => $stats['cached_bytes'] ?? null,
        ], JSON_UNESCAPED_SLASHES);
        return;
    }

    if ($mode !== 'restart') {
        throw new RuntimeException('unknown child mode: ' . $mode);
    }

    $initialStats = king_object_store_get_stats()['cdn'];
    $manualWarm = king_cdn_cache_object($objectId);
    $goneRoot = king_cdn_restart_561_child_fail_backend($config, $server);

    try {
        king_object_store_get($objectId);
        $failedReadClass = 'no-exception';
    } catch (Throwable $e) {
        $failedReadClass = get_class($e);
    }

    king_cdn_restart_561_child_restore_backend($config, $stateDirectory, $provider, $server, $goneRoot);
    $recoveredRead = king_object_store_get($objectId);
    $afterRecoverStats = king_object_store_get_stats()['cdn'];

    $goneRoot = king_cdn_restart_561_child_fail_backend($config, $server);
    $staleRead = king_object_store_get($objectId);

    echo json_encode([
        'initial_cached_object_count' => $initialStats['cached_object_count'] ?? null,
        'initial_cached_bytes' => $initialStats['cached_bytes'] ?? null,
        'initial_latest_cached_at_present' => array_key_exists('latest_cached_at', $initialStats),
        'initial_latest_cached_at' => $initialStats['latest_cached_at'] ?? null,
        'manual_warm' => $manualWarm,
        'failed_read_class' => $failedReadClass,
        'recovered_read_matches' => $recoveredRead === $payload,
        'after_recover_cached_object_count' => $afterRecoverStats['cached_object_count'] ?? null,
        'after_recover_cached_bytes' => $afterRecoverStats['cached_bytes'] ?? null,
        'stale_read_matches' => $staleRead === $payload,
    ], JSON_UNESCAPED_SLASHES);
        return;
} finally {
    king_cdn_restart_561_child_stop_cloud($server);
    if (!str_starts_with((string) ($config['primary_backend'] ?? ''), 'cloud_')
        && is_dir($goneRoot)
        && !is_dir($config['storage_root_path'])) {
        @rename($goneRoot, $config['storage_root_path']);
    }
}
PHP
    );

    return $scriptPath;
}

function king_cdn_restart_561_run_child(
    string $scriptPath,
    string $mode,
    string $objectId,
    string $payload,
    array $config,
    string $stateDirectory = '',
    string $provider = ''
): array {
    $extensionPath = dirname(__DIR__) . '/modules/king.so';
    $command = [
        PHP_BINARY,
        '-n',
        '-d', 'extension=' . $extensionPath,
        '-d', 'king.security_allow_config_override=1',
        $scriptPath,
        $mode,
        $payload,
        $objectId,
        json_encode($config, JSON_UNESCAPED_SLASHES),
        $stateDirectory,
        $provider,
    ];
    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        throw new RuntimeException('failed to launch CDN restart child process');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }

    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException('CDN restart child failed: ' . trim($stderr . "\n" . $stdout));
    }

    $decoded = json_decode(trim($stdout), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('CDN restart child returned invalid JSON: ' . trim($stdout));
    }

    return $decoded;
}

function king_cdn_restart_561_build_config(
    string $backend,
    string $root,
    ?string $provider = null
): array {
    $config = [
        'storage_root_path' => $root,
        'primary_backend' => $backend,
        'cdn_config' => [
            'enabled' => true,
            'default_ttl_seconds' => 300,
            'cache_size_mb' => 64,
            'serve_stale_on_error' => true,
        ],
    ];

    if ($provider === null) {
        return $config;
    }

    $config['cloud_credentials'] = [
        'api_endpoint' => 'http://127.0.0.1:1',
        'verify_tls' => false,
    ];

    if ($provider === 's3') {
        $config['cloud_credentials']['bucket'] = 'cdn-restart-s3';
        $config['cloud_credentials']['access_key'] = 'access';
        $config['cloud_credentials']['secret_key'] = 'secret';
        $config['cloud_credentials']['region'] = 'us-east-1';
        $config['cloud_credentials']['path_style'] = true;
    } elseif ($provider === 'gcs') {
        $config['cloud_credentials']['bucket'] = 'cdn-restart-gcs';
        $config['cloud_credentials']['access_token'] = 'gcs-token';
        $config['cloud_credentials']['path_style'] = true;
    } else {
        $config['cloud_credentials']['container'] = 'cdn-restart-azure';
        $config['cloud_credentials']['access_token'] = 'azure-token';
    }

    return $config;
}

function king_cdn_restart_561_run_case(string $backend, ?string $provider = null): array
{
    $suffix = $provider ?? $backend;
    $root = sys_get_temp_dir() . '/king_cdn_restart_561_' . $suffix . '_' . getmypid();
    $stateDirectory = $root . '/mock-state';
    $objectId = 'doc-' . str_replace('_', '-', $suffix);
    $payload = $backend . '-restart-payload';
    $scriptPath = king_cdn_restart_561_write_child_script();
    $config = king_cdn_restart_561_build_config($backend, $root, $provider);

    king_cdn_restart_561_cleanup_tree($root);
    mkdir($root, 0700, true);

    try {
        return [
            'warm' => king_cdn_restart_561_run_child(
                $scriptPath,
                'warm',
                $objectId,
                $payload,
                $config,
                $provider !== null ? $stateDirectory : '',
                $provider ?? ''
            ),
            'restart' => king_cdn_restart_561_run_child(
                $scriptPath,
                'restart',
                $objectId,
                $payload,
                $config,
                $provider !== null ? $stateDirectory : '',
                $provider ?? ''
            ),
            'payload_length' => strlen($payload),
        ];
    } finally {
        @unlink($scriptPath);
        king_cdn_restart_561_cleanup_tree($root . '.gone');
        king_object_store_s3_mock_cleanup_state_directory($stateDirectory);
        king_cdn_restart_561_cleanup_tree($root);
    }
}

$cases = [
    ['backend' => 'local_fs', 'provider' => null],
    ['backend' => 'distributed', 'provider' => null],
    ['backend' => 'cloud_s3', 'provider' => 's3'],
    ['backend' => 'cloud_gcs', 'provider' => 'gcs'],
    ['backend' => 'cloud_azure', 'provider' => 'azure'],
];

foreach ($cases as $case) {
    $backend = $case['backend'];
    $provider = $case['provider'];
    $result = king_cdn_restart_561_run_case($backend, $provider);

    var_dump($backend);
    var_dump(
        ($result['warm']['put'] ?? false) === true
        && ($result['warm']['read_matches'] ?? false) === true
    );
    var_dump(
        ($result['warm']['cached_object_count'] ?? null) === 1
        && ($result['warm']['cached_bytes'] ?? null) === $result['payload_length']
    );
    var_dump(
        ($result['restart']['initial_cached_object_count'] ?? null) === 0
        && ($result['restart']['initial_cached_bytes'] ?? null) === 0
        && ($result['restart']['initial_latest_cached_at_present'] ?? false) === true
        && $result['restart']['initial_latest_cached_at'] === null
    );
    var_dump(($result['restart']['manual_warm'] ?? false) === true);
    var_dump(($result['restart']['failed_read_class'] ?? null) === 'King\\SystemException');
    var_dump(
        ($result['restart']['recovered_read_matches'] ?? false) === true
        && ($result['restart']['after_recover_cached_object_count'] ?? null) === 1
        && ($result['restart']['after_recover_cached_bytes'] ?? null) === $result['payload_length']
    );
    var_dump(($result['restart']['stale_read_matches'] ?? false) === true);
}
?>
--EXPECT--
string(8) "local_fs"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
string(11) "distributed"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
string(8) "cloud_s3"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
string(9) "cloud_gcs"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
string(11) "cloud_azure"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
