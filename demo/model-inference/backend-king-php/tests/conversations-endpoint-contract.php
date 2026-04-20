<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../http/module_conversations.php';

function conversations_endpoint_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[conversations-endpoint-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    $rulesAsserted = 0;

    conversations_endpoint_contract_assert(
        function_exists('model_inference_handle_conversations_routes'),
        'module handler must exist'
    );
    $rulesAsserted++;

    $dbPath = sys_get_temp_dir() . '/conversations-endpoint-contract-' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        $pdo = model_inference_open_sqlite_pdo($dbPath);
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

        // 1. Unrelated path returns null (fall-through).
        $none = model_inference_handle_conversations_routes('/api/runtime', 'GET', $jsonResponse, $errorResponse, $openDatabase);
        conversations_endpoint_contract_assert($none === null, 'non-matching path returns null');
        $rulesAsserted++;

        // 2. /api/conversations without session_id → 404 not_implemented.
        $resp = model_inference_handle_conversations_routes('/api/conversations', 'GET', $jsonResponse, $errorResponse, $openDatabase);
        conversations_endpoint_contract_assert($resp['status'] === 404, 'collection-level GET returns 404');
        conversations_endpoint_contract_assert($decode($resp)['error']['code'] === 'not_implemented', 'error code is not_implemented');
        $rulesAsserted += 2;

        // 3. GET /messages on a nonexistent session returns found=false + empty list.
        $resp = model_inference_handle_conversations_routes('/api/conversations/sess-missing/messages', 'GET', $jsonResponse, $errorResponse, $openDatabase);
        conversations_endpoint_contract_assert($resp['status'] === 200, 'missing session → 200 with found=false');
        $body = $decode($resp);
        conversations_endpoint_contract_assert($body['status'] === 'ok', 'status=ok');
        conversations_endpoint_contract_assert($body['session_id'] === 'sess-missing', 'session_id echoed');
        conversations_endpoint_contract_assert($body['found'] === false, 'found=false on missing session');
        conversations_endpoint_contract_assert($body['meta'] === null, 'meta=null on missing session');
        conversations_endpoint_contract_assert($body['messages'] === [], 'messages=[] on missing session');
        conversations_endpoint_contract_assert($body['count'] === 0, 'count=0 on missing session');
        $rulesAsserted += 6;

        // 4. Non-GET on /messages → 405.
        foreach (['POST', 'DELETE', 'PATCH'] as $method) {
            $resp = model_inference_handle_conversations_routes('/api/conversations/sess-x/messages', $method, $jsonResponse, $errorResponse, $openDatabase);
            conversations_endpoint_contract_assert($resp['status'] === 405, "{$method} /messages → 405");
            conversations_endpoint_contract_assert($decode($resp)['error']['code'] === 'method_not_allowed', "{$method} → method_not_allowed");
            $rulesAsserted += 2;
        }

        // 5. GET /api/conversations/{id} (meta only) on missing session.
        $resp = model_inference_handle_conversations_routes('/api/conversations/sess-miss2', 'GET', $jsonResponse, $errorResponse, $openDatabase);
        conversations_endpoint_contract_assert($resp['status'] === 200, 'meta GET on missing returns 200');
        $body = $decode($resp);
        conversations_endpoint_contract_assert($body['found'] === false, 'found=false');
        conversations_endpoint_contract_assert($body['meta'] === null, 'meta is null');
        conversations_endpoint_contract_assert(!array_key_exists('messages', $body), 'meta response does NOT include messages');
        $rulesAsserted += 4;

        // 6. Seed a session via the store, then round-trip via the endpoint.
        $env = [
            'session_id' => 'sess-live',
            'messages' => [
                ['role' => 'user', 'content' => 'Hi, my name is Julius.'],
            ],
            'prompt' => null,
            'system' => null,
        ];
        model_inference_conversation_append_turn($pdo, $env, 'Your name is Julius.', 'req-42', ['model_name' => 'SmolLM2-135M-Instruct', 'quantization' => 'Q4_K']);

        $resp = model_inference_handle_conversations_routes('/api/conversations/sess-live/messages', 'GET', $jsonResponse, $errorResponse, $openDatabase);
        $body = $decode($resp);
        conversations_endpoint_contract_assert($resp['status'] === 200, 'list with real rows → 200');
        conversations_endpoint_contract_assert($body['found'] === true, 'found=true');
        conversations_endpoint_contract_assert($body['count'] === 2, 'count=2 after one turn');
        conversations_endpoint_contract_assert($body['messages'][0]['role'] === 'user', 'row 0 = user');
        conversations_endpoint_contract_assert($body['messages'][1]['role'] === 'assistant', 'row 1 = assistant');
        conversations_endpoint_contract_assert($body['messages'][1]['request_id'] === 'req-42', 'assistant row carries request_id');
        conversations_endpoint_contract_assert($body['meta']['turn_count'] === 1, 'meta.turn_count = 1');
        $rulesAsserted += 7;

        // 7. DELETE clears the session.
        $resp = model_inference_handle_conversations_routes('/api/conversations/sess-live', 'DELETE', $jsonResponse, $errorResponse, $openDatabase);
        conversations_endpoint_contract_assert($resp['status'] === 200, 'DELETE → 200');
        $body = $decode($resp);
        conversations_endpoint_contract_assert($body['deleted_message_count'] === 2, 'deleted_message_count=2');
        $rulesAsserted += 2;

        $resp = model_inference_handle_conversations_routes('/api/conversations/sess-live/messages', 'GET', $jsonResponse, $errorResponse, $openDatabase);
        $body = $decode($resp);
        conversations_endpoint_contract_assert($body['found'] === false, 'session no longer found after delete');
        conversations_endpoint_contract_assert($body['count'] === 0, 'count=0 after delete');
        $rulesAsserted += 2;

        // 8. session_id pattern validates against the router regex.
        //    Bad session ids get no match (fall through to null).
        foreach (['sess with space', 'sess/slash', 'sess$dollar'] as $bad) {
            $encoded = rawurlencode($bad);
            $resp = model_inference_handle_conversations_routes('/api/conversations/' . $encoded . '/messages', 'GET', $jsonResponse, $errorResponse, $openDatabase);
            conversations_endpoint_contract_assert($resp === null, "invalid session_id '{$bad}' falls through");
            $rulesAsserted++;
        }

        // 9. Valid session_id chars all accepted.
        foreach (['sess-ui-abc', 'sess.1.2.3', 'sess_underscore', 'SESS-UPPER123'] as $good) {
            $resp = model_inference_handle_conversations_routes('/api/conversations/' . $good . '/messages', 'GET', $jsonResponse, $errorResponse, $openDatabase);
            conversations_endpoint_contract_assert($resp !== null, "valid session_id '{$good}' routes to handler");
            conversations_endpoint_contract_assert($resp['status'] === 200, "valid session_id '{$good}' returns 200");
            $rulesAsserted += 2;
        }
    } finally {
        @unlink($dbPath);
    }

    fwrite(STDOUT, "[conversations-endpoint-contract] PASS ({$rulesAsserted} rules asserted)\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[conversations-endpoint-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
