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
 *     theme: string,
 *     avatar_path: ?string
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
    users.time_format,
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
            'theme' => is_string($row['theme'] ?? null) ? (string) $row['theme'] : 'dark',
            'avatar_path' => is_string($row['avatar_path'] ?? null) ? (string) $row['avatar_path'] : null,
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
