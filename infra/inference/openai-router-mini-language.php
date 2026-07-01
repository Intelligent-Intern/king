<?php
declare(strict_types=1);

function king_openai_router_mini_language_specs(): array
{
    return [
        'string.count_occurrences' => [
            'input' => [
                'subject' => 'string',
                'needle' => 'string',
                'case_sensitive' => 'bool',
            ],
            'output' => [
                'count' => 'int',
            ],
            'pure' => true,
            'io' => 'none',
        ],
        'string.literal_contains' => [
            'input' => [
                'subject' => 'string',
                'needle' => 'string',
                'case_sensitive' => 'bool',
            ],
            'output' => [
                'contains' => 'bool',
            ],
            'pure' => true,
            'io' => 'none',
        ],
        'math.evaluate_arithmetic' => [
            'input' => [
                'expression' => 'string',
            ],
            'output' => [
                'value' => 'int|float',
            ],
            'pure' => true,
            'io' => 'none',
        ],
    ];
}

function king_openai_router_mini_language_last_user_text(array $payload): string
{
    $messages = is_array($payload['messages'] ?? null) ? $payload['messages'] : [];
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

function king_openai_router_mini_language_program_from_payload(array $payload): ?array
{
    $text = king_openai_router_mini_language_last_user_text($payload);
    if ($text === '') {
        return null;
    }

    return king_openai_router_mini_language_count_program($text)
        ?? king_openai_router_mini_language_contains_program($text)
        ?? king_openai_router_mini_language_arithmetic_program($text);
}

function king_openai_router_mini_language_program_base(string $operation, array $input): array
{
    return [
        'language' => 'king-mini-language',
        'version' => 1,
        'operation' => $operation,
        'input' => $input,
        'safety' => [
            'pure' => true,
            'filesystem' => false,
            'cli' => false,
            'network' => false,
        ],
    ];
}

function king_openai_router_mini_language_count_program(string $text): ?array
{
    $normalized = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    if ($normalized === '') {
        return null;
    }

    $patterns = [
        '/\bhow many (?:letters?|characters?) ["\']?(\p{L}|\p{N})["\']? (?:are |are there )?in (?:the )?(?:word|string )?["\']?([\p{L}\p{N}_ -]{1,80})["\']?\??$/iu',
        '/\bhow often (?:does|is) ["\']?(\p{L}|\p{N})["\']? (?:appear|occurs?|contained) in (?:the )?(?:word|string )?["\']?([\p{L}\p{N}_ -]{1,80})["\']?\??$/iu',
        '/\bwie ?viele (?:buchstaben|zeichen) ["\']?(\p{L}|\p{N})["\']? (?:hat|sind in|kommen in) (?:dem |der |das |wort |string )?["\']?([\p{L}\p{N}_ -]{1,80})["\']?\??$/iu',
        '/\bwie oft (?:kommt|ist) ["\']?(\p{L}|\p{N})["\']? in (?:dem |der |das |wort |string )?["\']?([\p{L}\p{N}_ -]{1,80})["\']? (?:vor|enthalten)\??$/iu',
        '/\bho man ltter r ["\']?(\p{L}|\p{N})["\']? in da word ["\']?([\p{L}\p{N}_ -]{1,80})["\']?\??$/iu',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $normalized, $match) !== 1) {
            continue;
        }

        $needle = trim($match[1]);
        $subject = trim($match[2], " \t\n\r\0\x0B\"'");
        if ($needle === '' || $subject === '') {
            continue;
        }

        return king_openai_router_mini_language_program_base('string.count_occurrences', [
            'subject' => $subject,
            'needle' => $needle,
            'case_sensitive' => true,
        ]);
    }

    return null;
}

function king_openai_router_mini_language_contains_program(string $text): ?array
{
    $normalized = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    if ($normalized === '') {
        return null;
    }

    $patterns = [
        '/\bdoes (?:the )?(?:word|string )?["\']?([\p{L}\p{N}_ -]{1,80})["\']? contain ["\']?([\p{L}\p{N}_ -]{1,32})["\']?\??$/iu',
        '/\bis ["\']?([\p{L}\p{N}_ -]{1,32})["\']? contained in (?:the )?(?:word|string )?["\']?([\p{L}\p{N}_ -]{1,80})["\']?\??$/iu',
        '/\benthaelt (?:das )?(?:wort|string )?["\']?([\p{L}\p{N}_ -]{1,80})["\']? ["\']?([\p{L}\p{N}_ -]{1,32})["\']?\??$/iu',
    ];

    foreach ($patterns as $index => $pattern) {
        if (preg_match($pattern, $normalized, $match) !== 1) {
            continue;
        }

        $subject = trim($index === 1 ? $match[2] : $match[1], " \t\n\r\0\x0B\"'");
        $needle = trim($index === 1 ? $match[1] : $match[2], " \t\n\r\0\x0B\"'");
        if ($subject === '' || $needle === '') {
            continue;
        }

        return king_openai_router_mini_language_program_base('string.literal_contains', [
            'subject' => $subject,
            'needle' => $needle,
            'case_sensitive' => true,
        ]);
    }

    return null;
}

function king_openai_router_mini_language_arithmetic_program(string $text): ?array
{
    $normalized = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    if ($normalized === '') {
        return null;
    }

    $expression = null;
    if (preg_match('/\b(?:calculate|compute|what is|was ist|rechne)\s+([0-9][0-9+\-*\/().\s]{0,79})\??$/iu', $normalized, $match) === 1) {
        $expression = trim($match[1]);
    } elseif (preg_match('/^[0-9+\-*\/().\s]{1,80}\??$/', $normalized) === 1 && preg_match('/[+\-*\/]/', $normalized) === 1) {
        $expression = rtrim($normalized, " ?\t\n\r\0\x0B");
    }

    if (!is_string($expression) || $expression === '') {
        return null;
    }
    if (king_openai_router_mini_math_evaluate($expression)['ok'] !== true) {
        return null;
    }

    return king_openai_router_mini_language_program_base('math.evaluate_arithmetic', [
        'expression' => $expression,
    ]);
}

function king_openai_router_mini_language_execute(array $program): array
{
    if (($program['language'] ?? null) !== 'king-mini-language' || ($program['version'] ?? null) !== 1) {
        return ['ok' => false, 'error' => 'invalid_program_header'];
    }

    $operation = $program['operation'] ?? null;
    $input = $program['input'] ?? null;
    if (!is_string($operation) || !is_array($input)) {
        return ['ok' => false, 'error' => 'invalid_program_shape'];
    }

    return match ($operation) {
        'string.count_occurrences' => king_openai_router_mini_language_execute_count($program, $input),
        'string.literal_contains' => king_openai_router_mini_language_execute_contains($program, $input),
        'math.evaluate_arithmetic' => king_openai_router_mini_language_execute_arithmetic($program, $input),
        default => ['ok' => false, 'error' => 'unsupported_operation'],
    };
}

function king_openai_router_mini_language_execute_count(array $program, array $input): array
{
    if (!is_string($input['subject'] ?? null) || !is_string($input['needle'] ?? null) || !is_bool($input['case_sensitive'] ?? null)) {
        return ['ok' => false, 'error' => 'invalid_count_input'];
    }

    $count = king_openai_router_mini_count_literal($input['subject'], $input['needle'], $input['case_sensitive']);
    return [
        'ok' => true,
        'content' => (string) $count,
        'program' => $program,
        'result' => [
            'type' => 'integer',
            'value' => $count,
        ],
    ];
}

function king_openai_router_mini_language_execute_contains(array $program, array $input): array
{
    if (!is_string($input['subject'] ?? null) || !is_string($input['needle'] ?? null) || !is_bool($input['case_sensitive'] ?? null)) {
        return ['ok' => false, 'error' => 'invalid_contains_input'];
    }

    $subject = $input['case_sensitive'] ? $input['subject'] : strtolower($input['subject']);
    $needle = $input['case_sensitive'] ? $input['needle'] : strtolower($input['needle']);
    $contains = $needle !== '' && str_contains($subject, $needle);
    return [
        'ok' => true,
        'content' => $contains ? 'true' : 'false',
        'program' => $program,
        'result' => [
            'type' => 'boolean',
            'value' => $contains,
        ],
    ];
}

function king_openai_router_mini_language_execute_arithmetic(array $program, array $input): array
{
    if (!is_string($input['expression'] ?? null)) {
        return ['ok' => false, 'error' => 'invalid_arithmetic_input'];
    }

    $value = king_openai_router_mini_math_evaluate($input['expression']);
    if ($value['ok'] !== true) {
        return ['ok' => false, 'error' => $value['error'] ?? 'invalid_arithmetic'];
    }

    $number = $value['value'];
    $content = is_int($number) || floor((float) $number) === (float) $number
        ? (string) (int) $number
        : rtrim(rtrim(sprintf('%.12F', (float) $number), '0'), '.');
    return [
        'ok' => true,
        'content' => $content,
        'program' => $program,
        'result' => [
            'type' => is_int($number) || floor((float) $number) === (float) $number ? 'integer' : 'float',
            'value' => $number,
        ],
    ];
}

function king_openai_router_mini_language_result(array $payload): ?array
{
    $program = king_openai_router_mini_language_program_from_payload($payload);
    if (!is_array($program)) {
        return null;
    }

    $result = king_openai_router_mini_language_execute($program);
    return !empty($result['ok']) ? $result : null;
}

function king_openai_router_mini_count_literal(string $subject, string $needle, bool $caseSensitive): int
{
    if ($needle === '') {
        return 0;
    }

    $pattern = '/' . preg_quote($needle, '/') . '/u';
    if (!$caseSensitive) {
        $pattern .= 'i';
    }
    $matched = preg_match_all($pattern, $subject);
    return is_int($matched) ? $matched : 0;
}

function king_openai_router_mini_math_evaluate(string $expression): array
{
    $expression = trim($expression);
    if ($expression === '' || strlen($expression) > 80 || preg_match('/^[0-9+\-*\/().\s]+$/', $expression) !== 1) {
        return ['ok' => false, 'error' => 'invalid_arithmetic_expression'];
    }

    preg_match_all('/\d+(?:\.\d+)?|[()+\-*\/]/', $expression, $matches);
    $tokens = $matches[0] ?? [];
    if ($tokens === []) {
        return ['ok' => false, 'error' => 'empty_arithmetic_expression'];
    }
    if (implode('', $tokens) !== preg_replace('/\s+/', '', $expression)) {
        return ['ok' => false, 'error' => 'invalid_arithmetic_tokens'];
    }

    $position = 0;
    $result = king_openai_router_mini_math_parse_expression($tokens, $position);
    if ($result['ok'] !== true) {
        return $result;
    }
    if ($position !== count($tokens)) {
        return ['ok' => false, 'error' => 'trailing_arithmetic_tokens'];
    }

    return $result;
}

function king_openai_router_mini_math_parse_expression(array $tokens, int &$position): array
{
    $left = king_openai_router_mini_math_parse_term($tokens, $position);
    if ($left['ok'] !== true) {
        return $left;
    }

    while ($position < count($tokens) && in_array($tokens[$position], ['+', '-'], true)) {
        $operator = $tokens[$position++];
        $right = king_openai_router_mini_math_parse_term($tokens, $position);
        if ($right['ok'] !== true) {
            return $right;
        }
        $left['value'] = $operator === '+'
            ? $left['value'] + $right['value']
            : $left['value'] - $right['value'];
    }

    return $left;
}

function king_openai_router_mini_math_parse_term(array $tokens, int &$position): array
{
    $left = king_openai_router_mini_math_parse_factor($tokens, $position);
    if ($left['ok'] !== true) {
        return $left;
    }

    while ($position < count($tokens) && in_array($tokens[$position], ['*', '/'], true)) {
        $operator = $tokens[$position++];
        $right = king_openai_router_mini_math_parse_factor($tokens, $position);
        if ($right['ok'] !== true) {
            return $right;
        }
        if ($operator === '/' && (float) $right['value'] === 0.0) {
            return ['ok' => false, 'error' => 'division_by_zero'];
        }
        $left['value'] = $operator === '*'
            ? $left['value'] * $right['value']
            : $left['value'] / $right['value'];
    }

    return $left;
}

function king_openai_router_mini_math_parse_factor(array $tokens, int &$position): array
{
    if ($position >= count($tokens)) {
        return ['ok' => false, 'error' => 'expected_arithmetic_factor'];
    }

    $token = $tokens[$position++];
    if ($token === '+') {
        return king_openai_router_mini_math_parse_factor($tokens, $position);
    }
    if ($token === '-') {
        $value = king_openai_router_mini_math_parse_factor($tokens, $position);
        if ($value['ok'] === true) {
            $value['value'] = -$value['value'];
        }
        return $value;
    }
    if ($token === '(') {
        $value = king_openai_router_mini_math_parse_expression($tokens, $position);
        if ($value['ok'] !== true) {
            return $value;
        }
        if (($tokens[$position] ?? null) !== ')') {
            return ['ok' => false, 'error' => 'unclosed_arithmetic_group'];
        }
        $position++;
        return $value;
    }
    if (is_numeric($token)) {
        $value = str_contains($token, '.') ? (float) $token : (int) $token;
        return ['ok' => true, 'value' => $value];
    }

    return ['ok' => false, 'error' => 'unexpected_arithmetic_token'];
}
