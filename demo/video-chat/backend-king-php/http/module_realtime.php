<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/realtime/chat_attachments.php';
require_once __DIR__ . '/../domain/realtime/chat_archive.php';
require_once __DIR__ . '/../domain/realtime/realtime_activity_layout.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../support/database_core.php';
require_once __DIR__ . '/../domain/realtime/realtime_connection_contract.php';
require_once __DIR__ . '/../domain/realtime/realtime_call_presence_db.php';
require_once __DIR__ . '/../domain/realtime/realtime_call_context.php';
require_once __DIR__ . '/../domain/realtime/realtime_asset_version.php';
require_once __DIR__ . '/../domain/realtime/realtime_sputnik_dev.php';
require_once __DIR__ . '/../domain/realtime/realtime_lobby_sync.php';
require_once __DIR__ . '/../domain/realtime/realtime_room_snapshot.php';
require_once __DIR__ . '/../domain/realtime/realtime_gossipmesh.php';
require_once __DIR__ . '/../domain/realtime/realtime_sfu_iibin.php';
require_once __DIR__ . '/../domain/realtime/realtime_sfu_store.php';
require_once __DIR__ . '/../domain/realtime/realtime_sfu_session_protocol.php';
require_once __DIR__ . '/../domain/realtime/realtime_sfu_gateway.php';
require_once __DIR__ . '/module_realtime_attachments.php';
require_once __DIR__ . '/module_realtime_websocket_commands.php';
require_once __DIR__ . '/module_realtime_websocket_brokers.php';
require_once __DIR__ . '/module_realtime_websocket.php';

/**
 * @return array{
 *   call_id: string,
 *   room_id: string,
 *   user_id: int,
 *   call_role: string
 * }
 */
function videochat_realtime_content_disposition_filename(string $filename): string
{
    $safe = videochat_chat_attachment_safe_filename($filename, 'txt');
    $ascii = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $safe) ?? 'attachment.txt';
    $ascii = trim($ascii, " .\t\n\r\0\x0B");
    if ($ascii === '') {
        $ascii = 'attachment.txt';
    }

    return 'attachment; filename="' . addcslashes($ascii, "\\\"") . '"; filename*=UTF-8\'\'' . rawurlencode($safe);
}

function videochat_realtime_chat_attachment_error_status(string $code, string $reason): int
{
    if ($reason === 'forbidden') {
        return 403;
    }
    if ($reason === 'quota_exceeded' || $code === 'attachment_too_large') {
        return 413;
    }
    if ($reason === 'validation_failed') {
        return 422;
    }
    if ($reason === 'not_found') {
        return 404;
    }

    return 500;
}

function videochat_handle_realtime_routes(
    string $path,
    array $request,
    string $wsPath,
    array $apiAuthContext,
    array &$activeWebsocketsBySession,
    array &$presenceState,
    array &$lobbyState,
    array &$typingState,
    array &$reactionState,
    callable $authenticateRequest,
    callable $authFailureResponse,
    callable $rbacFailureResponse,
    callable $jsonResponse,
    callable $errorResponse,
    callable $openDatabase,
    callable $issueSessionId
): ?array {
    if ($path === '/api/realtime/sputnik-dev/sessions') {
        $method = videochat_realtime_method_from_request($request);
        if ($method !== 'POST') {
            return $errorResponse(405, 'method_not_allowed', 'Use POST for /api/realtime/sputnik-dev/sessions.', [
                'allowed_methods' => ['POST'],
            ]);
        }

        [$payload, $decodeError] = videochat_realtime_decode_json_request_body($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'sputnik_dev_invalid_request_body', 'Sputnik dev peer payload must be a JSON object.', [
                'reason' => $decodeError,
            ]);
        }

        $lastPrepareLockError = null;
        for ($attempt = 1; $attempt <= 8; $attempt += 1) {
            try {
                $pdo = $openDatabase();
                $result = videochat_realtime_prepare_sputnik_dev_peer_sessions(
                    $pdo,
                    $apiAuthContext,
                    $payload,
                    $request,
                    $issueSessionId
                );
                $lastPrepareLockError = null;
                break;
            } catch (Throwable $error) {
                if (function_exists('videochat_sqlite_is_transient_lock') && videochat_sqlite_is_transient_lock($error)) {
                    $lastPrepareLockError = $error;
                    if ($attempt < 8) {
                        usleep(videochat_sqlite_retry_delay_us($attempt, 80_000, 450_000));
                        continue;
                    }
                }
                error_log('[video-chat][sputnik-dev] prepare failed: ' . $error::class . ': ' . $error->getMessage());
                return $errorResponse(500, 'sputnik_dev_prepare_failed', 'Could not prepare Sputnik dev peers.', [
                    'reason' => 'internal_error',
                ]);
            }
        }
        if ($lastPrepareLockError instanceof Throwable) {
            error_log('[video-chat][sputnik-dev] prepare sqlite lock exhausted: ' . $lastPrepareLockError->getMessage());
            return $errorResponse(503, 'sputnik_dev_prepare_busy', 'Sputnik dev peer preparation is temporarily busy; retry shortly.', [
                'reason' => 'sqlite_busy',
            ]);
        }

        if (!(bool) ($result['ok'] ?? false)) {
            $reason = (string) ($result['reason'] ?? 'unknown');
            $status = $reason === 'forbidden' ? 403 : ($reason === 'call_not_found' ? 404 : 422);
            return $errorResponse($status, 'sputnik_dev_prepare_failed', 'Could not prepare Sputnik dev peers.', [
                'reason' => $reason,
                'errors' => is_array($result['errors'] ?? null) ? $result['errors'] : [],
            ]);
        }

        return $jsonResponse(201, [
            'status' => 'ok',
            'peers' => is_array($result['peers'] ?? null) ? $result['peers'] : [],
            'time' => gmdate('c'),
        ]);
    }

    $attachmentResponse = videochat_handle_realtime_attachment_routes(
        $path,
        $request,
        $authenticateRequest,
        $authFailureResponse,
        $jsonResponse,
        $errorResponse,
        $openDatabase
    );
    if ($attachmentResponse !== null) {
        return $attachmentResponse;
    }

    if ($path === '/sfu') {
        return videochat_handle_sfu_routes(
            $path,
            $request,
            $presenceState,
            $authenticateRequest,
            $authFailureResponse,
            $rbacFailureResponse,
            $errorResponse,
            $openDatabase
        );
    }

    return videochat_handle_realtime_websocket_route(
        $path,
        $request,
        $wsPath,
        $activeWebsocketsBySession,
        $presenceState,
        $lobbyState,
        $typingState,
        $reactionState,
        $authenticateRequest,
        $authFailureResponse,
        $rbacFailureResponse,
        $errorResponse,
        $openDatabase
    );
}
