--TEST--
King object-store public upload-session failures expose normalized quota and throttling prefixes across real cloud backends
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

function king_object_store_510_cleanup_tree(string $path): void
{
    if ($path === '' || !file_exists($path)) {
        return;
    }

    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            king_object_store_510_cleanup_tree($path . '/' . $entry);
        }
        @rmdir($path);
        return;
    }

    @unlink($path);
}

function king_object_store_510_stream(string $payload)
{
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, $payload);
    rewind($stream);
    return $stream;
}

function king_object_store_510_config(string $backend, array $mock, string $root): array
{
    return match ($backend) {
        'cloud_s3' => [
            'storage_root_path' => $root,
            'primary_backend' => 'cloud_s3',
            'chunk_size_kb' => 1,
            'cloud_credentials' => [
                'api_endpoint' => $mock['endpoint'],
                'bucket' => 'upload-norm-s3',
                'access_key' => 'access',
                'secret_key' => 'secret',
                'region' => 'us-east-1',
                'path_style' => true,
                'verify_tls' => false,
            ],
        ],
        'cloud_gcs' => [
            'storage_root_path' => $root,
            'primary_backend' => 'cloud_gcs',
            'chunk_size_kb' => 1,
            'cloud_credentials' => [
                'api_endpoint' => $mock['endpoint'],
                'bucket' => 'upload-norm-gcs',
                'access_token' => 'gcs-token',
                'path_style' => true,
                'verify_tls' => false,
            ],
        ],
        'cloud_azure' => [
            'storage_root_path' => $root,
            'primary_backend' => 'cloud_azure',
            'chunk_size_kb' => 1,
            'cloud_credentials' => [
                'api_endpoint' => $mock['endpoint'],
                'container' => 'upload-norm-azure',
                'access_token' => 'azure-token',
                'verify_tls' => false,
            ],
        ],
        default => throw new InvalidArgumentException('unknown backend ' . $backend),
    };
}

function king_object_store_510_state_path(string $root, string $uploadId): string
{
    return $root . '/.king_upload_sessions/' . $uploadId . '.state';
}

function king_object_store_510_state_value(string $root, string $uploadId, string $key): string
{
    $path = king_object_store_510_state_path($root, $uploadId);
    $lines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : false;

    if (!is_array($lines)) {
        throw new RuntimeException('missing upload state file for ' . $uploadId);
    }

    foreach ($lines as $line) {
        if (str_starts_with($line, $key . '=')) {
            return substr($line, strlen($key) + 1);
        }
    }

    throw new RuntimeException('missing upload state key ' . $key);
}

function king_object_store_510_rewrite_provider_token(string $root, string $uploadId, string $providerToken): void
{
    $path = king_object_store_510_state_path($root, $uploadId);
    $lines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : false;

    if (!is_array($lines)) {
        throw new RuntimeException('missing upload state file for rewrite');
    }

    foreach ($lines as $index => $line) {
        if (str_starts_with($line, 'provider_token=')) {
            $lines[$index] = 'provider_token=' . $providerToken;
            file_put_contents($path, implode("\n", $lines) . "\n");
            return;
        }
    }

    throw new RuntimeException('missing provider_token line');
}

function king_object_store_510_is_normalized_public_failure(
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

function king_object_store_510_capture_failure(callable $fn, string $backend, string $kind, int $httpCode): array
{
    try {
        $fn();
        return ['class' => 'none', 'normalized' => false];
    } catch (Throwable $e) {
        return [
            'class' => get_class($e),
            'normalized' => king_object_store_510_is_normalized_public_failure(
                $e->getMessage(),
                $backend,
                $kind,
                $httpCode
            ),
        ];
    }
}

function king_object_store_510_run_s3(): array
{
    $root = sys_get_temp_dir() . '/king_object_store_upload_norm_s3_510_' . getmypid();
    $stateDirectory = null;
    $mock = null;

    king_object_store_510_cleanup_tree($root);
    mkdir($root, 0700, true);

    try {
        $mock = king_object_store_s3_mock_start_server(
            null,
            '127.0.0.1',
            [
                'forced_responses' => [[
                    'method' => 'POST',
                    'target' => '/upload-norm-s3/upload-begin-s3?uploads',
                    'status' => 403,
                    'error_code' => 'QuotaExceeded',
                    'error_message' => 'Bucket quota exhausted.',
                ]],
            ]
        );
        $stateDirectory = $mock['state_directory'];
        $result = ['init' => king_object_store_init(king_object_store_510_config('cloud_s3', $mock, $root))];
        $failure = king_object_store_510_capture_failure(
            static fn() => king_object_store_begin_resumable_upload('upload-begin-s3'),
            'cloud_s3',
            'quota',
            403
        );
        $result['begin_quota_class'] = $failure['class'];
        $result['begin_quota_normalized'] = $failure['normalized'];
        king_object_store_s3_mock_stop_server($mock);
        $mock = null;

        $mock = king_object_store_s3_mock_start_server($stateDirectory);
        king_object_store_init(king_object_store_510_config('cloud_s3', $mock, $root));
        $append = king_object_store_begin_resumable_upload('upload-append-s3');
        $appendToken = king_object_store_510_state_value($root, $append['upload_id'], 'provider_token');
        king_object_store_s3_mock_stop_server($mock);
        $mock = null;

        $mock = king_object_store_s3_mock_start_server(
            $stateDirectory,
            '127.0.0.1',
            [
                'forced_responses' => [[
                    'method' => 'PUT',
                    'target' => '/upload-norm-s3/upload-append-s3?partNumber=1&uploadId=' . $appendToken,
                    'status' => 503,
                    'error_code' => 'SlowDown',
                    'error_message' => 'Reduce your request rate.',
                ]],
            ]
        );
        king_object_store_init(king_object_store_510_config('cloud_s3', $mock, $root));
        $failure = king_object_store_510_capture_failure(
            static fn() => king_object_store_append_resumable_upload_chunk(
                $append['upload_id'],
                king_object_store_510_stream('alpha')
            ),
            'cloud_s3',
            'throttle',
            503
        );
        $result['append_throttle_class'] = $failure['class'];
        $result['append_throttle_normalized'] = $failure['normalized'];
        king_object_store_s3_mock_stop_server($mock);
        $mock = null;

        $mock = king_object_store_s3_mock_start_server($stateDirectory);
        king_object_store_init(king_object_store_510_config('cloud_s3', $mock, $root));
        $complete = king_object_store_begin_resumable_upload('upload-complete-s3');
        king_object_store_append_resumable_upload_chunk(
            $complete['upload_id'],
            king_object_store_510_stream('omega'),
            ['final' => true]
        );
        $completeToken = king_object_store_510_state_value($root, $complete['upload_id'], 'provider_token');
        king_object_store_s3_mock_stop_server($mock);
        $mock = null;

        $mock = king_object_store_s3_mock_start_server(
            $stateDirectory,
            '127.0.0.1',
            [
                'forced_responses' => [[
                    'method' => 'POST',
                    'target' => '/upload-norm-s3/upload-complete-s3?uploadId=' . $completeToken,
                    'status' => 507,
                    'error_code' => 'InsufficientStorage',
                    'error_message' => 'Bucket storage quota exhausted.',
                ]],
            ]
        );
        king_object_store_init(king_object_store_510_config('cloud_s3', $mock, $root));
        $failure = king_object_store_510_capture_failure(
            static fn() => king_object_store_complete_resumable_upload($complete['upload_id']),
            'cloud_s3',
            'quota',
            507
        );
        $result['complete_quota_class'] = $failure['class'];
        $result['complete_quota_normalized'] = $failure['normalized'];
        king_object_store_s3_mock_stop_server($mock);
        $mock = null;

        $mock = king_object_store_s3_mock_start_server($stateDirectory);
        king_object_store_init(king_object_store_510_config('cloud_s3', $mock, $root));
        $abort = king_object_store_begin_resumable_upload('upload-abort-s3');
        $abortToken = king_object_store_510_state_value($root, $abort['upload_id'], 'provider_token');
        king_object_store_s3_mock_stop_server($mock);
        $mock = null;

        $mock = king_object_store_s3_mock_start_server(
            $stateDirectory,
            '127.0.0.1',
            [
                'forced_responses' => [[
                    'method' => 'DELETE',
                    'target' => '/upload-norm-s3/upload-abort-s3?uploadId=' . $abortToken,
                    'status' => 503,
                    'error_code' => 'SlowDown',
                    'error_message' => 'Abort throttled.',
                ]],
            ]
        );
        king_object_store_init(king_object_store_510_config('cloud_s3', $mock, $root));
        $failure = king_object_store_510_capture_failure(
            static fn() => king_object_store_abort_resumable_upload($abort['upload_id']),
            'cloud_s3',
            'throttle',
            503
        );
        $result['abort_throttle_class'] = $failure['class'];
        $result['abort_throttle_normalized'] = $failure['normalized'];

        return $result;
    } finally {
        if (is_array($mock)) {
            king_object_store_s3_mock_stop_server($mock);
        }
        king_object_store_510_cleanup_tree($root);
        if ($stateDirectory !== null) {
            king_object_store_s3_mock_cleanup_state_directory($stateDirectory);
        }
    }
}

function king_object_store_510_run_gcs(): array
{
    $root = sys_get_temp_dir() . '/king_object_store_upload_norm_gcs_510_' . getmypid();
    $stateDirectory = null;
    $mock = null;

    king_object_store_510_cleanup_tree($root);
    mkdir($root, 0700, true);

    try {
        $mock = king_object_store_s3_mock_start_server(
            null,
            '127.0.0.1',
            [
                'provider' => 'gcs',
                'expected_access_token' => 'gcs-token',
                'forced_responses' => [[
                    'method' => 'POST',
                    'target' => '/upload-norm-gcs/upload-begin-gcs?uploadType=resumable',
                    'status' => 403,
                    'error_code' => 'storageQuotaExceeded',
                    'error_message' => 'Project storage quota exhausted.',
                ]],
            ]
        );
        $stateDirectory = $mock['state_directory'];
        $result = ['init' => king_object_store_init(king_object_store_510_config('cloud_gcs', $mock, $root))];
        $failure = king_object_store_510_capture_failure(
            static fn() => king_object_store_begin_resumable_upload('upload-begin-gcs'),
            'cloud_gcs',
            'quota',
            403
        );
        $result['begin_quota_class'] = $failure['class'];
        $result['begin_quota_normalized'] = $failure['normalized'];

        return $result;
    } finally {
        if (is_array($mock)) {
            king_object_store_s3_mock_stop_server($mock);
        }
        king_object_store_510_cleanup_tree($root);
        if ($stateDirectory !== null) {
            king_object_store_s3_mock_cleanup_state_directory($stateDirectory);
        }
    }
}

function king_object_store_510_run_azure(): array
{
    $root = sys_get_temp_dir() . '/king_object_store_upload_norm_azure_510_' . getmypid();
    $stateDirectory = null;
    $mock = null;
    $firstBlockId = base64_encode('000001');

    king_object_store_510_cleanup_tree($root);
    mkdir($root, 0700, true);

    try {
        $mock = king_object_store_s3_mock_start_server(
            null,
            '127.0.0.1',
            [
                'provider' => 'azure',
                'expected_access_token' => 'azure-token',
                'forced_responses' => [[
                    'method' => 'PUT',
                    'target' => '/upload-norm-azure/upload-append-azure?comp=block&blockid=' . rawurlencode($firstBlockId),
                    'status' => 503,
                    'error_code' => 'ServerBusy',
                    'error_message' => 'Server busy.',
                ]],
            ]
        );
        $stateDirectory = $mock['state_directory'];
        $result = ['init' => king_object_store_init(king_object_store_510_config('cloud_azure', $mock, $root))];
        $append = king_object_store_begin_resumable_upload('upload-append-azure');
        $failure = king_object_store_510_capture_failure(
            static fn() => king_object_store_append_resumable_upload_chunk(
                $append['upload_id'],
                king_object_store_510_stream('alpha')
            ),
            'cloud_azure',
            'throttle',
            503
        );
        $result['append_throttle_class'] = $failure['class'];
        $result['append_throttle_normalized'] = $failure['normalized'];
        king_object_store_s3_mock_stop_server($mock);
        $mock = null;

        $mock = king_object_store_s3_mock_start_server(
            $stateDirectory,
            '127.0.0.1',
            [
                'provider' => 'azure',
                'expected_access_token' => 'azure-token',
                'forced_responses' => [[
                    'method' => 'PUT',
                    'target' => '/upload-norm-azure/upload-complete-azure?comp=blocklist',
                    'status' => 507,
                    'error_code' => 'InsufficientAccountResources',
                    'error_message' => 'Account storage quota exhausted.',
                ]],
            ]
        );
        king_object_store_init(king_object_store_510_config('cloud_azure', $mock, $root));
        $complete = king_object_store_begin_resumable_upload('upload-complete-azure');
        king_object_store_append_resumable_upload_chunk(
            $complete['upload_id'],
            king_object_store_510_stream('omega'),
            ['final' => true]
        );
        $failure = king_object_store_510_capture_failure(
            static fn() => king_object_store_complete_resumable_upload($complete['upload_id']),
            'cloud_azure',
            'quota',
            507
        );
        $result['complete_quota_class'] = $failure['class'];
        $result['complete_quota_normalized'] = $failure['normalized'];

        return $result;
    } finally {
        if (is_array($mock)) {
            king_object_store_s3_mock_stop_server($mock);
        }
        king_object_store_510_cleanup_tree($root);
        if ($stateDirectory !== null) {
            king_object_store_s3_mock_cleanup_state_directory($stateDirectory);
        }
    }
}

$s3 = king_object_store_510_run_s3();
var_dump('cloud_s3');
var_dump($s3['init']);
var_dump($s3['begin_quota_class']);
var_dump($s3['begin_quota_normalized']);
var_dump($s3['append_throttle_class']);
var_dump($s3['append_throttle_normalized']);
var_dump($s3['complete_quota_class']);
var_dump($s3['complete_quota_normalized']);
var_dump($s3['abort_throttle_class']);
var_dump($s3['abort_throttle_normalized']);

$gcs = king_object_store_510_run_gcs();
var_dump('cloud_gcs');
var_dump($gcs['init']);
var_dump($gcs['begin_quota_class']);
var_dump($gcs['begin_quota_normalized']);

$azure = king_object_store_510_run_azure();
var_dump('cloud_azure');
var_dump($azure['init']);
var_dump($azure['append_throttle_class']);
var_dump($azure['append_throttle_normalized']);
var_dump($azure['complete_quota_class']);
var_dump($azure['complete_quota_normalized']);
?>
--EXPECT--
string(8) "cloud_s3"
bool(true)
string(20) "King\SystemException"
bool(true)
string(20) "King\SystemException"
bool(true)
string(20) "King\SystemException"
bool(true)
string(20) "King\SystemException"
bool(true)
string(9) "cloud_gcs"
bool(true)
string(20) "King\SystemException"
bool(true)
string(11) "cloud_azure"
bool(true)
string(20) "King\SystemException"
bool(true)
string(20) "King\SystemException"
bool(true)
