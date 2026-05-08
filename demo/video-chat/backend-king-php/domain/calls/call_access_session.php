<?php

declare(strict_types=1);

require_once __DIR__ . '/../audit/audit_events.php';
require_once __DIR__ . '/../users/user_settings.php';

function videochat_call_access_session_int_option(array $options, string $key): int
{
    if (!is_numeric($options[$key] ?? null)) {
        return 0;
    }

    return max(0, (int) $options[$key]);
}

function videochat_call_access_session_string_option(array $options, string $key): string
{
    if (!is_string($options[$key] ?? null) && !is_numeric($options[$key] ?? null)) {
        return '';
    }

    return trim((string) $options[$key]);
}

function videochat_call_access_session_id_available(PDO $pdo, string $sessionId): bool
{
    $trimmedSessionId = trim($sessionId);
    if ($trimmedSessionId === '') {
        return false;
    }

    $sessionQuery = $pdo->prepare('SELECT 1 FROM sessions WHERE id = :id LIMIT 1');
    $sessionQuery->execute([':id' => $trimmedSessionId]);
    if ($sessionQuery->fetchColumn() !== false) {
        return false;
    }

    if (!videochat_tenant_table_has_column($pdo, 'call_access_sessions', 'session_id')) {
        return true;
    }

    $bindingQuery = $pdo->prepare('SELECT 1 FROM call_access_sessions WHERE session_id = :id LIMIT 1');
    $bindingQuery->execute([':id' => $trimmedSessionId]);
    return $bindingQuery->fetchColumn() === false;
}

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
            'access_link' => null,
            'call' => null,
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
            'access_link' => null,
            'call' => null,
        ];
    }

    $linkKind = videochat_call_access_link_kind($accessLink);
    $tenantId = is_numeric($accessLink['tenant_id'] ?? null) ? (int) $accessLink['tenant_id'] : null;
    $verifiedUserId = videochat_call_access_session_int_option($options, 'verified_user_id');
    $authenticatedUserId = videochat_call_access_session_int_option($options, 'authenticated_user_id');
    $verifiedSessionId = videochat_call_access_session_string_option($options, 'verified_session_id');
    $authenticatedSessionId = videochat_call_access_session_string_option($options, 'authenticated_session_id');
    $hostName = videochat_call_access_session_string_option($options, 'host_name');
    if (($verifiedUserId > 0 || $verifiedSessionId !== '') && ($authenticatedUserId <= 0 || $authenticatedSessionId === '')) {
        return [
            'ok' => false,
            'reason' => 'conflict',
            'errors' => ['auth' => 'session_context_changed'],
            'session' => null,
            'user' => null,
            'access_link' => $accessLink,
            'call' => $call,
        ];
    }
    if ($verifiedSessionId !== '' && $authenticatedSessionId !== '' && !hash_equals($verifiedSessionId, $authenticatedSessionId)) {
        return [
            'ok' => false,
            'reason' => 'conflict',
            'errors' => ['auth' => 'session_context_changed'],
            'session' => null,
            'user' => null,
            'access_link' => $accessLink,
            'call' => $call,
        ];
    }
    if ($verifiedUserId > 0 && $authenticatedUserId > 0 && $verifiedUserId !== $authenticatedUserId) {
        return [
            'ok' => false,
            'reason' => 'conflict',
            'errors' => ['auth' => 'session_context_changed'],
            'session' => null,
            'user' => null,
            'access_link' => $accessLink,
            'call' => $call,
        ];
    }
    $openLinkUsesAuthenticatedUser = false;
    if ($linkKind === 'open') {
        if ($authenticatedUserId > 0) {
            $targetUser = videochat_fetch_active_user_for_call_access($pdo, $authenticatedUserId, null, $tenantId, false);
            $openLinkUsesAuthenticatedUser = is_array($targetUser);
        } else {
            $guestName = trim((string) ($options['guest_name'] ?? ''));
            $guestCreate = videochat_create_guest_user_for_call_access($pdo, $guestName, $tenantId);
            if (!(bool) ($guestCreate['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'reason' => (string) ($guestCreate['reason'] ?? 'validation_failed'),
                    'errors' => is_array($guestCreate['errors'] ?? null) ? $guestCreate['errors'] : ['guest_name' => 'required_guest_name'],
                    'session' => null,
                    'user' => null,
                    'access_link' => null,
                    'call' => null,
                ];
            }
            $targetUser = is_array($guestCreate['user'] ?? null) ? $guestCreate['user'] : null;
        }
    }

    if (!is_array($targetUser)) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => ['target_user' => 'not_found_or_inactive'],
            'session' => null,
            'user' => null,
            'access_link' => null,
            'call' => null,
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
            'access_link' => null,
            'call' => null,
        ];
    }

    if ($linkKind === 'personal' && $verifiedUserId > 0 && $verifiedUserId !== $userId) {
        videochat_call_access_record_duplicate_personalized_link_review(
            $pdo,
            $accessLink,
            $call,
            $targetUser,
            $verifiedUserId,
            'session_verified_context',
            ['session_id' => $verifiedSessionId]
        );

        return [
            'ok' => false,
            'reason' => 'conflict',
            'errors' => ['auth' => 'session_context_changed'],
            'session' => null,
            'user' => null,
            'access_link' => $accessLink,
            'call' => $call,
        ];
    }
    if ($linkKind === 'personal' && $authenticatedUserId > 0 && $authenticatedUserId !== $userId) {
        videochat_call_access_record_duplicate_personalized_link_review(
            $pdo,
            $accessLink,
            $call,
            $targetUser,
            $authenticatedUserId,
            'session_host_verification',
            ['session_id' => $authenticatedSessionId]
        );

        if ($hostName !== '') {
            $hostRate = videochat_call_access_host_verification_rate_limit($pdo, $accessLink, $call, $authenticatedUserId);
            if (!(bool) ($hostRate['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'reason' => 'rate_limited',
                    'errors' => ['host_name' => 'rate_limited'],
                    'session' => null,
                    'user' => null,
                    'access_link' => null,
                    'call' => null,
                ];
            }
            videochat_call_access_record_host_verification_attempt(
                $pdo,
                $accessLink,
                $call,
                $authenticatedUserId,
                $hostName,
                'wrong_host_name'
            );
        }

        return [
            'ok' => false,
            'reason' => 'forbidden',
            'errors' => [
                'auth' => 'not_bound_to_current_user',
                'host_name' => $hostName === '' ? 'not_verified' : 'wrong_host_name',
            ],
            'session' => null,
            'user' => null,
            'access_link' => null,
            'call' => null,
        ];
    }

    if ($linkKind === 'open') {
        videochat_ensure_internal_call_participant(
            $pdo,
            (string) ($call['id'] ?? ''),
            $userId,
            (string) ($targetUser['email'] ?? ''),
            (string) ($targetUser['display_name'] ?? ''),
            'invited'
        );
    }

    $callDecision = videochat_decide_call_access_for_user(
        $pdo,
        (string) ($call['id'] ?? ''),
        $userId,
        $userRole,
        $tenantId
    );
    if (!(bool) ($callDecision['allowed'] ?? false)) {
        $decisionReason = (string) ($callDecision['reason'] ?? 'forbidden');
        if ($decisionReason === 'call_not_joinable_from_status') {
            return [
                'ok' => false,
                'reason' => 'conflict',
                'errors' => ['call_id' => 'call_not_joinable_from_status'],
                'session' => null,
                'user' => null,
                'access_link' => null,
                'call' => null,
            ];
        }

        return [
            'ok' => false,
            'reason' => 'forbidden',
            'errors' => ['target_user' => 'not_allowed_for_call'],
            'session' => null,
            'user' => null,
            'access_link' => null,
            'call' => null,
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
            'access_link' => null,
            'call' => null,
        ];
    }
    if (!videochat_call_access_session_id_available($pdo, $sessionId)) {
        return [
            'ok' => false,
            'reason' => 'conflict',
            'errors' => ['session' => 'session_id_not_available'],
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
    if ($linkKind === 'open' && !$openLinkUsesAuthenticatedUser) {
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
            'access_link' => null,
            'call' => null,
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
            $linkKind === 'open'
                ? <<<'SQL'
UPDATE call_access_links
SET last_used_at = :last_used_at
WHERE id = :id
SQL
                : <<<'SQL'
UPDATE call_access_links
SET last_used_at = :last_used_at,
    consumed_at = CASE
        WHEN consumed_at IS NULL OR consumed_at = '' THEN :consumed_at
        ELSE consumed_at
    END
WHERE id = :id
SQL
        );
        $touchParams = [
            ':id' => (string) ($accessLink['id'] ?? ''),
            ':last_used_at' => gmdate('c'),
        ];
        if ($linkKind !== 'open') {
            $touchParams[':consumed_at'] = gmdate('c');
        }
        $touch->execute($touchParams);

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
            'access_link' => null,
            'call' => null,
        ];
    }
    if (is_int($tenantId) && $tenantId > 0 && !videochat_tenant_user_is_member($pdo, $userId, $tenantId)) {
        videochat_audit_record_call_scoped_access_continued($pdo, $accessLink, $call, $targetUser, $sessionId);
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
