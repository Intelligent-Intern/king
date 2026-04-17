<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/inference/inference_request.php';

function model_inference_request_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[inference-request-envelope-contract] FAIL: {$message}\n");
    exit(1);
}

/** @return array<string, mixed> */
function model_inference_request_contract_valid_http_payload(): array
{
    return [
        'session_id' => 'sess-demo-01',
        'model_selector' => [
            'model_name' => 'SmolLM2-135M-Instruct',
            'quantization' => 'Q4_K',
            'prefer_local' => true,
        ],
        'prompt' => 'Write a single sentence about oceans.',
        'sampling' => [
            'temperature' => 0.7,
            'top_p' => 0.95,
            'top_k' => 40,
            'max_tokens' => 64,
        ],
        'stream' => false,
    ];
}

/**
 * Expect a validation failure with exactly this code+field+reason-prefix.
 *
 * @param callable $closure
 */
function model_inference_request_contract_expect_error(
    callable $closure,
    string $expectedCode,
    string $expectedField,
    ?string $reasonSubstring = null,
    string $contextLabel = ''
): void {
    try {
        $closure();
    } catch (InferenceRequestValidationError $error) {
        model_inference_request_contract_assert(
            $error->errorCode === $expectedCode,
            "[{$contextLabel}] expected code={$expectedCode}, got {$error->errorCode}"
        );
        model_inference_request_contract_assert(
            $error->field === $expectedField,
            "[{$contextLabel}] expected field={$expectedField}, got {$error->field}"
        );
        if ($reasonSubstring !== null) {
            model_inference_request_contract_assert(
                str_contains($error->reason, $reasonSubstring),
                "[{$contextLabel}] reason '{$error->reason}' must contain '{$reasonSubstring}'"
            );
        }
        $details = $error->toDetails();
        model_inference_request_contract_assert(
            ($details['field'] ?? null) === $expectedField,
            "[{$contextLabel}] toDetails().field must equal field"
        );
        model_inference_request_contract_assert(
            is_string($details['reason'] ?? null) && $details['reason'] !== '',
            "[{$contextLabel}] toDetails().reason must be non-empty string"
        );
        model_inference_request_contract_assert(
            array_key_exists('observed', $details),
            "[{$contextLabel}] toDetails() must expose 'observed'"
        );
        return;
    }
    model_inference_request_contract_assert(false, "[{$contextLabel}] expected InferenceRequestValidationError, none thrown");
}

try {
    // 1. Happy-path HTTP: normalizes fully.
    $http = model_inference_validate_infer_request(model_inference_request_contract_valid_http_payload(), ['transport' => 'http']);
    model_inference_request_contract_assert($http['session_id'] === 'sess-demo-01', 'session_id passthrough');
    model_inference_request_contract_assert($http['model_selector']['prefer_local'] === true, 'prefer_local default true');
    model_inference_request_contract_assert($http['stream'] === false, 'stream=false passthrough');
    model_inference_request_contract_assert($http['system'] === null, 'omitted system must normalize to null');
    model_inference_request_contract_assert($http['sampling']['seed'] === null, 'omitted seed must normalize to null');

    // 2. Happy-path WS: stream=true required.
    $wsPayload = model_inference_request_contract_valid_http_payload();
    $wsPayload['stream'] = true;
    $wsPayload['sampling']['seed'] = 42;
    $ws = model_inference_validate_infer_request($wsPayload, ['transport' => 'ws']);
    model_inference_request_contract_assert($ws['stream'] === true, 'ws stream=true passthrough');
    model_inference_request_contract_assert($ws['sampling']['seed'] === 42, 'explicit seed passthrough');

    // 3. HTTP rejects stream=true.
    $p = model_inference_request_contract_valid_http_payload();
    $p['stream'] = true;
    model_inference_request_contract_expect_error(
        static fn () => model_inference_validate_infer_request($p, ['transport' => 'http']),
        'invalid_request_envelope', 'stream', 'not valid on POST', 'http-stream-true'
    );

    // 4. WS rejects stream=false.
    $p = model_inference_request_contract_valid_http_payload();
    $p['stream'] = false;
    model_inference_request_contract_expect_error(
        static fn () => model_inference_validate_infer_request($p, ['transport' => 'ws']),
        'invalid_request_envelope', 'stream', 'not valid on WS', 'ws-stream-false'
    );

    // 5. Non-array payload.
    model_inference_request_contract_expect_error(
        static fn () => model_inference_validate_infer_request('not-an-object'),
        'invalid_request_envelope', '', 'must be a JSON object', 'non-array payload'
    );

    // 6. Missing session_id.
    $p = model_inference_request_contract_valid_http_payload();
    unset($p['session_id']);
    model_inference_request_contract_expect_error(
        static fn () => model_inference_validate_infer_request($p),
        'invalid_request_envelope', 'session_id', 'required string', 'missing session_id'
    );

    // 7. session_id with illegal char.
    $p = model_inference_request_contract_valid_http_payload();
    $p['session_id'] = 'has spaces';
    model_inference_request_contract_expect_error(
        static fn () => model_inference_validate_infer_request($p),
        'invalid_request_envelope', 'session_id', 'must match', 'session_id bad-char'
    );

    // 8. Unknown top-level key.
    $p = model_inference_request_contract_valid_http_payload();
    $p['extra'] = 'nope';
    model_inference_request_contract_expect_error(
        static fn () => model_inference_validate_infer_request($p),
        'invalid_request_envelope', 'extra', 'unknown top-level key', 'unknown top-level'
    );

    // 9. Missing model_selector.
    $p = model_inference_request_contract_valid_http_payload();
    unset($p['model_selector']);
    model_inference_request_contract_expect_error(
        static fn () => model_inference_validate_infer_request($p),
        'invalid_request_envelope', 'model_selector', 'required object', 'missing model_selector'
    );

    // 10. model_selector missing model_name.
    $p = model_inference_request_contract_valid_http_payload();
    unset($p['model_selector']['model_name']);
    model_inference_request_contract_expect_error(
        static fn () => model_inference_validate_infer_request($p),
        'invalid_request_envelope', 'model_selector.model_name', 'required string', 'missing model_name'
    );

    // 11. model_selector bad quantization.
    $p = model_inference_request_contract_valid_http_payload();
    $p['model_selector']['quantization'] = 'Q9_X';
    model_inference_request_contract_expect_error(
        static fn () => model_inference_validate_infer_request($p),
        'invalid_request_envelope', 'model_selector.quantization', 'must be one of', 'bad quantization'
    );

    // 12. model_selector.prefer_local not bool.
    $p = model_inference_request_contract_valid_http_payload();
    $p['model_selector']['prefer_local'] = 1;
    model_inference_request_contract_expect_error(
        static fn () => model_inference_validate_infer_request($p),
        'invalid_request_envelope', 'model_selector.prefer_local', 'must be a boolean', 'prefer_local int'
    );

    // 13. Unknown key inside model_selector.
    $p = model_inference_request_contract_valid_http_payload();
    $p['model_selector']['fast'] = true;
    model_inference_request_contract_expect_error(
        static fn () => model_inference_validate_infer_request($p),
        'invalid_request_envelope', 'model_selector.fast', 'unknown key inside model_selector', 'unknown selector key'
    );

    // 14. Empty prompt.
    $p = model_inference_request_contract_valid_http_payload();
    $p['prompt'] = '';
    model_inference_request_contract_expect_error(
        static fn () => model_inference_validate_infer_request($p),
        'invalid_request_envelope', 'prompt', 'at least 1 chars', 'empty prompt'
    );

    // 15. Oversized prompt (>131072).
    $p = model_inference_request_contract_valid_http_payload();
    $p['prompt'] = str_repeat('x', 131073);
    model_inference_request_contract_expect_error(
        static fn () => model_inference_validate_infer_request($p),
        'invalid_request_envelope', 'prompt', 'at most 131072 chars', 'oversized prompt'
    );

    // 16. system not-a-string.
    $p = model_inference_request_contract_valid_http_payload();
    $p['system'] = 42;
    model_inference_request_contract_expect_error(
        static fn () => model_inference_validate_infer_request($p),
        'invalid_request_envelope', 'system', 'must be a string', 'system int'
    );

    // 17. Empty system is accepted and normalized to null (not a validation failure).
    $p = model_inference_request_contract_valid_http_payload();
    $p['system'] = '';
    $normalized = model_inference_validate_infer_request($p);
    model_inference_request_contract_assert($normalized['system'] === null, 'empty system must normalize to null');

    // 18. sampling missing.
    $p = model_inference_request_contract_valid_http_payload();
    unset($p['sampling']);
    model_inference_request_contract_expect_error(
        static fn () => model_inference_validate_infer_request($p),
        'invalid_request_envelope', 'sampling', 'required object', 'missing sampling'
    );

    // 19. sampling.temperature out of range (high).
    $p = model_inference_request_contract_valid_http_payload();
    $p['sampling']['temperature'] = 2.5;
    model_inference_request_contract_expect_error(
        static fn () => model_inference_validate_infer_request($p),
        'invalid_request_envelope', 'sampling.temperature', 'within [0, 2]', 'temp high'
    );

    // 20. sampling.temperature out of range (negative).
    $p = model_inference_request_contract_valid_http_payload();
    $p['sampling']['temperature'] = -0.1;
    model_inference_request_contract_expect_error(
        static fn () => model_inference_validate_infer_request($p),
        'invalid_request_envelope', 'sampling.temperature', 'within', 'temp low'
    );

    // 21. sampling.top_p out of range (>1).
    $p = model_inference_request_contract_valid_http_payload();
    $p['sampling']['top_p'] = 1.5;
    model_inference_request_contract_expect_error(
        static fn () => model_inference_validate_infer_request($p),
        'invalid_request_envelope', 'sampling.top_p', 'within', 'top_p high'
    );

    // 22. sampling.top_k out of range.
    $p = model_inference_request_contract_valid_http_payload();
    $p['sampling']['top_k'] = 2048;
    model_inference_request_contract_expect_error(
        static fn () => model_inference_validate_infer_request($p),
        'invalid_request_envelope', 'sampling.top_k', 'within [0, 1024]', 'top_k too big'
    );

    // 23. sampling.max_tokens zero.
    $p = model_inference_request_contract_valid_http_payload();
    $p['sampling']['max_tokens'] = 0;
    model_inference_request_contract_expect_error(
        static fn () => model_inference_validate_infer_request($p),
        'invalid_request_envelope', 'sampling.max_tokens', 'within [1, 8192]', 'max_tokens zero'
    );

    // 24. sampling.max_tokens too big.
    $p = model_inference_request_contract_valid_http_payload();
    $p['sampling']['max_tokens'] = 9000;
    model_inference_request_contract_expect_error(
        static fn () => model_inference_validate_infer_request($p),
        'invalid_request_envelope', 'sampling.max_tokens', 'within [1, 8192]', 'max_tokens high'
    );

    // 25. sampling.seed negative.
    $p = model_inference_request_contract_valid_http_payload();
    $p['sampling']['seed'] = -1;
    model_inference_request_contract_expect_error(
        static fn () => model_inference_validate_infer_request($p),
        'invalid_request_envelope', 'sampling.seed', 'int in [0, 4294967295]', 'seed negative'
    );

    // 26. sampling.seed above u32.
    $p = model_inference_request_contract_valid_http_payload();
    $p['sampling']['seed'] = 4294967296;
    model_inference_request_contract_expect_error(
        static fn () => model_inference_validate_infer_request($p),
        'invalid_request_envelope', 'sampling.seed', 'int in [0, 4294967295]', 'seed overflow'
    );

    // 27. Unknown key inside sampling.
    $p = model_inference_request_contract_valid_http_payload();
    $p['sampling']['mirostat'] = 1;
    model_inference_request_contract_expect_error(
        static fn () => model_inference_validate_infer_request($p),
        'invalid_request_envelope', 'sampling.mirostat', 'unknown key inside sampling', 'unknown sampling key'
    );

    // 28. Missing stream entirely.
    $p = model_inference_request_contract_valid_http_payload();
    unset($p['stream']);
    model_inference_request_contract_expect_error(
        static fn () => model_inference_validate_infer_request($p),
        'invalid_request_envelope', 'stream', 'required boolean', 'missing stream'
    );

    // 29. stream wrong type.
    $p = model_inference_request_contract_valid_http_payload();
    $p['stream'] = 'yes';
    model_inference_request_contract_expect_error(
        static fn () => model_inference_validate_infer_request($p),
        'invalid_request_envelope', 'stream', 'must be a boolean', 'stream string'
    );

    // 30. Catalog rejection codes + details shape sanity.
    $catalogPath = __DIR__ . '/../../contracts/v1/api-ws-contract.catalog.json';
    $catalog = json_decode((string) @file_get_contents($catalogPath), true);
    model_inference_request_contract_assert(is_array($catalog), 'catalog must be readable');
    $catalogCodes = (array) (($catalog['errors'] ?? [])['codes'] ?? []);
    foreach (['invalid_request_envelope'] as $required) {
        model_inference_request_contract_assert(in_array($required, $catalogCodes, true), "catalog.errors.codes must list '{$required}'");
    }

    // 31. Float-acceptance: an integer in a float slot is promoted silently.
    $p = model_inference_request_contract_valid_http_payload();
    $p['sampling']['temperature'] = 1;
    $normalized = model_inference_validate_infer_request($p);
    model_inference_request_contract_assert($normalized['sampling']['temperature'] === 1.0, 'integer in float slot must normalize to float');

    // 32. Int-acceptance: an integral float in an int slot is accepted.
    $p = model_inference_request_contract_valid_http_payload();
    $p['sampling']['top_k'] = 40.0;
    $normalized = model_inference_validate_infer_request($p);
    model_inference_request_contract_assert($normalized['sampling']['top_k'] === 40, 'integral float in int slot must normalize to int');

    // 33. Non-integral float in int slot is rejected.
    $p = model_inference_request_contract_valid_http_payload();
    $p['sampling']['top_k'] = 40.5;
    model_inference_request_contract_expect_error(
        static fn () => model_inference_validate_infer_request($p),
        'invalid_request_envelope', 'sampling.top_k', 'must be an integer', 'non-integral top_k'
    );

    fwrite(STDOUT, "[inference-request-envelope-contract] PASS (33 rules asserted)\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[inference-request-envelope-contract] ERROR: ' . $error->getMessage() . ' @ ' . $error->getFile() . ':' . $error->getLine() . "\n");
    exit(1);
}
