<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../domain/auth/auth_store.php';

function auth_seed_contract_assert(bool $cond, string $msg): void
{
    if ($cond) { return; }
    fwrite(STDERR, "[auth-seed-contract] FAIL: {$msg}\n");
    exit(1);
}

try {
    $rulesAsserted = 0;

    // 1. Fixture file exists and parses.
    $fixturePath = __DIR__ . '/../fixtures/demo-users.json';
    auth_seed_contract_assert(is_file($fixturePath), 'demo-users.json fixture exists');
    $raw = file_get_contents($fixturePath);
    $fixture = json_decode((string) $raw, true);
    auth_seed_contract_assert(is_array($fixture), 'fixture parses as JSON object');
    auth_seed_contract_assert(($fixture['fixture_name'] ?? null) === 'king-model-inference-demo-users', 'fixture_name pinned');
    auth_seed_contract_assert(is_array($fixture['users']) && count($fixture['users']) >= 3, 'fixture has at least 3 users');
    $rulesAsserted += 4;

    // 2. Expected usernames present.
    $usernames = array_map(fn($u) => $u['username'], $fixture['users']);
    foreach (['admin', 'alice', 'bob'] as $expected) {
        auth_seed_contract_assert(in_array($expected, $usernames, true), "fixture contains {$expected}");
        $rulesAsserted++;
    }

    // 3. Each user has required fields + valid role.
    foreach ($fixture['users'] as $u) {
        auth_seed_contract_assert(isset($u['username'], $u['password'], $u['role'], $u['display_name']), "user {$u['username']} complete");
        auth_seed_contract_assert(in_array($u['role'], ['user', 'admin'], true), "user {$u['username']} role valid");
        auth_seed_contract_assert(strlen($u['password']) >= 6, "user {$u['username']} password >=6");
        $rulesAsserted += 3;
    }

    // 4. Fresh DB: seed hashes passwords + inserts rows.
    $dbPath = sys_get_temp_dir() . '/auth-seed-contract-' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        $pdo = model_inference_open_sqlite_pdo($dbPath);
        model_inference_auth_schema_migrate($pdo);
        $r = model_inference_auth_seed_demo_users($pdo, $fixturePath);
        auth_seed_contract_assert($r['seeded'] === 3, 'fresh seed inserts 3 users');
        auth_seed_contract_assert($r['source'] === $fixturePath, 'source echoed');
        $rulesAsserted += 2;

        foreach (['admin', 'alice', 'bob'] as $username) {
            $user = model_inference_auth_find_user_with_hash($pdo, $username);
            auth_seed_contract_assert($user !== null, "user {$username} inserted");
            auth_seed_contract_assert(str_starts_with((string) $user['password_hash'], '$2y$'), "{$username} password hashed with bcrypt");
            auth_seed_contract_assert($user['password_hash'] !== $username . '123', "{$username} plaintext never stored");
            $rulesAsserted += 3;
        }

        // admin should be role=admin, alice + bob role=user.
        auth_seed_contract_assert(model_inference_auth_find_user($pdo, 'admin')['role'] === 'admin', 'admin role=admin');
        auth_seed_contract_assert(model_inference_auth_find_user($pdo, 'alice')['role'] === 'user', 'alice role=user');
        auth_seed_contract_assert(model_inference_auth_find_user($pdo, 'bob')['role'] === 'user', 'bob role=user');
        $rulesAsserted += 3;

        // Verify credentials roundtrip.
        auth_seed_contract_assert(model_inference_auth_verify_credentials($pdo, 'admin', 'admin123') !== null, 'admin/admin123 verifies');
        auth_seed_contract_assert(model_inference_auth_verify_credentials($pdo, 'alice', 'alice123') !== null, 'alice/alice123 verifies');
        auth_seed_contract_assert(model_inference_auth_verify_credentials($pdo, 'bob', 'bob123') !== null, 'bob/bob123 verifies');
        auth_seed_contract_assert(model_inference_auth_verify_credentials($pdo, 'alice', 'wrong') === null, 'alice/wrong rejected');
        $rulesAsserted += 4;

        // 5. Idempotent re-seed.
        $r2 = model_inference_auth_seed_demo_users($pdo, $fixturePath);
        auth_seed_contract_assert($r2['seeded'] === 0, 'second seed inserts nothing');
        auth_seed_contract_assert($r2['skipped'] === 3, 'second seed skips all 3');
        $rulesAsserted += 2;

        // 6. Env overrides win.
        $overrideDbPath = sys_get_temp_dir() . '/auth-seed-override-' . bin2hex(random_bytes(4)) . '.sqlite';
        $pdoOverride = model_inference_open_sqlite_pdo($overrideDbPath);
        model_inference_auth_schema_migrate($pdoOverride);
        putenv('MODEL_INFERENCE_DEMO_ALICE_USERNAME=alice_override');
        putenv('MODEL_INFERENCE_DEMO_ALICE_PASSWORD=override123');
        model_inference_auth_seed_demo_users($pdoOverride, $fixturePath);
        auth_seed_contract_assert(model_inference_auth_find_user($pdoOverride, 'alice_override') !== null, 'env override user seeded');
        auth_seed_contract_assert(model_inference_auth_find_user($pdoOverride, 'alice') === null, 'original alice NOT seeded when overridden');
        auth_seed_contract_assert(model_inference_auth_verify_credentials($pdoOverride, 'alice_override', 'override123') !== null, 'env-overridden password works');
        putenv('MODEL_INFERENCE_DEMO_ALICE_USERNAME');
        putenv('MODEL_INFERENCE_DEMO_ALICE_PASSWORD');
        @unlink($overrideDbPath);
        $rulesAsserted += 3;

        // 7. Disable flag.
        $disableDbPath = sys_get_temp_dir() . '/auth-seed-disable-' . bin2hex(random_bytes(4)) . '.sqlite';
        $pdoDisabled = model_inference_open_sqlite_pdo($disableDbPath);
        model_inference_auth_schema_migrate($pdoDisabled);
        putenv('MODEL_INFERENCE_AUTH_DISABLE_DEMO_SEED=1');
        $disabledResult = model_inference_auth_seed_demo_users($pdoDisabled, $fixturePath);
        auth_seed_contract_assert($disabledResult['source'] === 'disabled', 'disable flag skips seed');
        auth_seed_contract_assert(model_inference_auth_find_user($pdoDisabled, 'admin') === null, 'disabled -> no users inserted');
        putenv('MODEL_INFERENCE_AUTH_DISABLE_DEMO_SEED');
        @unlink($disableDbPath);
        $rulesAsserted += 2;

        // 8. Hand-rotated hash preserved on re-seed.
        $rotatedHash = password_hash('rotated-secret', PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE users SET password_hash = :h WHERE username = :u')->execute([
            ':h' => $rotatedHash, ':u' => 'alice',
        ]);
        $r3 = model_inference_auth_seed_demo_users($pdo, $fixturePath);
        auth_seed_contract_assert($r3['preserved'] >= 1, 'rotated hash preserved flag set');
        auth_seed_contract_assert(model_inference_auth_verify_credentials($pdo, 'alice', 'rotated-secret') !== null, 'rotated password still verifies');
        auth_seed_contract_assert(model_inference_auth_verify_credentials($pdo, 'alice', 'alice123') === null, 'fixture password does NOT overwrite rotated one');
        $rulesAsserted += 3;
    } finally {
        @unlink($dbPath);
    }

    fwrite(STDOUT, "[auth-seed-contract] PASS ({$rulesAsserted} rules asserted)\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[auth-seed-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
