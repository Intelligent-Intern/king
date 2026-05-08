<?php

declare(strict_types=1);

function videochat_realtime_reset_waiting_connection_invite(
    callable $openDatabase,
    array &$lobbyState,
    array $presenceState,
    array $connection,
    string $reason,
    bool $broadcastSnapshot
): bool {
    $currentRoomId = videochat_presence_normalize_room_id((string) ($connection['room_id'] ?? ''), '');
    $pendingRoomId = videochat_presence_normalize_room_id((string) ($connection['pending_room_id'] ?? ''), '');
    if ($currentRoomId !== videochat_realtime_waiting_room_id() || $pendingRoomId === '') {
        return false;
    }

    $updated = videochat_realtime_mark_call_participant_invite_state(
        $openDatabase,
        $connection,
        'invited',
        ['pending']
    );
    if (!$updated) {
        return false;
    }

    videochat_realtime_sync_lobby_room_from_database(
        $lobbyState,
        $openDatabase,
        $pendingRoomId,
        videochat_realtime_connection_call_id($connection),
        null,
        videochat_realtime_connection_tenant_id($connection)
    );
    if ($broadcastSnapshot) {
        videochat_lobby_broadcast_room_snapshot(
            $lobbyState,
            $presenceState,
            $pendingRoomId,
            trim($reason) === '' ? 'presence_left' : trim($reason),
            null,
            null,
            is_numeric($connection['tenant_id'] ?? null) ? (int) $connection['tenant_id'] : null
        );
    }

    return true;
}

/**
 * @return array<string, mixed>
 */
function videochat_realtime_authenticate_websocket_request(array $request, callable $authenticateRequest): array
{
    try {
        $auth = $authenticateRequest($request, 'websocket');
        return is_array($auth)
            ? $auth
            : ['ok' => false, 'reason' => 'auth_backend_error', 'retryable' => true];
    } catch (Throwable) {
        return ['ok' => false, 'reason' => 'auth_backend_error', 'retryable' => true];
    }
}

function videochat_realtime_websocket_retryable_error_response(
    callable $errorResponse,
    int $status,
    string $code,
    string $message,
    string $reason
): array {
    return $errorResponse($status, $code, $message, [
        'reason' => $reason,
        'retryable' => true,
    ]);
}

function videochat_realtime_websocket_auth_retry_response(array $websocketAuth, callable $errorResponse): ?array
{
    $reason = (string) ($websocketAuth['reason'] ?? 'invalid_session');
    if (!videochat_realtime_is_transient_auth_backend_reason($reason)) {
        return null;
    }

    return videochat_realtime_websocket_retryable_error_response(
        $errorResponse,
        503,
        'websocket_auth_temporarily_unavailable',
        'Session validation is temporarily unavailable for realtime reconnect.',
        $reason
    );
}

function videochat_realtime_websocket_backfill_retry_response(array $roomResolution, callable $errorResponse): ?array
{
    if ((bool) ($roomResolution['ok'] ?? true) !== false) {
        return null;
    }

    return videochat_realtime_websocket_retryable_error_response(
        $errorResponse,
        503,
        'websocket_reconnect_backfill_unavailable',
        'Realtime reconnect backfill is temporarily unavailable.',
        (string) ($roomResolution['reason'] ?? 'realtime_backfill_unavailable')
    );
}

function videochat_realtime_websocket_disconnect_stale_asset_client(mixed $websocket, string $clientAssetVersion): bool
{
    return videochat_realtime_disconnect_stale_asset_client(
        $websocket,
        $clientAssetVersion,
        static function (array $frame) use ($websocket): void {
            videochat_presence_send_frame($websocket, $frame);
        },
        'ws'
    );
}

function videochat_realtime_handle_session_liveness_failure(
    mixed $websocket,
    array $sessionLiveness,
    int &$transientFailureCount,
    int &$transientStartedAtMs,
    int $transientGraceMs
): string {
    $reason = (string) ($sessionLiveness['reason'] ?? 'invalid_session');
    $isTransient = videochat_realtime_is_transient_auth_backend_reason($reason);
    $nowMs = videochat_lobby_now_ms();
    if ($isTransient) {
        if ($transientStartedAtMs <= 0) {
            $transientStartedAtMs = $nowMs;
        }
        $transientFailureCount++;
    }

    $policy = videochat_realtime_session_liveness_failure_policy(
        $reason,
        $transientFailureCount,
        $isTransient ? ($nowMs - $transientStartedAtMs) : 0,
        $transientGraceMs
    );
    $closeDescriptor = $policy['close_descriptor'];
    videochat_presence_send_frame($websocket, [
        'type' => 'system/error',
        'code' => $isTransient ? 'websocket_auth_temporarily_unavailable' : 'websocket_session_invalidated',
        'message' => $isTransient
            ? 'Session validation is temporarily unavailable for realtime commands.'
            : 'Session is no longer valid for realtime commands.',
        'details' => [
            'reason' => $reason,
            'retryable' => (bool) ($policy['retryable'] ?? false),
            'transient_failures' => $isTransient ? $transientFailureCount : 0,
            'close' => $closeDescriptor,
        ],
        'time' => gmdate('c'),
    ]);

    if (!(bool) ($policy['close'] ?? true)) {
        usleep(250_000);
        return 'continue';
    }

    try {
        king_client_websocket_close(
            $websocket,
            (int) ($closeDescriptor['close_code'] ?? 1008),
            (string) ($closeDescriptor['close_reason'] ?? 'session_invalidated')
        );
    } catch (Throwable) {
        // Best-effort close; detach/cleanup runs in the gateway finally block.
    }

    return 'break';
}
