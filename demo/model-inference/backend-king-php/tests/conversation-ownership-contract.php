<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../domain/auth/auth_store.php';
require_once __DIR__ . '/../domain/conversation/conversation_store.php';
require_once __DIR__ . '/../http/module_conversations.php';

function ownership_contract_assert(bool $cond, string $msg): void
{
    if ($cond) { return; }
    fwrite(STDERR, "[conversation-ownership-contract] FAIL: {$msg}\n");
    exit(1);
}

try {
    $rulesAsserted = 0;

    ownership_contract_assert(function_exists('model_inference_conversations_check_ownership'), 'ownership gate exists');
    ownership_contract_assert(function_exists('model_inference_conversation_list_by_user'), 'list_by_user exists');
    $rulesAsserted += 2;

    $dbPath = sys_get_temp_dir() . '/ownership-contract-' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        $pdo = model_inference_open_sqlite_pdo($dbPath);
        model_inference_auth_schema_migrate($pdo);
        model_inference_conversation_schema_migrate($pdo);

        // Schema has user_ref.
        $cols = array_column($pdo->query('PRAGMA table_info(conversations)')->fetchAll(), 'name');
        ownership_contract_assert(in_array('user_ref', $cols, true), 'conversations.user_ref exists');
        $indexes = array_column($pdo->query("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='conversations'")->fetchAll(), 'name');
        ownership_contract_assert(in_array('idx_conversations_user_ref', $indexes, true), 'idx_conversations_user_ref exists');
        $rulesAsserted += 2;

        // Idempotent re-migration preserves shape.
        model_inference_conversation_schema_migrate($pdo);
        $colsAfter = array_column($pdo->query('PRAGMA table_info(conversations)')->fetchAll(), 'name');
        ownership_contract_assert(count($colsAfter) === count($cols), 'remigrate stable');
        $rulesAsserted++;

        // Seed users.
        $alice = model_inference_auth_create_user($pdo, 'alice', 'alice123', 'Alice');
        $bob = model_inference_auth_create_user($pdo, 'bob', 'bob123', 'Bob');

        $modelEnv = ['model_name' => 'SmolLM2', 'quantization' => 'Q4_K'];

        // 1. Anonymous turn — user_ref stays NULL.
        $envAnon = [
            'session_id' => 'sess-anon',
            'messages' => [['role' => 'user', 'content' => 'hi']],
            'prompt' => null, 'system' => null,
        ];
        model_inference_conversation_append_turn($pdo, $envAnon, 'hi there', 'req-anon-1', $modelEnv, null);
        $metaAnon = model_inference_conversation_get_meta($pdo, 'sess-anon');
        ownership_contract_assert($metaAnon !== null && $metaAnon['user_ref'] === null, 'anonymous turn -> user_ref NULL');
        $rulesAsserted++;

        // 2. Authenticated turn — user_ref populated.
        $envAlice = [
            'session_id' => 'sess-alice',
            'messages' => [['role' => 'user', 'content' => 'hi']],
            'prompt' => null, 'system' => null,
        ];
        model_inference_conversation_append_turn($pdo, $envAlice, 'hi Alice', 'req-alice-1', $modelEnv, (int) $alice['id']);
        $metaAlice = model_inference_conversation_get_meta($pdo, 'sess-alice');
        ownership_contract_assert($metaAlice['user_ref'] === (int) $alice['id'], 'alice turn -> user_ref = alice.id');
        $rulesAsserted++;

        // 3. Anonymous turn appended to previously-authenticated session — user_ref stays.
        model_inference_conversation_append_turn($pdo, [
            'session_id' => 'sess-alice',
            'messages' => [['role' => 'user', 'content' => 'second']],
            'prompt' => null, 'system' => null,
        ], 'ack 2', 'req-alice-2', $modelEnv, null);
        $metaAlice2 = model_inference_conversation_get_meta($pdo, 'sess-alice');
        ownership_contract_assert($metaAlice2['user_ref'] === (int) $alice['id'], 'anonymous turn on owned session preserves user_ref');
        $rulesAsserted++;

        // 4. Bob turn on Alice's owned session — ownership must NOT flip to bob.
        model_inference_conversation_append_turn($pdo, [
            'session_id' => 'sess-alice',
            'messages' => [['role' => 'user', 'content' => 'bob tries']],
            'prompt' => null, 'system' => null,
        ], 'echo', 'req-bob-on-alice', $modelEnv, (int) $bob['id']);
        $metaAlice3 = model_inference_conversation_get_meta($pdo, 'sess-alice');
        ownership_contract_assert($metaAlice3['user_ref'] === (int) $alice['id'], 'cross-user append does NOT reassign ownership');
        $rulesAsserted++;

        // 5. Anonymous session remains anonymous even after re-append with a user
        //    (first-bind wins; only the first transition NULL -> userRef populates it).
        model_inference_conversation_append_turn($pdo, [
            'session_id' => 'sess-anon',
            'messages' => [['role' => 'user', 'content' => 'alice touches anon']],
            'prompt' => null, 'system' => null,
        ], 'echo', 'req-alice-on-anon', $modelEnv, (int) $alice['id']);
        $metaAnon2 = model_inference_conversation_get_meta($pdo, 'sess-anon');
        ownership_contract_assert($metaAnon2['user_ref'] === (int) $alice['id'], 'anonymous session adopts first authenticated caller');
        $rulesAsserted++;

        // --- Ownership gate via module endpoint ---
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
        $openDatabase = static fn (): PDO => $pdo;
        $decode = static fn (array $resp): array => json_decode($resp['body'] ?? '{}', true) ?: [];

        // 6. Anonymous conversation (sess-anon is now owned by alice
        //    because of the previous turn — so create a separate truly
        //    anonymous conversation for this test).
        model_inference_conversation_append_turn($pdo, [
            'session_id' => 'sess-open',
            'messages' => [['role' => 'user', 'content' => 'hi']],
            'prompt' => null, 'system' => null,
        ], 'ack', 'req-open-1', $modelEnv, null);
        $r = model_inference_handle_conversations_routes(
            '/api/conversations/sess-open/messages', 'GET',
            $jsonResponse, $errorResponse, $openDatabase,
            ['user' => null]
        );
        ownership_contract_assert($r['status'] === 200, 'anon session readable without auth');
        ownership_contract_assert($decode($r)['count'] === 2, 'anon session returns 2 messages');
        $rulesAsserted += 2;

        // 7. Owned session rejects anonymous reader.
        $r = model_inference_handle_conversations_routes(
            '/api/conversations/sess-alice/messages', 'GET',
            $jsonResponse, $errorResponse, $openDatabase,
            ['user' => null]
        );
        ownership_contract_assert($r['status'] === 403, 'owned session rejects anon -> 403');
        ownership_contract_assert($decode($r)['error']['code'] === 'ownership_denied', 'ownership_denied code');
        ownership_contract_assert($decode($r)['error']['details']['reason'] === 'caller_anonymous', 'reason caller_anonymous');
        $rulesAsserted += 3;

        // 8. Owned session rejects cross-user reader.
        $r = model_inference_handle_conversations_routes(
            '/api/conversations/sess-alice/messages', 'GET',
            $jsonResponse, $errorResponse, $openDatabase,
            ['user' => $bob]
        );
        ownership_contract_assert($r['status'] === 403, 'cross-user read -> 403');
        ownership_contract_assert($decode($r)['error']['details']['reason'] === 'caller_not_owner', 'reason caller_not_owner');
        $rulesAsserted += 2;

        // 9. Owner can read.
        $r = model_inference_handle_conversations_routes(
            '/api/conversations/sess-alice/messages', 'GET',
            $jsonResponse, $errorResponse, $openDatabase,
            ['user' => $alice]
        );
        ownership_contract_assert($r['status'] === 200, 'owner read -> 200');
        ownership_contract_assert($decode($r)['found'] === true, 'found=true for owner');
        $rulesAsserted += 2;

        // 10. DELETE inherits the same gate.
        $r = model_inference_handle_conversations_routes(
            '/api/conversations/sess-alice', 'DELETE',
            $jsonResponse, $errorResponse, $openDatabase,
            ['user' => $bob]
        );
        ownership_contract_assert($r['status'] === 403, 'DELETE cross-user -> 403');
        $rulesAsserted++;

        // 11. /api/conversations/me requires auth.
        $r = model_inference_handle_conversations_routes(
            '/api/conversations/me', 'GET',
            $jsonResponse, $errorResponse, $openDatabase,
            ['user' => null]
        );
        ownership_contract_assert($r['status'] === 401, '/me without auth -> 401');
        ownership_contract_assert($decode($r)['error']['code'] === 'invalid_credentials', '/me missing-bearer code');
        $rulesAsserted += 2;

        // 12. /api/conversations/me lists only the authenticated user's conversations.
        $r = model_inference_handle_conversations_routes(
            '/api/conversations/me', 'GET',
            $jsonResponse, $errorResponse, $openDatabase,
            ['user' => $alice]
        );
        ownership_contract_assert($r['status'] === 200, '/me with alice -> 200');
        $body = $decode($r);
        $aliceSessionIds = array_map(fn($c) => $c['session_id'], $body['conversations']);
        ownership_contract_assert(in_array('sess-alice', $aliceSessionIds, true), 'alice sees sess-alice');
        ownership_contract_assert(!in_array('sess-open', $aliceSessionIds, true), 'alice does NOT see anonymous sess-open');
        foreach ($body['conversations'] as $c) {
            ownership_contract_assert($c['user_ref'] === (int) $alice['id'], 'every conversation in /me is owned by the caller');
            $rulesAsserted++;
        }
        $rulesAsserted += 3;

        // 13. Bob sees zero conversations.
        $r = model_inference_handle_conversations_routes(
            '/api/conversations/me', 'GET',
            $jsonResponse, $errorResponse, $openDatabase,
            ['user' => $bob]
        );
        ownership_contract_assert($decode($r)['count'] === 0, 'bob has no owned conversations');
        $rulesAsserted++;
    } finally {
        @unlink($dbPath);
    }

    fwrite(STDOUT, "[conversation-ownership-contract] PASS ({$rulesAsserted} rules asserted)\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[conversation-ownership-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
