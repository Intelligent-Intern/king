<?php

declare(strict_types=1);

require_once __DIR__ . '/hybrid_discover.php';

final class ToolDescriptorValidationError extends InvalidArgumentException
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
            'observed' => is_array($this->observed) ? 'array(' . count($this->observed) . ')' : $this->observed,
        ];
    }
}

/** @return array<int, string> */
function model_inference_tool_descriptor_allowed_top_level_keys(): array
{
    return ['tool_id', 'name', 'description', 'mcp_target', 'input_schema_ref', 'capabilities', 'tags'];
}

/**
 * @param mixed $payload
 * @return array<string, mixed>
 * @throws ToolDescriptorValidationError
 */
function model_inference_validate_tool_descriptor($payload): array
{
    if (!is_array($payload)) {
        throw new ToolDescriptorValidationError('invalid_tool_descriptor', '', 'payload must be a JSON object', gettype($payload));
    }

    $allowed = model_inference_tool_descriptor_allowed_top_level_keys();
    foreach (array_keys($payload) as $key) {
        if (!is_string($key) || !in_array($key, $allowed, true)) {
            throw new ToolDescriptorValidationError(
                'invalid_tool_descriptor', (string) $key,
                'unknown top-level key (allowed: ' . implode(',', $allowed) . ')', $key
            );
        }
    }

    $toolId = model_inference_tool_descriptor_bounded_string($payload, 'tool_id', 1, 128, true);
    $name = model_inference_tool_descriptor_bounded_string($payload, 'name', 1, 128, true);
    $description = model_inference_tool_descriptor_bounded_string($payload, 'description', 1, 2048, true);

    if (!isset($payload['mcp_target']) || !is_array($payload['mcp_target'])) {
        throw new ToolDescriptorValidationError('invalid_tool_descriptor', 'mcp_target', 'required object', null);
    }
    $target = $payload['mcp_target'];
    $targetAllowed = ['host', 'port', 'service', 'method'];
    foreach (array_keys($target) as $tk) {
        if (!in_array($tk, $targetAllowed, true)) {
            throw new ToolDescriptorValidationError('invalid_tool_descriptor', "mcp_target.{$tk}", 'unknown key inside mcp_target', $tk);
        }
    }
    $host = model_inference_tool_descriptor_bounded_string($target, 'host', 1, 255, true, 'mcp_target.');
    $service = model_inference_tool_descriptor_bounded_string($target, 'service', 1, 128, true, 'mcp_target.');
    $method = model_inference_tool_descriptor_bounded_string($target, 'method', 1, 128, true, 'mcp_target.');
    if (!isset($target['port']) || !is_int($target['port'])) {
        throw new ToolDescriptorValidationError('invalid_tool_descriptor', 'mcp_target.port', 'required int', $target['port'] ?? null);
    }
    $port = (int) $target['port'];
    if ($port < 1 || $port > 65535) {
        throw new ToolDescriptorValidationError('invalid_tool_descriptor', 'mcp_target.port', 'must be 1..65535', $port);
    }

    $inputSchemaRef = null;
    if (array_key_exists('input_schema_ref', $payload) && $payload['input_schema_ref'] !== null) {
        if (!is_string($payload['input_schema_ref'])) {
            throw new ToolDescriptorValidationError('invalid_tool_descriptor', 'input_schema_ref', 'must be a string or null', gettype($payload['input_schema_ref']));
        }
        $len = strlen($payload['input_schema_ref']);
        if ($len > 256) {
            throw new ToolDescriptorValidationError('invalid_tool_descriptor', 'input_schema_ref', 'must be <= 256 chars', $len);
        }
        $inputSchemaRef = $payload['input_schema_ref'];
    }

    $capabilities = model_inference_tool_descriptor_string_array($payload, 'capabilities', 32, 1, 64);
    $tags = model_inference_tool_descriptor_string_array($payload, 'tags', 16, 1, 64);

    return [
        'tool_id' => $toolId,
        'name' => $name,
        'description' => $description,
        'mcp_target' => [
            'host' => $host,
            'port' => $port,
            'service' => $service,
            'method' => $method,
        ],
        'input_schema_ref' => $inputSchemaRef,
        'capabilities' => $capabilities,
        'tags' => $tags,
    ];
}

/** @param array<string, mixed> $payload */
function model_inference_tool_descriptor_bounded_string(
    array $payload,
    string $field,
    int $minLength,
    int $maxLength,
    bool $required,
    string $prefix = ''
): string {
    if (!array_key_exists($field, $payload)) {
        if (!$required) {
            return '';
        }
        throw new ToolDescriptorValidationError('invalid_tool_descriptor', $prefix . $field, 'required string', null);
    }
    $value = $payload[$field];
    if (!is_string($value)) {
        throw new ToolDescriptorValidationError('invalid_tool_descriptor', $prefix . $field, 'must be a string', gettype($value));
    }
    $len = strlen($value);
    if ($len < $minLength) {
        throw new ToolDescriptorValidationError('invalid_tool_descriptor', $prefix . $field, "must be at least {$minLength} char(s)", $len);
    }
    if ($len > $maxLength) {
        throw new ToolDescriptorValidationError('invalid_tool_descriptor', $prefix . $field, "must be at most {$maxLength} char(s)", $len);
    }
    return $value;
}

/**
 * @param array<string, mixed> $payload
 * @return array<int, string>
 */
function model_inference_tool_descriptor_string_array(
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
        throw new ToolDescriptorValidationError('invalid_tool_descriptor', $field, 'must be a JSON array', gettype($value));
    }
    if (count($value) > $maxItems) {
        throw new ToolDescriptorValidationError('invalid_tool_descriptor', $field, "must contain at most {$maxItems} items", count($value));
    }
    $result = [];
    foreach ($value as $i => $item) {
        if (!is_string($item)) {
            throw new ToolDescriptorValidationError('invalid_tool_descriptor', "{$field}[{$i}]", 'must be string', gettype($item));
        }
        $len = strlen($item);
        if ($len < $itemMin) {
            throw new ToolDescriptorValidationError('invalid_tool_descriptor', "{$field}[{$i}]", "must be at least {$itemMin} chars", $len);
        }
        if ($len > $itemMax) {
            throw new ToolDescriptorValidationError('invalid_tool_descriptor', "{$field}[{$i}]", "must be at most {$itemMax} chars", $len);
        }
        $result[] = $item;
    }
    return $result;
}

/**
 * Produce the embedding text for a validated tool descriptor.
 * Same general shape as the service descriptor variant but with a "tool:" prefix
 * so the BM25 token space stays deterministic.
 *
 * @param array<string, mixed> $descriptor
 */
function model_inference_tool_descriptor_embedding_text(array $descriptor): string
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

/**
 * Descriptor-field extractor mirroring model_inference_hybrid_tokenize_descriptor
 * but keyed for tools. Declared up here so module_discover.php can use it before
 * tool_embedding_store.php is loaded.
 *
 * @param array<string, mixed> $descriptor
 * @return array<int, string>
 */
function model_inference_hybrid_tokenize_tool_descriptor(array $descriptor): array
{
    $pieces = [];
    if (isset($descriptor['name']) && is_string($descriptor['name'])) {
        $pieces[] = $descriptor['name'];
    }
    if (isset($descriptor['description']) && is_string($descriptor['description'])) {
        $pieces[] = $descriptor['description'];
    }
    foreach ((array) ($descriptor['capabilities'] ?? []) as $cap) {
        if (is_string($cap)) {
            $pieces[] = $cap;
        }
    }
    foreach ((array) ($descriptor['tags'] ?? []) as $tag) {
        if (is_string($tag)) {
            $pieces[] = $tag;
        }
    }
    return model_inference_hybrid_tokenize(implode(' ', $pieces));
}
