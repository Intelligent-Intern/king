<?php
declare(strict_types=1);

function king_openai_router_env_bool(string $key, bool $fallback): bool
{
    $value = getenv($key);
    if ($value === false || trim($value) === '') {
        return $fallback;
    }

    return in_array(strtolower(trim($value)), ['1', 'on', 'true', 'yes'], true);
}

function king_openai_router_message_content_text(mixed $content): string
{
    if (is_string($content)) {
        return $content;
    }
    if (!is_array($content)) {
        return '';
    }

    $parts = [];
    foreach ($content as $part) {
        if (is_string($part)) {
            $parts[] = $part;
            continue;
        }
        if (is_array($part) && is_string($part['text'] ?? null)) {
            $parts[] = $part['text'];
        }
    }
    return trim(implode("\n", $parts));
}

function king_openai_router_deterministic_content(array $payload): ?string
{
    $content = king_inference_runtime_mini_op_content($payload);
    return is_string($content) && $content !== '' ? $content : null;
}

function king_openai_router_coder_instruction_text(): string
{
    return implode("\n", [
        'Local Coder instruction wrapper:',
        'Follow the latest user request exactly and in the requested language.',
        'If the user asks for JSON, code, SQL, exact text, a number, or "no Markdown", output only that artifact without fences or prose.',
        'If the user asks to receive Markdown source, wrap the Markdown source in a triple-tilde fence. Use ~~~markdown at the start and ~~~ at the end.',
        'If a King deterministic mini-op verifier is present, use its verified result for string, counting, regex-like, or arithmetic questions instead of estimating from memory.',
        'If tool/function fields are present without a King MCP execution result, treat them as context and answer normally.',
        'Do not invent facts or capabilities.',
    ]);
}

function king_openai_router_payload_has_coder_instruction_wrapper(array $payload): bool
{
    $messages = $payload['messages'] ?? null;
    if (!is_array($messages)) {
        return false;
    }

    foreach ($messages as $message) {
        if (!is_array($message) || (($message['role'] ?? null) !== 'system')) {
            continue;
        }
        $content = king_openai_router_message_content_text($message['content'] ?? '');
        if (str_starts_with($content, 'Local Coder instruction wrapper:')) {
            return true;
        }
    }

    return false;
}

function king_openai_router_apply_coder_instruction_wrapper(array $payload, bool $enabled): array
{
    if (!$enabled || king_openai_router_payload_has_coder_instruction_wrapper($payload)) {
        return $payload;
    }

    $messages = $payload['messages'] ?? null;
    if (!is_array($messages) || $messages === []) {
        return $payload;
    }

    array_unshift($messages, [
        'role' => 'system',
        'content' => king_openai_router_coder_instruction_text(),
    ]);
    $payload['messages'] = $messages;

    return $payload;
}
