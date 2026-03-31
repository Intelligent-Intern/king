--TEST--
King object-store cloud upload sessions expose stable abort and status semantics
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

function king_object_store_434_cleanup_dir(string $dir): void
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

function king_object_store_434_stream(string $payload)
{
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, $payload);
    rewind($stream);
    return $stream;
}

function king_object_store_434_run(string $backend, string $provider): array
{
    $root = sys_get_temp_dir() . '/king_object_store_abort_434_' . $backend . '_' . getmypid();
    if (!is_dir($root)) {
        mkdir($root, 0700, true);
    }

    $mockOptions = ['provider' => $provider];
    if ($provider === 'gcs') {
        $mockOptions['expected_access_token'] = 'gcs-token';
    } elseif ($provider === 'azure') {
        $mockOptions['expected_access_token'] = 'azure-token';
    }
    $mock = king_object_store_s3_mock_start_server(null, '127.0.0.1', $mockOptions);

    $config = [
        'storage_root_path' => $root,
        'primary_backend' => $backend,
        'chunk_size_kb' => 1,
        'cloud_credentials' => [
            'api_endpoint' => $mock['endpoint'],
            'verify_tls' => false,
        ],
    ];

    if ($backend === 'cloud_s3') {
        $config['cloud_credentials']['bucket'] = 'abort-s3';
        $config['cloud_credentials']['access_key'] = 'access';
        $config['cloud_credentials']['secret_key'] = 'secret';
        $config['cloud_credentials']['region'] = 'us-east-1';
        $config['cloud_credentials']['path_style'] = true;
    } elseif ($backend === 'cloud_gcs') {
        $config['cloud_credentials']['bucket'] = 'abort-gcs';
        $config['cloud_credentials']['access_token'] = 'gcs-token';
        $config['cloud_credentials']['path_style'] = true;
    } else {
        $config['cloud_credentials']['container'] = 'abort-azure';
        $config['cloud_credentials']['access_token'] = 'azure-token';
    }

    $result = [
        'init' => king_object_store_init($config),
    ];
    $started = king_object_store_begin_resumable_upload('abort-' . $provider, [
        'content_type' => 'application/octet-stream',
    ]);
    $result['protocol'] = $started['protocol'];
    $result['status_visible'] = king_object_store_get_resumable_upload_status($started['upload_id']) !== false;

    if ($backend === 'cloud_azure') {
        $result['chunk'] = king_object_store_append_resumable_upload_chunk(
            $started['upload_id'],
            king_object_store_434_stream('one')
        )['uploaded_part_count'] === 1;
    } elseif ($backend === 'cloud_gcs') {
        $result['chunk'] = king_object_store_append_resumable_upload_chunk(
            $started['upload_id'],
            king_object_store_434_stream('one')
        )['uploaded_part_count'] === 1;
    } else {
        $result['chunk'] = true;
    }

    $result['abort'] = king_object_store_abort_resumable_upload($started['upload_id']);
    $result['status_after_abort'] = king_object_store_get_resumable_upload_status($started['upload_id']);
    $result['object_visible'] = king_object_store_get('abort-' . $provider);

    $capture = king_object_store_s3_mock_stop_server($mock);
    $result['remote_abort_seen'] = match ($backend) {
        'cloud_s3' => count(array_filter(
            $capture['events'],
            static fn(array $event): bool =>
                $event['method'] === 'DELETE'
                && str_starts_with($event['target'], '/abort-s3/abort-s3?uploadId=')
        )) === 1,
        'cloud_gcs' => count(array_filter(
            $capture['events'],
            static fn(array $event): bool =>
                $event['method'] === 'DELETE'
                && str_starts_with($event['target'], '/__gcs_resumable/')
        )) === 1,
        default => count(array_filter(
            $capture['events'],
            static fn(array $event): bool =>
                $event['method'] === 'PUT'
                && $event['target'] === '/abort-azure/abort-azure?comp=blocklist'
        )) === 0,
    };

    king_object_store_434_cleanup_dir($root);
    king_object_store_s3_mock_cleanup_state_directory($mock['state_directory']);

    return $result;
}

foreach ([
    ['cloud_s3', 's3', 's3_multipart'],
    ['cloud_gcs', 'gcs', 'gcs_resumable'],
    ['cloud_azure', 'azure', 'azure_blocks'],
] as [$backend, $provider, $protocol]) {
    $result = king_object_store_434_run($backend, $provider);
    var_dump($backend);
    var_dump($result['init']);
    var_dump($result['protocol']);
    var_dump($result['status_visible']);
    var_dump($result['chunk']);
    var_dump($result['abort']);
    var_dump($result['status_after_abort']);
    var_dump($result['object_visible']);
    var_dump($result['remote_abort_seen']);
}
?>
--EXPECT--
string(8) "cloud_s3"
bool(true)
string(12) "s3_multipart"
bool(true)
bool(true)
bool(true)
bool(false)
bool(false)
bool(true)
string(9) "cloud_gcs"
bool(true)
string(13) "gcs_resumable"
bool(true)
bool(true)
bool(true)
bool(false)
bool(false)
bool(true)
string(11) "cloud_azure"
bool(true)
string(12) "azure_blocks"
bool(true)
bool(true)
bool(true)
bool(false)
bool(false)
bool(true)
