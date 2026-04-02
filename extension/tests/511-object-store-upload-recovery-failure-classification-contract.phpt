--TEST--
King object-store recovered upload sessions preserve normalized quota and throttling failures across real cloud backends
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

function king_object_store_511_cleanup_tree(string $path): void
{
    if ($path === '' || !file_exists($path)) {
        return;
    }

    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            king_object_store_511_cleanup_tree($path . '/' . $entry);
        }
        @rmdir($path);
        return;
    }

    @unlink($path);
}

function king_object_store_511_stream(string $payload)
{
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, $payload);
    rewind($stream);
    return $stream;
}

function king_object_store_511_config(string $backend, array $mock, string $root): array
{
    return match ($backend) {
        'cloud_s3' => [
            'storage_root_path' => $root,
            'primary_backend' => 'cloud_s3',
            'chunk_size_kb' => 1,
            'cloud_credentials' => [
                'api_endpoint' => $mock['endpoint'],
                'bucket' => 'recover-s3',
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
                'bucket' => 'recover-gcs',
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
                'container' => 'recover-azure',
                'access_token' => 'azure-token',
                'verify_tls' => false,
            ],
        ],
        default => throw new InvalidArgumentException('unknown backend ' . $backend),
    };
}

function king_object_store_511_state_path(string $root, string $uploadId): string
{
    return $root . '/.king_upload_sessions/' . $uploadId . '.state';
}

function king_object_store_511_state_value(string $root, string $uploadId, string $key): string
{
    $path = king_object_store_511_state_path($root, $uploadId);
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

function king_object_store_511_rewrite_provider_token(string $root, string $uploadId, string $providerToken): void
{
    $path = king_object_store_511_state_path($root, $uploadId);
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

function king_object_store_511_target_from_provider_token(string $providerToken): string
{
    $path = parse_url($providerToken, PHP_URL_PATH);
    $query = parse_url($providerToken, PHP_URL_QUERY);

    if (is_string($path) && $path !== '') {
        return $query !== null && $query !== ''
            ? $path . '?' . $query
            : $path;
    }

    return $providerToken;
}

function king_object_store_511_is_normalized_public_failure(
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

function king_object_store_511_capture_failure(callable $fn, string $backend, string $kind, int $httpCode): array
{
    try {
        $fn();
        return ['class' => 'none', 'normalized' => false];
    } catch (Throwable $e) {
        return [
            'class' => get_class($e),
            'normalized' => king_object_store_511_is_normalized_public_failure(
                $e->getMessage(),
                $backend,
                $kind,
                $httpCode
            ),
        ];
    }
}

function king_object_store_511_status_matches(
    mixed $status,
    int $uploadedBytes,
    int $uploadedPartCount,
    bool $finalChunkReceived,
    bool $remoteCompleted
): bool {
    return is_array($status)
        && ($status['recovered_after_restart'] ?? false) === true
        && ($status['uploaded_bytes'] ?? null) === $uploadedBytes
        && ($status['uploaded_part_count'] ?? null) === $uploadedPartCount
        && ($status['final_chunk_received'] ?? null) === $finalChunkReceived
        && ($status['remote_completed'] ?? null) === $remoteCompleted
        && ($status['completed'] ?? false) === false
        && ($status['aborted'] ?? false) === false;
}

function king_object_store_511_run_s3(): array
{
    $root = sys_get_temp_dir() . '/king_object_store_upload_recovery_s3_511_' . getmypid();
    $stateDirectory = null;
    $mock = null;

    king_object_store_511_cleanup_tree($root);
    mkdir($root, 0700, true);

    try {
        $mock = king_object_store_s3_mock_start_server();
        $stateDirectory = $mock['state_directory'];
        $result = ['init' => king_object_store_init(king_object_store_511_config('cloud_s3', $mock, $root))];

        $complete = king_object_store_begin_resumable_upload('recover-complete-s3');
        king_object_store_append_resumable_upload_chunk(
            $complete['upload_id'],
            king_object_store_511_stream('omega'),
            ['final' => true]
        );
        $completeToken = king_object_store_511_state_value($root, $complete['upload_id'], 'provider_token');
        king_object_store_s3_mock_stop_server($mock);
        $mock = null;

        $mock = king_object_store_s3_mock_start_server(
            $stateDirectory,
            '127.0.0.1',
            [
                'forced_responses' => [[
                    'method' => 'POST',
                    'target' => '/recover-s3/recover-complete-s3?uploadId=' . $completeToken,
                    'status' => 507,
                    'error_code' => 'InsufficientStorage',
                    'error_message' => 'Bucket storage quota exhausted.',
                ]],
            ]
        );
        king_object_store_init(king_object_store_511_config('cloud_s3', $mock, $root));
        $result['complete_status_recovered'] = king_object_store_511_status_matches(
            king_object_store_get_resumable_upload_status($complete['upload_id']),
            5,
            1,
            true,
            false
        );
        $failure = king_object_store_511_capture_failure(
            static fn() => king_object_store_complete_resumable_upload($complete['upload_id']),
            'cloud_s3',
            'quota',
            507
        );
        $result['complete_quota_class'] = $failure['class'];
        $result['complete_quota_normalized'] = $failure['normalized'];
        $result['complete_status_preserved'] = king_object_store_511_status_matches(
            king_object_store_get_resumable_upload_status($complete['upload_id']),
            5,
            1,
            true,
            false
        );
        king_object_store_s3_mock_stop_server($mock);
        $mock = null;

        $mock = king_object_store_s3_mock_start_server($stateDirectory);
        king_object_store_init(king_object_store_511_config('cloud_s3', $mock, $root));
        $abort = king_object_store_begin_resumable_upload('recover-abort-s3');
        $abortToken = king_object_store_511_state_value($root, $abort['upload_id'], 'provider_token');
        king_object_store_s3_mock_stop_server($mock);
        $mock = null;

        $mock = king_object_store_s3_mock_start_server(
            $stateDirectory,
            '127.0.0.1',
            [
                'forced_responses' => [[
                    'method' => 'DELETE',
                    'target' => '/recover-s3/recover-abort-s3?uploadId=' . $abortToken,
                    'status' => 503,
                    'error_code' => 'SlowDown',
                    'error_message' => 'Abort throttled.',
                ]],
            ]
        );
        king_object_store_init(king_object_store_511_config('cloud_s3', $mock, $root));
        $result['abort_status_recovered'] = king_object_store_511_status_matches(
            king_object_store_get_resumable_upload_status($abort['upload_id']),
            0,
            0,
            false,
            false
        );
        $failure = king_object_store_511_capture_failure(
            static fn() => king_object_store_abort_resumable_upload($abort['upload_id']),
            'cloud_s3',
            'throttle',
            503
        );
        $result['abort_throttle_class'] = $failure['class'];
        $result['abort_throttle_normalized'] = $failure['normalized'];
        $result['abort_status_preserved'] = king_object_store_511_status_matches(
            king_object_store_get_resumable_upload_status($abort['upload_id']),
            0,
            0,
            false,
            false
        );

        return $result;
    } finally {
        if (is_array($mock)) {
            king_object_store_s3_mock_stop_server($mock);
        }
        king_object_store_511_cleanup_tree($root);
        if ($stateDirectory !== null) {
            king_object_store_s3_mock_cleanup_state_directory($stateDirectory);
        }
    }
}

function king_object_store_511_run_gcs(): array
{
    $root = sys_get_temp_dir() . '/king_object_store_upload_recovery_gcs_511_' . getmypid();
    $stateDirectory = null;
    $mock = null;

    king_object_store_511_cleanup_tree($root);
    mkdir($root, 0700, true);

    try {
        $mock = king_object_store_s3_mock_start_server(
            null,
            '127.0.0.1',
            [
                'provider' => 'gcs',
                'expected_access_token' => 'gcs-token',
            ]
        );
        $stateDirectory = $mock['state_directory'];
        $result = ['init' => king_object_store_init(king_object_store_511_config('cloud_gcs', $mock, $root))];

        $append = king_object_store_begin_resumable_upload('recover-append-gcs');
        $appendToken = king_object_store_511_state_value($root, $append['upload_id'], 'provider_token');
        king_object_store_s3_mock_stop_server($mock);
        $mock = null;

        $mock = king_object_store_s3_mock_start_server(
            $stateDirectory,
            '127.0.0.1',
            [
                'provider' => 'gcs',
                'expected_access_token' => 'gcs-token',
                'forced_responses' => [[
                    'method' => 'PUT',
                    'target' => king_object_store_511_target_from_provider_token($appendToken),
                    'status' => 403,
                    'error_code' => 'storageQuotaExceeded',
                    'error_message' => 'Project storage quota exhausted.',
                ]],
            ]
        );
        king_object_store_511_rewrite_provider_token(
            $root,
            $append['upload_id'],
            $mock['endpoint'] . king_object_store_511_target_from_provider_token($appendToken)
        );
        king_object_store_init(king_object_store_511_config('cloud_gcs', $mock, $root));
        $result['append_status_recovered'] = king_object_store_511_status_matches(
            king_object_store_get_resumable_upload_status($append['upload_id']),
            0,
            0,
            false,
            false
        );
        $failure = king_object_store_511_capture_failure(
            static fn() => king_object_store_append_resumable_upload_chunk(
                $append['upload_id'],
                king_object_store_511_stream('alpha')
            ),
            'cloud_gcs',
            'quota',
            403
        );
        $result['append_quota_class'] = $failure['class'];
        $result['append_quota_normalized'] = $failure['normalized'];
        $result['append_status_preserved'] = king_object_store_511_status_matches(
            king_object_store_get_resumable_upload_status($append['upload_id']),
            0,
            0,
            false,
            false
        );
        king_object_store_s3_mock_stop_server($mock);
        $mock = null;

        $mock = king_object_store_s3_mock_start_server(
            $stateDirectory,
            '127.0.0.1',
            [
                'provider' => 'gcs',
                'expected_access_token' => 'gcs-token',
            ]
        );
        king_object_store_init(king_object_store_511_config('cloud_gcs', $mock, $root));
        $abort = king_object_store_begin_resumable_upload('recover-abort-gcs');
        king_object_store_append_resumable_upload_chunk(
            $abort['upload_id'],
            king_object_store_511_stream('chunk')
        );
        $abortToken = king_object_store_511_state_value($root, $abort['upload_id'], 'provider_token');
        king_object_store_s3_mock_stop_server($mock);
        $mock = null;

        $mock = king_object_store_s3_mock_start_server(
            $stateDirectory,
            '127.0.0.1',
            [
                'provider' => 'gcs',
                'expected_access_token' => 'gcs-token',
                'forced_responses' => [[
                    'method' => 'DELETE',
                    'target' => king_object_store_511_target_from_provider_token($abortToken),
                    'status' => 503,
                    'error_code' => 'TooManyRequests',
                    'error_message' => 'Abort throttled.',
                ]],
            ]
        );
        king_object_store_511_rewrite_provider_token(
            $root,
            $abort['upload_id'],
            $mock['endpoint'] . king_object_store_511_target_from_provider_token($abortToken)
        );
        king_object_store_init(king_object_store_511_config('cloud_gcs', $mock, $root));
        $result['abort_status_recovered'] = king_object_store_511_status_matches(
            king_object_store_get_resumable_upload_status($abort['upload_id']),
            5,
            1,
            false,
            false
        );
        $failure = king_object_store_511_capture_failure(
            static fn() => king_object_store_abort_resumable_upload($abort['upload_id']),
            'cloud_gcs',
            'throttle',
            503
        );
        $result['abort_throttle_class'] = $failure['class'];
        $result['abort_throttle_normalized'] = $failure['normalized'];
        $result['abort_status_preserved'] = king_object_store_511_status_matches(
            king_object_store_get_resumable_upload_status($abort['upload_id']),
            5,
            1,
            false,
            false
        );

        return $result;
    } finally {
        if (is_array($mock)) {
            king_object_store_s3_mock_stop_server($mock);
        }
        king_object_store_511_cleanup_tree($root);
        if ($stateDirectory !== null) {
            king_object_store_s3_mock_cleanup_state_directory($stateDirectory);
        }
    }
}

function king_object_store_511_run_azure(): array
{
    $root = sys_get_temp_dir() . '/king_object_store_upload_recovery_azure_511_' . getmypid();
    $stateDirectory = null;
    $mock = null;
    $firstBlockId = rawurlencode(base64_encode('000001'));

    king_object_store_511_cleanup_tree($root);
    mkdir($root, 0700, true);

    try {
        $mock = king_object_store_s3_mock_start_server(
            null,
            '127.0.0.1',
            [
                'provider' => 'azure',
                'expected_access_token' => 'azure-token',
            ]
        );
        $stateDirectory = $mock['state_directory'];
        $result = ['init' => king_object_store_init(king_object_store_511_config('cloud_azure', $mock, $root))];

        $append = king_object_store_begin_resumable_upload('recover-append-azure');
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
                    'target' => '/recover-azure/recover-append-azure?comp=block&blockid=' . $firstBlockId,
                    'status' => 503,
                    'error_code' => 'ServerBusy',
                    'error_message' => 'Server busy.',
                ]],
            ]
        );
        king_object_store_init(king_object_store_511_config('cloud_azure', $mock, $root));
        $result['append_status_recovered'] = king_object_store_511_status_matches(
            king_object_store_get_resumable_upload_status($append['upload_id']),
            0,
            0,
            false,
            false
        );
        $failure = king_object_store_511_capture_failure(
            static fn() => king_object_store_append_resumable_upload_chunk(
                $append['upload_id'],
                king_object_store_511_stream('alpha')
            ),
            'cloud_azure',
            'throttle',
            503
        );
        $result['append_throttle_class'] = $failure['class'];
        $result['append_throttle_normalized'] = $failure['normalized'];
        $result['append_status_preserved'] = king_object_store_511_status_matches(
            king_object_store_get_resumable_upload_status($append['upload_id']),
            0,
            0,
            false,
            false
        );
        king_object_store_s3_mock_stop_server($mock);
        $mock = null;

        $mock = king_object_store_s3_mock_start_server(
            $stateDirectory,
            '127.0.0.1',
            [
                'provider' => 'azure',
                'expected_access_token' => 'azure-token',
            ]
        );
        king_object_store_init(king_object_store_511_config('cloud_azure', $mock, $root));
        $complete = king_object_store_begin_resumable_upload('recover-complete-azure');
        king_object_store_append_resumable_upload_chunk(
            $complete['upload_id'],
            king_object_store_511_stream('omega'),
            ['final' => true]
        );
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
                    'target' => '/recover-azure/recover-complete-azure?comp=blocklist',
                    'status' => 507,
                    'error_code' => 'InsufficientAccountResources',
                    'error_message' => 'Account storage quota exhausted.',
                ]],
            ]
        );
        king_object_store_init(king_object_store_511_config('cloud_azure', $mock, $root));
        $result['complete_status_recovered'] = king_object_store_511_status_matches(
            king_object_store_get_resumable_upload_status($complete['upload_id']),
            5,
            1,
            true,
            false
        );
        $failure = king_object_store_511_capture_failure(
            static fn() => king_object_store_complete_resumable_upload($complete['upload_id']),
            'cloud_azure',
            'quota',
            507
        );
        $result['complete_quota_class'] = $failure['class'];
        $result['complete_quota_normalized'] = $failure['normalized'];
        $result['complete_status_preserved'] = king_object_store_511_status_matches(
            king_object_store_get_resumable_upload_status($complete['upload_id']),
            5,
            1,
            true,
            false
        );

        return $result;
    } finally {
        if (is_array($mock)) {
            king_object_store_s3_mock_stop_server($mock);
        }
        king_object_store_511_cleanup_tree($root);
        if ($stateDirectory !== null) {
            king_object_store_s3_mock_cleanup_state_directory($stateDirectory);
        }
    }
}

$s3 = king_object_store_511_run_s3();
var_dump('cloud_s3');
var_dump($s3['init']);
var_dump($s3['complete_status_recovered']);
var_dump($s3['complete_quota_class']);
var_dump($s3['complete_quota_normalized']);
var_dump($s3['complete_status_preserved']);
var_dump($s3['abort_status_recovered']);
var_dump($s3['abort_throttle_class']);
var_dump($s3['abort_throttle_normalized']);
var_dump($s3['abort_status_preserved']);

$gcs = king_object_store_511_run_gcs();
var_dump('cloud_gcs');
var_dump($gcs['init']);
var_dump($gcs['append_status_recovered']);
var_dump($gcs['append_quota_class']);
var_dump($gcs['append_quota_normalized']);
var_dump($gcs['append_status_preserved']);
var_dump($gcs['abort_status_recovered']);
var_dump($gcs['abort_throttle_class']);
var_dump($gcs['abort_throttle_normalized']);
var_dump($gcs['abort_status_preserved']);

$azure = king_object_store_511_run_azure();
var_dump('cloud_azure');
var_dump($azure['init']);
var_dump($azure['append_status_recovered']);
var_dump($azure['append_throttle_class']);
var_dump($azure['append_throttle_normalized']);
var_dump($azure['append_status_preserved']);
var_dump($azure['complete_status_recovered']);
var_dump($azure['complete_quota_class']);
var_dump($azure['complete_quota_normalized']);
var_dump($azure['complete_status_preserved']);
?>
--EXPECT--
string(8) "cloud_s3"
bool(true)
bool(true)
string(20) "King\SystemException"
bool(true)
bool(true)
bool(true)
string(20) "King\SystemException"
bool(true)
bool(true)
string(9) "cloud_gcs"
bool(true)
bool(true)
string(20) "King\SystemException"
bool(true)
bool(true)
bool(true)
string(20) "King\SystemException"
bool(true)
bool(true)
string(11) "cloud_azure"
bool(true)
bool(true)
string(20) "King\SystemException"
bool(true)
bool(true)
bool(true)
string(20) "King\SystemException"
bool(true)
bool(true)
