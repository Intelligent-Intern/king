<?php

declare(strict_types=1);

function videochat_request_header_value(array $request, string $headerName): string
{
    $headers = $request['headers'] ?? null;
    if (!is_array($headers) || $headerName === '') {
        return '';
    }

    foreach ($headers as $name => $value) {
        if (strcasecmp((string) $name, $headerName) !== 0) {
            continue;
        }

        if (is_string($value)) {
            return trim($value);
        }
        if (is_array($value)) {
            $flat = [];
            foreach ($value as $item) {
                if (is_scalar($item)) {
                    $flat[] = trim((string) $item);
                }
            }
            $flat = array_values(array_filter($flat, static fn (string $item): bool => $item !== ''));
            return trim(implode(', ', $flat));
        }
        if (is_scalar($value)) {
            return trim((string) $value);
        }
    }

    return '';
}

/**
 * @return array<string, scalar|null>
 */
function videochat_request_query_params(array $request): array
{
    $uri = $request['uri'] ?? null;
    if (!is_string($uri) || $uri === '') {
        return [];
    }

    $query = parse_url($uri, PHP_URL_QUERY);
    if (!is_string($query) || $query === '') {
        return [];
    }

    $parsed = [];
    parse_str($query, $parsed);
    if (!is_array($parsed)) {
        return [];
    }

    $normalized = [];
    foreach ($parsed as $key => $value) {
        if (!is_string($key) || $key === '') {
            continue;
        }
        if (is_scalar($value) || $value === null) {
            $normalized[$key] = $value;
        }
    }

    return $normalized;
}

function videochat_extract_session_token(array $request, string $transport): string
{
    $authorization = videochat_request_header_value($request, 'authorization');
    if ($authorization !== '' && preg_match('/^\s*Bearer\s+(.+)\s*$/i', $authorization, $matches) === 1) {
        $token = trim((string) ($matches[1] ?? ''));
        if ($token !== '') {
            return $token;
        }
    }

    $sessionHeader = videochat_request_header_value($request, 'x-session-id');
    if ($sessionHeader !== '') {
        return $sessionHeader;
    }

    if ($transport === 'websocket') {
        $query = videochat_request_query_params($request);
        foreach (['session', 'token', 'session_id'] as $key) {
            $value = $query[$key] ?? null;
            if (!is_string($value)) {
                continue;
            }
            $trimmed = trim($value);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }
    }

    return '';
}

function videochat_user_account_type(?string $email, mixed $passwordHash): string
{
    $normalizedEmail = strtolower(trim((string) ($email ?? '')));
    $storedHash = is_string($passwordHash) ? trim($passwordHash) : '';
    if ($storedHash === '' && str_starts_with($normalizedEmail, 'guest+') && str_ends_with($normalizedEmail, '@videochat.local')) {
        return 'guest';
    }

    return 'account';
}

function videochat_user_is_guest_account(?string $email, mixed $passwordHash): bool
{
    return videochat_user_account_type($email, $passwordHash) === 'guest';
}

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   session: array{
 *     id: string,
 *     issued_at: string,
 *     expires_at: string,
 *     revoked_at: ?string,
 *     client_ip: ?string,
 *     user_agent: ?string
 *   }|null,
 *   user: array{
 *     id: int,
 *     email: string,
 *     display_name: string,
 *     role: string,
 *     status: string,
 *     time_format: string,
 *     date_format: string,
 *     theme: string,
 *     avatar_path: ?string,
 *     account_type: string,
 *     is_guest: bool
 *   }|null
 * }
 */
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

    $query = $pdo->prepare(
        <<<'SQL'
SELECT
    sessions.id,
    sessions.issued_at,
    sessions.expires_at,
    sessions.revoked_at,
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
    users.avatar_path,
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
        return [
            'ok' => false,
            'reason' => 'invalid_session',
            'session' => null,
            'user' => null,
        ];
    }

    $revokedAt = is_string($row['revoked_at'] ?? null) ? trim((string) $row['revoked_at']) : '';
    if ($revokedAt !== '') {
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

    return [
        'ok' => true,
        'reason' => 'ok',
        'session' => [
            'id' => (string) $row['id'],
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
            'avatar_path' => is_string($row['avatar_path'] ?? null) ? (string) $row['avatar_path'] : null,
            'account_type' => $accountType,
            'is_guest' => $accountType === 'guest',
        ],
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
    ];
}

function videochat_normalize_role_slug(string $role): string
{
    $normalized = strtolower(trim($role));
    return in_array($normalized, ['admin', 'user'], true) ? $normalized : 'unknown';
}

/**
 * @return array<int, array{
 *   id: string,
 *   transport: string,
 *   matcher: string,
 *   allowed_roles: array<int, string>,
 *   path?: string,
 *   paths?: array<int, string>,
 *   prefix?: string
 * }>
 */
function videochat_rbac_permission_matrix(string $wsPath = '/ws'): array
{
    $normalizedWsPath = trim($wsPath);
    if ($normalizedWsPath === '') {
        $normalizedWsPath = '/ws';
    }
    if ($normalizedWsPath[0] !== '/') {
        $normalizedWsPath = '/' . $normalizedWsPath;
    }

    $authenticatedRoles = ['admin', 'user'];

    return [
        [
            'id' => 'rest_auth_session',
            'transport' => 'rest',
            'matcher' => 'exact_any',
            'paths' => ['/api/auth/session', '/api/auth/refresh', '/api/auth/logout'],
            'allowed_roles' => $authenticatedRoles,
        ],
        [
            'id' => 'websocket_gateway',
            'transport' => 'websocket',
            'matcher' => 'exact',
            'path' => $normalizedWsPath,
            'allowed_roles' => $authenticatedRoles,
        ],
        [
            'id' => 'rest_admin_scope',
            'transport' => 'rest',
            'matcher' => 'prefix',
            'prefix' => '/api/admin/',
            'allowed_roles' => ['admin'],
        ],
        [
            'id' => 'rest_moderation_scope',
            'transport' => 'rest',
            'matcher' => 'prefix',
            'prefix' => '/api/moderation/',
            'allowed_roles' => ['admin'],
        ],
        [
            'id' => 'rest_calls_collection',
            'transport' => 'rest',
            'matcher' => 'exact',
            'path' => '/api/calls',
            'allowed_roles' => $authenticatedRoles,
        ],
        [
            'id' => 'rest_calls_items',
            'transport' => 'rest',
            'matcher' => 'prefix',
            'prefix' => '/api/calls/',
            'allowed_roles' => $authenticatedRoles,
        ],
        [
            'id' => 'rest_invite_codes_collection',
            'transport' => 'rest',
            'matcher' => 'exact',
            'path' => '/api/invite-codes',
            'allowed_roles' => $authenticatedRoles,
        ],
        [
            'id' => 'rest_invite_codes_items',
            'transport' => 'rest',
            'matcher' => 'prefix',
            'prefix' => '/api/invite-codes/',
            'allowed_roles' => $authenticatedRoles,
        ],
        [
            'id' => 'rest_call_access_scope',
            'transport' => 'rest',
            'matcher' => 'prefix',
            'prefix' => '/api/call-access/',
            'allowed_roles' => $authenticatedRoles,
        ],
        [
            'id' => 'rest_user_scope',
            'transport' => 'rest',
            'matcher' => 'prefix',
            'prefix' => '/api/user/',
            'allowed_roles' => $authenticatedRoles,
        ],
    ];
}

/**
 * @return array{
 *   id: string,
 *   transport: string,
 *   matcher: string,
 *   allowed_roles: array<int, string>,
 *   path?: string,
 *   paths?: array<int, string>,
 *   prefix?: string
 * }|null
 */
function videochat_rbac_rule_for_path(string $path, string $wsPath = '/ws'): ?array
{
    $trimmedPath = trim($path);
    if ($trimmedPath === '') {
        return null;
    }

    foreach (videochat_rbac_permission_matrix($wsPath) as $rule) {
        $matcher = (string) ($rule['matcher'] ?? '');
        if ($matcher === 'exact') {
            if ($trimmedPath === (string) ($rule['path'] ?? '')) {
                return $rule;
            }
            continue;
        }

        if ($matcher === 'exact_any') {
            $paths = is_array($rule['paths'] ?? null) ? $rule['paths'] : [];
            if (in_array($trimmedPath, $paths, true)) {
                return $rule;
            }
            continue;
        }

        if ($matcher === 'prefix') {
            $prefix = (string) ($rule['prefix'] ?? '');
            if ($prefix !== '' && str_starts_with($trimmedPath, $prefix)) {
                return $rule;
            }
        }
    }

    return null;
}

/**
 * @return array<int, string>
 */
function videochat_rbac_allowed_roles_for_path(string $path, string $wsPath = '/ws'): array
{
    $rule = videochat_rbac_rule_for_path($path, $wsPath);
    if (!is_array($rule)) {
        return [];
    }

    $allowedRoles = $rule['allowed_roles'] ?? [];
    return is_array($allowedRoles) ? array_values($allowedRoles) : [];
}

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   rule_id: string,
 *   role: string,
 *   allowed_roles: array<int, string>
 * }
 */
function videochat_authorize_role_for_path(array $user, string $path, string $wsPath = '/ws'): array
{
    $rule = videochat_rbac_rule_for_path($path, $wsPath);
    $allowedRoles = is_array($rule['allowed_roles'] ?? null) ? array_values($rule['allowed_roles']) : [];
    $ruleId = is_string($rule['id'] ?? null) ? (string) $rule['id'] : 'not_applicable';
    if ($allowedRoles === []) {
        return [
            'ok' => true,
            'reason' => 'not_applicable',
            'rule_id' => $ruleId,
            'role' => videochat_normalize_role_slug((string) ($user['role'] ?? '')),
            'allowed_roles' => [],
        ];
    }

    $role = videochat_normalize_role_slug((string) ($user['role'] ?? ''));
    if ($role === 'unknown') {
        return [
            'ok' => false,
            'reason' => 'invalid_role',
            'rule_id' => $ruleId,
            'role' => $role,
            'allowed_roles' => $allowedRoles,
        ];
    }
    if (!in_array($role, $allowedRoles, true)) {
        return [
            'ok' => false,
            'reason' => 'role_not_allowed',
            'rule_id' => $ruleId,
            'role' => $role,
            'allowed_roles' => $allowedRoles,
        ];
    }

    return [
        'ok' => true,
        'reason' => 'ok',
        'rule_id' => $ruleId,
        'role' => $role,
        'allowed_roles' => $allowedRoles,
    ];
}

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   revoked_at: ?string
 * }
 */
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
    ?int $nowUnix = null
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

        $insertNew = $pdo->prepare(
            <<<'SQL'
INSERT INTO sessions(id, user_id, issued_at, expires_at, revoked_at, client_ip, user_agent)
VALUES(:id, :user_id, :issued_at, :expires_at, NULL, :client_ip, :user_agent)
SQL
        );
        $insertNew->execute([
            ':id' => $newSessionId,
            ':user_id' => $userId,
            ':issued_at' => $issuedAt,
            ':expires_at' => $expiresAt,
            ':client_ip' => $trimmedClientIp === '' ? null : $trimmedClientIp,
            ':user_agent' => $trimmedUserAgent === '' ? null : $trimmedUserAgent,
        ]);

        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->commit();
        }

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
    ?int $nowUnix = null
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
        $insert = $pdo->prepare(
            <<<'SQL'
INSERT INTO sessions(id, user_id, issued_at, expires_at, revoked_at, client_ip, user_agent)
VALUES(:id, :user_id, :issued_at, :expires_at, NULL, :client_ip, :user_agent)
SQL
        );
        $insert->execute([
            ':id' => $sessionId,
            ':user_id' => $userId,
            ':issued_at' => $issuedAt,
            ':expires_at' => $expiresAt,
            ':client_ip' => $trimmedClientIp === '' ? null : $trimmedClientIp,
            ':user_agent' => $trimmedUserAgent === '' ? null : $trimmedUserAgent,
        ]);
    } catch (Throwable) {
        return [
            'ok' => false,
            'reason' => 'insert_failed',
            'session' => null,
        ];
    }

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
