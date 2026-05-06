<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/users/onboarding_progress.php';

function videochat_handle_user_onboarding_routes(
    string $path,
    string $method,
    array $request,
    array $apiAuthContext,
    callable $jsonResponse,
    callable $errorResponse,
    callable $decodeJsonBody,
    callable $openDatabase
): ?array {
    if ($path !== '/api/user/onboarding/tours/complete') {
        return null;
    }

    if ($method !== 'POST') {
        return $errorResponse(405, 'method_not_allowed', 'Use POST for /api/user/onboarding/tours/complete.', [
            'allowed_methods' => ['POST'],
        ]);
    }

    $authenticatedUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
    if ($authenticatedUserId <= 0) {
        return $errorResponse(401, 'auth_failed', 'A valid session token is required.', [
            'reason' => 'invalid_user_context',
        ]);
    }
    $activeTenantId = (int) (($apiAuthContext['tenant']['id'] ?? ($apiAuthContext['session']['active_tenant_id'] ?? 0)));
    if ($activeTenantId <= 0) {
        return $errorResponse(403, 'tenant_required', 'A valid active tenant is required.', [
            'reason' => 'missing_active_tenant',
        ]);
    }

    [$payload, $decodeError] = $decodeJsonBody($request);
    if (!is_array($payload)) {
        return $errorResponse(400, 'user_onboarding_invalid_request_body', 'Onboarding payload must be a JSON object.', [
            'reason' => $decodeError,
        ]);
    }

    try {
        $pdo = $openDatabase();
        $result = videochat_complete_onboarding_tour(
            $pdo,
            $authenticatedUserId,
            $activeTenantId,
            $payload['tour_key'] ?? '',
            is_string($payload['completed_at'] ?? null) ? (string) $payload['completed_at'] : null
        );
    } catch (Throwable) {
        return $errorResponse(500, 'user_onboarding_update_failed', 'Could not update onboarding progress.', [
            'reason' => 'internal_error',
        ]);
    }

    if (!(bool) ($result['ok'] ?? false)) {
        $reason = (string) ($result['reason'] ?? 'internal_error');
        if ($reason === 'validation_failed') {
            return $errorResponse(422, 'user_onboarding_validation_failed', 'Onboarding payload failed validation.', [
                'fields' => is_array($result['errors'] ?? null) ? $result['errors'] : [],
            ]);
        }
        if ($reason === 'not_found') {
            return $errorResponse(404, 'user_not_found', 'Authenticated user could not be resolved.', [
                'user_id' => $authenticatedUserId,
            ]);
        }

        return $errorResponse(500, 'user_onboarding_update_failed', 'Could not update onboarding progress.', [
            'reason' => $reason,
        ]);
    }

    return $jsonResponse(200, [
        'status' => 'ok',
        'result' => [
            'state' => (string) ($result['reason'] ?? 'completed'),
            'onboarding' => $result['onboarding'],
        ],
        'time' => gmdate('c'),
    ]);
}
