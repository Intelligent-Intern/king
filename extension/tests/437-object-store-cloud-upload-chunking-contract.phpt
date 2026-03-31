--TEST--
King object-store cloud upload sessions expose a consistent sequential chunking contract
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

function king_object_store_437_cleanup_dir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        @unlink($dir . '/' . $entry);
    }

    @rmdir($dir);
}

function king_object_store_437_stream(string $payload)
{
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, $payload);
    rewind($stream);
    return $stream;
}

function king_object_store_437_backend_config(string $provider, string $endpoint): array
{
    return match ($provider) {
        'cloud_s3' => [
            'primary_backend' => 'cloud_s3',
            'cloud_credentials' => [
                'api_endpoint' => $endpoint,
                'bucket' => 'chunking-s3-test',
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
                'bucket' => 'chunking-gcs-test',
                'access_token' => 'gcs-token',
                'path_style' => true,
                'verify_tls' => false,
            ],
        ],
        'cloud_azure' => [
            'primary_backend' => 'cloud_azure',
            'cloud_credentials' => [
                'api_endpoint' => $endpoint,
                'container' => 'chunking-azure-test',
                'access_token' => 'azure-token',
                'verify_tls' => false,
            ],
        ],
    };
}

function king_object_store_437_mock_options(string $provider): array
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

$providers = ['cloud_s3', 'cloud_gcs', 'cloud_azure'];
$oversizedChunk = str_repeat('x', 1025);

foreach ($providers as $provider) {
    $root = sys_get_temp_dir() . '/king_object_store_chunking_437_' . $provider . '_' . getmypid();
    if (!is_dir($root)) {
        mkdir($root, 0700, true);
    }

    $mock = king_object_store_s3_mock_start_server(
        null,
        '127.0.0.1',
        king_object_store_437_mock_options($provider)
    );

    $config = king_object_store_437_backend_config($provider, $mock['endpoint']);
    $config['storage_root_path'] = $root;
    $config['chunk_size_kb'] = 1;

    var_dump(king_object_store_init($config));

    $started = king_object_store_begin_resumable_upload('chunk-contract-' . $provider, [
        'content_type' => 'application/octet-stream',
    ]);

    var_dump($started['backend'] === $provider);
    var_dump($started['chunk_size_bytes']);
    var_dump($started['sequential_chunks_required']);
    var_dump($started['final_chunk_may_be_shorter']);

    $caught = null;
    try {
        king_object_store_append_resumable_upload_chunk(
            $started['upload_id'],
            king_object_store_437_stream($oversizedChunk)
        );
    } catch (Throwable $throwable) {
        $caught = $throwable;
    }

    var_dump($caught instanceof King\ValidationException);
    var_dump($caught !== null && str_contains($caught->getMessage(), '1024 bytes'));

    $status = king_object_store_get_resumable_upload_status($started['upload_id']);
    var_dump($status['uploaded_bytes'] === 0);
    var_dump($status['next_part_number'] === 1);
    var_dump(king_object_store_abort_resumable_upload($started['upload_id']));

    king_object_store_s3_mock_stop_server($mock);
    king_object_store_437_cleanup_dir($root);
    king_object_store_s3_mock_cleanup_state_directory($mock['state_directory']);
}
?>
--EXPECT--
bool(true)
bool(true)
int(1024)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
int(1024)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
int(1024)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
