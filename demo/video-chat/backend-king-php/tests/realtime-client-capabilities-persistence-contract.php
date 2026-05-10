<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management_query.php';
require_once __DIR__ . '/../domain/realtime/realtime_connection_contract.php';
require_once __DIR__ . '/../domain/realtime/realtime_presence.php';
require_once __DIR__ . '/../domain/realtime/realtime_call_presence_db.php';

function videochat_vcap03_fail(string $message): never
{
    fwrite(STDERR, "[realtime-client-capabilities-persistence-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_vcap03_assert(bool $condition, string $message): void
{
    if (!$condition) {
        videochat_vcap03_fail($message);
    }
}

function videochat_vcap03_assert_no_forbidden_data(mixed $value, string $path = '$'): void
{
    if (is_string($value)) {
        videochat_vcap03_assert(
            preg_match('/(?:secret-token|cookie=value|v=0|candidate:|raw-frame|encoded-frame-bytes|private-device-label)/i', $value) !== 1,
            "forbidden value leaked at {$path}"
        );
        return;
    }
    if (!is_array($value)) {
        return;
    }
    foreach ($value as $key => $entry) {
        $keyText = is_string($key) ? $key : (string) $key;
        videochat_vcap03_assert(
            preg_match('/(?:^|_)(?:authorization|token|cookie|credential|secret|sdp|ice|candidate|frame|raw_frame|encoded_frame|protected_frame|device_label|label)(?:$|_)/i', $keyText) !== 1,
            "forbidden key leaked at {$path}.{$keyText}"
        );
        videochat_vcap03_assert_no_forbidden_data($entry, "{$path}.{$keyText}");
    }
}

/**
 * @return array<string, mixed>
 */
function videochat_vcap03_connection(
    string $connectionId,
    string $sessionId,
    string $callId,
    string $roomId,
    int $userId,
    string $displayName
): array {
    return [
        'connection_id' => $connectionId,
        'session_id' => $sessionId,
        'room_id' => $roomId,
        'active_call_id' => $callId,
        'requested_call_id' => $callId,
        'user_id' => $userId,
        'display_name' => $displayName,
        'role' => 'user',
        'call_role' => 'participant',
        'connected_at' => '2026-05-10T10:00:00+00:00',
    ];
}

/**
 * @return array<string, mixed>
 */
function videochat_vcap03_capabilities(string $participantSessionId, bool $camera = true): array
{
    return [
        'schema_version' => 'king.video.client_capabilities.v1',
        'participant_session_id' => $participantSessionId,
        'media' => [
            'camera' => $camera,
            'camera_720p30' => $camera,
            'microphone' => true,
            'screen_share' => false,
        ],
        'runtime' => [
            'websocket' => true,
            'webrtc' => true,
            'webassembly' => true,
            'webcodecs' => false,
            'gpu' => 'available_or_unknown',
            'wlvc_encoder' => true,
            'wlvc_decoder' => true,
        ],
        'constraints' => [
            'video_width' => 1280,
            'video_height' => 720,
            'video_fps' => 30,
        ],
        'authorization' => 'secret-token',
        'cookie' => 'cookie=value',
        'sdp' => "v=0\r\nsecret",
        'ice_candidates' => ['candidate:private-ice'],
        'encoded_frame' => 'encoded-frame-bytes',
        'device_label' => 'private-device-label',
        'received_at' => 'secret-token',
    ];
}

try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $nowMs = videochat_client_capabilities_now_ms();
    $callId = 'call-vcap-03';
    $roomId = 'room-vcap-03';

    $freshA = videochat_vcap03_connection('conn-vcap-a', 'sess-vcap-a', $callId, $roomId, 101, 'VCAP A');
    $freshB = videochat_vcap03_connection('conn-vcap-b', 'sess-vcap-b', $callId, $roomId, 102, 'VCAP B');
    foreach ([$freshA, $freshB] as $connection) {
        videochat_realtime_presence_db_upsert($pdo, $connection, $nowMs);
        videochat_client_capabilities_upsert(
            $pdo,
            $connection,
            videochat_client_capabilities_normalize(
                videochat_vcap03_capabilities('participant-' . $connection['connection_id']),
                $connection
            )
        );
    }

    $stalePresence = videochat_vcap03_connection('conn-vcap-stale', 'sess-vcap-stale', $callId, $roomId, 103, 'Stale VCAP');
    videochat_realtime_presence_db_upsert(
        $pdo,
        $stalePresence,
        $nowMs - videochat_realtime_presence_db_ttl_ms() - 1_000
    );
    videochat_client_capabilities_upsert(
        $pdo,
        $stalePresence,
        videochat_client_capabilities_normalize(videochat_vcap03_capabilities('participant-stale'), $stalePresence)
    );

    $wrongRoom = videochat_vcap03_connection('conn-vcap-wrong-room', 'sess-vcap-wrong-room', $callId, 'room-vcap-other', 104, 'Wrong Room');
    $wrongCall = videochat_vcap03_connection('conn-vcap-wrong-call', 'sess-vcap-wrong-call', 'call-vcap-other', $roomId, 105, 'Wrong Call');
    foreach ([$wrongRoom, $wrongCall] as $connection) {
        videochat_realtime_presence_db_upsert($pdo, $connection, $nowMs);
        videochat_client_capabilities_upsert(
            $pdo,
            $connection,
            videochat_client_capabilities_normalize(
                videochat_vcap03_capabilities('participant-' . $connection['connection_id']),
                $connection
            )
        );
    }

    $sessionMismatch = videochat_vcap03_connection('conn-vcap-session-mismatch', 'sess-vcap-old', $callId, $roomId, 106, 'Session Mismatch');
    videochat_realtime_presence_db_upsert($pdo, $sessionMismatch, $nowMs);
    videochat_client_capabilities_upsert(
        $pdo,
        $sessionMismatch,
        videochat_client_capabilities_normalize(videochat_vcap03_capabilities('participant-session-mismatch'), $sessionMismatch)
    );
    $pdo->prepare('UPDATE realtime_presence_connections SET session_id = :session_id WHERE connection_id = :connection_id')->execute([
        ':session_id' => 'sess-vcap-new',
        ':connection_id' => 'conn-vcap-session-mismatch',
    ]);

    $pendingPresence = videochat_vcap03_connection('conn-vcap-pending-presence', 'sess-vcap-pending', $callId, $roomId, 108, 'Pending Presence');
    videochat_client_capabilities_upsert(
        $pdo,
        $pendingPresence,
        videochat_client_capabilities_normalize(videochat_vcap03_capabilities('participant-pending-presence'), $pendingPresence)
    );
    $pendingRows = (int) $pdo
        ->query("SELECT COUNT(*) FROM realtime_client_capabilities WHERE connection_id = 'conn-vcap-pending-presence'")
        ->fetchColumn();
    videochat_vcap03_assert($pendingRows === 1, 'capability upsert must survive until presence coupling catches up');

    $fetched = videochat_client_capabilities_fetch_room($pdo, $callId, $roomId, $nowMs + 1_000);
    videochat_vcap03_assert(count($fetched) === 2, 'fetch must restore exactly the two fresh room-scoped capabilities');
    videochat_vcap03_assert(isset($fetched['conn-vcap-a'], $fetched['conn-vcap-b']), 'fresh call connections missing from persisted fetch');
    videochat_vcap03_assert(!isset($fetched['conn-vcap-stale']), 'stale presence capability must be ignored');
    videochat_vcap03_assert(!isset($fetched['conn-vcap-wrong-room']), 'wrong-room capability must be isolated');
    videochat_vcap03_assert(!isset($fetched['conn-vcap-wrong-call']), 'wrong-call capability must be isolated');
    videochat_vcap03_assert(!isset($fetched['conn-vcap-session-mismatch']), 'session-mismatched capability must not restore');
    videochat_vcap03_assert(!isset($fetched['conn-vcap-pending-presence']), 'capability without fresh presence must not restore');
    foreach ($fetched as $connectionId => $capabilities) {
        videochat_vcap03_assert(($capabilities['schema_version'] ?? '') === 'king.video.client_capabilities.v1', "{$connectionId} schema mismatch");
        videochat_vcap03_assert((bool) (($capabilities['media'] ?? [])['camera_720p30'] ?? false), "{$connectionId} 720p30 capability missing");
        videochat_vcap03_assert_no_forbidden_data($capabilities, "\$.{$connectionId}");
    }

    $retired = videochat_vcap03_connection('conn-vcap-retired', 'sess-vcap-retired', $callId, $roomId, 107, 'Retired VCAP');
    videochat_realtime_presence_db_upsert($pdo, $retired, $nowMs);
    videochat_client_capabilities_upsert(
        $pdo,
        $retired,
        videochat_client_capabilities_normalize(videochat_vcap03_capabilities('participant-retired'), $retired)
    );
    $retentionCutoff = $nowMs - videochat_client_capabilities_retention_ms() - 1_000;
    $pdo->prepare('UPDATE realtime_presence_connections SET last_seen_at_ms = :last_seen_at_ms WHERE connection_id = :connection_id')->execute([
        ':last_seen_at_ms' => $retentionCutoff,
        ':connection_id' => 'conn-vcap-retired',
    ]);
    $pdo->prepare('UPDATE realtime_client_capabilities SET updated_at_ms = :updated_at_ms WHERE connection_id = :connection_id')->execute([
        ':updated_at_ms' => $retentionCutoff,
        ':connection_id' => 'conn-vcap-retired',
    ]);
    videochat_client_capabilities_prune($pdo, $nowMs);
    $retiredRows = (int) $pdo
        ->query("SELECT COUNT(*) FROM realtime_client_capabilities WHERE connection_id = 'conn-vcap-retired'")
        ->fetchColumn();
    videochat_vcap03_assert($retiredRows === 0, 'retention prune must remove retired capability rows');

    $orphanCutoff = $nowMs - (videochat_client_capabilities_ttl_ms() * 2) - 1_000;
    $pdo->prepare('UPDATE realtime_client_capabilities SET updated_at_ms = :updated_at_ms WHERE connection_id = :connection_id')->execute([
        ':updated_at_ms' => $orphanCutoff,
        ':connection_id' => 'conn-vcap-pending-presence',
    ]);
    videochat_client_capabilities_prune($pdo, $nowMs);
    $pendingRowsAfterPrune = (int) $pdo
        ->query("SELECT COUNT(*) FROM realtime_client_capabilities WHERE connection_id = 'conn-vcap-pending-presence'")
        ->fetchColumn();
    videochat_vcap03_assert($pendingRowsAfterPrune === 0, 'orphaned capability rows must prune after presence TTL grace');

    videochat_realtime_remove_call_presence(static fn (): PDO => $pdo, $freshA);
    $removedRows = (int) $pdo
        ->query("SELECT COUNT(*) FROM realtime_client_capabilities WHERE connection_id = 'conn-vcap-a'")
        ->fetchColumn();
    videochat_vcap03_assert($removedRows === 0, 'presence removal must remove the coupled capability row');

    $callParticipantsTable = (int) $pdo
        ->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'call_participants'")
        ->fetchColumn();
    videochat_vcap03_assert($callParticipantsTable === 0, 'capability restore must not create durable call_participants storage');

    fwrite(STDOUT, "[realtime-client-capabilities-persistence-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[realtime-client-capabilities-persistence-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
