<?php

require_once __DIR__ . '/object_store_s3_mock_helper.inc';

/* Reusable object-store failover harness for persisted local_fs -> cloud_s3 outage and heal scenarios. */

function king_object_store_failover_harness_cleanup_tree(string $path): void
{
    if ($path === '' || (!file_exists($path) && !is_link($path))) {
        return;
    }

    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }

    $entries = scandir($path);
    if ($entries === false) {
        @rmdir($path);
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        king_object_store_failover_harness_cleanup_tree($path . '/' . $entry);
    }

    @rmdir($path);
}

function king_object_store_failover_harness_wait_until_system_ready(int $maxSeconds = 8): array
{
    for ($i = 0; $i < $maxSeconds; $i++) {
        $status = king_system_get_status();
        if (($status['lifecycle'] ?? null) === 'ready') {
            return $status;
        }

        sleep(1);
    }

    throw new RuntimeException('system runtime did not become ready before object-store failover harness scenario');
}

function king_object_store_failover_harness_wait_until_system_stopped(int $maxSeconds = 8): array
{
    for ($i = 0; $i < $maxSeconds; $i++) {
        $status = king_system_get_status();
        if (($status['initialized'] ?? true) === false) {
            return $status;
        }

        sleep(1);
    }

    throw new RuntimeException('system runtime did not stop after object-store failover harness shutdown request');
}

function king_object_store_failover_harness_create(
    array $options = [],
    string $bindHost = '127.0.0.1'
): array {
    $id = getmypid() . '-' . bin2hex(random_bytes(4));
    $root = sys_get_temp_dir() . '/king-object-store-failover-' . $id;
    $offlineRoot = $root . '.offline';
    $bucket = (string) ($options['bucket'] ?? 'object-store-failover');
    $objectId = (string) ($options['object_id'] ?? 'failover-doc');

    king_object_store_failover_harness_cleanup_tree($root);
    king_object_store_failover_harness_cleanup_tree($offlineRoot);

    if (!mkdir($root, 0700, true) && !is_dir($root)) {
        throw new RuntimeException('failed to create the object-store failover harness root directory');
    }

    $mock = king_object_store_s3_mock_start_server(null, $bindHost, $options['mock_options'] ?? []);

    return [
        'root' => $root,
        'offline_root' => $offlineRoot,
        'bucket' => $bucket,
        'object_id' => $objectId,
        'payload' => null,
        'system_started' => false,
        'mock' => $mock,
        'config' => [
            'storage_root_path' => $root,
            'primary_backend' => 'local_fs',
            'backup_backend' => 'cloud_s3',
            'cloud_credentials' => [
                'api_endpoint' => $mock['endpoint'],
                'bucket' => $bucket,
                'access_key' => 'access',
                'secret_key' => 'secret',
                'region' => 'us-east-1',
                'path_style' => true,
                'verify_tls' => false,
            ],
        ],
        'history' => [],
    ];
}

function king_object_store_failover_harness_capture_phase(
    array &$harness,
    string $phase,
    array $extra = []
): array {
    $objectId = (string) ($harness['object_id'] ?? '');
    $root = (string) ($harness['root'] ?? '');
    $offlineRoot = (string) ($harness['offline_root'] ?? '');
    $payloadPath = $objectId !== '' ? $root . '/' . $objectId : null;
    $metaPath = $objectId !== '' ? $root . '/' . $objectId . '.meta' : null;
    $offlinePayloadPath = $objectId !== '' ? $offlineRoot . '/' . $objectId : null;
    $offlineMetaPath = $objectId !== '' ? $offlineRoot . '/' . $objectId . '.meta' : null;

    clearstatcache();

    $capture = [
        'phase' => $phase,
        'captured_at' => time(),
        'root_exists' => $root !== '' && is_dir($root),
        'offline_root_exists' => $offlineRoot !== '' && is_dir($offlineRoot),
        'payload_present' => $payloadPath !== null && is_file($payloadPath),
        'meta_present' => $metaPath !== null && is_file($metaPath),
        'offline_payload_present' => $offlinePayloadPath !== null && is_file($offlinePayloadPath),
        'offline_meta_present' => $offlineMetaPath !== null && is_file($offlineMetaPath),
        'system_status' => king_system_get_status(),
        'object_store_stats' => king_object_store_get_stats()['object_store'] ?? null,
    ];

    foreach ($extra as $key => $value) {
        $capture[$key] = $value;
    }

    $harness['history'][] = $capture;
    return $capture;
}

function king_object_store_failover_harness_start(
    array &$harness,
    array $systemConfig = [],
    array $objectStoreConfigOverrides = []
): array {
    $systemResult = king_system_init(array_replace([
        'component_timeout_seconds' => 1,
    ], $systemConfig));
    if (!$systemResult) {
        throw new RuntimeException('failed to initialize the coordinated system runtime');
    }

    $harness['system_started'] = true;
    king_object_store_failover_harness_wait_until_system_ready();

    $objectStoreConfig = array_replace($harness['config'] ?? [], $objectStoreConfigOverrides);
    if (!king_object_store_init($objectStoreConfig)) {
        throw new RuntimeException('failed to initialize the object-store runtime for the failover harness');
    }

    return king_object_store_failover_harness_capture_phase($harness, 'initialized', [
        'system_init_result' => $systemResult,
    ]);
}

function king_object_store_failover_harness_prime_object(
    array &$harness,
    string $payload,
    ?string $objectId = null
): array {
    if ($objectId !== null && $objectId !== '') {
        $harness['object_id'] = $objectId;
    }

    $objectId = (string) ($harness['object_id'] ?? '');
    if ($objectId === '') {
        throw new RuntimeException('object-store failover harness requires a non-empty object id');
    }

    $harness['payload'] = $payload;
    $putResult = king_object_store_put($objectId, $payload);
    $metadata = king_object_store_get_metadata($objectId);

    return king_object_store_failover_harness_capture_phase($harness, 'primed', [
        'put_result' => $putResult,
        'metadata' => $metadata,
    ]);
}

function king_object_store_failover_harness_simulate_primary_outage(
    array &$harness
): array {
    $root = (string) ($harness['root'] ?? '');
    $offlineRoot = (string) ($harness['offline_root'] ?? '');
    $objectId = (string) ($harness['object_id'] ?? '');

    if ($root === '' || $offlineRoot === '' || $objectId === '') {
        throw new RuntimeException('object-store failover harness is missing the primary outage paths');
    }

    if (!@rename($root, $offlineRoot)) {
        throw new RuntimeException('failed to move the object-store primary root offline');
    }

    $metadata = king_object_store_get_metadata($objectId);

    return king_object_store_failover_harness_capture_phase($harness, 'primary_outage', [
        'metadata' => $metadata,
    ]);
}

function king_object_store_failover_harness_heal_from_backup(
    array &$harness
): array {
    $objectId = (string) ($harness['object_id'] ?? '');
    if ($objectId === '') {
        throw new RuntimeException('object-store failover harness cannot heal without an object id');
    }

    $payload = king_object_store_get($objectId);
    $metadata = king_object_store_get_metadata($objectId);

    return king_object_store_failover_harness_capture_phase($harness, 'healed', [
        'payload' => $payload,
        'metadata' => $metadata,
    ]);
}

function king_object_store_failover_harness_capture_history(array $harness): array
{
    return is_array($harness['history'] ?? null) ? $harness['history'] : [];
}

function king_object_store_failover_harness_shutdown(array &$harness): array
{
    $shutdownResult = null;
    $systemStatus = king_system_get_status();

    if (($harness['system_started'] ?? false) === true) {
        $shutdownResult = king_system_shutdown();
        $systemStatus = king_object_store_failover_harness_wait_until_system_stopped();
        $harness['system_started'] = false;
    }

    $mockCapture = king_object_store_s3_mock_stop_server($harness['mock']);
    $capture = king_object_store_failover_harness_capture_phase($harness, 'stopped', [
        'system_shutdown_result' => $shutdownResult,
        'system_stopped' => ($systemStatus['initialized'] ?? true) === false,
        'system_status' => $systemStatus,
        'mock_capture' => $mockCapture,
    ]);

    return $capture;
}

function king_object_store_failover_harness_destroy(array &$harness): void
{
    king_object_store_failover_harness_cleanup_tree((string) ($harness['root'] ?? ''));
    king_object_store_failover_harness_cleanup_tree((string) ($harness['offline_root'] ?? ''));

    if (is_array($harness['mock'] ?? null)) {
        king_object_store_s3_mock_cleanup_state_directory(
            (string) ($harness['mock']['state_directory'] ?? '')
        );
    }

    $harness = [];
}
