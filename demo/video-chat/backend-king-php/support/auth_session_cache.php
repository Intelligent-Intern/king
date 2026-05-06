<?php

declare(strict_types=1);

require_once __DIR__ . '/tenant_context.php';
require_once __DIR__ . '/localization.php';

function videochat_mark_session_revoked_locally(string $sessionId, string $revokedAt): void
{
    $trimmedSessionId = trim($sessionId);
    if ($trimmedSessionId === '') {
        return;
    }

    if (!isset($GLOBALS['videochat_revoked_session_ids']) || !is_array($GLOBALS['videochat_revoked_session_ids'])) {
        $GLOBALS['videochat_revoked_session_ids'] = [];
    }

    $GLOBALS['videochat_revoked_session_ids'][$trimmedSessionId] = trim($revokedAt) !== '' ? trim($revokedAt) : gmdate('c');
}

function videochat_session_revoked_locally(string $sessionId): bool
{
    $trimmedSessionId = trim($sessionId);
    if ($trimmedSessionId === '') {
        return false;
    }

    return isset($GLOBALS['videochat_revoked_session_ids'])
        && is_array($GLOBALS['videochat_revoked_session_ids'])
        && isset($GLOBALS['videochat_revoked_session_ids'][$trimmedSessionId]);
}

function videochat_mark_session_issued_locally(
    string $sessionId,
    int $userId,
    string $issuedAt,
    string $expiresAt,
    ?string $clientIp = null,
    ?string $userAgent = null,
    ?int $activeTenantId = null
): void {
    $trimmedSessionId = trim($sessionId);
    if ($trimmedSessionId === '' || $userId <= 0) {
        return;
    }

    if (!isset($GLOBALS['videochat_issued_session_ids']) || !is_array($GLOBALS['videochat_issued_session_ids'])) {
        $GLOBALS['videochat_issued_session_ids'] = [];
    }

    $GLOBALS['videochat_issued_session_ids'][$trimmedSessionId] = [
        'id' => $trimmedSessionId,
        'user_id' => $userId,
        'issued_at' => $issuedAt,
        'expires_at' => $expiresAt,
        'client_ip' => $clientIp,
        'user_agent' => $userAgent,
        'active_tenant_id' => $activeTenantId,
    ];
}

function videochat_validate_locally_issued_session_token(PDO $pdo, string $sessionId, ?int $nowUnix = null): ?array
{
    $trimmedSessionId = trim($sessionId);
    $localSessions = $GLOBALS['videochat_issued_session_ids'] ?? null;
    if ($trimmedSessionId === '' || !is_array($localSessions) || !is_array($localSessions[$trimmedSessionId] ?? null)) {
        return null;
    }

    $localSession = $localSessions[$trimmedSessionId];
    $expiresAt = is_string($localSession['expires_at'] ?? null) ? (string) $localSession['expires_at'] : '';
    $expiresAtUnix = strtotime($expiresAt);
    $currentUnix = $nowUnix ?? time();
    if (!is_int($expiresAtUnix) || $expiresAtUnix <= $currentUnix) {
        return [
            'ok' => false,
            'reason' => 'expired_session',
            'session' => null,
            'user' => null,
        ];
    }

    $userId = (int) ($localSession['user_id'] ?? 0);
    if ($userId <= 0) {
        return [
            'ok' => false,
            'reason' => 'invalid_session',
            'session' => null,
            'user' => null,
        ];
    }

    $query = $pdo->prepare(
        <<<'SQL'
SELECT
    users.id AS user_id,
    users.email,
    users.display_name,
    users.status AS user_status,
    users.password_hash,
    users.time_format,
    users.date_format,
    users.theme,
    users.locale,
    users.theme_editor_enabled,
    users.avatar_path,
    users.post_logout_landing_url,
    roles.slug AS role_slug
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE users.id = :user_id
LIMIT 1
SQL
    );
    $query->execute([':user_id' => $userId]);
    $row = $query->fetch();
    if (!is_array($row)) {
        return [
            'ok' => false,
            'reason' => 'invalid_session',
            'session' => null,
            'user' => null,
        ];
    }

    $userStatus = is_string($row['user_status'] ?? null) ? (string) $row['user_status'] : 'disabled';
    if ($userStatus !== 'active') {
        return [
            'ok' => false,
            'reason' => 'user_inactive',
            'session' => null,
            'user' => null,
        ];
    }

    $accountType = videochat_user_account_type(
        is_string($row['email'] ?? null) ? (string) $row['email'] : '',
        $row['password_hash'] ?? null
    );
    $tenant = videochat_tenant_context_for_user(
        $pdo,
        (int) $row['user_id'],
        isset($localSession['active_tenant_id']) ? (int) $localSession['active_tenant_id'] : null
    );
    if ($tenant === null) {
        return [
            'ok' => false,
            'reason' => 'tenant_membership_inactive',
            'session' => null,
            'user' => null,
        ];
    }
    $tenantPayload = videochat_tenant_auth_payload($tenant);
    $localization = videochat_localization_payload($pdo, $row['locale'] ?? null);

    return [
        'ok' => true,
        'reason' => 'ok',
        'session' => [
            'id' => $trimmedSessionId,
            'active_tenant_id' => (int) ($tenantPayload['id'] ?? 0),
            'issued_at' => is_string($localSession['issued_at'] ?? null) ? (string) $localSession['issued_at'] : '',
            'expires_at' => $expiresAt,
            'revoked_at' => null,
            'client_ip' => is_string($localSession['client_ip'] ?? null) ? (string) $localSession['client_ip'] : null,
            'user_agent' => is_string($localSession['user_agent'] ?? null) ? (string) $localSession['user_agent'] : null,
        ],
        'user' => [
            'id' => (int) $row['user_id'],
            'email' => (string) $row['email'],
            'display_name' => (string) $row['display_name'],
            'role' => is_string($row['role_slug'] ?? null) && $row['role_slug'] !== '' ? (string) $row['role_slug'] : 'user',
            'status' => $userStatus,
            'time_format' => is_string($row['time_format'] ?? null) ? (string) $row['time_format'] : '24h',
            'date_format' => is_string($row['date_format'] ?? null) ? (string) $row['date_format'] : 'dmy_dot',
            'theme' => is_string($row['theme'] ?? null) ? (string) $row['theme'] : 'dark',
            'locale' => (string) ($localization['locale'] ?? 'en'),
            'direction' => (string) ($localization['direction'] ?? 'ltr'),
            'supported_locales' => is_array($localization['supported_locales'] ?? null) ? $localization['supported_locales'] : [],
            'can_edit_themes' => (string) ($row['role_slug'] ?? 'user') === 'admin'
                || ((int) ($row['theme_editor_enabled'] ?? 0)) === 1
                || (bool) (($tenantPayload['permissions']['edit_themes'] ?? false)),
            'avatar_path' => is_string($row['avatar_path'] ?? null) ? (string) $row['avatar_path'] : null,
            'post_logout_landing_url' => is_string($row['post_logout_landing_url'] ?? null)
                ? trim((string) $row['post_logout_landing_url'])
                : '',
            'account_type' => $accountType,
            'is_guest' => $accountType === 'guest',
            'tenant' => $tenantPayload,
        ],
        'tenant' => $tenantPayload,
    ];
}
