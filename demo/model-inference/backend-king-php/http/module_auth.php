<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/auth/auth_store.php';

/**
 * #A-2 auth HTTP surface.
 *
 *   POST /api/auth/login    — {username, password} → session + user
 *   POST /api/auth/logout   — Bearer token → revoke
 *   GET  /api/auth/whoami   — Bearer token → user + session
 *
 * Contract JSON: contracts/v1/auth-request.contract.json and
 * contracts/v1/user-session.contract.json.
 *
 * Scope fence: this module does NOT block any other route. Auth is
 * OPTIONAL across /api/infer, /api/rag, /api/discover,
 * /api/conversations/*. Only these three endpoints under /api/auth/*
 * enforce credential flow.
 */
function model_inference_handle_auth_routes(
    string $path,
    string $method,
    array $request,
    callable $jsonResponse,
    callable $errorResponse,
    callable $openDatabase
): ?array {
    if ($path !== '/api/auth/login' && $path !== '/api/auth/logout' && $path !== '/api/auth/whoami') {
        return null;
    }

    $pdo = $openDatabase();
    model_inference_auth_schema_migrate($pdo);

    if ($path === '/api/auth/login') {
        return model_inference_handle_auth_login($method, $request, $jsonResponse, $errorResponse, $pdo);
    }
    if ($path === '/api/auth/logout') {
        return model_inference_handle_auth_logout($method, $request, $jsonResponse, $errorResponse, $pdo);
    }
    return model_inference_handle_auth_whoami($method, $request, $jsonResponse, $errorResponse, $pdo);
}

function model_inference_handle_auth_login(
    string $method,
    array $request,
    callable $jsonResponse,
    callable $errorResponse,
    PDO $pdo
): array {
    if ($method !== 'POST') {
        return $errorResponse(405, 'method_not_allowed', 'POST required.', [
            'path' => '/api/auth/login', 'method' => $method, 'allowed' => ['POST'],
        ]);
    }
    $body = $request['body'] ?? '';
    if (!is_string($body) || trim($body) === '') {
        return $errorResponse(400, 'invalid_request_envelope', 'POST /api/auth/login requires a JSON body.', [
            'field' => '', 'reason' => 'empty_body',
        ]);
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return $errorResponse(400, 'invalid_request_envelope', 'POST /api/auth/login body is not valid JSON.', [
            'field' => '', 'reason' => 'invalid_json',
        ]);
    }
    foreach (array_keys($decoded) as $k) {
        if (!in_array($k, ['username', 'password'], true)) {
            return $errorResponse(400, 'invalid_request_envelope', "unknown top-level key: {$k}", [
                'field' => (string) $k, 'reason' => 'unknown_top_level_key',
            ]);
        }
    }
    if (!isset($decoded['username']) || !is_string($decoded['username']) || $decoded['username'] === '') {
        return $errorResponse(400, 'invalid_request_envelope', 'username is required.', ['field' => 'username']);
    }
    if (!isset($decoded['password']) || !is_string($decoded['password']) || $decoded['password'] === '') {
        return $errorResponse(400, 'invalid_request_envelope', 'password is required.', ['field' => 'password']);
    }
    $username = $decoded['username'];
    $password = $decoded['password'];
    if (strlen($username) < 2 || strlen($username) > 64) {
        return $errorResponse(400, 'invalid_request_envelope', 'username must be 2..64 chars.', ['field' => 'username']);
    }
    if (strlen($password) < 1 || strlen($password) > 256) {
        return $errorResponse(400, 'invalid_request_envelope', 'password length out of range.', ['field' => 'password']);
    }

    $user = model_inference_auth_verify_credentials($pdo, $username, $password);
    if ($user === null) {
        return $errorResponse(401, 'invalid_credentials', 'Invalid username or password.', [
            'reason' => 'verify_failed',
        ]);
    }
    $clientIp = model_inference_auth_extract_client_ip($request);
    $userAgent = model_inference_auth_extract_user_agent($request);
    $session = model_inference_auth_issue_session(
        $pdo, (int) $user['id'], null, $clientIp, $userAgent
    );
    return $jsonResponse(200, [
        'status' => 'ok',
        'session' => [
            'id' => $session['id'],
            'user_id' => $session['user_id'],
            'issued_at' => $session['issued_at'],
            'expires_at' => $session['expires_at'],
            'ttl_seconds' => $session['ttl_seconds'],
            'revoked_at' => null,
        ],
        'user' => $user,
        'time' => gmdate('c'),
    ]);
}

function model_inference_handle_auth_logout(
    string $method,
    array $request,
    callable $jsonResponse,
    callable $errorResponse,
    PDO $pdo
): array {
    if ($method !== 'POST') {
        return $errorResponse(405, 'method_not_allowed', 'POST required.', [
            'path' => '/api/auth/logout', 'method' => $method, 'allowed' => ['POST'],
        ]);
    }
    $token = model_inference_auth_extract_bearer_token($request);
    if ($token === '') {
        return $jsonResponse(200, [
            'status' => 'ok',
            'revocation_state' => 'already_revoked',
            'revoked_at' => null,
            'time' => gmdate('c'),
        ]);
    }
    $revoked = model_inference_auth_revoke_session($pdo, $token);
    return $jsonResponse(200, [
        'status' => 'ok',
        'revocation_state' => $revoked ? 'revoked' : 'already_revoked',
        'revoked_at' => $revoked ? gmdate('c') : null,
        'time' => gmdate('c'),
    ]);
}

function model_inference_handle_auth_whoami(
    string $method,
    array $request,
    callable $jsonResponse,
    callable $errorResponse,
    PDO $pdo
): array {
    if ($method !== 'GET') {
        return $errorResponse(405, 'method_not_allowed', 'GET required.', [
            'path' => '/api/auth/whoami', 'method' => $method, 'allowed' => ['GET'],
        ]);
    }
    $token = model_inference_auth_extract_bearer_token($request);
    if ($token === '') {
        return $errorResponse(401, 'invalid_credentials', 'Authorization: Bearer <token> is required.', [
            'field' => 'authorization', 'reason' => 'missing_bearer',
        ]);
    }
    $ctx = model_inference_auth_validate_session($pdo, $token);
    if ($ctx === null) {
        return $errorResponse(401, 'session_expired', 'Session is expired, revoked, or unknown.', [
            'field' => 'authorization', 'reason' => 'validate_failed',
        ]);
    }
    return $jsonResponse(200, [
        'status' => 'ok',
        'user' => $ctx['user'],
        'session' => $ctx['session'],
        'time' => gmdate('c'),
    ]);
}

/**
 * Extract an Authorization: Bearer <token> value from a request's
 * headers. Case-insensitive header lookup. Returns '' when absent.
 *
 * @param array<string, mixed> $request
 */
function model_inference_auth_extract_bearer_token(array $request): string
{
    $headers = (array) ($request['headers'] ?? []);
    $authValue = '';
    foreach ($headers as $name => $value) {
        if (!is_string($name)) {
            continue;
        }
        if (strcasecmp($name, 'authorization') === 0) {
            $authValue = is_array($value) ? (string) reset($value) : (string) $value;
            break;
        }
    }
    $authValue = trim($authValue);
    if ($authValue !== '' && stripos($authValue, 'Bearer ') === 0) {
        $token = trim(substr($authValue, 7));
        if ($token !== '') {
            return $token;
        }
    }
    // #A-5 WebSocket fallback: browsers cannot attach custom headers to
    // the WS upgrade, so we also accept ?auth_token=<32-hex> in the URI.
    // Only 32-hex tokens (the shape issued by #A-1) are honored.
    $uri = (string) ($request['uri'] ?? $request['path'] ?? '');
    $qPos = strpos($uri, '?');
    if ($qPos !== false) {
        $q = [];
        parse_str(substr($uri, $qPos + 1), $q);
        $candidate = isset($q['auth_token']) ? trim((string) $q['auth_token']) : '';
        if ($candidate !== '' && preg_match('/^[a-f0-9]{32}$/', $candidate) === 1) {
            return $candidate;
        }
    }
    return '';
}

/**
 * @param array<string, mixed> $request
 */
function model_inference_auth_extract_client_ip(array $request): ?string
{
    $candidates = [
        $request['client_ip'] ?? null,
        $request['remote_addr'] ?? null,
    ];
    foreach ($candidates as $c) {
        if (is_string($c) && $c !== '' && strlen($c) <= 64) {
            return $c;
        }
    }
    return null;
}

/**
 * @param array<string, mixed> $request
 */
function model_inference_auth_extract_user_agent(array $request): ?string
{
    $headers = (array) ($request['headers'] ?? []);
    foreach ($headers as $name => $value) {
        if (is_string($name) && strcasecmp($name, 'user-agent') === 0) {
            $ua = is_array($value) ? (string) reset($value) : (string) $value;
            return $ua === '' ? null : substr($ua, 0, 512);
        }
    }
    return null;
}
