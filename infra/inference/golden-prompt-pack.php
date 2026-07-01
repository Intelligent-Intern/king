<?php
declare(strict_types=1);

function king_inference_golden_prompt_pack(): array
{
    $system = 'You are a deterministic King inference contract runner. Follow the requested output format exactly.';
    $cases = [];
    $seed = 3000;
    $addCase = static function (
        string $category,
        string $name,
        string $prompt,
        array $expected,
        int $maxTokens = 32,
        string $scope = 'model',
        ?array $stop = null
    ) use (&$cases, &$seed, $system): void {
        $case = [
            'name' => 'pack_' . $name,
            'category' => $category,
            'coverage' => $category,
            'scope' => $scope,
            'mode' => 'deterministic',
            'sampler' => ['temperature' => 0.0, 'top_p' => 1.0, 'seed' => $seed++],
            'max_tokens' => $maxTokens,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $prompt],
            ],
            'expected' => $expected,
        ];
        if ($stop !== null) {
            $case['stop'] = $stop;
        }
        $cases[] = $case;
    };
    $addQualitative = static function (
        string $category,
        string $name,
        string $prompt,
        int $maxTokens = 160
    ) use (&$cases, &$seed, $system): void {
        $cases[] = [
            'name' => 'pack_' . $name,
            'category' => $category,
            'coverage' => $category,
            'scope' => 'model',
            'mode' => 'qualitative',
            'sampler' => ['temperature' => 0.2, 'top_p' => 0.9, 'seed' => $seed++],
            'max_tokens' => $maxTokens,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $prompt],
            ],
        ];
    };

    for ($i = 1; $i <= 12; $i++) {
        $marker = sprintf('KING_EXACT_%02d', $i);
        $addCase(
            'exact output',
            strtolower($marker),
            'Return exactly this marker and nothing else: ' . $marker,
            ['type' => 'exact', 'tokens' => [$marker]],
            12,
            'fast'
        );
    }

    foreach ([
        ['banana', 'a', '3'], ['letter', 'e', '2'], ['mississippi', 's', '4'],
        ['committee', 'm', '2'], ['bookkeeper', 'e', '3'], ['parallel', 'l', '2'],
        ['sassafras', 's', '4'], ['alloy', 'l', '2'], ['queue', 'u', '2'],
        ['abracadabra', 'a', '5'], ['programming', 'm', '2'], ['cranberry', 'r', '2'],
        ['encyclopedia', 'e', '2'], ['Tennessee', 'e', '4'], ['assessment', 's', '4'],
        ['development', 'e', '3'], ['controller', 'l', '2'], ['minimum', 'm', '3'],
        ['referer', 'r', '3'], ['configuration', 'i', '2'],
    ] as $index => [$word, $needle, $count]) {
        $addCase(
            'counting',
            sprintf('count_%02d_%s_%s', $index + 1, strtolower($word), $needle),
            sprintf('How many letters %s are in the word %s? Answer with only the number.', $needle, $word),
            ['type' => 'exact', 'tokens' => [$count]],
            8,
            'fast'
        );
    }

    foreach ([
        ['7 + 5', '12'], ['13 + 29', '42'], ['100 - 37', '63'], ['9 * 8', '72'],
        ['144 / 12', '12'], ['6 * 7 + 1', '43'], ['90 - 45', '45'], ['18 + 24', '42'],
        ['11 * 11', '121'], ['81 / 9', '9'], ['14 + 15', '29'], ['64 - 19', '45'],
        ['12 * 12', '144'], ['125 / 5', '25'], ['33 + 44', '77'], ['70 - 28', '42'],
        ['8 * 13', '104'], ['96 / 8', '12'], ['17 + 19', '36'], ['55 - 13', '42'],
        ['15 * 6', '90'], ['72 / 6', '12'], ['101 - 59', '42'], ['23 + 19', '42'],
    ] as $index => [$expression, $answer]) {
        $addCase(
            'arithmetic',
            'arithmetic_' . sprintf('%02d', $index + 1),
            'Calculate ' . $expression . '. Answer only with the number.',
            ['type' => 'exact', 'tokens' => [$answer]],
            10,
            'fast'
        );
    }

    foreach ([
        ['status', 'ok'], ['route', 'openai'], ['backend', 'king'], ['format', 'json'],
        ['runtime', 'native'], ['stream', 'ready'], ['cache', 'off'], ['profile', 'cpu'],
        ['profile', 'gpu'], ['mode', 'strict'], ['scope', 'fast'], ['scope', 'model'],
    ] as $index => [$key, $value]) {
        $addCase(
            'JSON contract',
            'json_' . sprintf('%02d', $index + 1),
            sprintf('Return only this JSON object: {"%s":"%s"}', $key, $value),
            ['type' => 'json_object', 'object' => [$key => $value]],
            32,
            'model'
        );
    }

    foreach ([
        'King Notes', 'Runtime Contract', 'Inference Smoke', 'GPU Profile',
        'Prompt Pack', 'Router Check', 'Tokenizer Step', 'Sampler Result',
    ] as $index => $heading) {
        $addCase(
            'Markdown source contract',
            'markdown_' . sprintf('%02d', $index + 1),
            'Return Markdown source with one H1 heading named ' . $heading . '. Wrap it in a triple-tilde markdown fence.',
            ['type' => 'contains_all', 'texts' => ['~~~markdown', '# ' . $heading, '~~~']],
            64,
            'model'
        );
    }

    foreach ([
        ['king_alpha', 'alpha'], ['king_beta', 'beta'], ['king_gamma', 'gamma'],
        ['king_delta', 'delta'], ['king_epsilon', 'epsilon'], ['king_zeta', 'zeta'],
        ['king_eta', 'eta'], ['king_theta', 'theta'], ['king_iota', 'iota'], ['king_kappa', 'kappa'],
    ] as $index => [$function, $value]) {
        $addCase(
            'PHP code contract',
            'php_' . sprintf('%02d', $index + 1),
            sprintf('Return only a PHP code fence containing a function named %s that returns the string %s.', $function, $value),
            ['type' => 'contains_all', 'texts' => ['```php', 'function ' . $function, 'return', $value]],
            96,
            'model'
        );
    }

    foreach ([
        ['Gib exakt JA aus.', 'JA'], ['Gib exakt NEIN aus.', 'NEIN'], ['Gib exakt OK aus.', 'OK'],
        ['Gib exakt 42 aus.', '42'], ['Gib exakt King aus.', 'King'], ['Gib exakt Token aus.', 'Token'],
        ['Gib exakt Modell aus.', 'Modell'], ['Gib exakt GPU aus.', 'GPU'], ['Gib exakt CPU aus.', 'CPU'],
        ['Gib exakt Router aus.', 'Router'],
    ] as $index => [$prompt, $answer]) {
        $addCase(
            'German exact instruction',
            'german_exact_' . sprintf('%02d', $index + 1),
            $prompt,
            ['type' => 'exact', 'tokens' => [$answer]],
            12,
            'fast'
        );
    }

    for ($i = 1; $i <= 8; $i++) {
        $stop = sprintf('STOP_PACK_%02d', $i);
        $addCase(
            'stop token behavior',
            'stop_' . sprintf('%02d', $i),
            sprintf('Respond with exactly: before %s after', $stop),
            ['type' => 'not_contains', 'text' => $stop],
            32,
            'fast',
            [$stop]
        );
    }

    foreach ([
        ['classification', 'Classify whether this text asks for code, explanation, or data: Write a PHP function for parsing JSON.'],
        ['classification', 'Classify whether this text asks for code, explanation, or data: Explain what a token is.'],
        ['classification', 'Classify whether this text asks for code, explanation, or data: Return a JSON object with status ok.'],
        ['summarization', 'Summarize in one sentence why deterministic prompt probes are useful.'],
        ['summarization', 'Summarize in one sentence why silent CPU fallback is dangerous.'],
        ['reasoning sample', 'Explain briefly how a stop sequence should affect streamed output.'],
        ['reasoning sample', 'Explain briefly why exact JSON output must not include prose.'],
        ['reasoning sample', 'Explain briefly what a tokenizer does before inference.'],
    ] as $index => [$category, $prompt]) {
        $addQualitative($category, 'qualitative_' . sprintf('%02d', $index + 1), $prompt);
    }

    return [
        'id' => 'king-core-prompt-pack-v1',
        'version' => 1,
        'categories' => [
            'exact output' => 'Strict string contracts for format drift.',
            'counting' => 'Small deterministic character counting checks.',
            'arithmetic' => 'Small deterministic arithmetic checks.',
            'JSON contract' => 'Machine-readable JSON-only responses.',
            'Markdown source contract' => 'Markdown source fences and headings.',
            'PHP code contract' => 'Simple PHP code-fence generation contracts.',
            'German exact instruction' => 'German instruction following with exact outputs.',
            'stop token behavior' => 'Stop-sequence boundary checks.',
            'classification' => 'Qualitative intent classification samples.',
            'summarization' => 'Qualitative short-answer samples.',
            'reasoning sample' => 'Qualitative explanation samples.',
        ],
        'cases' => $cases,
    ];
}
