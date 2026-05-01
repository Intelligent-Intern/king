<?php

declare(strict_types=1);

/**
 * Public health must stay on a strict allow-list. Database paths, schema
 * details, demo users, RBAC maps, and runtime internals belong behind
 * /api/admin/runtime only.
 *
 * @param array<string, mixed> $runtimePayload
 * @return array<string, mixed>
 */
function videochat_public_runtime_health_payload(array $runtimePayload): array
{
    $payload = [
        'service' => is_string($runtimePayload['service'] ?? null)
            ? $runtimePayload['service']
            : 'video-chat-backend-king-php',
        'status' => 'ok',
        'time' => is_string($runtimePayload['time'] ?? null) ? $runtimePayload['time'] : gmdate('c'),
    ];
    $assetVersion = trim((string) (getenv('VIDEOCHAT_ASSET_VERSION') ?: ''));
    if ($assetVersion !== '') {
        $payload['asset_version'] = $assetVersion;
    }

    return $payload;
}

function videochat_handle_runtime_routes(
    string $path,
    string $method,
    callable $jsonResponse,
    callable $runtimeEnvelope,
    string $wsPath
): ?array {
    if ($path === '/health' || $path === '/api/runtime') {
        $payload = $runtimeEnvelope();
        return $jsonResponse(200, videochat_public_runtime_health_payload($payload));
    }

    if ($path === '/api/admin/runtime') {
        $payload = $runtimeEnvelope();
        $payload['status'] = 'ok';
        return $jsonResponse(200, $payload);
    }

    if ($path === '/api/version') {
        $payload = $runtimeEnvelope();
        return $jsonResponse(200, [
            'service' => $payload['service'],
            'app' => $payload['app'],
            'runtime' => [
                'king_version' => $payload['runtime']['king_version'],
                'build' => $payload['runtime']['health']['build'],
                'module_version' => $payload['runtime']['health']['module_version'],
            ],
            'time' => $payload['time'],
        ]);
    }

    if ($path === '/' || $path === '/api/bootstrap') {
        return $jsonResponse(200, [
            'service' => 'video-chat-backend-king-php',
            'status' => 'bootstrapped',
            'message' => 'King HTTP and WebSocket scaffold is active.',
            'ws_path' => $wsPath,
            'runtime_endpoint' => '/api/runtime',
            'version_endpoint' => '/api/version',
            'admin_runtime_endpoint' => '/api/admin/runtime',
            'calls_endpoint' => '/api/calls',
            'call_create_endpoint' => '/api/calls',
            'call_update_endpoint_template' => '/api/calls/{id}',
            'call_route_resolve_endpoint_template' => '/api/calls/resolve/{ref}',
            'call_cancel_endpoint_template' => '/api/calls/{id}/cancel',
            'call_access_join_endpoint_template' => '/api/call-access/{access_id}/join',
            'call_access_session_endpoint_template' => '/api/call-access/{access_id}/session',
            'invite_code_create_endpoint' => '/api/invite-codes',
            'invite_code_copy_endpoint_template' => '/api/invite-codes/{id}/copy',
            'invite_code_redeem_endpoint' => '/api/invite-codes/redeem',
            'login_endpoint' => '/api/auth/login',
            'session_endpoint' => '/api/auth/session',
            'session_state_endpoint' => '/api/auth/session-state',
            'refresh_endpoint' => '/api/auth/refresh',
            'logout_endpoint' => '/api/auth/logout',
            'admin_probe_endpoint' => '/api/admin/ping',
            'admin_infrastructure_endpoint' => '/api/admin/infrastructure',
            'admin_marketplace_apps_endpoint' => '/api/admin/marketplace/apps',
            'admin_users_endpoint' => '/api/admin/users',
            'admin_user_update_endpoint_template' => '/api/admin/users/{id}',
            'admin_user_deactivate_endpoint_template' => '/api/admin/users/{id}/deactivate',
            'admin_user_reactivate_endpoint_template' => '/api/admin/users/{id}/reactivate',
            'moderation_probe_endpoint' => '/api/moderation/ping',
            'user_probe_endpoint' => '/api/user/ping',
            'user_directory_endpoint' => '/api/user/directory',
            'user_avatar_upload_endpoint' => '/api/user/avatar',
            'user_avatar_file_endpoint_template' => '/api/user/avatar-files/{filename}',
            'user_settings_endpoint' => '/api/user/settings',
            'time' => gmdate('c'),
        ]);
    }

    return null;
}
