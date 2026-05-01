<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../domain/auth/auth_store.php';

function auth_store_contract_assert(bool $cond, string $msg): void
{
    if ($cond) { return; }
    fwrite(STDERR, "[auth-store-contract] FAIL: {$msg}\n");
    exit(1);
}

try {
    $rulesAsserted = 0;

    foreach ([
        'model_inference_auth_schema_migrate',
        'model_inference_auth_create_user',
        'model_inference_auth_find_user',
        'model_inference_auth_verify_credentials',
        'model_inference_auth_issue_session',
        'model_inference_auth_validate_session',
        'model_inference_auth_revoke_session',
        'model_inference_auth_seed_demo_users',
        'model_inference_auth_default_ttl_seconds',
        'model_inference_auth_allowed_roles',
    ] as $fn) {
        auth_store_contract_assert(function_exists($fn), "{$fn} must exist");
        $rulesAsserted++;
    }

    auth_store_contract_assert(model_inference_auth_allowed_roles() === ['user', 'admin'], 'roles pinned');
    auth_store_contract_assert(model_inference_auth_allowed_statuses() === ['active', 'disabled'], 'statuses pinned');
    $rulesAsserted += 2;

    $dbPath = sys_get_temp_dir() . '/auth-store-contract-' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        $pdo = model_inference_open_sqlite_pdo($dbPath);
        model_inference_auth_schema_migrate($pdo);

        // Schema inspection.
        $usersCols = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name');
        foreach (['id', 'username', 'password_hash', 'display_name', 'role', 'status', 'created_at', 'updated_at'] as $c) {
            auth_store_contract_assert(in_array($c, $usersCols, true), "users column {$c}");
            $rulesAsserted++;
        }
        $sessionsCols = array_column($pdo->query('PRAGMA table_info(sessions)')->fetchAll(), 'name');
        foreach (['id', 'user_id', 'issued_at', 'expires_at', 'revoked_at', 'client_ip', 'user_agent'] as $c) {
            auth_store_contract_assert(in_array($c, $sessionsCols, true), "sessions column {$c}");
            $rulesAsserted++;
        }

        // Idempotent migrate.
        model_inference_auth_schema_migrate($pdo);
        $usersColsAfter = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name');
        auth_store_contract_assert(count($usersColsAfter) === count($usersCols), 'remigrate preserves users shape');
        $rulesAsserted++;

        // Username validation.
        foreach ([
            'empty' => '',
            'too-short' => 'a',
            'too-long' => str_repeat('x', 65),
            'invalid-chars' => 'alice bob',
            'special' => 'a@b',
        ] as $label => $bad) {
            $rejected = false;
            try {
                model_inference_auth_create_user($pdo, $bad, 'password', 'Bad');
            } catch (InvalidArgumentException $e) {
                $rejected = true;
            }
            auth_store_contract_assert($rejected, "create_user rejects username: {$label}");
            $rulesAsserted++;
        }

        // Password validation.
        foreach ([
            'too-short' => '12345',
            'too-long' => str_repeat('x', 257),
        ] as $label => $bad) {
            $rejected = false;
            try {
                model_inference_auth_create_user($pdo, 'user_' . bin2hex(random_bytes(2)), $bad, 'Bad');
            } catch (InvalidArgumentException $e) {
                $rejected = true;
            }
            auth_store_contract_assert($rejected, "create_user rejects password: {$label}");
            $rulesAsserted++;
        }

        // Role validation.
        $rej = false;
        try {
            model_inference_auth_create_user($pdo, 'alice_role', 'password', 'Alice', 'superuser');
        } catch (InvalidArgumentException $e) {
            $rej = true;
        }
        auth_store_contract_assert($rej, 'create_user rejects unknown role');
        $rulesAsserted++;

        // Happy path.
        $u = model_inference_auth_create_user($pdo, 'alice', 'alice123', 'Alice', 'user');
        auth_store_contract_assert($u['id'] > 0 && $u['username'] === 'alice' && $u['role'] === 'user' && $u['status'] === 'active', 'create_user returns envelope');
        auth_store_contract_assert(!array_key_exists('password_hash', $u), 'envelope omits password_hash');
        $rulesAsserted += 2;

        // Hash is bcrypt.
        $row = model_inference_auth_find_user_with_hash($pdo, 'alice');
        auth_store_contract_assert(is_array($row) && is_string($row['password_hash']) && str_starts_with($row['password_hash'], '$2y$'), 'password_hash is bcrypt $2y$');
        auth_store_contract_assert($row['password_hash'] !== 'alice123', 'plaintext never persisted');
        $rulesAsserted += 2;

        // Duplicate username.
        $caught = false;
        try {
            model_inference_auth_create_user($pdo, 'alice', 'something_else', 'Dup');
        } catch (RuntimeException $e) {
            $caught = str_contains($e->getMessage(), 'username_taken');
        }
        auth_store_contract_assert($caught, 'duplicate username rejected');
        $rulesAsserted++;

        // find_user hit/miss.
        auth_store_contract_assert(model_inference_auth_find_user($pdo, 'alice') !== null, 'find_user hit');
        auth_store_contract_assert(model_inference_auth_find_user($pdo, 'none') === null, 'find_user miss');
        $rulesAsserted += 2;

        // Verify credentials.
        auth_store_contract_assert(model_inference_auth_verify_credentials($pdo, 'alice', 'alice123') !== null, 'verify ok');
        auth_store_contract_assert(model_inference_auth_verify_credentials($pdo, 'alice', 'wrong') === null, 'verify wrong password');
        auth_store_contract_assert(model_inference_auth_verify_credentials($pdo, 'unknown', 'alice123') === null, 'verify unknown user');
        auth_store_contract_assert(model_inference_auth_verify_credentials($pdo, '', '') === null, 'verify empty rejected');
        $rulesAsserted += 4;

        // Disabled user cannot verify.
        $pdo->exec("UPDATE users SET status = 'disabled' WHERE username = 'alice'");
        auth_store_contract_assert(model_inference_auth_verify_credentials($pdo, 'alice', 'alice123') === null, 'disabled user cannot verify');
        $pdo->exec("UPDATE users SET status = 'active' WHERE username = 'alice'");
        $rulesAsserted++;

        // Session lifecycle.
        $sess = model_inference_auth_issue_session($pdo, $u['id'], 3600, '127.0.0.1', 'phpunit');
        auth_store_contract_assert(preg_match('/^[a-f0-9]{32}$/', $sess['id']) === 1, 'session id is 32-char hex');
        auth_store_contract_assert($sess['ttl_seconds'] === 3600, 'ttl preserved');
        auth_store_contract_assert($sess['user_id'] === $u['id'], 'user_id preserved');
        $rulesAsserted += 3;

        // TTL clamping.
        $clampedLow = model_inference_auth_issue_session($pdo, $u['id'], 5);
        auth_store_contract_assert($clampedLow['ttl_seconds'] === model_inference_auth_default_ttl_seconds(), 'ttl<60 clamps to default');
        $clampedHigh = model_inference_auth_issue_session($pdo, $u['id'], 99999999);
        auth_store_contract_assert($clampedHigh['ttl_seconds'] === model_inference_auth_default_ttl_seconds(), 'ttl>30d clamps to default');
        $rulesAsserted += 2;

        $rejected = false;
        try {
            model_inference_auth_issue_session($pdo, 0, 3600);
        } catch (InvalidArgumentException $e) {
            $rejected = true;
        }
        auth_store_contract_assert($rejected, 'issue_session rejects user_id=0');
        $rulesAsserted++;

        // Validate session.
        $v = model_inference_auth_validate_session($pdo, $sess['id']);
        auth_store_contract_assert($v !== null && $v['session']['id'] === $sess['id'] && $v['user']['username'] === 'alice', 'validate_session returns session + user');
        auth_store_contract_assert(!array_key_exists('password_hash', $v['user']), 'validated user envelope omits password_hash');
        auth_store_contract_assert(model_inference_auth_validate_session($pdo, 'bogus') === null, 'validate unknown token fails');
        auth_store_contract_assert(model_inference_auth_validate_session($pdo, '') === null, 'validate empty token fails');
        $rulesAsserted += 4;

        // Revoke + re-validate.
        auth_store_contract_assert(model_inference_auth_revoke_session($pdo, $sess['id']) === true, 'revoke returns true on first call');
        auth_store_contract_assert(model_inference_auth_revoke_session($pdo, $sess['id']) === false, 'revoke returns false when already revoked');
        auth_store_contract_assert(model_inference_auth_validate_session($pdo, $sess['id']) === null, 'validate rejects revoked session');
        $rulesAsserted += 3;

        // Expired session.
        $expiredToken = bin2hex(random_bytes(16));
        $pdo->prepare('INSERT INTO sessions (id, user_id, issued_at, expires_at) VALUES (:id, :uid, :iss, :exp)')->execute([
            ':id' => $expiredToken, ':uid' => $u['id'],
            ':iss' => gmdate('c', time() - 7200),
            ':exp' => gmdate('c', time() - 3600),
        ]);
        auth_store_contract_assert(model_inference_auth_validate_session($pdo, $expiredToken) === null, 'validate rejects expired session');
        $rulesAsserted++;

        // Disabled user invalidates active session.
        $pdo->exec("UPDATE users SET status = 'disabled' WHERE username = 'alice'");
        $sess2 = model_inference_auth_issue_session($pdo, $u['id'], 3600);
        auth_store_contract_assert(model_inference_auth_validate_session($pdo, $sess2['id']) === null, 'validate rejects session of disabled user');
        $pdo->exec("UPDATE users SET status = 'active' WHERE username = 'alice'");
        $rulesAsserted++;

        // Seed users from fixture.
        $fixturePath = __DIR__ . '/../fixtures/demo-users.json';
        $result = model_inference_auth_seed_demo_users($pdo, $fixturePath);
        auth_store_contract_assert($result['seeded'] >= 1, 'seed inserts at least one fixture user');
        auth_store_contract_assert($result['source'] === $fixturePath, 'seed source path echoed');
        auth_store_contract_assert(model_inference_auth_find_user($pdo, 'admin') !== null, 'admin seeded');
        auth_store_contract_assert(model_inference_auth_find_user($pdo, 'bob') !== null, 'bob seeded');
        $rulesAsserted += 4;

        // Idempotent re-seed.
        $result2 = model_inference_auth_seed_demo_users($pdo, $fixturePath);
        auth_store_contract_assert($result2['seeded'] === 0, 'second seed inserts nothing');
        auth_store_contract_assert($result2['skipped'] >= 1, 'second seed marks fixture rows as skipped');
        $rulesAsserted += 2;

        // Disable flag.
        putenv('MODEL_INFERENCE_AUTH_DISABLE_DEMO_SEED=1');
        $disabled = model_inference_auth_seed_demo_users($pdo, $fixturePath);
        auth_store_contract_assert($disabled['source'] === 'disabled', 'seed honors disable env flag');
        putenv('MODEL_INFERENCE_AUTH_DISABLE_DEMO_SEED');
        $rulesAsserted++;

        // Missing fixture path safe.
        $missing = model_inference_auth_seed_demo_users($pdo, '/tmp/nonexistent-fixture.json');
        auth_store_contract_assert(str_starts_with($missing['source'], 'missing:'), 'missing fixture reports "missing:"');
        $rulesAsserted++;

        // Preserve hand-rotated hash (env override password differs from stored hash).
        $pdo->exec("UPDATE users SET password_hash = '" . password_hash('bob-rotated-99', PASSWORD_DEFAULT) . "' WHERE username = 'bob'");
        $result3 = model_inference_auth_seed_demo_users($pdo, $fixturePath);
        auth_store_contract_assert($result3['preserved'] >= 1, 'rotated hash preserved (not overwritten)');
        $rulesAsserted++;
    } finally {
        @unlink($dbPath);
    }

    fwrite(STDOUT, "[auth-store-contract] PASS ({$rulesAsserted} rules asserted)\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[auth-store-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
