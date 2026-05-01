<?php

declare(strict_types=1);

final class ServiceDescriptorValidationError extends InvalidArgumentException
{
    public string $errorCode;
    public string $field;
    public string $reason;
    /** @var mixed */
    public $observed;

    /** @param mixed $observed */
    public function __construct(string $errorCode, string $field, string $reason, $observed = null)
    {
        $this->errorCode = $errorCode;
        $this->field = $field;
        $this->reason = $reason;
        $this->observed = $observed;
        parent::__construct($errorCode . '|' . $field . '|' . $reason);
    }

    /** @return array<string, mixed> */
    public function toDetails(): array
    {
        return [
            'field' => $this->field,
            'reason' => $this->reason,
            'observed' => $this->summarizeObserved($this->observed),
        ];
    }

    /** @param mixed $value @return mixed */
    private function summarizeObserved($value)
    {
        if (is_array($value)) {
            return 'array(' . count($value) . ')';
        }
        if (is_object($value)) {
            return 'object(' . $value::class . ')';
        }
        if (is_string($value) && strlen($value) > 80) {
            return substr($value, 0, 77) . '...';
        }
        return $value;
    }
}

/** @return array<int, string> */
function model_inference_service_descriptor_allowed_top_level_keys(): array
{
    return ['service_id', 'service_type', 'name', 'description', 'capabilities', 'tags'];
}

/**
 * Validate + normalize a service descriptor payload.
 *
 * @param mixed $payload
 * @return array<string, mixed> normalized descriptor with defaults applied
 * @throws ServiceDescriptorValidationError
 */
function model_inference_validate_service_descriptor($payload): array
{
    if (!is_array($payload)) {
        throw new ServiceDescriptorValidationError(
            'invalid_service_descriptor', '', 'payload must be a JSON object', gettype($payload)
        );
    }

    $allowed = model_inference_service_descriptor_allowed_top_level_keys();
    foreach (array_keys($payload) as $key) {
        if (!is_string($key) || !in_array($key, $allowed, true)) {
            throw new ServiceDescriptorValidationError(
                'invalid_service_descriptor', (string) $key,
                'unknown top-level key (allowed: ' . implode(',', $allowed) . ')', $key
            );
        }
    }

    $serviceId = model_inference_service_descriptor_bounded_string(
        $payload, 'service_id', 1, 128, true
    );
    $serviceType = model_inference_service_descriptor_bounded_string(
        $payload, 'service_type', 1, 64, true
    );
    $name = model_inference_service_descriptor_bounded_string(
        $payload, 'name', 1, 128, true
    );
    $description = model_inference_service_descriptor_bounded_string(
        $payload, 'description', 1, 2048, true
    );

    $capabilities = model_inference_service_descriptor_string_array(
        $payload, 'capabilities', 32, 1, 64
    );
    $tags = model_inference_service_descriptor_string_array(
        $payload, 'tags', 16, 1, 64
    );

    return [
        'service_id' => $serviceId,
        'service_type' => $serviceType,
        'name' => $name,
        'description' => $description,
        'capabilities' => $capabilities,
        'tags' => $tags,
    ];
}

/**
 * @param array<string, mixed> $payload
 */
function model_inference_service_descriptor_bounded_string(
    array $payload,
    string $field,
    int $minLength,
    int $maxLength,
    bool $required
): string {
    if (!array_key_exists($field, $payload)) {
        if (!$required) {
            return '';
        }
        throw new ServiceDescriptorValidationError(
            'invalid_service_descriptor', $field, 'required string', null
        );
    }
    $value = $payload[$field];
    if (!is_string($value)) {
        throw new ServiceDescriptorValidationError(
            'invalid_service_descriptor', $field, 'must be a string', gettype($value)
        );
    }
    $len = strlen($value);
    if ($len < $minLength) {
        throw new ServiceDescriptorValidationError(
            'invalid_service_descriptor', $field, "must be at least {$minLength} char(s)", $len
        );
    }
    if ($len > $maxLength) {
        throw new ServiceDescriptorValidationError(
            'invalid_service_descriptor', $field, "must be at most {$maxLength} char(s)", $len
        );
    }
    return $value;
}

/**
 * @param array<string, mixed> $payload
 * @return array<int, string>
 */
function model_inference_service_descriptor_string_array(
    array $payload,
    string $field,
    int $maxItems,
    int $itemMin,
    int $itemMax
): array {
    if (!array_key_exists($field, $payload)) {
        return [];
    }
    $value = $payload[$field];
    if (!is_array($value) || $value !== array_values($value)) {
        throw new ServiceDescriptorValidationError(
            'invalid_service_descriptor', $field, 'must be a JSON array', gettype($value)
        );
    }
    $count = count($value);
    if ($count > $maxItems) {
        throw new ServiceDescriptorValidationError(
            'invalid_service_descriptor', $field, "must contain at most {$maxItems} item(s)", $count
        );
    }
    $normalized = [];
    foreach ($value as $i => $item) {
        if (!is_string($item)) {
            throw new ServiceDescriptorValidationError(
                'invalid_service_descriptor', "{$field}[{$i}]", 'must be a string', gettype($item)
            );
        }
        $len = strlen($item);
        if ($len < $itemMin) {
            throw new ServiceDescriptorValidationError(
                'invalid_service_descriptor', "{$field}[{$i}]", "must be at least {$itemMin} char(s)", $len
            );
        }
        if ($len > $itemMax) {
            throw new ServiceDescriptorValidationError(
                'invalid_service_descriptor', "{$field}[{$i}]", "must be at most {$itemMax} char(s)", $len
            );
        }
        $normalized[] = $item;
    }
    return $normalized;
}

/**
 * Produce the single text blob that is embedded for a validated descriptor.
 * Fields are joined with newlines so each signal is a separable token stream
 * for the BM25 path in #S-6 while still contributing to the dense embedding.
 *
 * @param array<string, mixed> $descriptor already-validated descriptor
 */
function model_inference_service_descriptor_embedding_text(array $descriptor): string
{
    $lines = [];
    $lines[] = 'name: ' . $descriptor['name'];
    $lines[] = 'description: ' . $descriptor['description'];
    if (!empty($descriptor['capabilities'])) {
        $lines[] = 'capabilities: ' . implode(', ', $descriptor['capabilities']);
    }
    if (!empty($descriptor['tags'])) {
        $lines[] = 'tags: ' . implode(', ', $descriptor['tags']);
    }
    return implode("\n", $lines);
}
