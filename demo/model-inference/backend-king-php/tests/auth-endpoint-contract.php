<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../http/module_auth.php';

function auth_endpoint_contract_assert(bool $cond, string $msg): void
{
    if ($cond) { return; }
    fwrite(STDERR, "[auth-endpoint-contract] FAIL: {$msg}\n");
    exit(1);
}

try {
    $rulesAsserted = 0;

    auth_endpoint_contract_assert(function_exists('model_inference_handle_auth_routes'), 'handler exists');
    auth_endpoint_contract_assert(function_exists('model_inference_auth_extract_bearer_token'), 'bearer extractor exists');
    $rulesAsserted += 2;

    $dbPath = sys_get_temp_dir() . '/auth-endpoint-contract-' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        $pdo = model_inference_open_sqlite_pdo($dbPath);
        model_inference_auth_schema_migrate($pdo);
        $openDatabase = static fn (): PDO => $pdo;
        $jsonResponse = static function (int $status, array $payload): array {
            return ['status' => $status, 'headers' => ['content-type' => 'application/json'], 'body' => json_encode($payload, JSON_UNESCAPED_SLASHES)];
        };
        $errorResponse = static function (int $status, string $code, string $message, array $details = []) use ($jsonResponse): array {
            return $jsonResponse($status, [
                'status' => 'error',
                'error' => ['code' => $code, 'message' => $message, 'details' => $details],
                'time' => gmdate('c'),
            ]);
        };
        $decode = static fn (array $resp): array => json_decode($resp['body'] ?? '{}', true) ?: [];

        // Seed a user.
        $alice = model_inference_auth_create_user($pdo, 'alice', 'alice123', 'Alice', 'user');

        // Bearer extractor coverage.
        auth_endpoint_contract_assert(model_inference_auth_extract_bearer_token([]) === '', 'extract: no headers -> empty');
        auth_endpoint_contract_assert(
            model_inference_auth_extract_bearer_token(['headers' => ['Authorization' => 'Bearer abc123']]) === 'abc123',
            'extract canonical header'
        );
        auth_endpoint_contract_assert(
            model_inference_auth_extract_bearer_token(['headers' => ['authorization' => 'bearer xyz']]) === 'xyz',
            'extract case-insensitive'
        );
        auth_endpoint_contract_assert(
            model_inference_auth_extract_bearer_token(['headers' => ['Authorization' => 'Basic abc']]) === '',
            'extract rejects non-Bearer scheme'
        );
        auth_endpoint_contract_assert(
            model_inference_auth_extract_bearer_token(['headers' => ['Authorization' => '']]) === '',
            'extract empty header'
        );
        $rulesAsserted += 5;

        // Path matching.
        auth_endpoint_contract_assert(
            model_inference_handle_auth_routes('/api/runtime', 'GET', [], $jsonResponse, $errorResponse, $openDatabase) === null,
            'non-auth path returns null'
        );
        $rulesAsserted++;

        // --- /api/auth/login ---
        $r = model_inference_handle_auth_routes('/api/auth/login', 'GET', [], $jsonResponse, $errorResponse, $openDatabase);
        auth_endpoint_contract_assert($r['status'] === 405, 'GET /api/auth/login -> 405');
        auth_endpoint_contract_assert($decode($r)['error']['code'] === 'method_not_allowed', 'login GET error code');
        $rulesAsserted += 2;

        $r = model_inference_handle_auth_routes('/api/auth/login', 'POST', ['body' => ''], $jsonResponse, $errorResponse, $openDatabase);
        auth_endpoint_contract_assert($r['status'] === 400 && $decode($r)['error']['code'] === 'invalid_request_envelope', 'empty body -> 400');
        $rulesAsserted++;

        $r = model_inference_handle_auth_routes('/api/auth/login', 'POST', ['body' => 'not-json'], $jsonResponse, $errorResponse, $openDatabase);
        auth_endpoint_contract_assert($r['status'] === 400, 'non-JSON body -> 400');
        $rulesAsserted++;

        $r = model_inference_handle_auth_routes('/api/auth/login', 'POST', ['body' => json_encode(['username' => 'alice', 'password' => 'alice123', 'extra' => true])], $jsonResponse, $errorResponse, $openDatabase);
        auth_endpoint_contract_assert($r['status'] === 400, 'unknown key -> 400');
        auth_endpoint_contract_assert($decode($r)['error']['details']['reason'] === 'unknown_top_level_key', 'unknown key reason pinned');
        $rulesAsserted += 2;

        foreach ([
            ['password' => 'alice123'],
            ['username' => 'alice'],
            ['username' => '', 'password' => 'alice123'],
            ['username' => 'alice', 'password' => ''],
            ['username' => 'x', 'password' => 'alice123'],           // too short
            ['username' => str_repeat('a', 65), 'password' => 'x'],   // too long
        ] as $bad) {
            $r = model_inference_handle_auth_routes('/api/auth/login', 'POST', ['body' => json_encode($bad)], $jsonResponse, $errorResponse, $openDatabase);
            auth_endpoint_contract_assert($r['status'] === 400, 'invalid login payload -> 400: ' . json_encode($bad));
            $rulesAsserted++;
        }

        // Wrong credentials -> 401 invalid_credentials.
        $r = model_inference_handle_auth_routes('/api/auth/login', 'POST', ['body' => json_encode(['username' => 'alice', 'password' => 'wrong'])], $jsonResponse, $errorResponse, $openDatabase);
        auth_endpoint_contract_assert($r['status'] === 401, 'wrong password -> 401');
        auth_endpoint_contract_assert($decode($r)['error']['code'] === 'invalid_credentials', 'wrong password code');
        $rulesAsserted += 2;

        // Unknown user -> same 401 invalid_credentials.
        $r = model_inference_handle_auth_routes('/api/auth/login', 'POST', ['body' => json_encode(['username' => 'ghost', 'password' => 'alice123'])], $jsonResponse, $errorResponse, $openDatabase);
        auth_endpoint_contract_assert($r['status'] === 401 && $decode($r)['error']['code'] === 'invalid_credentials', 'unknown user -> 401 invalid_credentials (same code)');
        $rulesAsserted++;

        // Successful login.
        $r = model_inference_handle_auth_routes(
            '/api/auth/login', 'POST',
            [
                'body' => json_encode(['username' => 'alice', 'password' => 'alice123']),
                'headers' => ['User-Agent' => 'phpunit', 'Authorization' => 'Bearer ignored-on-login'],
                'client_ip' => '10.0.0.7',
            ],
            $jsonResponse, $errorResponse, $openDatabase
        );
        auth_endpoint_contract_assert($r['status'] === 200, 'valid login -> 200');
        $body = $decode($r);
        auth_endpoint_contract_assert($body['status'] === 'ok', 'status=ok');
        auth_endpoint_contract_assert(isset($body['session']['id']) && preg_match('/^[a-f0-9]{32}$/', $body['session']['id']), 'session.id 32-hex');
        auth_endpoint_contract_assert($body['session']['ttl_seconds'] >= 60, 'ttl_seconds sane');
        auth_endpoint_contract_assert($body['session']['user_id'] === $alice['id'], 'session.user_id matches');
        auth_endpoint_contract_assert($body['user']['username'] === 'alice' && $body['user']['role'] === 'user', 'user envelope');
        auth_endpoint_contract_assert(!array_key_exists('password_hash', $body['user']), 'user envelope strips password_hash');
        $rulesAsserted += 6;

        $aliceToken = $body['session']['id'];

        // --- /api/auth/whoami ---
        $r = model_inference_handle_auth_routes('/api/auth/whoami', 'POST', [], $jsonResponse, $errorResponse, $openDatabase);
        auth_endpoint_contract_assert($r['status'] === 405, 'POST /whoami -> 405');
        $rulesAsserted++;

        $r = model_inference_handle_auth_routes('/api/auth/whoami', 'GET', [], $jsonResponse, $errorResponse, $openDatabase);
        auth_endpoint_contract_assert($r['status'] === 401, 'whoami without token -> 401');
        auth_endpoint_contract_assert($decode($r)['error']['code'] === 'invalid_credentials', 'whoami missing bearer code');
        $rulesAsserted += 2;

        $r = model_inference_handle_auth_routes('/api/auth/whoami', 'GET', [
            'headers' => ['Authorization' => 'Bearer nope'],
        ], $jsonResponse, $errorResponse, $openDatabase);
        auth_endpoint_contract_assert($r['status'] === 401, 'whoami with bogus token -> 401');
        auth_endpoint_contract_assert($decode($r)['error']['code'] === 'session_expired', 'whoami bogus token code = session_expired');
        $rulesAsserted += 2;

        $r = model_inference_handle_auth_routes('/api/auth/whoami', 'GET', [
            'headers' => ['Authorization' => 'Bearer ' . $aliceToken],
        ], $jsonResponse, $errorResponse, $openDatabase);
        auth_endpoint_contract_assert($r['status'] === 200, 'whoami with valid token -> 200');
        $who = $decode($r);
        auth_endpoint_contract_assert($who['user']['username'] === 'alice', 'whoami returns alice');
        auth_endpoint_contract_assert($who['session']['id'] === $aliceToken, 'whoami returns same token');
        $rulesAsserted += 3;

        // --- /api/auth/logout ---
        $r = model_inference_handle_auth_routes('/api/auth/logout', 'GET', [], $jsonResponse, $errorResponse, $openDatabase);
        auth_endpoint_contract_assert($r['status'] === 405, 'GET /logout -> 405');
        $rulesAsserted++;

        // Idempotent: no token returns 200 with already_revoked.
        $r = model_inference_handle_auth_routes('/api/auth/logout', 'POST', [], $jsonResponse, $errorResponse, $openDatabase);
        auth_endpoint_contract_assert($r['status'] === 200, 'logout without token -> 200');
        auth_endpoint_contract_assert($decode($r)['revocation_state'] === 'already_revoked', 'no-token logout state');
        $rulesAsserted += 2;

        // Real revoke.
        $r = model_inference_handle_auth_routes('/api/auth/logout', 'POST', [
            'headers' => ['Authorization' => 'Bearer ' . $aliceToken],
        ], $jsonResponse, $errorResponse, $openDatabase);
        auth_endpoint_contract_assert($r['status'] === 200, 'logout live token -> 200');
        auth_endpoint_contract_assert($decode($r)['revocation_state'] === 'revoked', 'logout -> revoked');
        auth_endpoint_contract_assert(is_string($decode($r)['revoked_at']), 'revoked_at populated');
        $rulesAsserted += 3;

        // Re-logout is idempotent.
        $r = model_inference_handle_auth_routes('/api/auth/logout', 'POST', [
            'headers' => ['Authorization' => 'Bearer ' . $aliceToken],
        ], $jsonResponse, $errorResponse, $openDatabase);
        auth_endpoint_contract_assert($decode($r)['revocation_state'] === 'already_revoked', 're-logout -> already_revoked');
        $rulesAsserted++;

        // whoami after logout -> 401.
        $r = model_inference_handle_auth_routes('/api/auth/whoami', 'GET', [
            'headers' => ['Authorization' => 'Bearer ' . $aliceToken],
        ], $jsonResponse, $errorResponse, $openDatabase);
        auth_endpoint_contract_assert($r['status'] === 401, 'whoami after logout -> 401');
        $rulesAsserted++;
    } finally {
        @unlink($dbPath);
    }

    fwrite(STDOUT, "[auth-endpoint-contract] PASS ({$rulesAsserted} rules asserted)\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[auth-endpoint-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
