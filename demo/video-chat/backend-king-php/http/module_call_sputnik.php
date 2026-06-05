<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/calls/call_sputnik.php';
require_once __DIR__ . '/../support/tenant_context.php';

function videochat_handle_call_sputnik_routes(
    string $path,
    string $method,
    array $request,
    array $apiAuthContext,
    callable $jsonResponse,
    callable $errorResponse,
    callable $decodeJsonBody,
    callable $openDatabase
): ?array {
    if (preg_match('#^/api/calls/([A-Za-z0-9._-]{1,200})/sputnik-swarm$#', $path, $matches) !== 1) {
        return null;
    }

    if (!in_array($method, ['GET', 'POST', 'DELETE'], true)) {
        return $errorResponse(405, 'method_not_allowed', 'Use GET, POST or DELETE for /api/calls/{id}/sputnik-swarm.', [
            'allowed_methods' => ['GET', 'POST', 'DELETE'],
        ]);
    }

    $authenticatedUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
    $authenticatedUserRole = (string) (($apiAuthContext['user']['role'] ?? 'user'));
    if ($authenticatedUserId <= 0) {
        return $errorResponse(401, 'auth_failed', 'A valid session token is required.', [
            'reason' => 'invalid_user_context',
        ]);
    }

    $callId = (string) ($matches[1] ?? '');
    $result = null;
    try {
        if ($method === 'POST') {
            $payload = [];
            $rawBody = $request['body'] ?? '';
            if (is_string($rawBody) && trim($rawBody) !== '') {
                [$decoded, $decodeError] = $decodeJsonBody($request);
                if (!is_array($decoded)) {
                    return $errorResponse(400, 'call_sputnik_invalid_request_body', 'Sputnik payload must be a JSON object.', [
                        'reason' => $decodeError,
                    ]);
                }
                $payload = $decoded;
            }

            $result = videochat_sputnik_start(
                $openDatabase(),
                $callId,
                $authenticatedUserId,
                $authenticatedUserRole,
                $payload,
                videochat_tenant_id_from_auth_context($apiAuthContext)
            );
        } elseif ($method === 'DELETE') {
            $result = videochat_sputnik_runner_action($openDatabase(), 'DELETE', $callId, $authenticatedUserId);
        } else {
            $result = videochat_sputnik_runner_action($openDatabase(), 'GET', $callId, $authenticatedUserId);
        }
    } catch (Throwable $exception) {
        error_log(sprintf(
            '[video-chat][sputnik] request failed call_id=%s method=%s exception=%s message=%s',
            $callId,
            $method,
            $exception::class,
            $exception->getMessage()
        ));
        return $errorResponse(500, 'call_sputnik_failed', 'Could not control Sputnik swarm.', [
            'reason' => 'internal_error',
        ]);
    }

    if (!(bool) ($result['ok'] ?? false)) {
        $reason = (string) ($result['reason'] ?? 'runner_failed');
        $status = max(400, (int) ($result['status'] ?? 500));
        if ($reason === 'forbidden') {
            return $errorResponse(403, 'call_sputnik_forbidden', 'Only superadmins can control Sputnik participants.', [
                'call_id' => $callId,
            ]);
        }
        if ($reason === 'runner_disabled') {
            return $errorResponse(503, 'call_sputnik_runner_disabled', 'Sputnik runner is not enabled.', [
                'call_id' => $callId,
            ]);
        }
        if ($reason === 'not_found') {
            return $errorResponse(404, 'calls_not_found', 'The requested call does not exist.', [
                'call_id' => $callId,
            ]);
        }

        return $errorResponse($status, 'call_sputnik_runner_failed', 'Sputnik runner did not accept the request.', [
            'call_id' => $callId,
            'reason' => $reason,
        ]);
    }

    $runner = is_array($result['runner'] ?? null) ? $result['runner'] : [];
    $runnerBody = is_array($runner['body'] ?? null) ? $runner['body'] : [];
    $runnerResult = $runnerBody['result'] ?? null;

    return $jsonResponse(200, [
        'status' => 'ok',
        'result' => [
            'state' => (string) ($result['reason'] ?? 'ok'),
            'call_id' => $callId,
            'runner' => is_array($runnerResult) ? $runnerResult : null,
        ],
        'time' => gmdate('c'),
    ]);
}
