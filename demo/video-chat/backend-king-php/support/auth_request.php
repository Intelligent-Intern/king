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
