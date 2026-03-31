--TEST--
King object-store rejects CRLF-bearing cloud metadata header values before network I/O
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

function king_object_store_449_cleanup_tree(string $path): void
{
    if ($path === '' || !file_exists($path)) {
        return;
    }

    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            king_object_store_449_cleanup_tree($path . '/' . $entry);
        }

        @rmdir($path);
        return;
    }

    @unlink($path);
}

function king_object_store_449_backend_config(string $provider, string $endpoint): array
{
    return match ($provider) {
        'cloud_s3' => [
            'primary_backend' => 'cloud_s3',
            'cloud_credentials' => [
                'api_endpoint' => $endpoint,
                'bucket' => 'header-sanitize-s3',
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
                'bucket' => 'header-sanitize-gcs',
                'access_token' => 'gcs-token',
                'path_style' => true,
                'verify_tls' => false,
            ],
        ],
        'cloud_azure' => [
            'primary_backend' => 'cloud_azure',
            'cloud_credentials' => [
                'api_endpoint' => $endpoint,
                'container' => 'header-sanitize-azure',
                'access_token' => 'azure-token',
                'verify_tls' => false,
            ],
        ],
    };
}

function king_object_store_449_mock_options(string $provider): array
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

function king_object_store_449_event_has_injected_header(array $event): bool
{
    foreach ((array) ($event['headers'] ?? []) as $name => $value) {
        if (str_contains((string) $name, 'X-Evil')) {
            return true;
        }

        if (str_contains((string) $value, 'X-Evil')) {
            return true;
        }
    }

    return false;
}

foreach (['cloud_s3', 'cloud_gcs', 'cloud_azure'] as $provider) {
    $root = sys_get_temp_dir() . '/king_object_store_header_sanitize_449_' . $provider . '_' . getmypid();
    king_object_store_449_cleanup_tree($root);
    mkdir($root, 0700, true);

    $mock = king_object_store_s3_mock_start_server(
        null,
        '127.0.0.1',
        king_object_store_449_mock_options($provider)
    );

    $config = king_object_store_449_backend_config($provider, $mock['endpoint']);
    $config['storage_root_path'] = $root;

    $typeException = null;
    $encodingException = null;
    $uploadException = null;

    var_dump($provider);
    var_dump(king_object_store_init($config));

    try {
        king_object_store_put('crlf-type-' . $provider, 'alpha', [
            'content_type' => "application/json\r\nX-Evil: 1",
        ]);
    } catch (Throwable $throwable) {
        $typeException = $throwable;
    }
    var_dump($typeException instanceof King\ValidationException);
    var_dump($typeException !== null && str_contains($typeException->getMessage(), 'content_type'));

    try {
        king_object_store_put('crlf-encoding-' . $provider, 'bravo', [
            'content_encoding' => "gzip\r\nX-Evil: 1",
        ]);
    } catch (Throwable $throwable) {
        $encodingException = $throwable;
    }
    var_dump($encodingException instanceof King\ValidationException);
    var_dump($encodingException !== null && str_contains($encodingException->getMessage(), 'content_encoding'));

    try {
        king_object_store_begin_resumable_upload('crlf-upload-' . $provider, [
            'content_type' => "application/octet-stream\r\nX-Evil: 1",
        ]);
    } catch (Throwable $throwable) {
        $uploadException = $throwable;
    }
    var_dump($uploadException instanceof King\ValidationException);
    var_dump($uploadException !== null && str_contains($uploadException->getMessage(), 'content_type'));

    $capture = king_object_store_s3_mock_stop_server($mock);
    $blockedObjectIds = [
        'crlf-type-' . $provider,
        'crlf-encoding-' . $provider,
        'crlf-upload-' . $provider,
    ];
    var_dump(count(array_filter(
        $capture['events'] ?? [],
        static fn(array $event): bool =>
            in_array((string) ($event['object_id'] ?? ''), $blockedObjectIds, true)
            || king_object_store_449_event_has_injected_header($event)
    )) === 0);

    king_object_store_449_cleanup_tree($root);
    king_object_store_s3_mock_cleanup_state_directory($mock['state_directory']);
}
?>
--EXPECT--
string(8) "cloud_s3"
bool(true)
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
bool(true)
string(11) "cloud_azure"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
