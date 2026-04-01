--TEST--
King object-store blocks imported cloud metadata with CRLF-bearing header values before emitting write headers
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

function king_object_store_450_cleanup_tree(string $path): void
{
    if ($path === '' || !file_exists($path)) {
        return;
    }

    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            king_object_store_450_cleanup_tree($path . '/' . $entry);
        }

        @rmdir($path);
        return;
    }

    @unlink($path);
}

function king_object_store_450_backend_config(string $provider, string $endpoint): array
{
    return match ($provider) {
        'cloud_s3' => [
            'primary_backend' => 'cloud_s3',
            'cloud_credentials' => [
                'api_endpoint' => $endpoint,
                'bucket' => 'import-sanitize-s3',
                'access_key' => 'access',
                'secret_key' => 'secret',
                'region' => 'us-east-1',
                'path_style' => true,
                'verify_tls' => false,
            ],
        ],
        'cloud_gcs' => [
            'primary_backend' => 'cloud_gcs',
            'cloud_credentials' => [
                'api_endpoint' => $endpoint,
                'bucket' => 'import-sanitize-gcs',
                'access_token' => 'gcs-token',
                'path_style' => true,
                'verify_tls' => false,
            ],
        ],
        'cloud_azure' => [
            'primary_backend' => 'cloud_azure',
            'cloud_credentials' => [
                'api_endpoint' => $endpoint,
                'container' => 'import-sanitize-azure',
                'access_token' => 'azure-token',
                'verify_tls' => false,
            ],
        ],
    };
}

function king_object_store_450_mock_options(string $provider): array
{
    return match ($provider) {
        'cloud_gcs' => [
            'provider' => 'gcs',
            'expected_access_token' => 'gcs-token',
        ],
        'cloud_azure' => [
            'provider' => 'azure',
            'expected_access_token' => 'azure-token',
        ],
        default => [],
    };
}

function king_object_store_450_write_import_fixture(string $directory, string $objectId, string $payload): void
{
    $sha256 = hash('sha256', $payload);
    file_put_contents($directory . '/' . $objectId, $payload);
    file_put_contents(
        $directory . '/' . $objectId . '.meta',
        "object_id={$objectId}\n"
        . "content_type=application/x-king-restore\r\n"
        . "x-ignore=1\n"
        . "content_encoding=\n"
        . "etag={$sha256}\n"
        . "integrity_sha256={$sha256}\n"
        . "content_length=" . strlen($payload) . "\n"
        . "version=1\n"
        . "created_at=1700000000\n"
        . "modified_at=1700000000\n"
        . "expires_at=0\n"
        . "object_type=1\n"
        . "cache_policy=0\n"
        . "cache_ttl_seconds=0\n"
        . "local_fs_present=1\n"
        . "distributed_present=0\n"
        . "cloud_s3_present=0\n"
        . "cloud_gcs_present=0\n"
        . "cloud_azure_present=0\n"
        . "is_backed_up=0\n"
        . "replication_status=0\n"
        . "is_distributed=0\n"
        . "distribution_peer_count=0\n"
    );
}

foreach (['cloud_s3', 'cloud_gcs', 'cloud_azure'] as $provider) {
    $root = sys_get_temp_dir() . '/king_object_store_header_sanitize_450_' . $provider . '_' . getmypid();
    $import = $root . '/import';
    $objectId = 'restore-' . $provider;

    king_object_store_450_cleanup_tree($root);
    mkdir($import, 0700, true);
    king_object_store_450_write_import_fixture($import, $objectId, 'payload');

    $mock = king_object_store_s3_mock_start_server(
        null,
        '127.0.0.1',
        king_object_store_450_mock_options($provider)
    );

    $config = king_object_store_450_backend_config($provider, $mock['endpoint']);
    $config['storage_root_path'] = $root;

    var_dump($provider);
    var_dump(king_object_store_init($config));
    var_dump(king_object_store_restore_object($objectId, $import));

    $stats = king_object_store_get_stats()['object_store'];
    var_dump(str_contains($stats['runtime_primary_adapter_error'], 'CR/LF'));
    var_dump(king_object_store_get($objectId));

    $capture = king_object_store_s3_mock_stop_server($mock);
    var_dump(count(array_filter(
        $capture['events'] ?? [],
        static fn(array $event): bool =>
            ($event['method'] ?? '') === 'PUT'
            && ($event['object_id'] ?? '') === $objectId
    )) === 0);

    king_object_store_450_cleanup_tree($root);
    king_object_store_s3_mock_cleanup_state_directory($mock['state_directory']);
}
?>
--EXPECT--
string(8) "cloud_s3"
bool(true)
bool(false)
bool(true)
bool(false)
bool(true)
string(9) "cloud_gcs"
bool(true)
bool(false)
bool(true)
bool(false)
bool(true)
string(11) "cloud_azure"
bool(true)
bool(false)
bool(true)
bool(false)
bool(true)
