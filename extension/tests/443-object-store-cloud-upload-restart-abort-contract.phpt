--TEST--
King object-store cloud upload sessions can be aborted after restart and release the object lock across real cloud backends
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

function king_object_store_443_cleanup_tree(string $path): void
{
    if ($path === '' || !file_exists($path)) {
        return;
    }

    if (is_dir($path) && !is_link($path)) {
        $entries = scandir($path);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                king_object_store_443_cleanup_tree($path . '/' . $entry);
            }
        }
        @rmdir($path);
        return;
    }

    @unlink($path);
}

function king_object_store_443_stream(string $payload)
{
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, $payload);
    rewind($stream);
    return $stream;
}

$cases = [
    'cloud_s3' => [
        'provider' => 's3',
        'object_id' => 'abort-s3',
        'bucket' => 'restart-abort-s3-test',
        'credentials' => [
            'access_key' => 'access',
            'secret_key' => 'secret',
            'region' => 'us-east-1',
            'path_style' => true,
            'verify_tls' => false,
        ],
    ],
    'cloud_gcs' => [
        'provider' => 'gcs',
        'object_id' => 'abort-gcs',
        'bucket' => 'restart-abort-gcs-test',
        'credentials' => [
            'access_token' => 'gcs-token',
            'path_style' => true,
            'verify_tls' => false,
        ],
        'mock_options' => [
            'provider' => 'gcs',
            'expected_access_token' => 'gcs-token',
        ],
    ],
    'cloud_azure' => [
        'provider' => 'azure',
        'object_id' => 'abort-azure',
        'container' => 'restart-abort-azure-test',
        'credentials' => [
            'access_token' => 'azure-token',
            'verify_tls' => false,
        ],
        'mock_options' => [
            'provider' => 'azure',
            'expected_access_token' => 'azure-token',
        ],
    ],
];

foreach ($cases as $backend => $case) {
    $root = sys_get_temp_dir() . '/king_object_store_restart_abort_443_' . $backend . '_' . getmypid();
    $mock = king_object_store_s3_mock_start_server(
        null,
        '127.0.0.1',
        $case['mock_options'] ?? []
    );

    king_object_store_443_cleanup_tree($root);
    mkdir($root, 0700, true);

    $config = [
        'storage_root_path' => $root,
        'primary_backend' => $backend,
        'chunk_size_kb' => 1,
        'cloud_credentials' => array_merge(
            [
                'api_endpoint' => $mock['endpoint'],
            ],
            isset($case['bucket']) ? ['bucket' => $case['bucket']] : ['container' => $case['container']],
            $case['credentials']
        ),
    ];

    var_dump(king_object_store_init($config));
    $started = king_object_store_begin_resumable_upload($case['object_id'], [
        'content_type' => 'application/octet-stream',
    ]);
    var_dump($started['backend']);
    var_dump(king_object_store_append_resumable_upload_chunk(
        $started['upload_id'],
        king_object_store_443_stream('part-one')
    )['uploaded_bytes']);

    var_dump(king_object_store_init($config));
    $status = king_object_store_get_resumable_upload_status($started['upload_id']);
    var_dump($status['recovered_after_restart']);
    var_dump($status['uploaded_part_count']);

    var_dump(king_object_store_abort_resumable_upload($started['upload_id']));
    var_dump(king_object_store_get_resumable_upload_status($started['upload_id']));
    var_dump(king_object_store_get($case['object_id']));

    $retry = king_object_store_begin_resumable_upload($case['object_id'], [
        'content_type' => 'application/octet-stream',
    ]);
    var_dump(is_array($retry));
    var_dump(king_object_store_abort_resumable_upload($retry['upload_id']));

    $capture = king_object_store_s3_mock_stop_server($mock);
    var_dump(count($capture['events']) > 0);

    king_object_store_443_cleanup_tree($root);
    king_object_store_s3_mock_cleanup_state_directory($mock['state_directory']);
}
?>
--EXPECT--
bool(true)
string(8) "cloud_s3"
int(8)
bool(true)
bool(true)
int(1)
bool(true)
bool(false)
bool(false)
bool(true)
bool(true)
bool(true)
bool(true)
string(9) "cloud_gcs"
int(8)
bool(true)
bool(true)
int(1)
bool(true)
bool(false)
bool(false)
bool(true)
bool(true)
bool(true)
bool(true)
string(11) "cloud_azure"
int(8)
bool(true)
bool(true)
int(1)
bool(true)
bool(false)
bool(false)
bool(true)
bool(true)
bool(true)
