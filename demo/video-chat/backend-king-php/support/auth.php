<?php

declare(strict_types=1);

require_once __DIR__ . '/auth_request.php';
require_once __DIR__ . '/auth_session_cache.php';
require_once __DIR__ . '/auth_rbac.php';
require_once __DIR__ . '/tenant_context.php';

function videochat_validate_session_token(PDO $pdo, string $sessionId, ?int $nowUnix = null): array
{
    $trimmedSessionId = trim($sessionId);
    if ($trimmedSessionId === '') {
        return [
            'ok' => false,
            'reason' => 'missing_session',
            'session' => null,
            'user' => null,
        ];
    }
    if (videochat_session_revoked_locally($trimmedSessionId)) {
        return [
            'ok' => false,
            'reason' => 'revoked_session',
            'session' => null,
            'user' => null,
        ];
    }

    $activeTenantSelect = videochat_tenant_table_has_column($pdo, 'sessions', 'active_tenant_id')
        ? 'sessions.active_tenant_id AS active_tenant_id,'
        : 'NULL AS active_tenant_id,';
    $query = $pdo->prepare(
        <<<SQL
SELECT
    sessions.id,
    {$activeTenantSelect}
    sessions.issued_at,
    sessions.expires_at,
    sessions.revoked_at,
    sessions.post_logout_landing_url AS session_post_logout_landing_url,
    sessions.client_ip,
    sessions.user_agent,
    users.id AS user_id,
    users.email,
    users.display_name,
    users.status AS user_status,
    users.password_hash,
    users.time_format,
    users.date_format,
    users.theme,
    users.theme_editor_enabled,
    users.avatar_path,
    users.post_logout_landing_url AS user_post_logout_landing_url,
    roles.slug AS role_slug
FROM sessions
INNER JOIN users ON users.id = sessions.user_id
INNER JOIN roles ON roles.id = users.role_id
WHERE sessions.id = :session_id
LIMIT 1
SQL
    );
    $query->execute([':session_id' => $trimmedSessionId]);
    $row = $query->fetch();
    if (!is_array($row)) {
        $localValidation = videochat_validate_locally_issued_session_token($pdo, $trimmedSessionId, $nowUnix);
        if (is_array($localValidation)) {
            return $localValidation;
        }

        return [
            'ok' => false,
            'reason' => 'invalid_session',
            'session' => null,
            'user' => null,
        ];
    }

    $revokedAt = is_string($row['revoked_at'] ?? null) ? trim((string) $row['revoked_at']) : '';
    if ($revokedAt !== '') {
        videochat_mark_session_revoked_locally($trimmedSessionId, $revokedAt);
        return [
            'ok' => false,
            'reason' => 'revoked_session',
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

    $expiresAt = is_string($row['expires_at'] ?? null) ? (string) $row['expires_at'] : '';
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

    $accountType = videochat_user_account_type(
        is_string($row['email'] ?? null) ? (string) $row['email'] : '',
        $row['password_hash'] ?? null
    );
    $tenant = videochat_tenant_context_for_user(
        $pdo,
        (int) $row['user_id'],
        isset($row['active_tenant_id']) ? (int) $row['active_tenant_id'] : null
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

    return [
        'ok' => true,
        'reason' => 'ok',
        'session' => [
            'id' => (string) $row['id'],
            'active_tenant_id' => (int) ($tenantPayload['id'] ?? 0),
            'issued_at' => (string) $row['issued_at'],
            'expires_at' => $expiresAt,
            'revoked_at' => null,
            'client_ip' => is_string($row['client_ip'] ?? null) ? (string) $row['client_ip'] : null,
            'user_agent' => is_string($row['user_agent'] ?? null) ? (string) $row['user_agent'] : null,
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
            'can_edit_themes' => (string) ($row['role_slug'] ?? 'user') === 'admin'
                || ((int) ($row['theme_editor_enabled'] ?? 0)) === 1
                || (bool) (($tenantPayload['permissions']['edit_themes'] ?? false)),
            'avatar_path' => is_string($row['avatar_path'] ?? null) ? (string) $row['avatar_path'] : null,
            'post_logout_landing_url' => (static function (array $source): string {
                $sessionUrl = is_string($source['session_post_logout_landing_url'] ?? null)
                    ? trim((string) $source['session_post_logout_landing_url'])
                    : '';
                if ($sessionUrl !== '') {
                    return $sessionUrl;
                }
                return is_string($source['user_post_logout_landing_url'] ?? null)
                    ? trim((string) $source['user_post_logout_landing_url'])
                    : '';
            })($row),
            'account_type' => $accountType,
            'is_guest' => $accountType === 'guest',
            'tenant' => $tenantPayload,
        ],
        'tenant' => $tenantPayload,
    ];
}

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   token: string,
 *   session: array<string, mixed>|null,
 *   user: array<string, mixed>|null
 * }
 */
function videochat_authenticate_request(PDO $pdo, array $request, string $transport): array
{
    $token = videochat_extract_session_token($request, $transport);
    if ($token === '') {
        return [
            'ok' => false,
            'reason' => 'missing_session',
            'token' => '',
            'session' => null,
            'user' => null,
        ];
    }

    $validation = videochat_validate_session_token($pdo, $token);
    return [
        'ok' => (bool) $validation['ok'],
        'reason' => (string) $validation['reason'],
        'token' => $token,
        'session' => $validation['session'],
        'user' => $validation['user'],
        'tenant' => $validation['tenant'] ?? null,
    ];
}

function videochat_revoke_session(PDO $pdo, string $sessionId, ?string $revokedAt = null): array
{
    $trimmedSessionId = trim($sessionId);
    if ($trimmedSessionId === '') {
        return [
            'ok' => false,
            'reason' => 'missing_session',
            'revoked_at' => null,
        ];
    }

    $select = $pdo->prepare('SELECT revoked_at FROM sessions WHERE id = :session_id LIMIT 1');
    $select->execute([':session_id' => $trimmedSessionId]);
    $row = $select->fetch();
    if (!is_array($row)) {
        return [
            'ok' => false,
            'reason' => 'invalid_session',
            'revoked_at' => null,
        ];
    }

    $existingRevokedAt = is_string($row['revoked_at'] ?? null) ? trim((string) $row['revoked_at']) : '';
    if ($existingRevokedAt !== '') {
        videochat_mark_session_revoked_locally($trimmedSessionId, $existingRevokedAt);
        return [
            'ok' => true,
            'reason' => 'already_revoked',
            'revoked_at' => $existingRevokedAt,
        ];
    }

    $effectiveRevokedAt = trim((string) ($revokedAt ?? gmdate('c')));
    if ($effectiveRevokedAt === '') {
        $effectiveRevokedAt = gmdate('c');
    }

    $update = $pdo->prepare(
        'UPDATE sessions SET revoked_at = :revoked_at WHERE id = :session_id AND (revoked_at IS NULL OR revoked_at = \'\')'
    );
    $update->execute([
        ':revoked_at' => $effectiveRevokedAt,
        ':session_id' => $trimmedSessionId,
    ]);
    videochat_mark_session_revoked_locally($trimmedSessionId, $effectiveRevokedAt);

    return [
        'ok' => true,
        'reason' => 'revoked',
        'revoked_at' => $effectiveRevokedAt,
    ];
}

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   replaced_session_id: string,
 *   revoked_at: ?string,
 *   new_session: array{
 *     id: string,
 *     token: string,
 *     token_type: string,
 *     issued_at: string,
 *     expires_at: string,
 *     expires_in_seconds: int
 *   }|null
 * }
 */
function videochat_rotate_session_token(
    PDO $pdo,
    string $currentSessionId,
    int $userId,
    callable $issueSessionId,
    ?int $ttlSeconds = null,
    ?string $clientIp = null,
    ?string $userAgent = null,
    ?int $nowUnix = null,
    ?int $activeTenantId = null
): array {
    $trimmedCurrentSessionId = trim($currentSessionId);
    if ($trimmedCurrentSessionId === '') {
        return [
            'ok' => false,
            'reason' => 'missing_session',
            'replaced_session_id' => '',
            'revoked_at' => null,
            'new_session' => null,
        ];
    }
    if ($userId <= 0) {
        return [
            'ok' => false,
            'reason' => 'invalid_user',
            'replaced_session_id' => $trimmedCurrentSessionId,
            'revoked_at' => null,
            'new_session' => null,
        ];
    }
    if (videochat_session_revoked_locally($trimmedCurrentSessionId)) {
        return [
            'ok' => false,
            'reason' => 'session_not_rotatable',
            'replaced_session_id' => $trimmedCurrentSessionId,
            'revoked_at' => null,
            'new_session' => null,
        ];
    }

    $resolvedTtlSeconds = $ttlSeconds;
    if (!is_int($resolvedTtlSeconds)) {
        $resolvedTtlSeconds = (int) (getenv('VIDEOCHAT_SESSION_TTL_SECONDS') ?: 43_200);
    }
    if ($resolvedTtlSeconds < 60) {
        $resolvedTtlSeconds = 60;
    } elseif ($resolvedTtlSeconds > 2_592_000) {
        $resolvedTtlSeconds = 2_592_000;
    }

    $effectiveNowUnix = $nowUnix ?? time();
    $issuedAt = gmdate('c', $effectiveNowUnix);
    $expiresAt = gmdate('c', $effectiveNowUnix + $resolvedTtlSeconds);
    $revokedAt = $issuedAt;
    $tenant = videochat_tenant_context_for_user($pdo, $userId, $activeTenantId);
    if ($tenant === null) {
        return [
            'ok' => false,
            'reason' => 'tenant_membership_inactive',
            'replaced_session_id' => $trimmedCurrentSessionId,
            'revoked_at' => null,
            'new_session' => null,
        ];
    }
    $resolvedTenantId = (int) ($tenant['id'] ?? 0);

    $trimmedClientIp = trim((string) ($clientIp ?? ''));
    $trimmedUserAgent = substr(trim((string) ($userAgent ?? '')), 0, 500);

    $startedTransaction = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTransaction = true;
    }

    try {
        $rotateCurrent = $pdo->prepare(
            <<<'SQL'
UPDATE sessions
SET revoked_at = :revoked_at
WHERE id = :session_id
  AND user_id = :user_id
  AND (revoked_at IS NULL OR revoked_at = '')
  AND datetime(expires_at) > datetime(:issued_at)
SQL
        );
        $rotateCurrent->execute([
            ':revoked_at' => $revokedAt,
            ':session_id' => $trimmedCurrentSessionId,
            ':user_id' => $userId,
            ':issued_at' => $issuedAt,
        ]);

        if ($rotateCurrent->rowCount() !== 1) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return [
                'ok' => false,
                'reason' => 'session_not_rotatable',
                'replaced_session_id' => $trimmedCurrentSessionId,
                'revoked_at' => null,
                'new_session' => null,
            ];
        }

        $newSessionId = '';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidateId = trim((string) $issueSessionId());
            if ($candidateId === '' || $candidateId === $trimmedCurrentSessionId) {
                continue;
            }
            $newSessionId = $candidateId;
            break;
        }
        if ($newSessionId === '') {
            throw new RuntimeException('Could not issue a distinct session token.');
        }

        $hasActiveTenantColumn = videochat_tenant_table_has_column($pdo, 'sessions', 'active_tenant_id');
        $insertSql = $hasActiveTenantColumn
            ? 'INSERT INTO sessions(id, user_id, active_tenant_id, issued_at, expires_at, revoked_at, client_ip, user_agent) VALUES(:id, :user_id, :active_tenant_id, :issued_at, :expires_at, NULL, :client_ip, :user_agent)'
            : 'INSERT INTO sessions(id, user_id, issued_at, expires_at, revoked_at, client_ip, user_agent) VALUES(:id, :user_id, :issued_at, :expires_at, NULL, :client_ip, :user_agent)';
        $insertParams = [
            ':id' => $newSessionId,
            ':user_id' => $userId,
            ':issued_at' => $issuedAt,
            ':expires_at' => $expiresAt,
            ':client_ip' => $trimmedClientIp === '' ? null : $trimmedClientIp,
            ':user_agent' => $trimmedUserAgent === '' ? null : $trimmedUserAgent,
        ];
        if ($hasActiveTenantColumn) {
            $insertParams[':active_tenant_id'] = $resolvedTenantId;
        }
        $insertNew = $pdo->prepare($insertSql);
        $insertNew->execute($insertParams);

        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->commit();
        }
        videochat_mark_session_revoked_locally($trimmedCurrentSessionId, $revokedAt);
        videochat_mark_session_issued_locally(
            $newSessionId,
            $userId,
            $issuedAt,
            $expiresAt,
            $trimmedClientIp === '' ? null : $trimmedClientIp,
            $trimmedUserAgent === '' ? null : $trimmedUserAgent,
            $resolvedTenantId
        );

        return [
            'ok' => true,
            'reason' => 'rotated',
            'replaced_session_id' => $trimmedCurrentSessionId,
            'revoked_at' => $revokedAt,
            'new_session' => [
                'id' => $newSessionId,
                'token' => $newSessionId,
                'token_type' => 'session_id',
                'issued_at' => $issuedAt,
                'expires_at' => $expiresAt,
                'expires_in_seconds' => $resolvedTtlSeconds,
                'active_tenant_id' => $resolvedTenantId,
            ],
        ];
    } catch (Throwable) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [
            'ok' => false,
            'reason' => 'rotation_failed',
            'replaced_session_id' => $trimmedCurrentSessionId,
            'revoked_at' => null,
            'new_session' => null,
        ];
    }
}

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   session: array{
 *     id: string,
 *     token: string,
 *     token_type: string,
 *     issued_at: string,
 *     expires_at: string,
 *     expires_in_seconds: int
 *   }|null
 * }
 */
function videochat_issue_session_for_user(
    PDO $pdo,
    int $userId,
    callable $issueSessionId,
    ?int $ttlSeconds = null,
    ?string $clientIp = null,
    ?string $userAgent = null,
    ?int $nowUnix = null,
    ?int $activeTenantId = null
): array {
    if ($userId <= 0) {
        return [
            'ok' => false,
            'reason' => 'invalid_user',
            'session' => null,
        ];
    }

    $resolvedTtlSeconds = $ttlSeconds;
    if (!is_int($resolvedTtlSeconds)) {
        $resolvedTtlSeconds = (int) (getenv('VIDEOCHAT_SESSION_TTL_SECONDS') ?: 43_200);
    }
    if ($resolvedTtlSeconds < 60) {
        $resolvedTtlSeconds = 60;
    } elseif ($resolvedTtlSeconds > 2_592_000) {
        $resolvedTtlSeconds = 2_592_000;
    }

    $effectiveNow = $nowUnix ?? time();
    $issuedAt = gmdate('c', $effectiveNow);
    $expiresAt = gmdate('c', $effectiveNow + $resolvedTtlSeconds);
    $trimmedClientIp = trim((string) ($clientIp ?? ''));
    $trimmedUserAgent = substr(trim((string) ($userAgent ?? '')), 0, 500);
    $tenant = videochat_tenant_context_for_user($pdo, $userId, $activeTenantId);
    if ($tenant === null) {
        return [
            'ok' => false,
            'reason' => 'tenant_membership_inactive',
            'session' => null,
        ];
    }
    $resolvedTenantId = (int) ($tenant['id'] ?? 0);

    $sessionId = '';
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $candidate = trim((string) $issueSessionId());
        if ($candidate !== '') {
            $sessionId = $candidate;
            break;
        }
    }
    if ($sessionId === '') {
        return [
            'ok' => false,
            'reason' => 'session_issue_failed',
            'session' => null,
        ];
    }

    try {
        $hasActiveTenantColumn = videochat_tenant_table_has_column($pdo, 'sessions', 'active_tenant_id');
        $insertSql = $hasActiveTenantColumn
            ? 'INSERT INTO sessions(id, user_id, active_tenant_id, issued_at, expires_at, revoked_at, client_ip, user_agent) VALUES(:id, :user_id, :active_tenant_id, :issued_at, :expires_at, NULL, :client_ip, :user_agent)'
            : 'INSERT INTO sessions(id, user_id, issued_at, expires_at, revoked_at, client_ip, user_agent) VALUES(:id, :user_id, :issued_at, :expires_at, NULL, :client_ip, :user_agent)';
        $insertParams = [
            ':id' => $sessionId,
            ':user_id' => $userId,
            ':issued_at' => $issuedAt,
            ':expires_at' => $expiresAt,
            ':client_ip' => $trimmedClientIp === '' ? null : $trimmedClientIp,
            ':user_agent' => $trimmedUserAgent === '' ? null : $trimmedUserAgent,
        ];
        if ($hasActiveTenantColumn) {
            $insertParams[':active_tenant_id'] = $resolvedTenantId;
        }
        $insert = $pdo->prepare($insertSql);
        $insert->execute($insertParams);
    } catch (Throwable) {
        return [
            'ok' => false,
            'reason' => 'insert_failed',
            'session' => null,
        ];
    }
    videochat_mark_session_issued_locally(
        $sessionId,
        $userId,
        $issuedAt,
        $expiresAt,
        $trimmedClientIp === '' ? null : $trimmedClientIp,
        $trimmedUserAgent === '' ? null : $trimmedUserAgent,
        $resolvedTenantId
    );

    return [
        'ok' => true,
        'reason' => 'issued',
        'session' => [
            'id' => $sessionId,
            'token' => $sessionId,
            'token_type' => 'session_id',
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
            'expires_in_seconds' => $resolvedTtlSeconds,
            'active_tenant_id' => $resolvedTenantId,
        ],
    ];
}

function videochat_register_active_websocket(
    array &$activeWebsocketsBySession,
    string $sessionId,
    mixed $websocket,
    ?string $connectionId = null
): string {
    $trimmedSessionId = trim($sessionId);
    if ($trimmedSessionId === '') {
        return '';
    }

    $effectiveConnectionId = trim((string) ($connectionId ?? ''));
    if ($effectiveConnectionId === '') {
        $effectiveConnectionId = 'ws_' . hash('sha1', uniqid((string) mt_rand(), true) . microtime(true));
    }

    if (!isset($activeWebsocketsBySession[$trimmedSessionId]) || !is_array($activeWebsocketsBySession[$trimmedSessionId])) {
        $activeWebsocketsBySession[$trimmedSessionId] = [];
    }

    $activeWebsocketsBySession[$trimmedSessionId][$effectiveConnectionId] = $websocket;
    return $effectiveConnectionId;
}

function videochat_unregister_active_websocket(
    array &$activeWebsocketsBySession,
    string $sessionId,
    string $connectionId
): void {
    $trimmedSessionId = trim($sessionId);
    $trimmedConnectionId = trim($connectionId);
    if ($trimmedSessionId === '' || $trimmedConnectionId === '') {
        return;
    }

    if (!isset($activeWebsocketsBySession[$trimmedSessionId]) || !is_array($activeWebsocketsBySession[$trimmedSessionId])) {
        return;
    }

    unset($activeWebsocketsBySession[$trimmedSessionId][$trimmedConnectionId]);
    if ($activeWebsocketsBySession[$trimmedSessionId] === []) {
        unset($activeWebsocketsBySession[$trimmedSessionId]);
    }
}

function videochat_close_tracked_websockets_for_session(
    array &$activeWebsocketsBySession,
    string $sessionId,
    ?callable $closeWebsocket = null
): int {
    $trimmedSessionId = trim($sessionId);
    if ($trimmedSessionId === '') {
        return 0;
    }

    $tracked = $activeWebsocketsBySession[$trimmedSessionId] ?? null;
    if (!is_array($tracked) || $tracked === []) {
        unset($activeWebsocketsBySession[$trimmedSessionId]);
        return 0;
    }

    if ($closeWebsocket === null) {
        $closeWebsocket = static function (mixed $websocket): bool {
            if (!is_resource($websocket) || !function_exists('king_client_websocket_close')) {
                return false;
            }

            try {
                return king_client_websocket_close($websocket, 1008, 'session_revoked') === true;
            } catch (Throwable) {
                return false;
            }
        };
    }

    $closedCount = 0;
    foreach ($tracked as $websocket) {
        try {
            if ($closeWebsocket($websocket) === true) {
                $closedCount++;
            }
        } catch (Throwable) {
            // Close should be best-effort while revocation remains fail-closed.
        }
    }

    unset($activeWebsocketsBySession[$trimmedSessionId]);
    return $closedCount;
}
