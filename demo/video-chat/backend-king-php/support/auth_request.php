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

function videochat_auth_table_has_column(PDO $pdo, string $tableName, string $columnName): bool
{
    $safeTable = preg_replace('/[^A-Za-z0-9_]/', '', $tableName);
    if (!is_string($safeTable) || $safeTable === '') {
        return false;
    }

    try {
        $columns = $pdo->query('PRAGMA table_info(' . $safeTable . ')');
        foreach ($columns ?: [] as $column) {
            if (strcasecmp((string) ($column['name'] ?? ''), $columnName) === 0) {
                return true;
            }
        }
    } catch (Throwable) {
        return false;
    }

    return false;
}

function videochat_auth_user_is_superadmin_row(array $row): bool
{
    $role = strtolower(trim((string) ($row['role_slug'] ?? ($row['role'] ?? ''))));
    $status = strtolower(trim((string) ($row['status'] ?? ($row['user_status'] ?? 'active'))));
    if ($role !== 'admin' || $status !== 'active') {
        return false;
    }

    $email = strtolower(trim((string) ($row['email'] ?? '')));
    $passwordHash = is_string($row['password_hash'] ?? null) ? trim((string) $row['password_hash']) : '';
    if (videochat_user_is_guest_account($email, $passwordHash)) {
        return false;
    }

    return ((int) ($row['is_superadmin'] ?? 0)) === 1;
}

function videochat_user_is_superadmin(PDO $pdo, int $userId): bool
{
    if ($userId <= 0 || !videochat_auth_table_has_column($pdo, 'users', 'is_superadmin')) {
        return false;
    }

    try {
        $query = $pdo->prepare(
            <<<'SQL'
SELECT users.email, users.password_hash, users.status, users.is_superadmin, roles.slug AS role_slug
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE users.id = :id
LIMIT 1
SQL
        );
        $query->execute([':id' => $userId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return false;
    }

    return is_array($row) && videochat_auth_user_is_superadmin_row($row);
}

function videochat_auth_context_user_is_superadmin(array $apiAuthContext): bool
{
    $user = is_array($apiAuthContext['user'] ?? null) ? (array) $apiAuthContext['user'] : [];
    return ((int) ($user['is_superadmin'] ?? 0)) === 1
        && strtolower(trim((string) ($user['role'] ?? ''))) === 'admin'
        && strtolower(trim((string) ($user['status'] ?? 'active'))) === 'active';
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
 *     can_edit_themes: bool,
 *     avatar_path: ?string,
 *     account_type: string,
 *     is_guest: bool
 *   }|null
 * }
 */
