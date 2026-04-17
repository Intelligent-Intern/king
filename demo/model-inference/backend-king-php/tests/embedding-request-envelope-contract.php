<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/embedding/embedding_request.php';

function embedding_request_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[embedding-request-envelope-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    $rulesAsserted = 0;

    // 1. Valid minimal request.
    $valid = model_inference_validate_embedding_request([
        'texts' => ['hello world'],
        'model_selector' => ['model_name' => 'nomic-embed-text-v1.5', 'quantization' => 'Q8_0'],
    ]);
    embedding_request_contract_assert($valid['texts'] === ['hello world'], 'texts must be preserved');
    embedding_request_contract_assert($valid['model_selector']['model_name'] === 'nomic-embed-text-v1.5', 'model_name must be preserved');
    embedding_request_contract_assert($valid['options']['normalize'] === true, 'normalize must default to true');
    embedding_request_contract_assert($valid['options']['truncate'] === true, 'truncate must default to true');
    $rulesAsserted += 4;

    // 2. Valid request with options.
    $withOptions = model_inference_validate_embedding_request([
        'texts' => ['a', 'b', 'c'],
        'model_selector' => ['model_name' => 'test', 'quantization' => 'F16'],
        'options' => ['normalize' => false, 'truncate' => false],
    ]);
    embedding_request_contract_assert($withOptions['options']['normalize'] === false, 'normalize=false must be accepted');
    embedding_request_contract_assert($withOptions['options']['truncate'] === false, 'truncate=false must be accepted');
    embedding_request_contract_assert(count($withOptions['texts']) === 3, 'multiple texts must be accepted');
    $rulesAsserted += 3;

    // 3. texts validation.
    $rejections = [
        ['missing texts'       => [], 'field' => 'texts'],
        ['empty texts array'   => ['texts' => [], 'model_selector' => ['model_name' => 't', 'quantization' => 'Q8_0']], 'field' => 'texts'],
        ['non-string in texts' => ['texts' => [123], 'model_selector' => ['model_name' => 't', 'quantization' => 'Q8_0']], 'field' => 'texts[0]'],
        ['empty string item'   => ['texts' => [''], 'model_selector' => ['model_name' => 't', 'quantization' => 'Q8_0']], 'field' => 'texts[0]'],
        ['too many texts'      => ['texts' => array_fill(0, 65, 'x'), 'model_selector' => ['model_name' => 't', 'quantization' => 'Q8_0']], 'field' => 'texts'],
    ];
    foreach ($rejections as $case) {
        $label = array_keys($case)[0];
        if ($label === 'field') {
            continue;
        }
        $payload = $case[$label];
        $rejected = false;
        try {
            model_inference_validate_embedding_request($payload);
        } catch (EmbeddingRequestValidationError $e) {
            $rejected = true;
        }
        embedding_request_contract_assert($rejected, "must reject: {$label}");
        $rulesAsserted++;
    }

    // 4. model_selector validation.
    $selectorCases = [
        'missing model_selector' => ['texts' => ['a']],
        'missing model_name' => ['texts' => ['a'], 'model_selector' => ['quantization' => 'Q8_0']],
        'missing quantization' => ['texts' => ['a'], 'model_selector' => ['model_name' => 'test']],
        'invalid quantization' => ['texts' => ['a'], 'model_selector' => ['model_name' => 'test', 'quantization' => 'INVALID']],
        'unknown selector key' => ['texts' => ['a'], 'model_selector' => ['model_name' => 'test', 'quantization' => 'Q8_0', 'extra' => true]],
    ];
    foreach ($selectorCases as $label => $payload) {
        $rejected = false;
        try {
            model_inference_validate_embedding_request($payload);
        } catch (EmbeddingRequestValidationError $e) {
            $rejected = true;
        }
        embedding_request_contract_assert($rejected, "must reject: {$label}");
        $rulesAsserted++;
    }

    // 5. options validation.
    $optionsCases = [
        'options not object' => ['texts' => ['a'], 'model_selector' => ['model_name' => 't', 'quantization' => 'Q8_0'], 'options' => 'string'],
        'normalize not bool' => ['texts' => ['a'], 'model_selector' => ['model_name' => 't', 'quantization' => 'Q8_0'], 'options' => ['normalize' => 1]],
        'truncate not bool' => ['texts' => ['a'], 'model_selector' => ['model_name' => 't', 'quantization' => 'Q8_0'], 'options' => ['truncate' => 'yes']],
        'unknown option key' => ['texts' => ['a'], 'model_selector' => ['model_name' => 't', 'quantization' => 'Q8_0'], 'options' => ['extra' => true]],
    ];
    foreach ($optionsCases as $label => $payload) {
        $rejected = false;
        try {
            model_inference_validate_embedding_request($payload);
        } catch (EmbeddingRequestValidationError $e) {
            $rejected = true;
        }
        embedding_request_contract_assert($rejected, "must reject: {$label}");
        $rulesAsserted++;
    }

    // 6. Unknown top-level key rejected.
    $rejected = false;
    try {
        model_inference_validate_embedding_request([
            'texts' => ['a'],
            'model_selector' => ['model_name' => 't', 'quantization' => 'Q8_0'],
            'extra_key' => true,
        ]);
    } catch (EmbeddingRequestValidationError $e) {
        $rejected = true;
    }
    embedding_request_contract_assert($rejected, 'must reject unknown top-level keys');
    $rulesAsserted++;

    // 7. Non-array payload rejected.
    $rejected = false;
    try {
        model_inference_validate_embedding_request('not an array');
    } catch (EmbeddingRequestValidationError $e) {
        $rejected = true;
    }
    embedding_request_contract_assert($rejected, 'must reject non-array payload');
    $rulesAsserted++;

    // 8. All quantizations accepted.
    foreach (['Q2_K', 'Q3_K', 'Q4_0', 'Q4_K', 'Q5_K', 'Q6_K', 'Q8_0', 'F16'] as $quant) {
        $v = model_inference_validate_embedding_request([
            'texts' => ['test'],
            'model_selector' => ['model_name' => 'test', 'quantization' => $quant],
        ]);
        embedding_request_contract_assert($v['model_selector']['quantization'] === $quant, "quantization {$quant} must be accepted");
        $rulesAsserted++;
    }

    // 9. Validation error has toDetails().
    try {
        model_inference_validate_embedding_request([]);
    } catch (EmbeddingRequestValidationError $e) {
        $details = $e->toDetails();
        embedding_request_contract_assert(array_key_exists('field', $details), 'toDetails must include field');
        embedding_request_contract_assert(array_key_exists('reason', $details), 'toDetails must include reason');
        $rulesAsserted += 2;
    }

    fwrite(STDOUT, "[embedding-request-envelope-contract] PASS ({$rulesAsserted} rules asserted)\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[embedding-request-envelope-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
