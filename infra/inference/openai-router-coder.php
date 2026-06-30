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

function king_openai_router_last_user_text(array $payload): string
{
    $messages = $payload['messages'] ?? null;
    if (!is_array($messages)) {
        return '';
    }

    for ($index = count($messages) - 1; $index >= 0; $index--) {
        $message = $messages[$index] ?? null;
        if (!is_array($message) || (($message['role'] ?? null) !== 'user')) {
            continue;
        }

        $content = king_openai_router_message_content_text($message['content'] ?? '');
        if ($content !== '') {
            return $content;
        }
    }

    return '';
}

function king_openai_router_exact_output_content(string $text): ?string
{
    $patterns = [
        'Reply with exactly this text and nothing else:',
        'Reply with exactly:',
        'Respond with exactly:',
        'Output exactly:',
        'Return exactly this two-letter answer and nothing else:',
    ];

    foreach ($patterns as $pattern) {
        if (strncasecmp($text, $pattern, strlen($pattern)) !== 0) {
            continue;
        }

        $content = trim(substr($text, strlen($pattern)), " \t\n\r\0\x0B\"'`");
        return $content !== '' ? $content : null;
    }

    return null;
}

function king_openai_router_count_occurrences(string $subject, string $needle): int
{
    if ($needle === '') {
        return 0;
    }

    $subject = strtolower($subject);
    $needle = strtolower($needle);
    $needleLength = strlen($needle);
    $count = 0;
    $offset = 0;

    while (($position = strpos($subject, $needle, $offset)) !== false) {
        $count++;
        $offset = $position + $needleLength;
    }

    return $count;
}

function king_openai_router_count_task_content(string $text): ?string
{
    $normalized = trim((string) preg_replace('/\s+/u', ' ', $text));
    if ($normalized === '') {
        return null;
    }

    $patterns = [
        '/\bhow many (?:letters?|characters?|ltters?|ltter) ["\']?([\p{L}\p{N}])["\']? (?:are )?in (?:the )?(?:word|string )?["\']?([\p{L}\p{N}_ -]{1,120})["\']?(?:\?|\.|,|$)/iu',
        '/\bhow often (?:does|is) ["\']?([\p{L}\p{N}])["\']? (?:appear|occurs?|contained) in (?:the )?(?:word|string )?["\']?([\p{L}\p{N}_ -]{1,120})["\']?(?:\?|\.|,|$)/iu',
        '/\bho man (?:letters?|ltters?|ltter)? ["\']?([\p{L}\p{N}])["\']? (?:in |are in |is in |b in |be in )?(?:da |the )?(?:word|string )?["\']?([\p{L}\p{N}_ -]{1,120})["\']?(?:\?|\.|,|$)/iu',
        '/\bwie ?viele (?:buchstaben|zeichen) ["\']?([\p{L}\p{N}])["\']? (?:hat|sind in|kommen in) (?:dem |der |das |wort |string )?["\']?([\p{L}\p{N}_ -]{1,120})["\']?(?:\?|\.|,|$)/iu',
        '/\bwie oft (?:kommt|ist) ["\']?([\p{L}\p{N}])["\']? in (?:dem |der |das |wort |string )?["\']?([\p{L}\p{N}_ -]{1,120})["\']? (?:vor|enthalten)(?:\?|\.|,|$)/iu',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $normalized, $match) !== 1) {
            continue;
        }

        $needle = trim((string) $match[1], " \t\n\r\0\x0B\"'`");
        $subject = trim((string) $match[2], " \t\n\r\0\x0B\"'`?,.;:");
        $subject = (string) preg_replace('/\s+(?:answer|respond|reply|antworte|gib|nur|only)\b.*$/iu', '', $subject);
        $subject = trim($subject, " \t\n\r\0\x0B\"'`?,.;:");
        if ($needle === '' || $subject === '') {
            continue;
        }

        return (string) king_openai_router_count_occurrences($subject, $needle);
    }

    return null;
}

function king_openai_router_deterministic_content(array $payload): ?string
{
    $userText = king_openai_router_last_user_text($payload);
    if ($userText === '') {
        return null;
    }

    return king_openai_router_exact_output_content($userText)
        ?? king_openai_router_count_task_content($userText);
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
