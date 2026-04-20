<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../domain/auth/auth_middleware.php';

function auth_middleware_contract_assert(bool $cond, string $msg): void
{
    if ($cond) { return; }
    fwrite(STDERR, "[auth-middleware-contract] FAIL: {$msg}\n");
    exit(1);
}

try {
    $rulesAsserted = 0;
    auth_middleware_contract_assert(function_exists('model_inference_auth_apply_middleware'), 'middleware exists');
    $rulesAsserted++;

    $dbPath = sys_get_temp_dir() . '/auth-middleware-contract-' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        $pdo = model_inference_open_sqlite_pdo($dbPath);
        model_inference_auth_schema_migrate($pdo);

        // 1. No headers — anonymous, no 401 raised, just null user.
        $r = model_inference_auth_apply_middleware($pdo, ['headers' => []]);
        auth_middleware_contract_assert($r['user'] === null, 'no header → user=null');
        auth_middleware_contract_assert($r['auth_session'] === null, 'no header → auth_session=null');
        auth_middleware_contract_assert($r['auth_reason'] === 'anonymous', 'no header → reason=anonymous');
        $rulesAsserted += 3;

        // 2. Bogus token — stays anonymous with a distinct reason.
        $r = model_inference_auth_apply_middleware($pdo, ['headers' => ['Authorization' => 'Bearer nope']]);
        auth_middleware_contract_assert($r['user'] === null, 'bogus token → user=null');
        auth_middleware_contract_assert($r['auth_reason'] === 'invalid_or_expired_token', 'bogus token reason');
        $rulesAsserted += 2;

        // 3. Valid token — full hydration.
        $user = model_inference_auth_create_user($pdo, 'alice', 'alice123', 'Alice');
        $session = model_inference_auth_issue_session($pdo, (int) $user['id'], 3600);
        $r = model_inference_auth_apply_middleware($pdo, [
            'headers' => ['Authorization' => 'Bearer ' . $session['id']],
        ]);
        auth_middleware_contract_assert($r['user'] !== null, 'valid token hydrates user');
        auth_middleware_contract_assert($r['user']['username'] === 'alice', 'user.username = alice');
        auth_middleware_contract_assert($r['user']['role'] === 'user', 'user.role = user');
        auth_middleware_contract_assert(!array_key_exists('password_hash', $r['user']), 'user envelope strips password_hash');
        auth_middleware_contract_assert($r['auth_session']['id'] === $session['id'], 'auth_session.id matches');
        auth_middleware_contract_assert($r['auth_reason'] === 'authenticated', 'reason=authenticated');
        $rulesAsserted += 6;

        // 4. Revoked token — back to anonymous + reason.
        model_inference_auth_revoke_session($pdo, $session['id']);
        $r = model_inference_auth_apply_middleware($pdo, [
            'headers' => ['Authorization' => 'Bearer ' . $session['id']],
        ]);
        auth_middleware_contract_assert($r['user'] === null, 'revoked token → user=null');
        auth_middleware_contract_assert($r['auth_reason'] === 'invalid_or_expired_token', 'revoked token reason');
        $rulesAsserted += 2;

        // 5. Expired token — inserted by hand.
        $expired = bin2hex(random_bytes(16));
        $pdo->prepare('INSERT INTO sessions (id, user_id, issued_at, expires_at) VALUES (:id, :uid, :iss, :exp)')->execute([
            ':id' => $expired, ':uid' => (int) $user['id'],
            ':iss' => gmdate('c', time() - 7200),
            ':exp' => gmdate('c', time() - 3600),
        ]);
        $r = model_inference_auth_apply_middleware($pdo, [
            'headers' => ['Authorization' => 'Bearer ' . $expired],
        ]);
        auth_middleware_contract_assert($r['user'] === null, 'expired token → user=null');
        auth_middleware_contract_assert($r['auth_reason'] === 'invalid_or_expired_token', 'expired token reason');
        $rulesAsserted += 2;

        // 6. Non-Bearer scheme is treated as anonymous (middleware never
        //    pretends to accept Basic / Digest).
        $r = model_inference_auth_apply_middleware($pdo, [
            'headers' => ['Authorization' => 'Basic ' . base64_encode('alice:alice123')],
        ]);
        auth_middleware_contract_assert($r['user'] === null, 'Basic scheme → anonymous');
        auth_middleware_contract_assert($r['auth_reason'] === 'anonymous', 'Basic → reason=anonymous');
        $rulesAsserted += 2;

        // 7. Case-insensitive header name.
        $session2 = model_inference_auth_issue_session($pdo, (int) $user['id'], 3600);
        $r = model_inference_auth_apply_middleware($pdo, [
            'headers' => ['authorization' => 'bearer ' . $session2['id']],
        ]);
        auth_middleware_contract_assert($r['user'] !== null && $r['user']['username'] === 'alice', 'case-insensitive header + scheme');
        $rulesAsserted++;

        // 8. Middleware is a pure extension — original request keys survive.
        $r = model_inference_auth_apply_middleware($pdo, [
            'headers' => ['Authorization' => 'Bearer ' . $session2['id']],
            'body' => 'hello',
            'method' => 'POST',
            'path' => '/api/infer',
            'custom_key' => 'keep',
        ]);
        auth_middleware_contract_assert($r['body'] === 'hello', 'body preserved');
        auth_middleware_contract_assert($r['method'] === 'POST', 'method preserved');
        auth_middleware_contract_assert($r['path'] === '/api/infer', 'path preserved');
        auth_middleware_contract_assert($r['custom_key'] === 'keep', 'custom keys preserved');
        $rulesAsserted += 4;

        // 9. Disabled user kills the session.
        $pdo->exec("UPDATE users SET status = 'disabled' WHERE id = " . (int) $user['id']);
        $r = model_inference_auth_apply_middleware($pdo, [
            'headers' => ['Authorization' => 'Bearer ' . $session2['id']],
        ]);
        auth_middleware_contract_assert($r['user'] === null, 'disabled user → anonymous');
        auth_middleware_contract_assert($r['auth_reason'] === 'invalid_or_expired_token', 'disabled user reason');
        $rulesAsserted += 2;
    } finally {
        @unlink($dbPath);
    }

    fwrite(STDOUT, "[auth-middleware-contract] PASS ({$rulesAsserted} rules asserted)\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[auth-middleware-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
