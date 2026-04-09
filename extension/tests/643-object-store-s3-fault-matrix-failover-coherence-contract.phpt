--TEST--
King object-store cloud_s3 fault matrix preserves multi-backend replica and failover coherence after recovery
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

function king_object_store_643_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function king_object_store_643_cleanup_tree(string $path): void
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
                king_object_store_643_cleanup_tree($path . '/' . $entry);
            }
        }
        @rmdir($path);
        return;
    }

    @unlink($path);
}

$cases = [
    [
        'name' => 'credentials',
        'bucket' => 'fault-matrix-credentials',
        'object_id' => 'doc-credentials',
        'initial_payload' => 'alpha-credentials',
        'recovered_payload' => 'bravo-credentials',
        'mock_options' => [
            'expected_access_key' => 'expected-access',
        ],
        'credentials' => [
            'access_key' => 'wrong-access',
            'secret_key' => 'secret',
            'region' => 'us-east-1',
            'path_style' => true,
            'verify_tls' => false,
        ],
        'recovery_credentials' => [
            'access_key' => 'expected-access',
            'secret_key' => 'secret',
            'region' => 'us-east-1',
            'path_style' => true,
            'verify_tls' => false,
        ],
        'error_contains' => 'credentials were rejected',
        'fault_evidence' => 'auth_rejected',
    ],
    [
        'name' => 'throttle',
        'bucket' => 'fault-matrix-throttle',
        'object_id' => 'doc-throttle',
        'initial_payload' => 'alpha-throttle',
        'recovered_payload' => 'bravo-throttle',
        'mock_options' => [
            'forced_responses' => [
                [
                    'method' => 'PUT',
                    'target' => '/fault-matrix-throttle/doc-throttle',
                    'times' => 1,
                    'status' => 503,
                    'error_code' => 'SlowDown',
                    'error_message' => 'Reduce your request rate.',
                ],
            ],
        ],
        'credentials' => [
            'access_key' => 'access',
            'secret_key' => 'secret',
            'region' => 'us-east-1',
            'path_style' => true,
            'verify_tls' => false,
        ],
        'error_contains' => 'throttled',
        'fault_evidence' => 'forced_error_code',
        'fault_evidence_value' => 'SlowDown',
    ],
    [
        'name' => 'network',
        'bucket' => 'fault-matrix-network',
        'object_id' => 'doc-network',
        'initial_payload' => 'alpha-network',
        'recovered_payload' => 'bravo-network',
        'mock_options' => [
            'forced_responses' => [
                [
                    'method' => 'PUT',
                    'target' => '/fault-matrix-network/doc-network',
                    'times' => 1,
                    'close_connection_without_response' => true,
                ],
            ],
        ],
        'credentials' => [
            'access_key' => 'access',
            'secret_key' => 'secret',
            'region' => 'us-east-1',
            'path_style' => true,
            'verify_tls' => false,
        ],
        'error_contains' => 'network I/O with the configured endpoint failed',
        'fault_evidence' => 'forced_disconnect',
    ],
];

foreach ($cases as $case) {
    $name = $case['name'];
    $bucket = $case['bucket'];
    $objectId = $case['object_id'];
    $initialPayload = $case['initial_payload'];
    $recoveredPayload = $case['recovered_payload'];

    $root = sys_get_temp_dir() . '/king_object_store_643_' . $name . '_' . getmypid();
    $offlineRoot = $root . '.offline';
    $payloadPath = $root . '/' . $objectId;
    $metaPath = $root . '/' . $objectId . '.meta';

    king_object_store_643_cleanup_tree($root);
    king_object_store_643_cleanup_tree($offlineRoot);
    @mkdir($root, 0700, true);

    $mock = null;
    $stateDirectory = null;
    try {
        $mock = king_object_store_s3_mock_start_server(
            null,
            '127.0.0.1',
            $case['mock_options'] ?? []
        );
        $stateDirectory = $mock['state_directory'] ?? null;

        $config = [
            'storage_root_path' => $root,
            'primary_backend' => 'local_fs',
            'backup_backend' => 'cloud_s3',
            'cloud_credentials' => array_merge(
                [
                    'api_endpoint' => $mock['endpoint'],
                    'bucket' => $bucket,
                ],
                $case['credentials']
            ),
        ];

        king_object_store_643_assert(
            king_object_store_init($config) === true,
            $name . ': object-store init failed.'
        );

        $failedPut = false;
        try {
            king_object_store_put($objectId, $initialPayload);
        } catch (Throwable $e) {
            $failedPut = true;
            king_object_store_643_assert(
                str_contains($e->getMessage(), $case['error_contains']),
                $name . ': failure message did not include expected fault marker.'
            );
        }
        king_object_store_643_assert($failedPut, $name . ': expected first cloud-backed put to fail.');

        king_object_store_643_assert(
            king_object_store_get($objectId) === $initialPayload,
            $name . ': primary local payload did not survive cloud fault.'
        );
        $metaAfterFault = king_object_store_get_metadata($objectId);
        king_object_store_643_assert(
            (int) ($metaAfterFault['is_backed_up'] ?? 1) === 0,
            $name . ': metadata unexpectedly marked object as backed up during fault.'
        );

        $statsAfterFault = king_object_store_get_stats()['object_store'] ?? [];
        king_object_store_643_assert(
            ($statsAfterFault['runtime_backup_adapter_status'] ?? '') === 'failed',
            $name . ': backup adapter status was not failed after injected cloud fault.'
        );
        king_object_store_643_assert(
            str_contains((string) ($statsAfterFault['runtime_backup_adapter_error'] ?? ''), $case['error_contains']),
            $name . ': backup adapter error did not include expected fault marker.'
        );

        $recoveryCredentials = $case['recovery_credentials'] ?? $case['credentials'];
        $recoveryConfig = $config;
        $recoveryConfig['cloud_credentials'] = array_merge(
            [
                'api_endpoint' => $mock['endpoint'],
                'bucket' => $bucket,
            ],
            $recoveryCredentials
        );

        king_object_store_643_assert(
            king_object_store_init($recoveryConfig) === true,
            $name . ': re-init after fault failed.'
        );
        king_object_store_643_assert(
            king_object_store_put($objectId, $recoveredPayload) === true,
            $name . ': recovery write did not succeed.'
        );

        $metaAfterRecovery = king_object_store_get_metadata($objectId);
        king_object_store_643_assert(
            (int) ($metaAfterRecovery['is_backed_up'] ?? 0) === 1,
            $name . ': metadata was not healed to backed_up after recovery write.'
        );
        king_object_store_643_assert(
            (int) ($metaAfterRecovery['content_length'] ?? -1) === strlen($recoveredPayload),
            $name . ': recovered metadata content_length mismatch.'
        );

        $statsAfterRecovery = king_object_store_get_stats()['object_store'] ?? [];
        king_object_store_643_assert(
            ($statsAfterRecovery['runtime_primary_adapter_status'] ?? '') === 'ok',
            $name . ': primary adapter did not recover to ok.'
        );
        king_object_store_643_assert(
            ($statsAfterRecovery['runtime_backup_adapter_status'] ?? '') === 'ok',
            $name . ': backup adapter did not recover to ok.'
        );
        king_object_store_643_assert(
            (int) ($statsAfterRecovery['object_count'] ?? -1) === 1,
            $name . ': object_count mismatch after recovery.'
        );

        king_object_store_643_assert(@rename($root, $offlineRoot), $name . ': failed to move primary root offline.');
        clearstatcache();
        king_object_store_643_assert(!is_dir($root), $name . ': root unexpectedly still present after outage simulation.');
        king_object_store_643_assert(
            king_object_store_get($objectId) === $recoveredPayload,
            $name . ': failover read did not recover payload from cloud backup.'
        );
        clearstatcache();
        king_object_store_643_assert(is_dir($root), $name . ': root was not recreated by failover heal.');
        king_object_store_643_assert(is_file($payloadPath), $name . ': local payload file was not healed after failover read.');
        king_object_store_643_assert(is_file($metaPath), $name . ': local metadata file was not healed after failover read.');

        $statsAfterFailover = king_object_store_get_stats()['object_store'] ?? [];
        king_object_store_643_assert(
            ($statsAfterFailover['runtime_primary_adapter_status'] ?? '') === 'ok',
            $name . ': primary adapter was not ok after failover heal.'
        );
        king_object_store_643_assert(
            ($statsAfterFailover['runtime_backup_adapter_status'] ?? '') === 'ok',
            $name . ': backup adapter was not ok after failover heal.'
        );

        $capture = king_object_store_s3_mock_stop_server($mock);
        $mock = null;
        $events = $capture['events'] ?? [];
        $targets = array_map(
            static fn(array $event): string => $event['method'] . ' ' . $event['target'],
            $events
        );
        king_object_store_643_assert(
            in_array('PUT /' . $bucket . '/' . $objectId, $targets, true),
            $name . ': mock capture did not observe backup write traffic.'
        );
        king_object_store_643_assert(
            in_array('GET /' . $bucket . '/' . $objectId, $targets, true),
            $name . ': mock capture did not observe failover recovery read traffic.'
        );

        $faultEvidence = (string) ($case['fault_evidence'] ?? '');
        if ($faultEvidence === 'auth_rejected') {
            king_object_store_643_assert(
                count(array_filter(
                    $events,
                    static fn(array $event): bool => !empty($event['auth_rejected'])
                )) >= 1,
                $name . ': mock capture did not observe credential rejection evidence.'
            );
        } elseif ($faultEvidence === 'forced_error_code') {
            $expectedCode = (string) ($case['fault_evidence_value'] ?? '');
            king_object_store_643_assert(
                count(array_filter(
                    $events,
                    static fn(array $event): bool => ($event['forced_error_code'] ?? '') === $expectedCode
                )) >= 1,
                $name . ': mock capture did not observe forced throttling evidence.'
            );
        } elseif ($faultEvidence === 'forced_disconnect') {
            king_object_store_643_assert(
                count(array_filter(
                    $events,
                    static fn(array $event): bool => !empty($event['forced_disconnect'])
                )) >= 1,
                $name . ': mock capture did not observe forced disconnect evidence.'
            );
        }
    } finally {
        if ($mock !== null) {
            king_object_store_s3_mock_stop_server($mock);
        }

        king_object_store_643_cleanup_tree($root);
        king_object_store_643_cleanup_tree($offlineRoot);
        if (is_string($stateDirectory) && $stateDirectory !== '') {
            king_object_store_s3_mock_cleanup_state_directory($stateDirectory);
        }
    }
}

echo "OK\n";
?>
--EXPECT--
OK
