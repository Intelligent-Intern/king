<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/inference/inference_request.php';
require_once __DIR__ . '/../domain/inference/inference_session.php';

function chat_memory_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[chat-memory-contract] FAIL: {$message}\n");
    exit(1);
}

/** @return array<string, mixed> */
function chat_memory_contract_base(): array
{
    return [
        'session_id' => 'sess-demo-01',
        'model_selector' => ['model_name' => 'SmolLM2-135M-Instruct', 'quantization' => 'Q4_K', 'prefer_local' => true],
        'sampling' => ['temperature' => 0.7, 'top_p' => 0.95, 'top_k' => 40, 'max_tokens' => 64],
        'stream' => false,
    ];
}

try {
    $rulesAsserted = 0;

    // 1. Envelope validator accepts messages[] without prompt.
    $v = model_inference_validate_infer_request(array_merge(chat_memory_contract_base(), [
        'messages' => [
            ['role' => 'user', 'content' => 'hi'],
            ['role' => 'assistant', 'content' => 'hello!'],
            ['role' => 'user', 'content' => 'what is 2+2?'],
        ],
    ]));
    chat_memory_contract_assert(is_array($v['messages']), 'messages preserved on normalized envelope');
    chat_memory_contract_assert(count($v['messages']) === 3, 'messages count preserved');
    chat_memory_contract_assert($v['messages'][2]['role'] === 'user', 'last role preserved');
    chat_memory_contract_assert($v['messages'][2]['content'] === 'what is 2+2?', 'last content preserved');
    chat_memory_contract_assert($v['prompt'] === null, 'prompt may be null when messages present');
    $rulesAsserted += 5;

    // 2. Envelope validator accepts messages[] WITH a trailing prompt.
    $v2 = model_inference_validate_infer_request(array_merge(chat_memory_contract_base(), [
        'messages' => [
            ['role' => 'user', 'content' => 'hi'],
            ['role' => 'assistant', 'content' => 'hello!'],
        ],
        'prompt' => 'say something short',
    ]));
    chat_memory_contract_assert($v2['prompt'] === 'say something short', 'prompt preserved alongside messages');
    chat_memory_contract_assert(count($v2['messages']) === 2, 'messages kept separate from prompt');
    $rulesAsserted += 2;

    // 3. Single-turn mode (no messages) still requires prompt.
    $rejected = false;
    try {
        model_inference_validate_infer_request(chat_memory_contract_base());
    } catch (InferenceRequestValidationError $e) {
        $rejected = $e->field === 'prompt';
    }
    chat_memory_contract_assert($rejected, 'prompt required when messages absent');
    $rulesAsserted++;

    // 4. messages[] rejections.
    $rejections = [
        'empty array' => [],
        'too many' => array_fill(0, 65, ['role' => 'user', 'content' => 'x']),
        'non-array item' => ['not-an-object'],
        'missing role' => [['content' => 'x']],
        'missing content' => [['role' => 'user']],
        'invalid role' => [['role' => 'tool', 'content' => 'x']],
        'empty content' => [['role' => 'user', 'content' => '']],
        'content too long' => [['role' => 'user', 'content' => str_repeat('x', 32769)]],
        'unknown key' => [['role' => 'user', 'content' => 'x', 'extra' => 1]],
    ];
    foreach ($rejections as $label => $messages) {
        $rej = false;
        try {
            model_inference_validate_infer_request(array_merge(chat_memory_contract_base(), ['messages' => $messages]));
        } catch (InferenceRequestValidationError $e) {
            $rej = true;
        }
        chat_memory_contract_assert($rej, "must reject: {$label}");
        $rulesAsserted++;
    }

    // 5. InferenceSession::buildMessages plumbs messages[] to llama.cpp verbatim.
    $tmpRoot = sys_get_temp_dir() . '/chat-memory-contract-' . bin2hex(random_bytes(4));
    mkdir($tmpRoot, 0775, true);
    try {
        $session = new InferenceSession('/bin/true', '/bin/true', $tmpRoot);

        // messages-only path.
        $plumbed = $session->buildMessages(array_merge($v, ['prompt' => null]));
        chat_memory_contract_assert(count($plumbed) === 3, 'messages-only path preserves turn count');
        chat_memory_contract_assert($plumbed[0] === ['role' => 'user', 'content' => 'hi'], 'first turn verbatim');
        chat_memory_contract_assert($plumbed[2] === ['role' => 'user', 'content' => 'what is 2+2?'], 'last turn verbatim');
        $rulesAsserted += 3;

        // messages + trailing prompt path.
        $plumbed2 = $session->buildMessages($v2);
        chat_memory_contract_assert(count($plumbed2) === 3, 'messages + prompt appended yields N+1');
        chat_memory_contract_assert($plumbed2[2] === ['role' => 'user', 'content' => 'say something short'], 'prompt appended as user turn');
        $rulesAsserted += 2;

        // messages + leading system already present -> system field ignored.
        $withLeadingSystem = [
            ['role' => 'system', 'content' => 'be terse'],
            ['role' => 'user', 'content' => 'hi'],
        ];
        $plumbed3 = $session->buildMessages(array_merge(chat_memory_contract_base(), [
            'messages' => $withLeadingSystem,
            'prompt' => null,
            'system' => 'be verbose', // should be ignored because messages already has a system turn
        ]));
        chat_memory_contract_assert(count($plumbed3) === 2, 'leading system suppresses system field');
        chat_memory_contract_assert($plumbed3[0]['content'] === 'be terse', 'leading system from messages[] wins');
        $rulesAsserted += 2;

        // messages WITHOUT leading system + system field -> prepended.
        $plumbed4 = $session->buildMessages(array_merge(chat_memory_contract_base(), [
            'messages' => [['role' => 'user', 'content' => 'hi']],
            'prompt' => null,
            'system' => 'be terse',
        ]));
        chat_memory_contract_assert(count($plumbed4) === 2, 'system prepended when no leading system turn');
        chat_memory_contract_assert($plumbed4[0] === ['role' => 'system', 'content' => 'be terse'], 'system prepended verbatim');
        $rulesAsserted += 2;

        // Legacy (no messages) path still works.
        $legacy = $session->buildMessages([
            'prompt' => 'hello',
            'system' => 'be nice',
            'messages' => null,
        ]);
        chat_memory_contract_assert(count($legacy) === 2, 'legacy path: system + user = 2');
        chat_memory_contract_assert($legacy[0] === ['role' => 'system', 'content' => 'be nice'], 'legacy system preserved');
        chat_memory_contract_assert($legacy[1] === ['role' => 'user', 'content' => 'hello'], 'legacy user preserved');
        $rulesAsserted += 3;
    } finally {
        @rmdir($tmpRoot);
    }

    // 6. Contract JSON names messages in the envelope.
    $contractPath = __DIR__ . '/../../contracts/v1/inference-request.contract.json';
    $contract = json_decode((string) file_get_contents($contractPath), true);
    chat_memory_contract_assert(is_array($contract) && isset($contract['envelope']['messages']), 'contract JSON declares messages field');
    chat_memory_contract_assert(in_array('messages', model_inference_request_allowed_top_level_keys(), true), 'messages is in allowed top-level keys');
    chat_memory_contract_assert(model_inference_request_allowed_message_roles() === ['system', 'user', 'assistant'], 'roles pinned');
    $rulesAsserted += 3;

    // 7. Shared resolver is the single source of truth for HTTP + WS paths.
    require_once __DIR__ . '/../domain/inference/chat_messages.php';
    chat_memory_contract_assert(
        function_exists('model_inference_build_chat_messages'),
        'model_inference_build_chat_messages must exist as the shared resolver'
    );
    // Both transports must produce identical messages[] from the same envelope.
    $shared = model_inference_build_chat_messages($v);
    chat_memory_contract_assert(
        $shared === $session->buildMessages($v),
        'InferenceSession::buildMessages must return exactly what the shared resolver returns'
    );
    $rulesAsserted += 2;

    // 8. `messages: null` is treated as absent (validator doesn't accept it,
    //    but the resolver must handle it gracefully for callers that bypass
    //    the validator, e.g. transcript replay).
    $nullMessages = model_inference_build_chat_messages([
        'messages' => null,
        'prompt' => 'hello',
        'system' => null,
    ]);
    chat_memory_contract_assert(count($nullMessages) === 1, 'messages=null falls back to single-turn');
    chat_memory_contract_assert($nullMessages[0] === ['role' => 'user', 'content' => 'hello'], 'single-turn payload shape');
    $rulesAsserted += 2;

    // 9. Empty messages[] from the resolver (bypassing the validator) also falls back.
    $emptyMessages = model_inference_build_chat_messages([
        'messages' => [],
        'prompt' => 'hello',
    ]);
    chat_memory_contract_assert(count($emptyMessages) === 1, 'empty messages[] falls back to single-turn');
    $rulesAsserted++;

    fwrite(STDOUT, "[chat-memory-contract] PASS ({$rulesAsserted} rules asserted)\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[chat-memory-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
