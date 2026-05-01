<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/realtime/chat_attachments.php';
require_once __DIR__ . '/../domain/realtime/chat_archive.php';
require_once __DIR__ . '/../domain/realtime/realtime_activity_layout.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../domain/realtime/realtime_connection_contract.php';
require_once __DIR__ . '/../domain/realtime/realtime_call_presence_db.php';
require_once __DIR__ . '/../domain/realtime/realtime_call_context.php';
require_once __DIR__ . '/../domain/realtime/realtime_asset_version.php';
require_once __DIR__ . '/../domain/realtime/realtime_lobby_sync.php';
require_once __DIR__ . '/../domain/realtime/realtime_room_snapshot.php';
require_once __DIR__ . '/../domain/realtime/realtime_gossipmesh.php';
require_once __DIR__ . '/../domain/realtime/realtime_sfu_iibin.php';
require_once __DIR__ . '/../domain/realtime/realtime_sfu_store.php';
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
    callable $openDatabase
): ?array {
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
