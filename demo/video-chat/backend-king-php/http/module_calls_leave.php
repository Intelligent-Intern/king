<?php

declare(strict_types=1);

function videochat_handle_call_leave_routes(
    string $path,
    string $method,
    array $request,
    array $apiAuthContext,
    callable $jsonResponse,
    callable $errorResponse,
    callable $openDatabase
): ?array {
    unset($request);

    if (preg_match('#^/api/calls/([A-Za-z0-9._-]{1,200})/leave$#', $path, $callLeaveMatch) !== 1) {
        return null;
    }

    if ($method !== 'POST') {
        return $errorResponse(405, 'method_not_allowed', 'Use POST for /api/calls/{id}/leave.', [
            'allowed_methods' => ['POST'],
        ]);
    }

    $authenticatedUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
    $authenticatedUserRole = (string) (($apiAuthContext['user']['role'] ?? 'user'));
    if ($authenticatedUserId <= 0) {
        return $errorResponse(401, 'auth_failed', 'A valid session token is required.', [
            'reason' => 'invalid_user_context',
        ]);
    }

    $callId = (string) ($callLeaveMatch[1] ?? '');
    try {
        $pdo = $openDatabase();
        $leaveResult = videochat_leave_call(
            $pdo,
            $callId,
            $authenticatedUserId,
            $authenticatedUserRole,
            videochat_tenant_id_from_auth_context($apiAuthContext)
        );
    } catch (Throwable) {
        return $errorResponse(500, 'calls_leave_failed', 'Could not leave call.', [
            'reason' => 'internal_error',
        ]);
    }

    $leaveReason = (string) ($leaveResult['reason'] ?? 'internal_error');
    if (!(bool) ($leaveResult['ok'] ?? false)) {
        if ($leaveReason === 'validation_failed') {
            return $errorResponse(409, 'calls_leave_state_conflict', 'Call cannot be left from its current state.', [
                'fields' => is_array($leaveResult['errors'] ?? null) ? $leaveResult['errors'] : [],
                'call_id' => $callId,
            ]);
        }
        if ($leaveReason === 'not_found') {
            return $errorResponse(404, 'calls_not_found', 'The requested call does not exist.', [
                'call_id' => $callId,
            ]);
        }
        if ($leaveReason === 'forbidden') {
            return $errorResponse(403, 'calls_forbidden', 'You are not allowed to leave this call.', [
                'call_id' => $callId,
            ]);
        }

        return $errorResponse(500, 'calls_leave_failed', 'Could not leave call.', [
            'reason' => 'internal_error',
        ]);
    }

    return $jsonResponse(200, [
        'status' => 'ok',
        'result' => [
            'state' => (string) ($leaveResult['state'] ?? 'left'),
            'reason' => $leaveReason,
            'call' => $leaveResult['call'] ?? null,
            'lifecycle' => $leaveResult['lifecycle'] ?? null,
            'left_at' => (string) ($leaveResult['left_at'] ?? ''),
        ],
        'time' => gmdate('c'),
    ]);
}
