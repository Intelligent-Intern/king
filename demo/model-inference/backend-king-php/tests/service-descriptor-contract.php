<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/discovery/service_descriptor.php';

function service_descriptor_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[service-descriptor-contract] FAIL: {$message}\n");
    exit(1);
}

/** @param array<string, mixed> $payload */
function service_descriptor_contract_expect_reject(array $payload, string $label): void
{
    $rejected = false;
    try {
        model_inference_validate_service_descriptor($payload);
    } catch (ServiceDescriptorValidationError $e) {
        $rejected = true;
    }
    service_descriptor_contract_assert($rejected, "must reject: {$label}");
}

try {
    $rulesAsserted = 0;

    // 1. Valid minimal descriptor (no capabilities, no tags).
    $valid = model_inference_validate_service_descriptor([
        'service_id' => 'svc-node-a',
        'service_type' => 'king.inference.v1',
        'name' => 'node-a inference',
        'description' => 'Primary inference node serving SmolLM2-135M-Instruct with Q4_K quantization.',
    ]);
    service_descriptor_contract_assert($valid['service_id'] === 'svc-node-a', 'service_id must be preserved');
    service_descriptor_contract_assert($valid['service_type'] === 'king.inference.v1', 'service_type must be preserved');
    service_descriptor_contract_assert($valid['capabilities'] === [], 'capabilities must default to []');
    service_descriptor_contract_assert($valid['tags'] === [], 'tags must default to []');
    $rulesAsserted += 4;

    // 2. Valid descriptor with capabilities and tags.
    $full = model_inference_validate_service_descriptor([
        'service_id' => 'svc-tool-weather',
        'service_type' => 'king.tool.v1',
        'name' => 'weather lookup',
        'description' => 'Lookup current weather by city name.',
        'capabilities' => ['weather', 'geocoding'],
        'tags' => ['external', 'read-only'],
    ]);
    service_descriptor_contract_assert($full['capabilities'] === ['weather', 'geocoding'], 'capabilities preserved');
    service_descriptor_contract_assert($full['tags'] === ['external', 'read-only'], 'tags preserved');
    $rulesAsserted += 2;

    // 3. service_id validation.
    service_descriptor_contract_expect_reject([
        'service_type' => 'x', 'name' => 'n', 'description' => 'd',
    ], 'missing service_id');
    service_descriptor_contract_expect_reject([
        'service_id' => '', 'service_type' => 'x', 'name' => 'n', 'description' => 'd',
    ], 'empty service_id');
    service_descriptor_contract_expect_reject([
        'service_id' => str_repeat('x', 129),
        'service_type' => 'x', 'name' => 'n', 'description' => 'd',
    ], 'service_id too long');
    service_descriptor_contract_expect_reject([
        'service_id' => 123, 'service_type' => 'x', 'name' => 'n', 'description' => 'd',
    ], 'service_id not string');
    $rulesAsserted += 4;

    // 4. service_type validation.
    service_descriptor_contract_expect_reject([
        'service_id' => 'a', 'name' => 'n', 'description' => 'd',
    ], 'missing service_type');
    service_descriptor_contract_expect_reject([
        'service_id' => 'a', 'service_type' => '', 'name' => 'n', 'description' => 'd',
    ], 'empty service_type');
    service_descriptor_contract_expect_reject([
        'service_id' => 'a', 'service_type' => str_repeat('x', 65),
        'name' => 'n', 'description' => 'd',
    ], 'service_type too long');
    $rulesAsserted += 3;

    // 5. name validation.
    service_descriptor_contract_expect_reject([
        'service_id' => 'a', 'service_type' => 't', 'description' => 'd',
    ], 'missing name');
    service_descriptor_contract_expect_reject([
        'service_id' => 'a', 'service_type' => 't', 'name' => str_repeat('x', 129),
        'description' => 'd',
    ], 'name too long');
    $rulesAsserted += 2;

    // 6. description validation.
    service_descriptor_contract_expect_reject([
        'service_id' => 'a', 'service_type' => 't', 'name' => 'n',
    ], 'missing description');
    service_descriptor_contract_expect_reject([
        'service_id' => 'a', 'service_type' => 't', 'name' => 'n',
        'description' => str_repeat('x', 2049),
    ], 'description too long');
    $rulesAsserted += 2;

    // 7. capabilities validation.
    service_descriptor_contract_expect_reject([
        'service_id' => 'a', 'service_type' => 't', 'name' => 'n', 'description' => 'd',
        'capabilities' => 'not-an-array',
    ], 'capabilities not array');
    service_descriptor_contract_expect_reject([
        'service_id' => 'a', 'service_type' => 't', 'name' => 'n', 'description' => 'd',
        'capabilities' => array_fill(0, 33, 'x'),
    ], 'too many capabilities');
    service_descriptor_contract_expect_reject([
        'service_id' => 'a', 'service_type' => 't', 'name' => 'n', 'description' => 'd',
        'capabilities' => [''],
    ], 'empty capability string');
    service_descriptor_contract_expect_reject([
        'service_id' => 'a', 'service_type' => 't', 'name' => 'n', 'description' => 'd',
        'capabilities' => [123],
    ], 'non-string capability');
    service_descriptor_contract_expect_reject([
        'service_id' => 'a', 'service_type' => 't', 'name' => 'n', 'description' => 'd',
        'capabilities' => [str_repeat('x', 65)],
    ], 'capability item too long');
    $rulesAsserted += 5;

    // 8. tags validation.
    service_descriptor_contract_expect_reject([
        'service_id' => 'a', 'service_type' => 't', 'name' => 'n', 'description' => 'd',
        'tags' => array_fill(0, 17, 'x'),
    ], 'too many tags');
    service_descriptor_contract_expect_reject([
        'service_id' => 'a', 'service_type' => 't', 'name' => 'n', 'description' => 'd',
        'tags' => [123],
    ], 'non-string tag');
    $rulesAsserted += 2;

    // 9. Unknown top-level key rejected.
    service_descriptor_contract_expect_reject([
        'service_id' => 'a', 'service_type' => 't', 'name' => 'n', 'description' => 'd',
        'extra' => true,
    ], 'unknown top-level key');
    $rulesAsserted++;

    // 10. Non-array payload rejected.
    $rejected = false;
    try {
        model_inference_validate_service_descriptor('not-an-array');
    } catch (ServiceDescriptorValidationError $e) {
        $rejected = true;
    }
    service_descriptor_contract_assert($rejected, 'must reject non-array payload');
    $rulesAsserted++;

    // 11. Validation error has toDetails().
    try {
        model_inference_validate_service_descriptor([]);
    } catch (ServiceDescriptorValidationError $e) {
        $details = $e->toDetails();
        service_descriptor_contract_assert(array_key_exists('field', $details), 'toDetails must include field');
        service_descriptor_contract_assert(array_key_exists('reason', $details), 'toDetails must include reason');
        service_descriptor_contract_assert(array_key_exists('observed', $details), 'toDetails must include observed');
        $rulesAsserted += 3;
    }

    // 12. embedding_text includes name and description.
    $embeddingText = model_inference_service_descriptor_embedding_text($full);
    service_descriptor_contract_assert(str_contains($embeddingText, 'weather lookup'), 'embedding text contains name');
    service_descriptor_contract_assert(str_contains($embeddingText, 'Lookup current weather'), 'embedding text contains description');
    service_descriptor_contract_assert(str_contains($embeddingText, 'weather'), 'embedding text contains capability');
    service_descriptor_contract_assert(str_contains($embeddingText, 'external'), 'embedding text contains tag');
    $rulesAsserted += 4;

    // 13. embedding_text from descriptor without capabilities/tags skips those lines.
    $emptyExtras = model_inference_service_descriptor_embedding_text($valid);
    service_descriptor_contract_assert(!str_contains($emptyExtras, 'capabilities:'), 'embedding text omits empty capabilities');
    service_descriptor_contract_assert(!str_contains($emptyExtras, 'tags:'), 'embedding text omits empty tags');
    $rulesAsserted += 2;

    // 14. Deterministic normalization: validating an already-normalized descriptor returns the same shape.
    $roundtrip = model_inference_validate_service_descriptor($full);
    service_descriptor_contract_assert($roundtrip === $full, 'validation is idempotent');
    $rulesAsserted++;

    // 15. Contract JSON fixture loads and pins the allowed top-level keys.
    $contractPath = __DIR__ . '/../../contracts/v1/service-descriptor.contract.json';
    service_descriptor_contract_assert(is_file($contractPath), 'contract JSON fixture must exist');
    $contract = json_decode((string) file_get_contents($contractPath), true);
    service_descriptor_contract_assert(is_array($contract), 'contract JSON must parse');
    service_descriptor_contract_assert(
        $contract['contract_name'] === 'king-model-inference-service-descriptor',
        'contract_name must match'
    );
    $envelopeKeys = array_keys($contract['request_envelope']);
    sort($envelopeKeys);
    $allowedKeys = model_inference_service_descriptor_allowed_top_level_keys();
    sort($allowedKeys);
    service_descriptor_contract_assert(
        $envelopeKeys === $allowedKeys,
        'contract envelope keys must equal allowed_top_level_keys()'
    );
    $rulesAsserted += 4;

    fwrite(STDOUT, "[service-descriptor-contract] PASS ({$rulesAsserted} rules asserted)\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[service-descriptor-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
