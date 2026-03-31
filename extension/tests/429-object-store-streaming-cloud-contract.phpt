--TEST--
King object-store bounded-memory stream ingress and egress remain consistent across real cloud backends
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

function king_object_store_streaming_429_cleanup_dir(string $dir): void
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

function king_object_store_streaming_429_run(string $backend, string $provider): array
{
    $root = sys_get_temp_dir() . '/king_object_store_stream_cloud_429_' . $backend . '_' . getmypid();
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
        $config['cloud_credentials']['bucket'] = 'stream-s3';
        $config['cloud_credentials']['access_key'] = 'access';
        $config['cloud_credentials']['secret_key'] = 'secret';
        $config['cloud_credentials']['region'] = 'us-east-1';
        $config['cloud_credentials']['path_style'] = true;
    } elseif ($backend === 'cloud_gcs') {
        $config['cloud_credentials']['bucket'] = 'stream-gcs';
        $config['cloud_credentials']['access_token'] = 'gcs-token';
        $config['cloud_credentials']['path_style'] = true;
    } else {
        $config['cloud_credentials']['container'] = 'stream-azure';
        $config['cloud_credentials']['access_token'] = 'azure-token';
    }

    $objectId = 'stream-' . $provider;
    $payload = str_repeat('0123456789abcdef', 900) . 'tail';
    $payloadHash = hash('sha256', $payload);
    $source = fopen('php://temp', 'w+');
    fwrite($source, $payload);
    rewind($source);

    $result = [
        'init' => king_object_store_init($config),
        'put' => king_object_store_put_from_stream($objectId, $source, [
            'content_type' => 'application/octet-stream',
            'object_type' => 'binary_data',
            'cache_policy' => 'etag',
        ]),
    ];

    $meta = king_object_store_get_metadata($objectId);
    $result['content_length'] = $meta['content_length'] === strlen($payload);
    $result['integrity'] = $meta['integrity_sha256'] === $payloadHash;

    $destination = fopen('php://temp', 'w+');
    $result['get_to_stream'] = king_object_store_get_to_stream($objectId, $destination);
    rewind($destination);
    $result['payload_roundtrip'] = stream_get_contents($destination) === $payload;

    $rangeDestination = fopen('php://temp', 'w+');
    $result['range_get_to_stream'] = king_object_store_get_to_stream($objectId, $rangeDestination, [
        'offset' => 2048,
        'length' => 17,
    ]);
    rewind($rangeDestination);
    $result['range_payload'] = stream_get_contents($rangeDestination) === substr($payload, 2048, 17);

    $capture = king_object_store_s3_mock_stop_server($mock);
    $result['put_length_seen'] = count(array_filter(
        $capture['events'],
        static fn(array $event): bool =>
            $event['method'] === 'PUT'
            && $event['object_id'] === $objectId
            && $event['content_length'] === strlen($payload)
    )) >= 1;
    $result['range_header_seen'] = count(array_filter(
        $capture['events'],
        static fn(array $event): bool =>
            $event['method'] === 'GET'
            && $event['object_id'] === $objectId
            && (($event['headers']['range'] ?? '') === 'bytes=2048-2064')
    )) >= 1;

    king_object_store_streaming_429_cleanup_dir($root);
    king_object_store_s3_mock_cleanup_state_directory($mock['state_directory']);

    return $result;
}

foreach ([
    ['cloud_s3', 's3'],
    ['cloud_gcs', 'gcs'],
    ['cloud_azure', 'azure'],
] as [$backend, $provider]) {
    $result = king_object_store_streaming_429_run($backend, $provider);
    var_dump($backend);
    var_dump($result['init']);
    var_dump($result['put']);
    var_dump($result['content_length']);
    var_dump($result['integrity']);
    var_dump($result['get_to_stream']);
    var_dump($result['payload_roundtrip']);
    var_dump($result['range_get_to_stream']);
    var_dump($result['range_payload']);
    var_dump($result['put_length_seen']);
    var_dump($result['range_header_seen']);
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
bool(true)
bool(true)
