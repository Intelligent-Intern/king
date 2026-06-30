<?php
declare(strict_types=1);

function king_openai_router_prompt_json(mixed $value, int $maxBytes = 4096): string
{
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        return 'null';
    }
    if (strlen($json) <= $maxBytes) {
        return $json;
    }
    return substr($json, 0, $maxBytes) . '...';
}

function king_openai_router_is_instruction_message(mixed $message): bool
{
    if (!is_array($message)) {
        return false;
    }
    $role = $message['role'] ?? null;
    return $role === 'system' || $role === 'developer';
}

function king_openai_router_message_tool_calls_text(array $message): string
{
    $toolCalls = $message['tool_calls'] ?? null;
    if (!is_array($toolCalls) || $toolCalls === []) {
        return '';
    }

    return "Assistant tool-call context only. King has not executed these calls:\n"
        . king_openai_router_prompt_json($toolCalls);
}

function king_openai_router_tool_field_keys(): array
{
    return [
        'tools',
        'tool_choice',
        'parallel_tool_calls',
        'functions',
        'function_call',
    ];
}

function king_openai_router_named_function_from_value(mixed $value): string
{
    if (!is_array($value)) {
        return '';
    }
    if (is_string($value['name'] ?? null) && $value['name'] !== '') {
        return $value['name'];
    }
    $function = $value['function'] ?? null;
    if (is_array($function) && is_string($function['name'] ?? null) && $function['name'] !== '') {
        return $function['name'];
    }
    return '';
}

function king_openai_router_tool_status(array $payload): array
{
    $available = [];
    $invalidSchemaCount = 0;
    $tools = is_array($payload['tools'] ?? null) ? $payload['tools'] : [];
    foreach ($tools as $tool) {
        $name = king_openai_router_named_function_from_value($tool);
        if ($name !== '') {
            $available[$name] = true;
            continue;
        }
        $invalidSchemaCount++;
    }
    $functions = is_array($payload['functions'] ?? null) ? $payload['functions'] : [];
    foreach ($functions as $function) {
        $name = king_openai_router_named_function_from_value($function);
        if ($name !== '') {
            $available[$name] = true;
            continue;
        }
        $invalidSchemaCount++;
    }

    $forced = [];
    foreach (['tool_choice', 'function_call'] as $key) {
        $name = king_openai_router_named_function_from_value($payload[$key] ?? null);
        if ($name !== '') {
            $forced[$name] = true;
        }
    }

    $assistant = [];
    $messages = is_array($payload['messages'] ?? null) ? $payload['messages'] : [];
    foreach ($messages as $message) {
        if (!is_array($message) || (($message['role'] ?? null) !== 'assistant')) {
            continue;
        }
        $toolCalls = is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [];
        foreach ($toolCalls as $call) {
            $name = king_openai_router_named_function_from_value($call);
            if ($name !== '') {
                $assistant[$name] = true;
            }
        }
    }

    $availableNames = array_keys($available);
    sort($availableNames);
    $forcedNames = array_keys($forced);
    sort($forcedNames);
    $assistantNames = array_keys($assistant);
    sort($assistantNames);
    $referenced = array_unique([...$forcedNames, ...$assistantNames]);
    sort($referenced);
    $unknown = array_values(array_filter(
        $referenced,
        static fn (string $name): bool => !isset($available[$name])
    ));

    $present = [];
    foreach (king_openai_router_tool_field_keys() as $key) {
        if (array_key_exists($key, $payload) && $payload[$key] !== null && $payload[$key] !== []) {
            $present[] = $key;
        }
    }

    return [
        'present_fields' => $present,
        'available_tool_names' => $availableNames,
        'forced_tool_names' => $forcedNames,
        'assistant_tool_call_names' => $assistantNames,
        'unknown_tool_names' => $unknown,
        'tool_schema_count' => count($tools),
        'legacy_function_count' => count($functions),
        'invalid_schema_count' => $invalidSchemaCount,
        'parallel_tool_calls_present' => array_key_exists('parallel_tool_calls', $payload),
        'context_only' => $present !== [] || $assistantNames !== [],
    ];
}

function king_openai_router_tool_result_text(array $message): string
{
    $content = king_openai_router_message_content_text($message['content'] ?? '');
    $name = is_string($message['name'] ?? null) && $message['name'] !== ''
        ? $message['name']
        : '';
    $toolCallId = is_string($message['tool_call_id'] ?? null) && $message['tool_call_id'] !== ''
        ? $message['tool_call_id']
        : '';
    $header = 'Tool result context only. King has not executed a tool in this route.';
    if ($name !== '') {
        $header .= "\nTool name: " . $name;
    }
    if ($toolCallId !== '') {
        $header .= "\nTool call id: " . $toolCallId;
    }

    return $content !== '' ? $header . "\n" . $content : $header;
}

function king_openai_router_instruction_message(array $message): ?array
{
    $role = $message['role'] ?? null;
    $content = king_openai_router_message_content_text($message['content'] ?? '');
    if ($content === '') {
        return null;
    }
    if ($role === 'developer') {
        $content = "Developer instruction:\n" . $content;
    }

    return [
        'role' => 'system',
        'content' => $content,
    ];
}

function king_openai_router_normalize_prompt_message(mixed $message): ?array
{
    if (!is_array($message)) {
        return null;
    }

    $role = $message['role'] ?? null;
    $role = is_string($role) ? strtolower(trim($role)) : '';
    if ($role === 'system' || $role === 'developer') {
        return king_openai_router_instruction_message(['role' => $role, 'content' => $message['content'] ?? '']);
    }
    if ($role === 'assistant') {
        $content = king_openai_router_message_content_text($message['content'] ?? '');
        if ($content === '') {
            $content = king_openai_router_message_tool_calls_text($message);
        }
        return $content !== '' ? ['role' => 'assistant', 'content' => $content] : null;
    }
    if ($role === 'tool') {
        return ['role' => 'user', 'content' => king_openai_router_tool_result_text($message)];
    }

    $content = king_openai_router_message_content_text($message['content'] ?? '');
    if ($role !== 'user') {
        $content = $content !== ''
            ? "Message with unsupported role `" . ($role !== '' ? $role : 'unknown') . "`:\n" . $content
            : '';
    }

    return $content !== '' ? ['role' => 'user', 'content' => $content] : null;
}

function king_openai_router_normalize_prompt_messages(array $payload): array
{
    $messages = $payload['messages'] ?? null;
    if (!is_array($messages) || $messages === []) {
        return $payload;
    }

    $normalized = [];
    foreach ($messages as $message) {
        $entry = king_openai_router_normalize_prompt_message($message);
        if ($entry !== null) {
            $normalized[] = $entry;
        }
    }
    if ($normalized !== []) {
        $payload['messages'] = $normalized;
    }

    return $payload;
}

function king_openai_router_tool_context_text(array $toolStatus): string
{
    if (empty($toolStatus['context_only'])) {
        return '';
    }

    $parts = [
        'OpenAI tool/function fields are present as context only.',
        'King has not executed any tool call in this route.',
    ];
    $available = $toolStatus['available_tool_names'] ?? [];
    if (is_array($available) && $available !== []) {
        $parts[] = 'Configured tool names: ' . implode(', ', $available) . '.';
    }
    $forced = $toolStatus['forced_tool_names'] ?? [];
    if (is_array($forced) && $forced !== []) {
        $parts[] = 'Requested tool names: ' . implode(', ', $forced) . '.';
    }
    $unknown = $toolStatus['unknown_tool_names'] ?? [];
    if (is_array($unknown) && $unknown !== []) {
        $parts[] = 'Unknown requested tool names: ' . implode(', ', $unknown) . '.';
    }

    return implode("\n", $parts);
}

function king_openai_router_insert_instruction_message(array $payload, array $message): array
{
    $messages = $payload['messages'] ?? null;
    if (!is_array($messages) || $messages === []) {
        return $payload;
    }

    $insertAt = 0;
    foreach ($messages as $index => $entry) {
        if (!king_openai_router_is_instruction_message($entry)) {
            break;
        }
        $insertAt = $index + 1;
    }
    array_splice($messages, $insertAt, 0, [$message]);
    $payload['messages'] = $messages;
    return $payload;
}

function king_openai_router_apply_tool_context(array $payload, array $toolStatus): array
{
    $text = king_openai_router_tool_context_text($toolStatus);
    if ($text === '') {
        return $payload;
    }

    return king_openai_router_insert_instruction_message($payload, [
        'role' => 'system',
        'content' => $text,
    ]);
}

function king_openai_router_strip_tool_execution_fields(array $payload): array
{
    foreach (king_openai_router_tool_field_keys() as $key) {
        unset($payload[$key]);
    }
    return $payload;
}

function king_openai_router_apply_context_policy(array $payload, string $contextPolicy): array
{
    if ($contextPolicy !== 'last_user') {
        return $payload;
    }

    $messages = $payload['messages'] ?? null;
    if (!is_array($messages) || $messages === []) {
        return $payload;
    }

    $instructionMessages = [];
    foreach ($messages as $message) {
        if (king_openai_router_is_instruction_message($message)
            && king_openai_router_message_content_text($message['content'] ?? '') !== ''
        ) {
            $instructionMessages[] = $message;
        }
    }

    for ($index = count($messages) - 1; $index >= 0; $index--) {
        $message = $messages[$index] ?? null;
        if (!is_array($message) || (($message['role'] ?? null) !== 'user')) {
            continue;
        }
        $content = king_openai_router_message_content_text($message['content'] ?? '');
        if ($content === '') {
            continue;
        }
        $payload['messages'] = [
            ...$instructionMessages,
            [
                'role' => 'user',
                'content' => $content,
            ],
        ];
        return $payload;
    }

    return $payload;
}

function king_openai_router_ensure_default_system_instruction(array $payload, string $defaultSystemPrompt): array
{
    if (trim($defaultSystemPrompt) === '') {
        return $payload;
    }

    $messages = $payload['messages'] ?? null;
    if (!is_array($messages) || $messages === []) {
        return $payload;
    }

    foreach ($messages as $message) {
        if (king_openai_router_is_instruction_message($message)
            && king_openai_router_message_content_text($message['content'] ?? '') !== ''
        ) {
            return $payload;
        }
    }

    array_unshift($messages, [
        'role' => 'system',
        'content' => $defaultSystemPrompt,
    ]);
    $payload['messages'] = $messages;
    return $payload;
}

function king_openai_router_assemble_chat_messages(
    array $payload,
    string $contextPolicy,
    string $defaultSystemPrompt,
    bool $coderInstructionWrapper
): array {
    $toolStatus = king_openai_router_tool_status($payload);
    $payload = king_openai_router_normalize_prompt_messages($payload);
    $payload = king_openai_router_apply_tool_context($payload, $toolStatus);
    $payload = king_openai_router_strip_tool_execution_fields($payload);
    $payload = king_openai_router_apply_context_policy($payload, $contextPolicy);
    $payload = king_openai_router_ensure_default_system_instruction($payload, $defaultSystemPrompt);
    return king_openai_router_apply_coder_instruction_wrapper($payload, $coderInstructionWrapper);
}
