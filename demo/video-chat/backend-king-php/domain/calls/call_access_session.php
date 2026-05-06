<?php

declare(strict_types=1);

require_once __DIR__ . '/../users/user_settings.php';

function videochat_issue_session_for_call_access(
    PDO $pdo,
    string $accessId,
    callable $issueSessionId,
    array $requestMeta = [],
    array $options = []
): array {
    $resolve = videochat_resolve_call_access_public($pdo, $accessId);
    if (!(bool) ($resolve['ok'] ?? false)) {
        return [
            'ok' => false,
            'reason' => (string) ($resolve['reason'] ?? 'internal_error'),
            'errors' => is_array($resolve['errors'] ?? null) ? $resolve['errors'] : [],
            'session' => null,
            'user' => null,
            'access_link' => is_array($resolve['access_link'] ?? null) ? $resolve['access_link'] : null,
            'call' => is_array($resolve['call'] ?? null) ? $resolve['call'] : null,
        ];
    }

    $accessLink = is_array($resolve['access_link'] ?? null) ? $resolve['access_link'] : null;
    $call = is_array($resolve['call'] ?? null) ? $resolve['call'] : null;
    $targetUser = is_array($resolve['target_user'] ?? null) ? $resolve['target_user'] : null;
    if (!is_array($accessLink) || !is_array($call)) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => ['access_link' => 'access_link_or_call_not_found'],
            'session' => null,
            'user' => null,
            'access_link' => $accessLink,
            'call' => $call,
        ];
    }

    $linkKind = videochat_call_access_link_kind($accessLink);
    $tenantId = is_numeric($accessLink['tenant_id'] ?? null) ? (int) $accessLink['tenant_id'] : null;
    if ($linkKind === 'open') {
        $guestName = trim((string) ($options['guest_name'] ?? ''));
        $guestCreate = videochat_create_guest_user_for_call_access($pdo, $guestName, $tenantId);
        if (!(bool) ($guestCreate['ok'] ?? false)) {
            return [
                'ok' => false,
                'reason' => (string) ($guestCreate['reason'] ?? 'validation_failed'),
                'errors' => is_array($guestCreate['errors'] ?? null) ? $guestCreate['errors'] : ['guest_name' => 'required_guest_name'],
                'session' => null,
                'user' => null,
                'access_link' => $accessLink,
                'call' => $call,
            ];
        }
        $targetUser = is_array($guestCreate['user'] ?? null) ? $guestCreate['user'] : null;
    }

    if (!is_array($targetUser)) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => ['target_user' => 'not_found_or_inactive'],
            'session' => null,
            'user' => null,
            'access_link' => $accessLink,
            'call' => $call,
        ];
    }

    $userId = (int) ($targetUser['id'] ?? 0);
    $userRole = (string) ($targetUser['role'] ?? 'user');
    if ($userId <= 0) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => ['target_user' => 'invalid_target_user'],
            'session' => null,
            'user' => null,
            'access_link' => $accessLink,
            'call' => $call,
        ];
    }

    $callPermission = videochat_get_call_for_user(
        $pdo,
        (string) ($call['id'] ?? ''),
        $userId,
        $userRole,
        $tenantId
    );
    if ($linkKind === 'open' && !(bool) ($callPermission['ok'] ?? false)) {
        videochat_ensure_internal_call_participant(
            $pdo,
            (string) ($call['id'] ?? ''),
            $userId,
            (string) ($targetUser['email'] ?? ''),
            (string) ($targetUser['display_name'] ?? '')
        );
        $callPermission = videochat_get_call_for_user(
            $pdo,
            (string) ($call['id'] ?? ''),
            $userId,
            $userRole,
            $tenantId
        );
    }
    if (!(bool) ($callPermission['ok'] ?? false)) {
        return [
            'ok' => false,
            'reason' => 'forbidden',
            'errors' => ['target_user' => 'not_allowed_for_call'],
            'session' => null,
            'user' => null,
            'access_link' => $accessLink,
            'call' => $call,
        ];
    }

    $ttlSeconds = (int) (getenv('VIDEOCHAT_SESSION_TTL_SECONDS') ?: 43_200);
    if ($ttlSeconds < 60) {
        $ttlSeconds = 60;
    } elseif ($ttlSeconds > 2_592_000) {
        $ttlSeconds = 2_592_000;
    }

    $sessionId = trim((string) $issueSessionId());
    if ($sessionId === '') {
        return [
            'ok' => false,
            'reason' => 'internal_error',
            'errors' => ['session' => 'session_id_generation_failed'],
            'session' => null,
            'user' => null,
            'access_link' => $accessLink,
            'call' => $call,
        ];
    }

    $issuedAt = gmdate('c');
    $expiresAt = gmdate('c', time() + $ttlSeconds);
    $clientIp = trim((string) ($requestMeta['client_ip'] ?? ''));
    $userAgent = substr(trim((string) ($requestMeta['user_agent'] ?? '')), 0, 500);
    $callId = trim((string) ($call['id'] ?? ''));
    $roomId = trim((string) ($call['room_id'] ?? ''));
    $postLogoutLandingUrl = '';
    if ($linkKind === 'open') {
        $ownerUserId = (int) (($call['owner']['user_id'] ?? 0));
        $postLogoutLandingUrl = videochat_fetch_user_post_logout_landing_url($pdo, $ownerUserId);
    } else {
        $postLogoutLandingUrl = videochat_fetch_user_post_logout_landing_url($pdo, $userId);
    }
    if ($callId === '' || $roomId === '') {
        return [
            'ok' => false,
            'reason' => 'internal_error',
            'errors' => ['call' => 'missing_call_room_binding'],
            'session' => null,
            'user' => null,
            'access_link' => $accessLink,
            'call' => $call,
        ];
    }

    try {
        $pdo->beginTransaction();

        $insert = $pdo->prepare(
            <<<'SQL'
INSERT INTO sessions(id, user_id, issued_at, expires_at, revoked_at, post_logout_landing_url, client_ip, user_agent)
VALUES(:id, :user_id, :issued_at, :expires_at, NULL, :post_logout_landing_url, :client_ip, :user_agent)
SQL
        );
        $insert->execute([
            ':id' => $sessionId,
            ':user_id' => $userId,
            ':issued_at' => $issuedAt,
            ':expires_at' => $expiresAt,
            ':post_logout_landing_url' => $postLogoutLandingUrl,
            ':client_ip' => $clientIp === '' ? null : $clientIp,
            ':user_agent' => $userAgent === '' ? null : $userAgent,
        ]);
        if (is_int($tenantId) && $tenantId > 0 && videochat_tenant_table_has_column($pdo, 'sessions', 'active_tenant_id')) {
            videochat_tenant_update_session($pdo, $sessionId, $tenantId);
        }

        $touch = $pdo->prepare(
            <<<'SQL'
UPDATE call_access_links
SET last_used_at = :last_used_at,
    consumed_at = CASE
        WHEN consumed_at IS NULL OR consumed_at = '' THEN :consumed_at
        ELSE consumed_at
    END
WHERE id = :id
SQL
        );
        $touch->execute([
            ':id' => (string) ($accessLink['id'] ?? ''),
            ':last_used_at' => gmdate('c'),
            ':consumed_at' => gmdate('c'),
        ]);

        $bindTenantColumn = is_int($tenantId) && $tenantId > 0 && videochat_tenant_table_has_column($pdo, 'call_access_sessions', 'tenant_id')
            ? ', tenant_id'
            : '';
        $bindTenantValue = $bindTenantColumn !== '' ? ', :tenant_id' : '';
        $bind = $pdo->prepare(
            <<<SQL
INSERT INTO call_access_sessions(session_id, access_id, call_id, room_id, user_id, link_kind, issued_at, expires_at{$bindTenantColumn})
VALUES(:session_id, :access_id, :call_id, :room_id, :user_id, :link_kind, :issued_at, :expires_at{$bindTenantValue})
SQL
        );
        $bindParams = [
            ':session_id' => $sessionId,
            ':access_id' => (string) ($accessLink['id'] ?? ''),
            ':call_id' => $callId,
            ':room_id' => $roomId,
            ':user_id' => $userId,
            ':link_kind' => $linkKind,
            ':issued_at' => $issuedAt,
            ':expires_at' => $expiresAt,
        ];
        if ($bindTenantColumn !== '') {
            $bindParams[':tenant_id'] = $tenantId;
        }
        $bind->execute($bindParams);

        $pdo->commit();
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return [
            'ok' => false,
            'reason' => 'internal_error',
            'errors' => [],
            'session' => null,
            'user' => null,
            'access_link' => $accessLink,
            'call' => $call,
        ];
    }

    $freshLink = videochat_fetch_call_access_link($pdo, (string) ($accessLink['id'] ?? ''), $tenantId);
    $freshCall = videochat_get_call_for_user(
        $pdo,
        (string) ($call['id'] ?? ''),
        $userId,
        $userRole,
        $tenantId
    );

    return [
        'ok' => true,
        'reason' => 'issued',
        'errors' => [],
        'session' => [
            'id' => $sessionId,
            'token' => $sessionId,
            'token_type' => 'session_id',
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
            'expires_in_seconds' => $ttlSeconds,
        ],
        'user' => [
            'id' => $userId,
            'email' => (string) ($targetUser['email'] ?? ''),
            'display_name' => (string) ($targetUser['display_name'] ?? ''),
            'role' => videochat_normalize_role_slug((string) ($targetUser['role'] ?? 'user')),
            'status' => (string) ($targetUser['status'] ?? 'active'),
            'time_format' => (string) ($targetUser['time_format'] ?? '24h'),
            'date_format' => (string) ($targetUser['date_format'] ?? 'dmy_dot'),
            'theme' => (string) ($targetUser['theme'] ?? 'dark'),
            'avatar_path' => is_string($targetUser['avatar_path'] ?? null) ? (string) $targetUser['avatar_path'] : null,
            'post_logout_landing_url' => $postLogoutLandingUrl,
            'account_type' => (string) ($targetUser['account_type'] ?? 'account'),
            'is_guest' => (bool) ($targetUser['is_guest'] ?? false),
        ],
        'access_link' => is_array($freshLink) ? $freshLink : $accessLink,
        'call' => is_array($freshCall['call'] ?? null) ? $freshCall['call'] : $call,
    ];
}

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   errors: array<string, string>,
 *   access_link: ?array<string, mixed>,
 *   call: ?array<string, mixed>
 * }
 */
