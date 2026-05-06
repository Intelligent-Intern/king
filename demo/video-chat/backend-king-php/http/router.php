<?php

declare(strict_types=1);

require_once __DIR__ . '/module_runtime.php';
require_once __DIR__ . '/module_auth_session.php';
require_once __DIR__ . '/module_infrastructure.php';
require_once __DIR__ . '/module_operations.php';
require_once __DIR__ . '/module_marketplace.php';
require_once __DIR__ . '/module_tenancy.php';
require_once __DIR__ . '/module_backend_modules.php';
require_once __DIR__ . '/module_localization.php';
require_once __DIR__ . '/module_users.php';
require_once __DIR__ . '/module_workspace_administration.php';
require_once __DIR__ . '/module_invites.php';
require_once __DIR__ . '/module_calls.php';
require_once __DIR__ . '/module_appointment_calendar.php';
require_once __DIR__ . '/module_realtime.php';

/**
 * @return array<int, string>
 */
function videochat_dispatch_route_module_order(): array
{
    return [
        'runtime',
        'auth_session',
        'infrastructure',
        'operations',
        'marketplace',
        'tenancy',
        'backend_modules',
        'localization',
        'users',
        'workspace_administration',
        'invites',
        'calls',
        'appointment_calendar',
        'realtime',
    ];
}

/**
 * Deterministic HTTP/WS dispatcher that wires focused backend modules.
 *
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function videochat_dispatch_request(
    array $request,
    array &$activeWebsocketsBySession,
    array &$presenceState,
    array &$lobbyState,
    array &$typingState,
    array &$reactionState,
    callable $jsonResponse,
    callable $errorResponse,
    callable $methodFromRequest,
    callable $decodeJsonBody,
    callable $openDatabase,
    callable $issueSessionId,
    callable $pathFromRequest,
    callable $runtimeEnvelope,
    string $wsPath,
    string $avatarStorageRoot,
    int $avatarMaxBytes
): array {
    $path = $pathFromRequest($request);
    $method = $methodFromRequest($request);
    $corsHeaders = [
        'access-control-allow-methods' => 'GET,POST,PUT,PATCH,DELETE,OPTIONS',
        'access-control-allow-headers' => 'Authorization, Content-Type, X-Session-Id',
        'access-control-max-age' => '600',
    ];

    if ($method === 'OPTIONS' && (str_starts_with($path, '/api/') || $path === '/' || $path === '/health')) {
        return [
            'status' => 204,
            'headers' => $corsHeaders,
            'body' => '',
        ];
    }

    $isPublicEndpoint = static function (string $requestPath) use ($wsPath): bool {
        if ($requestPath === $wsPath) {
            return false;
        }

        if (in_array(
            $requestPath,
                ['/', '/health', '/api/bootstrap', '/api/runtime', '/api/version', '/api/auth/login', '/api/auth/session-state', '/api/auth/email-change/confirm'],
            true
        )) {
            return true;
        }

        if ($requestPath === '/api/workspace/appearance' || $requestPath === '/api/public-leads') {
            return true;
        }

        if ($requestPath === '/api/localization/resources') {
            return true;
        }

        if (preg_match('#^/api/workspace/branding-files/[^/]+$#', $requestPath) === 1) {
            return true;
        }

        if (preg_match('#^/api/workspace/background-images/[^/]+$#', $requestPath) === 1) {
            return true;
        }

        if (preg_match('#^/api/call-access/[A-Fa-f0-9-]{36}/(join|session)$#', $requestPath) === 1) {
            return true;
        }

        if (preg_match('#^/api/appointment-calendar/public/[A-Fa-f0-9-]{36}(?:/book)?$#', $requestPath) === 1) {
            return true;
        }

        return false;
    };

    $authenticateRequest = static function (array $authRequest, string $transport) use ($openDatabase): array {
        try {
            $pdo = $openDatabase();
            return videochat_authenticate_request($pdo, $authRequest, $transport);
        } catch (Throwable) {
            return [
                'ok' => false,
                'reason' => 'auth_backend_error',
                'token' => '',
                'session' => null,
                'user' => null,
            ];
        }
    };

    $authFailureResponse = static function (string $transport, string $reason) use ($errorResponse): array {
        $status = $reason === 'auth_backend_error' ? 500 : 401;
        $code = $transport === 'websocket' ? 'websocket_auth_failed' : 'auth_failed';
        $message = $status === 500
            ? 'Authentication check failed due to a backend error.'
            : 'A valid session token is required.';

        return $errorResponse($status, $code, $message, [
            'reason' => $reason,
        ]);
    };

    $rbacFailureResponse = static function (string $transport, array $rbacDecision, string $requestPath) use ($errorResponse): array {
        $status = 403;
        $code = $transport === 'websocket' ? 'websocket_forbidden' : 'rbac_forbidden';
        $message = $transport === 'websocket'
            ? 'Session role is not allowed for websocket access.'
            : 'Session role is not allowed for this endpoint.';

        return $errorResponse($status, $code, $message, [
            'reason' => (string) ($rbacDecision['reason'] ?? 'role_not_allowed'),
            'rule_id' => (string) ($rbacDecision['rule_id'] ?? 'unknown'),
            'role' => (string) ($rbacDecision['role'] ?? 'unknown'),
            'allowed_roles' => is_array($rbacDecision['allowed_roles'] ?? null) ? array_values($rbacDecision['allowed_roles']) : [],
            'path' => $requestPath,
        ]);
    };

    $apiAuthContext = [];
    if (str_starts_with($path, '/api/') && !$isPublicEndpoint($path)) {
        $apiAuthContext = $authenticateRequest($request, 'rest');
        if (!(bool) ($apiAuthContext['ok'] ?? false)) {
            return $authFailureResponse('rest', (string) ($apiAuthContext['reason'] ?? 'invalid_session'));
        }

        $rbacDecision = videochat_authorize_role_for_path((array) ($apiAuthContext['user'] ?? []), $path, $wsPath);
        if (!(bool) ($rbacDecision['ok'] ?? false)) {
            return $rbacFailureResponse('rest', $rbacDecision, $path);
        }
    }

    foreach (videochat_dispatch_route_module_order() as $moduleName) {
        $response = null;
        if ($moduleName === 'runtime') {
            $response = videochat_handle_runtime_routes(
                $path,
                $method,
                $jsonResponse,
                $runtimeEnvelope,
                $wsPath
            );
        } elseif ($moduleName === 'auth_session') {
            $response = videochat_handle_auth_session_routes(
                $path,
                $method,
                $request,
                $apiAuthContext,
                $activeWebsocketsBySession,
                $jsonResponse,
                $errorResponse,
                $decodeJsonBody,
                $openDatabase,
                $issueSessionId
            );
        } elseif ($moduleName === 'infrastructure') {
            $response = videochat_handle_infrastructure_routes(
                $path,
                $method,
                $jsonResponse,
                $errorResponse
            );
        } elseif ($moduleName === 'operations') {
            $response = videochat_handle_operations_routes(
                $path,
                $method,
                $jsonResponse,
                $errorResponse,
                $openDatabase
            );
        } elseif ($moduleName === 'marketplace') {
            $response = videochat_handle_marketplace_routes(
                $path,
                $method,
                $request,
                $apiAuthContext,
                $jsonResponse,
                $errorResponse,
                $decodeJsonBody,
                $openDatabase
            );
        } elseif ($moduleName === 'tenancy') {
            $response = videochat_handle_tenancy_routes(
                $path,
                $method,
                $request,
                $apiAuthContext,
                $jsonResponse,
                $errorResponse,
                $decodeJsonBody,
                $openDatabase
            );
        } elseif ($moduleName === 'backend_modules') {
            $response = videochat_handle_backend_module_routes(
                $path,
                $method,
                $request,
                $apiAuthContext,
                $jsonResponse,
                $errorResponse,
                $decodeJsonBody
            );
        } elseif ($moduleName === 'localization') {
            $response = videochat_handle_localization_routes(
                $path,
                $method,
                $request,
                $apiAuthContext,
                $jsonResponse,
                $errorResponse,
                $decodeJsonBody,
                $openDatabase
            );
        } elseif ($moduleName === 'users') {
            $response = videochat_handle_user_routes(
                $path,
                $method,
                $request,
                $apiAuthContext,
                $corsHeaders,
                $avatarStorageRoot,
                $avatarMaxBytes,
                $jsonResponse,
                $errorResponse,
                $decodeJsonBody,
                $openDatabase
            );
        } elseif ($moduleName === 'workspace_administration') {
            $response = videochat_handle_workspace_administration_routes(
                $path,
                $method,
                $request,
                $apiAuthContext,
                $avatarStorageRoot,
                $avatarMaxBytes,
                $jsonResponse,
                $errorResponse,
                $decodeJsonBody,
                $openDatabase
            );
        } elseif ($moduleName === 'invites') {
            $response = videochat_handle_invite_routes(
                $path,
                $method,
                $request,
                $apiAuthContext,
                $jsonResponse,
                $errorResponse,
                $decodeJsonBody,
                $openDatabase
            );
        } elseif ($moduleName === 'calls') {
            $response = videochat_handle_call_routes(
                $path,
                $method,
                $request,
                $apiAuthContext,
                $jsonResponse,
                $errorResponse,
                $decodeJsonBody,
                $openDatabase,
                $issueSessionId
            );
        } elseif ($moduleName === 'appointment_calendar') {
            $response = videochat_handle_appointment_calendar_routes(
                $path,
                $method,
                $request,
                $apiAuthContext,
                $jsonResponse,
                $errorResponse,
                $decodeJsonBody,
                $openDatabase
            );
        } elseif ($moduleName === 'realtime') {
            $response = videochat_handle_realtime_routes(
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
                $jsonResponse,
                $errorResponse,
                $openDatabase
            );
        }

        if (is_array($response)) {
            return $response;
        }
    }

    return $errorResponse(404, 'not_found', 'The requested endpoint does not exist.', [
        'path' => $path,
    ]);
}
