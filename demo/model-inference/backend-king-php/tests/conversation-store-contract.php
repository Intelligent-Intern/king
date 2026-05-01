<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../domain/conversation/conversation_store.php';

function conversation_store_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[conversation-store-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    $rulesAsserted = 0;

    foreach ([
        'model_inference_conversation_schema_migrate',
        'model_inference_conversation_append_turn',
        'model_inference_conversation_get_meta',
        'model_inference_conversation_list_messages',
        'model_inference_conversation_delete',
    ] as $fn) {
        conversation_store_contract_assert(function_exists($fn), "{$fn} must exist");
        $rulesAsserted++;
    }

    $dbPath = sys_get_temp_dir() . '/conversation-store-contract-' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        $pdo = model_inference_open_sqlite_pdo($dbPath);
        model_inference_conversation_schema_migrate($pdo);

        // 1. Schema — conversations table.
        $conversationsCols = array_column($pdo->query('PRAGMA table_info(conversations)')->fetchAll(), 'name');
        foreach ([
            'session_id', 'created_at', 'updated_at', 'turn_count',
            'last_request_id', 'last_model_name', 'last_model_quantization',
        ] as $col) {
            conversation_store_contract_assert(
                in_array($col, $conversationsCols, true),
                "conversations table must have {$col}"
            );
            $rulesAsserted++;
        }

        // 2. Schema — conversation_messages table.
        $messagesCols = array_column($pdo->query('PRAGMA table_info(conversation_messages)')->fetchAll(), 'name');
        foreach ([
            'message_id', 'session_id', 'seq', 'role', 'content',
            'request_id', 'model_name', 'model_quantization', 'created_at',
        ] as $col) {
            conversation_store_contract_assert(
                in_array($col, $messagesCols, true),
                "conversation_messages table must have {$col}"
            );
            $rulesAsserted++;
        }

        // 3. Index on (session_id, seq) exists.
        $indexes = array_column(
            $pdo->query("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='conversation_messages'")->fetchAll(),
            'name'
        );
        conversation_store_contract_assert(
            in_array('idx_conversation_messages_session_seq', $indexes, true),
            'unique index idx_conversation_messages_session_seq must exist'
        );
        $rulesAsserted++;

        // 4. Idempotent migration.
        model_inference_conversation_schema_migrate($pdo);
        $conversationsColsAfter = array_column($pdo->query('PRAGMA table_info(conversations)')->fetchAll(), 'name');
        conversation_store_contract_assert(
            count($conversationsColsAfter) === count($conversationsCols),
            'repeated migration must not duplicate columns'
        );
        $rulesAsserted++;

        // 5. Empty state.
        conversation_store_contract_assert(
            model_inference_conversation_get_meta($pdo, 'sess-none') === null,
            'get_meta returns null when session does not exist'
        );
        conversation_store_contract_assert(
            model_inference_conversation_list_messages($pdo, 'sess-none') === [],
            'list_messages returns [] on missing session'
        );
        conversation_store_contract_assert(
            model_inference_conversation_list_messages($pdo, 'sess-none', 0) === [],
            'list_messages with limit=0 returns []'
        );
        $rulesAsserted += 3;

        // 6. Missing session_id rejected.
        $rejected = false;
        try {
            model_inference_conversation_append_turn($pdo, [], 'hello', 'req-1', []);
        } catch (InvalidArgumentException $e) {
            $rejected = true;
        }
        conversation_store_contract_assert($rejected, 'append_turn rejects missing session_id');
        $rulesAsserted++;

        // 7. First append with messages[] persists all client-side turns plus assistant reply.
        $env1 = [
            'session_id' => 'sess-A',
            'messages' => [
                ['role' => 'user', 'content' => 'Hi, my name is Julius.'],
            ],
            'prompt' => null,
            'system' => null,
        ];
        $modelEnv = ['model_id' => 'mdl-x', 'model_name' => 'SmolLM2-135M-Instruct', 'quantization' => 'Q4_K'];
        $r1 = model_inference_conversation_append_turn($pdo, $env1, 'Nice to meet you, Julius.', 'req-1', $modelEnv);
        conversation_store_contract_assert($r1['appended'] === 2, 'first append persists 1 user + 1 assistant = 2 messages');
        conversation_store_contract_assert($r1['seq_before'] === 0, 'seq starts at 0 for new session');
        conversation_store_contract_assert($r1['seq_after'] === 2, 'seq advances to 2 after first turn');
        $rulesAsserted += 3;

        // 8. Listing.
        $messages = model_inference_conversation_list_messages($pdo, 'sess-A');
        conversation_store_contract_assert(count($messages) === 2, 'list returns 2 messages');
        conversation_store_contract_assert($messages[0]['role'] === 'user' && $messages[0]['content'] === 'Hi, my name is Julius.', 'message[0] is user turn verbatim');
        conversation_store_contract_assert($messages[1]['role'] === 'assistant' && $messages[1]['content'] === 'Nice to meet you, Julius.', 'message[1] is assistant reply verbatim');
        conversation_store_contract_assert($messages[0]['seq'] === 0, 'message[0] seq = 0');
        conversation_store_contract_assert($messages[1]['seq'] === 1, 'message[1] seq = 1');
        conversation_store_contract_assert($messages[1]['request_id'] === 'req-1', 'assistant message has request_id');
        conversation_store_contract_assert($messages[0]['request_id'] === null, 'user message has null request_id');
        $rulesAsserted += 7;

        // 9. Meta reflects the append.
        $meta = model_inference_conversation_get_meta($pdo, 'sess-A');
        conversation_store_contract_assert($meta !== null, 'meta returned after append');
        conversation_store_contract_assert($meta['turn_count'] === 1, 'turn_count = 1 after one append');
        conversation_store_contract_assert($meta['last_request_id'] === 'req-1', 'last_request_id preserved');
        conversation_store_contract_assert($meta['last_model_name'] === 'SmolLM2-135M-Instruct', 'last_model_name preserved');
        $rulesAsserted += 4;

        // 10. Second turn: client re-sends the full history; only the NEW user turn and the new assistant reply get persisted.
        $env2 = [
            'session_id' => 'sess-A',
            'messages' => [
                ['role' => 'user', 'content' => 'Hi, my name is Julius.'],
                ['role' => 'assistant', 'content' => 'Nice to meet you, Julius.'],
                ['role' => 'user', 'content' => 'What is my name?'],
            ],
            'prompt' => null,
            'system' => null,
        ];
        $r2 = model_inference_conversation_append_turn($pdo, $env2, 'Your name is Julius.', 'req-2', $modelEnv);
        conversation_store_contract_assert($r2['appended'] === 2, 'second turn persists 1 new user + 1 assistant = 2 messages');
        $rulesAsserted++;

        $messages2 = model_inference_conversation_list_messages($pdo, 'sess-A');
        conversation_store_contract_assert(count($messages2) === 4, 'after two turns there are 4 messages total');
        conversation_store_contract_assert($messages2[2]['content'] === 'What is my name?', 'seq=2 is new user turn');
        conversation_store_contract_assert($messages2[3]['content'] === 'Your name is Julius.', 'seq=3 is new assistant reply');
        conversation_store_contract_assert($messages2[3]['request_id'] === 'req-2', 'seq=3 carries new request_id');
        $rulesAsserted += 4;

        $meta2 = model_inference_conversation_get_meta($pdo, 'sess-A');
        conversation_store_contract_assert($meta2['turn_count'] === 2, 'turn_count = 2 after two turns');
        $rulesAsserted++;

        // 11. Legacy prompt-only path: first append persists prompt + optional system + assistant.
        $envLegacy = [
            'session_id' => 'sess-B',
            'messages' => null,
            'prompt' => 'hello',
            'system' => 'be concise',
        ];
        $rL = model_inference_conversation_append_turn($pdo, $envLegacy, 'hi there', 'req-L', $modelEnv);
        conversation_store_contract_assert($rL['appended'] === 3, 'legacy path persists system + user + assistant = 3');
        $mL = model_inference_conversation_list_messages($pdo, 'sess-B');
        conversation_store_contract_assert(count($mL) === 3, 'legacy session has 3 messages');
        conversation_store_contract_assert($mL[0]['role'] === 'system' && $mL[0]['content'] === 'be concise', 'legacy[0] = system');
        conversation_store_contract_assert($mL[1]['role'] === 'user' && $mL[1]['content'] === 'hello', 'legacy[1] = user');
        conversation_store_contract_assert($mL[2]['role'] === 'assistant' && $mL[2]['content'] === 'hi there', 'legacy[2] = assistant');
        $rulesAsserted += 5;

        // 12. Session isolation.
        conversation_store_contract_assert(
            count(model_inference_conversation_list_messages($pdo, 'sess-A')) === 4,
            'sess-A still has 4 messages (not polluted by sess-B)'
        );
        conversation_store_contract_assert(
            count(model_inference_conversation_list_messages($pdo, 'sess-C')) === 0,
            'sess-C (never used) is empty'
        );
        $rulesAsserted += 2;

        // 13. Delete clears one session only.
        $deleted = model_inference_conversation_delete($pdo, 'sess-A');
        conversation_store_contract_assert($deleted === 4, 'delete returns 4 removed messages');
        conversation_store_contract_assert(
            model_inference_conversation_list_messages($pdo, 'sess-A') === [],
            'sess-A is empty after delete'
        );
        conversation_store_contract_assert(
            model_inference_conversation_get_meta($pdo, 'sess-A') === null,
            'sess-A meta is gone after delete'
        );
        conversation_store_contract_assert(
            count(model_inference_conversation_list_messages($pdo, 'sess-B')) === 3,
            'sess-B is untouched by sess-A delete'
        );
        $rulesAsserted += 4;

        // 14. Limit caps the result.
        $capped = model_inference_conversation_list_messages($pdo, 'sess-B', 2);
        conversation_store_contract_assert(count($capped) === 2, 'limit=2 returns 2 rows');
        conversation_store_contract_assert($capped[0]['seq'] === 0 && $capped[1]['seq'] === 1, 'limit returns earliest rows first');
        $rulesAsserted += 2;
    } finally {
        @unlink($dbPath);
    }

    fwrite(STDOUT, "[conversation-store-contract] PASS ({$rulesAsserted} rules asserted)\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[conversation-store-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
