<?php

declare(strict_types=1);

final class EmbeddingRequestValidationError extends InvalidArgumentException
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
function model_inference_embedding_request_allowed_top_level_keys(): array
{
    return ['texts', 'model_selector', 'options'];
}

/**
 * @param mixed $payload
 * @return array<string, mixed>
 * @throws EmbeddingRequestValidationError
 */
function model_inference_validate_embedding_request($payload): array
{
    if (!is_array($payload)) {
        throw new EmbeddingRequestValidationError(
            'invalid_request_envelope', '', 'payload must be a JSON object', gettype($payload)
        );
    }

    $allowedKeys = model_inference_embedding_request_allowed_top_level_keys();
    foreach (array_keys($payload) as $key) {
        if (!is_string($key) || !in_array($key, $allowedKeys, true)) {
            throw new EmbeddingRequestValidationError(
                'invalid_request_envelope', (string) $key,
                'unknown top-level key (allowed: ' . implode(',', $allowedKeys) . ')', $key
            );
        }
    }

    if (!array_key_exists('texts', $payload)) {
        throw new EmbeddingRequestValidationError(
            'invalid_request_envelope', 'texts', 'required array of strings', null
        );
    }
    $texts = $payload['texts'];
    if (!is_array($texts) || $texts !== array_values($texts)) {
        throw new EmbeddingRequestValidationError(
            'invalid_request_envelope', 'texts', 'must be a JSON array', gettype($texts)
        );
    }
    $textCount = count($texts);
    if ($textCount < 1) {
        throw new EmbeddingRequestValidationError(
            'invalid_request_envelope', 'texts', 'must contain at least 1 item', $textCount
        );
    }
    if ($textCount > 64) {
        throw new EmbeddingRequestValidationError(
            'invalid_request_envelope', 'texts', 'must contain at most 64 items', $textCount
        );
    }
    $validatedTexts = [];
    foreach ($texts as $i => $text) {
        if (!is_string($text)) {
            throw new EmbeddingRequestValidationError(
                'invalid_request_envelope', "texts[{$i}]", 'must be a string', gettype($text)
            );
        }
        $len = strlen($text);
        if ($len < 1) {
            throw new EmbeddingRequestValidationError(
                'invalid_request_envelope', "texts[{$i}]", 'must be at least 1 char', $len
            );
        }
        if ($len > 32768) {
            throw new EmbeddingRequestValidationError(
                'invalid_request_envelope', "texts[{$i}]", 'must be at most 32768 chars', $len
            );
        }
        $validatedTexts[] = $text;
    }

    if (!array_key_exists('model_selector', $payload)) {
        throw new EmbeddingRequestValidationError(
            'invalid_request_envelope', 'model_selector', 'required object', null
        );
    }
    $selector = $payload['model_selector'];
    if (!is_array($selector)) {
        throw new EmbeddingRequestValidationError(
            'invalid_request_envelope', 'model_selector', 'must be an object', gettype($selector)
        );
    }

    if (!array_key_exists('model_name', $selector) || !is_string($selector['model_name'])) {
        throw new EmbeddingRequestValidationError(
            'invalid_request_envelope', 'model_selector.model_name', 'required string', null
        );
    }
    $modelName = $selector['model_name'];
    if (strlen($modelName) < 1 || strlen($modelName) > 128) {
        throw new EmbeddingRequestValidationError(
            'invalid_request_envelope', 'model_selector.model_name', 'must be 1-128 chars', strlen($modelName)
        );
    }

    if (!array_key_exists('quantization', $selector) || !is_string($selector['quantization'])) {
        throw new EmbeddingRequestValidationError(
            'invalid_request_envelope', 'model_selector.quantization', 'required string', null
        );
    }
    $quantization = $selector['quantization'];
    $allowedQuants = ['Q2_K', 'Q3_K', 'Q4_0', 'Q4_K', 'Q5_K', 'Q6_K', 'Q8_0', 'F16'];
    if (!in_array($quantization, $allowedQuants, true)) {
        throw new EmbeddingRequestValidationError(
            'invalid_request_envelope', 'model_selector.quantization',
            'must be one of ' . implode(',', $allowedQuants), $quantization
        );
    }

    foreach (array_keys($selector) as $key) {
        if (!in_array($key, ['model_name', 'quantization'], true)) {
            throw new EmbeddingRequestValidationError(
                'invalid_request_envelope', "model_selector.{$key}", 'unknown key inside model_selector', $key
            );
        }
    }

    $normalize = true;
    $truncate = true;
    if (array_key_exists('options', $payload)) {
        $options = $payload['options'];
        if (!is_array($options)) {
            throw new EmbeddingRequestValidationError(
                'invalid_request_envelope', 'options', 'must be an object', gettype($options)
            );
        }
        foreach (array_keys($options) as $key) {
            if (!in_array($key, ['normalize', 'truncate'], true)) {
                throw new EmbeddingRequestValidationError(
                    'invalid_request_envelope', "options.{$key}", 'unknown key inside options', $key
                );
            }
        }
        if (array_key_exists('normalize', $options)) {
            if (!is_bool($options['normalize'])) {
                throw new EmbeddingRequestValidationError(
                    'invalid_request_envelope', 'options.normalize', 'must be a boolean', $options['normalize']
                );
            }
            $normalize = $options['normalize'];
        }
        if (array_key_exists('truncate', $options)) {
            if (!is_bool($options['truncate'])) {
                throw new EmbeddingRequestValidationError(
                    'invalid_request_envelope', 'options.truncate', 'must be a boolean', $options['truncate']
                );
            }
            $truncate = $options['truncate'];
        }
    }

    return [
        'texts' => $validatedTexts,
        'model_selector' => [
            'model_name' => $modelName,
            'quantization' => $quantization,
        ],
        'options' => [
            'normalize' => $normalize,
            'truncate' => $truncate,
        ],
    ];
}
