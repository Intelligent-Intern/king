<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/calls/call_management.php';

function videochat_handle_call_reactivate_routes(
    string $path,
    string $method,
    array $request,
    array $apiAuthContext,
    callable $jsonResponse,
    callable $errorResponse,
    callable $decodeJsonBody,
    callable $openDatabase
): ?array {
    if (preg_match('#^/api/calls/([A-Za-z0-9._-]{1,200})/reactivate$#', $path, $callReactivateMatch) !== 1) {
        return null;
    }

    if ($method !== 'POST') {
        return $errorResponse(405, 'method_not_allowed', 'Use POST for /api/calls/{id}/reactivate.', [
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

    [$payload, $decodeError] = $decodeJsonBody($request);
    if (!is_array($payload)) {
        return $errorResponse(400, 'calls_reactivate_invalid_request_body', 'Call reactivation payload must be a non-empty JSON object.', [
            'reason' => $decodeError,
        ]);
    }

    $callId = (string) ($callReactivateMatch[1] ?? '');
    try {
        $pdo = $openDatabase();
        $reactivateResult = videochat_reactivate_call($pdo, $callId, $authenticatedUserId, $authenticatedUserRole, $payload);
    } catch (Throwable) {
        return $errorResponse(500, 'calls_reactivate_failed', 'Could not reactivate call.', [
            'reason' => 'internal_error',
        ]);
    }

    $reason = (string) ($reactivateResult['reason'] ?? 'internal_error');
    if (!(bool) ($reactivateResult['ok'] ?? false)) {
        if ($reason === 'validation_failed') {
            return $errorResponse(422, 'calls_reactivate_validation_failed', 'Call reactivation payload failed validation.', [
                'fields' => is_array($reactivateResult['errors'] ?? null) ? $reactivateResult['errors'] : [],
                'call_id' => $callId,
            ]);
        }
        if ($reason === 'not_found') {
            return $errorResponse(404, 'calls_not_found', 'The requested call does not exist.', [
                'call_id' => $callId,
            ]);
        }
        if ($reason === 'forbidden') {
            return $errorResponse(403, 'calls_forbidden', 'Only primary admin user #1 can reactivate calls.', [
                'call_id' => $callId,
            ]);
        }

        return $errorResponse(500, 'calls_reactivate_failed', 'Could not reactivate call.', [
            'reason' => 'internal_error',
        ]);
    }

    return $jsonResponse(200, [
        'status' => 'ok',
        'result' => [
            'state' => $reason === 'reactivated' ? 'reactivated' : $reason,
            'call' => $reactivateResult['call'] ?? null,
            'owner_rows' => max(0, (int) ($reactivateResult['owner_rows'] ?? 0)),
            'participant_rows' => max(0, (int) ($reactivateResult['participant_rows'] ?? 0)),
        ],
        'time' => gmdate('c'),
    ]);
}
