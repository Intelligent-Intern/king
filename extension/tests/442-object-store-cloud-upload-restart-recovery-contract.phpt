--TEST--
King object-store cloud upload sessions survive restart and can complete across real cloud backends
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

function king_object_store_442_cleanup_tree(string $path): void
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
                king_object_store_442_cleanup_tree($path . '/' . $entry);
            }
        }
        @rmdir($path);
        return;
    }

    @unlink($path);
}

function king_object_store_442_stream(string $payload)
{
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, $payload);
    rewind($stream);
    return $stream;
}

$cases = [
    'cloud_s3' => [
        'provider' => 's3',
        'object_id' => 'restart-s3',
        'bucket' => 'restart-s3-test',
        'credentials' => [
            'access_key' => 'access',
            'secret_key' => 'secret',
            'region' => 'us-east-1',
            'path_style' => true,
            'verify_tls' => false,
        ],
        'first' => 'alpha-',
        'second' => 'omega',
    ],
    'cloud_gcs' => [
        'provider' => 'gcs',
        'object_id' => 'restart-gcs',
        'bucket' => 'restart-gcs-test',
        'credentials' => [
            'access_token' => 'gcs-token',
            'path_style' => true,
            'verify_tls' => false,
        ],
        'mock_options' => [
            'provider' => 'gcs',
            'expected_access_token' => 'gcs-token',
        ],
        'first' => 'chunk-one',
        'second' => '++chunk-two',
    ],
    'cloud_azure' => [
        'provider' => 'azure',
        'object_id' => 'restart-azure',
        'container' => 'restart-azure-test',
        'credentials' => [
            'access_token' => 'azure-token',
            'verify_tls' => false,
        ],
        'mock_options' => [
            'provider' => 'azure',
            'expected_access_token' => 'azure-token',
        ],
        'first' => 'azure-',
        'second' => 'blocks',
    ],
];

foreach ($cases as $backend => $case) {
    $root = sys_get_temp_dir() . '/king_object_store_restart_442_' . $backend . '_' . getmypid();
    $mock = king_object_store_s3_mock_start_server(
        null,
        '127.0.0.1',
        $case['mock_options'] ?? []
    );
    $payload = $case['first'] . $case['second'];
    $payloadHash = hash('sha256', $payload);

    king_object_store_442_cleanup_tree($root);
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
        'integrity_sha256' => $payloadHash,
    ]);
    var_dump($started['backend']);
    var_dump($started['recovered_after_restart']);

    $afterChunkOne = king_object_store_append_resumable_upload_chunk(
        $started['upload_id'],
        king_object_store_442_stream($case['first'])
    );
    var_dump($afterChunkOne['uploaded_bytes']);
    var_dump($afterChunkOne['recovered_after_restart']);

    var_dump(king_object_store_init($config));
    $status = king_object_store_get_resumable_upload_status($started['upload_id']);
    var_dump($status['recovered_after_restart']);
    var_dump($status['uploaded_bytes']);
    var_dump($status['uploaded_part_count']);

    $afterChunkTwo = king_object_store_append_resumable_upload_chunk(
        $started['upload_id'],
        king_object_store_442_stream($case['second']),
        ['final' => true]
    );
    var_dump($afterChunkTwo['recovered_after_restart']);
    var_dump($afterChunkTwo['final_chunk_received']);

    $completed = king_object_store_complete_resumable_upload($started['upload_id']);
    var_dump($completed['completed']);
    var_dump($completed['recovered_after_restart']);
    var_dump(king_object_store_get_resumable_upload_status($started['upload_id']));
    var_dump(king_object_store_get($case['object_id']));

    $capture = king_object_store_s3_mock_stop_server($mock);
    var_dump(count($capture['events']) > 0);

    king_object_store_442_cleanup_tree($root);
    king_object_store_s3_mock_cleanup_state_directory($mock['state_directory']);
}
?>
--EXPECT--
bool(true)
string(8) "cloud_s3"
bool(false)
int(6)
bool(false)
bool(true)
bool(true)
int(6)
int(1)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
string(11) "alpha-omega"
bool(true)
bool(true)
string(9) "cloud_gcs"
bool(false)
int(9)
bool(false)
bool(true)
bool(true)
int(9)
int(1)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
string(20) "chunk-one++chunk-two"
bool(true)
bool(true)
string(11) "cloud_azure"
bool(false)
int(6)
bool(false)
bool(true)
bool(true)
int(6)
int(1)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
string(12) "azure-blocks"
bool(true)
