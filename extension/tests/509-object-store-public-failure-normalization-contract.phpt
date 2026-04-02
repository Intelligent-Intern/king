--TEST--
King object-store public CRUD failures expose normalized quota and throttling prefixes across real cloud backends
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

function king_object_store_509_cleanup_tree(string $path): void
{
    if ($path === '' || !file_exists($path)) {
        return;
    }

    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            king_object_store_509_cleanup_tree($path . '/' . $entry);
        }
        @rmdir($path);
        return;
    }

    @unlink($path);
}

function king_object_store_509_stream(string $payload)
{
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, $payload);
    rewind($stream);
    return $stream;
}

function king_object_store_509_is_normalized_public_failure(
    string $message,
    string $backend,
    string $kind,
    int $httpCode
): bool {
    $prefix = $kind === 'throttle'
        ? 'Object-store primary backend throttled the operation; retry with backoff.'
        : 'Object-store primary backend rejected the operation because the configured endpoint reported exhausted quota.';
    $detail = $kind === 'throttle'
        ? 'was throttled by the configured endpoint'
        : 'reported exhausted quota';

    return str_starts_with($message, $prefix)
        && str_contains($message, $backend)
        && str_contains($message, $detail)
        && str_contains($message, 'HTTP ' . $httpCode);
}

function king_object_store_509_provider_case(string $backend, string $kind): array
{
    $suffix = $kind === 'throttle' ? 'throttle' : 'quota';

    return match ($backend) {
        'cloud_s3' => [
            'mock_options' => [
                'forced_responses' => [
                    [
                        'method' => 'GET',
                        'target' => '/public-s3-' . $suffix . '?list-type=2',
                        'status' => $kind === 'throttle' ? 429 : 507,
                        'error_code' => $kind === 'throttle' ? 'TooManyRequestsException' : 'InsufficientStorage',
                        'error_message' => $kind === 'throttle' ? 'Reduce your request rate.' : 'Bucket storage quota exhausted.',
                    ],
                    [
                        'method' => 'PUT',
                        'target' => '/public-s3-' . $suffix . '/write-s3-' . $suffix,
                        'status' => $kind === 'throttle' ? 503 : 403,
                        'error_code' => $kind === 'throttle' ? 'SlowDown' : 'QuotaExceeded',
                        'error_message' => $kind === 'throttle' ? 'Reduce your request rate.' : 'Bucket quota exhausted.',
                    ],
                    [
                        'method' => 'HEAD',
                        'target' => '/public-s3-' . $suffix . '/read-s3-' . $suffix,
                        'status' => $kind === 'throttle' ? 429 : 507,
                        'error_code' => $kind === 'throttle' ? 'TooManyRequestsException' : 'InsufficientStorage',
                        'error_message' => $kind === 'throttle' ? 'Rate exceeded.' : 'Read quota exhausted.',
                    ],
                    [
                        'method' => 'GET',
                        'target' => '/public-s3-' . $suffix . '/read-s3-' . $suffix,
                        'status' => $kind === 'throttle' ? 429 : 507,
                        'error_code' => $kind === 'throttle' ? 'TooManyRequestsException' : 'InsufficientStorage',
                        'error_message' => $kind === 'throttle' ? 'Rate exceeded.' : 'Read quota exhausted.',
                    ],
                    [
                        'method' => 'DELETE',
                        'target' => '/public-s3-' . $suffix . '/delete-s3-' . $suffix,
                        'status' => $kind === 'throttle' ? 503 : 409,
                        'error_code' => $kind === 'throttle' ? 'SlowDown' : 'StorageLimitExceeded',
                        'error_message' => $kind === 'throttle' ? 'Delete throttled.' : 'Delete quota exhausted.',
                    ],
                ],
            ],
            'config' => static fn(array $mock, string $root): array => [
                'storage_root_path' => $root,
                'primary_backend' => 'cloud_s3',
                'cloud_credentials' => [
                    'api_endpoint' => $mock['endpoint'],
                    'bucket' => 'public-s3-' . $suffix,
                    'access_key' => 'access',
                    'secret_key' => 'secret',
                    'region' => 'us-east-1',
                    'path_style' => true,
                    'verify_tls' => false,
                ],
            ],
            'seed_read_id' => 'read-s3-' . $suffix,
            'seed_delete_id' => 'delete-s3-' . $suffix,
            'write_id' => 'write-s3-' . $suffix,
            'list_target' => 'GET /public-s3-' . $suffix . '?list-type=2',
            'write_http_code' => $kind === 'throttle' ? 503 : 403,
            'read_http_code' => $kind === 'throttle' ? 429 : 507,
            'delete_http_code' => $kind === 'throttle' ? 503 : 409,
            'list_http_code' => $kind === 'throttle' ? 429 : 507,
        ],
        'cloud_gcs' => [
            'mock_options' => [
                'provider' => 'gcs',
                'expected_access_token' => 'gcs-token',
                'forced_responses' => [
                    [
                        'method' => 'GET',
                        'target' => '/public-gcs-' . $suffix . '/',
                        'status' => $kind === 'throttle' ? 429 : 403,
                        'error_code' => $kind === 'throttle' ? 'TooManyRequests' : 'storageQuotaExceeded',
                        'error_message' => $kind === 'throttle' ? 'Rate exceeded.' : 'Project storage quota exhausted.',
                    ],
                    [
                        'method' => 'PUT',
                        'target' => '/public-gcs-' . $suffix . '/write-gcs-' . $suffix,
                        'status' => $kind === 'throttle' ? 503 : 507,
                        'error_code' => $kind === 'throttle' ? 'RateLimitExceeded' : 'InsufficientStorage',
                        'error_message' => $kind === 'throttle' ? 'Reduce your request rate.' : 'Not enough storage quota.',
                    ],
                    [
                        'method' => 'HEAD',
                        'target' => '/public-gcs-' . $suffix . '/read-gcs-' . $suffix,
                        'status' => $kind === 'throttle' ? 429 : 507,
                        'error_code' => $kind === 'throttle' ? 'TooManyRequests' : 'InsufficientStorage',
                        'error_message' => $kind === 'throttle' ? 'Read rate exceeded.' : 'Read quota exhausted.',
                    ],
                    [
                        'method' => 'GET',
                        'target' => '/public-gcs-' . $suffix . '/read-gcs-' . $suffix,
                        'status' => $kind === 'throttle' ? 429 : 507,
                        'error_code' => $kind === 'throttle' ? 'TooManyRequests' : 'InsufficientStorage',
                        'error_message' => $kind === 'throttle' ? 'Read rate exceeded.' : 'Read quota exhausted.',
                    ],
                    [
                        'method' => 'DELETE',
                        'target' => '/public-gcs-' . $suffix . '/delete-gcs-' . $suffix,
                        'status' => $kind === 'throttle' ? 503 : 409,
                        'error_code' => $kind === 'throttle' ? 'TooManyRequests' : 'QuotaExceeded',
                        'error_message' => $kind === 'throttle' ? 'Delete throttled.' : 'Delete quota exhausted.',
                    ],
                ],
            ],
            'config' => static fn(array $mock, string $root): array => [
                'storage_root_path' => $root,
                'primary_backend' => 'cloud_gcs',
                'cloud_credentials' => [
                    'api_endpoint' => $mock['endpoint'],
                    'bucket' => 'public-gcs-' . $suffix,
                    'access_token' => 'gcs-token',
                    'path_style' => true,
                    'verify_tls' => false,
                ],
            ],
            'seed_read_id' => 'read-gcs-' . $suffix,
            'seed_delete_id' => 'delete-gcs-' . $suffix,
            'write_id' => 'write-gcs-' . $suffix,
            'list_target' => 'GET /public-gcs-' . $suffix . '/',
            'write_http_code' => $kind === 'throttle' ? 503 : 507,
            'read_http_code' => $kind === 'throttle' ? 429 : 507,
            'delete_http_code' => $kind === 'throttle' ? 503 : 409,
            'list_http_code' => $kind === 'throttle' ? 429 : 403,
        ],
        'cloud_azure' => [
            'mock_options' => [
                'provider' => 'azure',
                'expected_access_token' => 'azure-token',
                'forced_responses' => [
                    [
                        'method' => 'GET',
                        'target' => '/public-azure-' . $suffix . '?restype=container&comp=list',
                        'status' => $kind === 'throttle' ? 429 : 409,
                        'error_code' => $kind === 'throttle' ? 'TooManyRequests' : 'QuotaExceeded',
                        'error_message' => $kind === 'throttle' ? 'Rate exceeded.' : 'The account quota has been exceeded.',
                    ],
                    [
                        'method' => 'PUT',
                        'target' => '/public-azure-' . $suffix . '/write-azure-' . $suffix,
                        'status' => $kind === 'throttle' ? 503 : 507,
                        'error_code' => $kind === 'throttle' ? 'ServerBusy' : 'InsufficientAccountResources',
                        'error_message' => $kind === 'throttle' ? 'Server busy.' : 'Account storage quota exhausted.',
                    ],
                    [
                        'method' => 'HEAD',
                        'target' => '/public-azure-' . $suffix . '/read-azure-' . $suffix,
                        'status' => $kind === 'throttle' ? 429 : 507,
                        'error_code' => $kind === 'throttle' ? 'TooManyRequests' : 'InsufficientStorage',
                        'error_message' => $kind === 'throttle' ? 'Read rate exceeded.' : 'Read quota exhausted.',
                    ],
                    [
                        'method' => 'GET',
                        'target' => '/public-azure-' . $suffix . '/read-azure-' . $suffix,
                        'status' => $kind === 'throttle' ? 429 : 507,
                        'error_code' => $kind === 'throttle' ? 'TooManyRequests' : 'InsufficientStorage',
                        'error_message' => $kind === 'throttle' ? 'Read rate exceeded.' : 'Read quota exhausted.',
                    ],
                    [
                        'method' => 'DELETE',
                        'target' => '/public-azure-' . $suffix . '/delete-azure-' . $suffix,
                        'status' => $kind === 'throttle' ? 503 : 507,
                        'error_code' => $kind === 'throttle' ? 'ServerBusy' : 'InsufficientStorage',
                        'error_message' => $kind === 'throttle' ? 'Delete throttled.' : 'Delete quota exhausted.',
                    ],
                ],
            ],
            'config' => static fn(array $mock, string $root): array => [
                'storage_root_path' => $root,
                'primary_backend' => 'cloud_azure',
                'cloud_credentials' => [
                    'api_endpoint' => $mock['endpoint'],
                    'container' => 'public-azure-' . $suffix,
                    'access_token' => 'azure-token',
                    'verify_tls' => false,
                ],
            ],
            'seed_read_id' => 'read-azure-' . $suffix,
            'seed_delete_id' => 'delete-azure-' . $suffix,
            'write_id' => 'write-azure-' . $suffix,
            'list_target' => 'GET /public-azure-' . $suffix . '?restype=container&comp=list',
            'write_http_code' => $kind === 'throttle' ? 503 : 507,
            'read_http_code' => $kind === 'throttle' ? 429 : 507,
            'delete_http_code' => $kind === 'throttle' ? 503 : 507,
            'list_http_code' => $kind === 'throttle' ? 429 : 409,
        ],
        default => throw new InvalidArgumentException('unknown backend ' . $backend),
    };
}

function king_object_store_509_run_case(string $backend, string $kind): array
{
    $case = king_object_store_509_provider_case($backend, $kind);
    $root = sys_get_temp_dir() . '/king_object_store_public_failure_509_' . $backend . '_' . $kind . '_' . getmypid();
    $stateDirectory = null;
    $mock = null;

    king_object_store_509_cleanup_tree($root);
    mkdir($root, 0700, true);

    try {
        $mock = king_object_store_s3_mock_start_server();
        $stateDirectory = $mock['state_directory'];
        $config = $case['config']($mock, $root);
        $result = [
            'init' => king_object_store_init($config),
        ];

        $result['seed_read'] = king_object_store_put($case['seed_read_id'], 'read-seed');
        $result['seed_delete'] = king_object_store_put($case['seed_delete_id'], 'delete-seed');
        king_object_store_s3_mock_stop_server($mock);
        $mock = null;

        $mock = king_object_store_s3_mock_start_server($stateDirectory, '127.0.0.1', $case['mock_options']);
        $config = $case['config']($mock, $root);
        king_object_store_init($config);

        try {
            king_object_store_put_from_stream(
                $case['write_id'],
                king_object_store_509_stream('payload')
            );
            $result['write_class'] = 'none';
            $result['write_normalized'] = false;
        } catch (Throwable $e) {
            $result['write_class'] = get_class($e);
            $result['write_normalized'] = king_object_store_509_is_normalized_public_failure(
                $e->getMessage(),
                $backend,
                $kind,
                $case['write_http_code']
            );
        }

        $destination = fopen('php://temp', 'w+');
        try {
            king_object_store_get_to_stream($case['seed_read_id'], $destination);
            $result['read_class'] = 'none';
            $result['read_normalized'] = false;
        } catch (Throwable $e) {
            $result['read_class'] = get_class($e);
            $result['read_normalized'] = king_object_store_509_is_normalized_public_failure(
                $e->getMessage(),
                $backend,
                $kind,
                $case['read_http_code']
            );
        }
        fclose($destination);

        try {
            king_object_store_delete($case['seed_delete_id']);
            $result['delete_class'] = 'none';
            $result['delete_normalized'] = false;
        } catch (Throwable $e) {
            $result['delete_class'] = get_class($e);
            $result['delete_normalized'] = king_object_store_509_is_normalized_public_failure(
                $e->getMessage(),
                $backend,
                $kind,
                $case['delete_http_code']
            );
        }

        try {
            king_object_store_list();
            $result['list_class'] = 'none';
            $result['list_normalized'] = false;
        } catch (Throwable $e) {
            $result['list_class'] = get_class($e);
            $result['list_normalized'] = king_object_store_509_is_normalized_public_failure(
                $e->getMessage(),
                $backend,
                $kind,
                $case['list_http_code']
            );
        }

        $capture = king_object_store_s3_mock_stop_server($mock);
        $mock = null;
        $targets = array_map(
            static fn(array $event): string => $event['method'] . ' ' . $event['target'],
            $capture['events']
        );
        $result['saw_list_target'] = in_array($case['list_target'], $targets, true);

        return $result;
    } finally {
        if (is_array($mock)) {
            king_object_store_s3_mock_stop_server($mock);
        }
        king_object_store_509_cleanup_tree($root);
        if ($stateDirectory !== null) {
            king_object_store_s3_mock_cleanup_state_directory($stateDirectory);
        }
    }
}

foreach (['cloud_s3', 'cloud_gcs', 'cloud_azure'] as $backend) {
    foreach (['throttle', 'quota'] as $kind) {
        $result = king_object_store_509_run_case($backend, $kind);
        var_dump($backend);
        var_dump($kind);
        var_dump($result['init']);
        var_dump($result['seed_read']);
        var_dump($result['seed_delete']);
        var_dump($result['write_class']);
        var_dump($result['write_normalized']);
        var_dump($result['read_class']);
        var_dump($result['read_normalized']);
        var_dump($result['delete_class']);
        var_dump($result['delete_normalized']);
        var_dump($result['list_class']);
        var_dump($result['list_normalized']);
        var_dump($result['saw_list_target']);
    }
}
?>
--EXPECT--
string(8) "cloud_s3"
string(8) "throttle"
bool(true)
bool(true)
bool(true)
string(20) "King\SystemException"
bool(true)
string(20) "King\SystemException"
bool(true)
string(20) "King\SystemException"
bool(true)
string(20) "King\SystemException"
bool(true)
bool(true)
string(8) "cloud_s3"
string(5) "quota"
bool(true)
bool(true)
bool(true)
string(20) "King\SystemException"
bool(true)
string(20) "King\SystemException"
bool(true)
string(20) "King\SystemException"
bool(true)
string(20) "King\SystemException"
bool(true)
bool(true)
string(9) "cloud_gcs"
string(8) "throttle"
bool(true)
bool(true)
bool(true)
string(20) "King\SystemException"
bool(true)
string(20) "King\SystemException"
bool(true)
string(20) "King\SystemException"
bool(true)
string(20) "King\SystemException"
bool(true)
bool(true)
string(9) "cloud_gcs"
string(5) "quota"
bool(true)
bool(true)
bool(true)
string(20) "King\SystemException"
bool(true)
string(20) "King\SystemException"
bool(true)
string(20) "King\SystemException"
bool(true)
string(20) "King\SystemException"
bool(true)
bool(true)
string(11) "cloud_azure"
string(8) "throttle"
bool(true)
bool(true)
bool(true)
string(20) "King\SystemException"
bool(true)
string(20) "King\SystemException"
bool(true)
string(20) "King\SystemException"
bool(true)
string(20) "King\SystemException"
bool(true)
bool(true)
string(11) "cloud_azure"
string(5) "quota"
bool(true)
bool(true)
bool(true)
string(20) "King\SystemException"
bool(true)
string(20) "King\SystemException"
bool(true)
string(20) "King\SystemException"
bool(true)
string(20) "King\SystemException"
bool(true)
bool(true)
