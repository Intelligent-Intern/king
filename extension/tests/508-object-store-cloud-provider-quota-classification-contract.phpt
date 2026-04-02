--TEST--
King object-store classifies provider quota exhaustion distinctly across real cloud backends
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

function king_object_store_508_cleanup_dir(string $dir): void
{
    if ($dir === '' || !is_dir($dir)) {
        return;
    }

    $entries = scandir($dir);
    if ($entries === false) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir . '/' . $entry;
        if (is_dir($path)) {
            king_object_store_508_cleanup_dir($path);
            @rmdir($path);
            continue;
        }

        @unlink($path);
    }

    @rmdir($dir);
}

function king_object_store_508_is_distinct_quota_error(string $message, int $httpCode): bool
{
    return str_contains($message, 'reported exhausted quota')
        && str_contains($message, 'HTTP ' . $httpCode)
        && !str_contains($message, 'credentials were rejected')
        && !str_contains($message, 'throttled')
        && !str_contains($message, 'could not connect to the configured endpoint');
}

function king_object_store_508_provider_case(string $provider): array
{
    return match ($provider) {
        'cloud_s3' => [
            'mock_options' => [
                'forced_responses' => [
                    [
                        'method' => 'GET',
                        'target' => '/quota-test-s3?list-type=2',
                        'status' => 507,
                        'error_code' => 'InsufficientStorage',
                        'error_message' => 'Bucket storage quota exhausted.',
                    ],
                    [
                        'method' => 'PUT',
                        'target' => '/quota-test-s3/doc-s3',
                        'status' => 403,
                        'error_code' => 'QuotaExceeded',
                        'error_message' => 'Bucket quota exhausted.',
                    ],
                ],
            ],
            'config' => static fn(array $mock, string $root): array => [
                'storage_root_path' => $root,
                'primary_backend' => 'cloud_s3',
                'cloud_credentials' => [
                    'api_endpoint' => $mock['endpoint'],
                    'bucket' => 'quota-test-s3',
                    'access_key' => 'access',
                    'secret_key' => 'secret',
                    'region' => 'us-east-1',
                    'path_style' => true,
                    'verify_tls' => false,
                ],
            ],
            'object_id' => 'doc-s3',
            'list_target' => 'GET /quota-test-s3?list-type=2',
            'put_target' => 'PUT /quota-test-s3/doc-s3',
            'list_http_code' => 507,
            'put_http_code' => 403,
        ],
        'cloud_gcs' => [
            'mock_options' => [
                'provider' => 'gcs',
                'expected_access_token' => 'gcs-token',
                'forced_responses' => [
                    [
                        'method' => 'GET',
                        'target' => '/quota-test-gcs/',
                        'status' => 403,
                        'error_code' => 'storageQuotaExceeded',
                        'error_message' => 'Project storage quota exhausted.',
                    ],
                    [
                        'method' => 'PUT',
                        'target' => '/quota-test-gcs/doc-gcs',
                        'status' => 507,
                        'error_code' => 'InsufficientStorage',
                        'error_message' => 'Not enough storage quota.',
                    ],
                ],
            ],
            'config' => static fn(array $mock, string $root): array => [
                'storage_root_path' => $root,
                'primary_backend' => 'cloud_gcs',
                'cloud_credentials' => [
                    'api_endpoint' => $mock['endpoint'],
                    'bucket' => 'quota-test-gcs',
                    'access_token' => 'gcs-token',
                    'path_style' => true,
                    'verify_tls' => false,
                ],
            ],
            'object_id' => 'doc-gcs',
            'list_target' => 'GET /quota-test-gcs/',
            'put_target' => 'PUT /quota-test-gcs/doc-gcs',
            'list_http_code' => 403,
            'put_http_code' => 507,
        ],
        'cloud_azure' => [
            'mock_options' => [
                'provider' => 'azure',
                'expected_access_token' => 'azure-token',
                'forced_responses' => [
                    [
                        'method' => 'GET',
                        'target' => '/quota-test-azure?restype=container&comp=list',
                        'status' => 409,
                        'error_code' => 'QuotaExceeded',
                        'error_message' => 'The account quota has been exceeded.',
                    ],
                    [
                        'method' => 'PUT',
                        'target' => '/quota-test-azure/doc-azure',
                        'status' => 507,
                        'error_code' => 'InsufficientAccountResources',
                        'error_message' => 'Account storage quota exhausted.',
                    ],
                ],
            ],
            'config' => static fn(array $mock, string $root): array => [
                'storage_root_path' => $root,
                'primary_backend' => 'cloud_azure',
                'cloud_credentials' => [
                    'api_endpoint' => $mock['endpoint'],
                    'container' => 'quota-test-azure',
                    'access_token' => 'azure-token',
                    'verify_tls' => false,
                ],
            ],
            'object_id' => 'doc-azure',
            'list_target' => 'GET /quota-test-azure?restype=container&comp=list',
            'put_target' => 'PUT /quota-test-azure/doc-azure',
            'list_http_code' => 409,
            'put_http_code' => 507,
        ],
        default => throw new InvalidArgumentException('unknown provider ' . $provider),
    };
}

function king_object_store_508_run_case(string $provider): array
{
    $case = king_object_store_508_provider_case($provider);
    $root = sys_get_temp_dir() . '/king_object_store_quota_' . $provider . '_' . getmypid();
    $mock = null;

    if (!is_dir($root)) {
        mkdir($root, 0700, true);
    }

    try {
        $mock = king_object_store_s3_mock_start_server(null, '127.0.0.1', $case['mock_options']);
        $config = $case['config']($mock, $root);
        $result = [];

        $result['init'] = king_object_store_init($config);
        $stats = king_object_store_get_stats()['object_store'];
        $result['init_status'] = $stats['runtime_primary_adapter_status'];
        $result['init_distinct_quota_error'] = king_object_store_508_is_distinct_quota_error(
            $stats['runtime_primary_adapter_error'],
            $case['list_http_code']
        );

        try {
            king_object_store_put($case['object_id'], 'alpha');
            $result['put_exception_class'] = 'none';
            $result['put_distinct_quota_error'] = false;
        } catch (Throwable $e) {
            $result['put_exception_class'] = get_class($e);
            $result['put_distinct_quota_error'] = king_object_store_508_is_distinct_quota_error(
                $e->getMessage(),
                $case['put_http_code']
            );
        }

        $stats = king_object_store_get_stats()['object_store'];
        $result['post_put_status'] = $stats['runtime_primary_adapter_status'];
        $result['post_put_distinct_quota_error'] = king_object_store_508_is_distinct_quota_error(
            $stats['runtime_primary_adapter_error'],
            $case['put_http_code']
        );

        try {
            king_object_store_list();
            $result['list_exception_class'] = 'none';
            $result['list_distinct_quota_error'] = false;
        } catch (Throwable $e) {
            $result['list_exception_class'] = get_class($e);
            $result['list_distinct_quota_error'] = king_object_store_508_is_distinct_quota_error(
                $e->getMessage(),
                $case['list_http_code']
            );
        }

        $stats = king_object_store_get_stats()['object_store'];
        $result['post_list_status'] = $stats['runtime_primary_adapter_status'];
        $result['post_list_distinct_quota_error'] = king_object_store_508_is_distinct_quota_error(
            $stats['runtime_primary_adapter_error'],
            $case['list_http_code']
        );

        $capture = king_object_store_s3_mock_stop_server($mock);
        $mock = null;
        $targets = array_map(
            static fn(array $event): string => $event['method'] . ' ' . $event['target'],
            $capture['events']
        );
        $result['saw_list_target'] = in_array($case['list_target'], $targets, true);
        $result['saw_put_target'] = in_array($case['put_target'], $targets, true);

        return $result;
    } finally {
        if (is_array($mock)) {
            king_object_store_s3_mock_stop_server($mock);
            king_object_store_s3_mock_cleanup_state_directory($mock['state_directory']);
        }
        king_object_store_508_cleanup_dir($root);
    }
}

$results = [];
foreach (['cloud_s3', 'cloud_gcs', 'cloud_azure'] as $provider) {
    $results[$provider] = king_object_store_508_run_case($provider);
}

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
?>
--EXPECT--
{
    "cloud_s3": {
        "init": true,
        "init_status": "failed",
        "init_distinct_quota_error": true,
        "put_exception_class": "King\\SystemException",
        "put_distinct_quota_error": true,
        "post_put_status": "failed",
        "post_put_distinct_quota_error": true,
        "list_exception_class": "King\\SystemException",
        "list_distinct_quota_error": true,
        "post_list_status": "failed",
        "post_list_distinct_quota_error": true,
        "saw_list_target": true,
        "saw_put_target": true
    },
    "cloud_gcs": {
        "init": true,
        "init_status": "failed",
        "init_distinct_quota_error": true,
        "put_exception_class": "King\\SystemException",
        "put_distinct_quota_error": true,
        "post_put_status": "failed",
        "post_put_distinct_quota_error": true,
        "list_exception_class": "King\\SystemException",
        "list_distinct_quota_error": true,
        "post_list_status": "failed",
        "post_list_distinct_quota_error": true,
        "saw_list_target": true,
        "saw_put_target": true
    },
    "cloud_azure": {
        "init": true,
        "init_status": "failed",
        "init_distinct_quota_error": true,
        "put_exception_class": "King\\SystemException",
        "put_distinct_quota_error": true,
        "post_put_status": "failed",
        "post_put_distinct_quota_error": true,
        "list_exception_class": "King\\SystemException",
        "list_distinct_quota_error": true,
        "post_list_status": "failed",
        "post_list_distinct_quota_error": true,
        "saw_list_target": true,
        "saw_put_target": true
    }
}
