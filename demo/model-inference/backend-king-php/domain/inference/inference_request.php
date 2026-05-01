<?php

declare(strict_types=1);

/**
 * Pure validator for the inference-request envelope shared by
 * POST /api/infer (#M-10) and WS 'infer.start' (#M-11).
 *
 * See demo/model-inference/contracts/v1/inference-request.contract.json for
 * the pinned shape. Every validation rule in this file maps 1:1 to a rule
 * documented there; keep them aligned when either changes.
 *
 * Exceptions carry a machine-readable payload (field + reason + observed)
 * via InvalidArgumentException::getMessage() using the format
 *   "<canonical_code>|<field>|<reason>|<observed_json>"
 * so the route handlers can project it into the typed error envelope
 * without re-parsing free text.
 */

final class InferenceRequestValidationError extends InvalidArgumentException
{
    public string $errorCode;
    public string $field;
    public string $reason;
    /** @var mixed */
    public $observed;

    /**
     * @param mixed $observed
     */
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
function model_inference_request_allowed_quantizations(): array
{
    return ['Q2_K', 'Q3_K', 'Q4_0', 'Q4_K', 'Q5_K', 'Q6_K', 'Q8_0', 'F16'];
}

/** @return array<int, string> */
function model_inference_request_allowed_top_level_keys(): array
{
    return ['session_id', 'model_selector', 'prompt', 'system', 'messages', 'sampling', 'stream'];
}

/** @return array<int, string> */
function model_inference_request_allowed_message_roles(): array
{
    return ['system', 'user', 'assistant'];
}

/**
 * Validate + normalize an inference request payload.
 *
 * $options['transport'] gates the 'stream' field. Allowed values:
 *   - 'http' (default): stream MUST be false; stream=true rejected
 *     with invalid_request_envelope (an HTTP client cannot consume
 *     token-frame bursts).
 *   - 'ws': stream MUST be true; stream=false rejected with
 *     invalid_request_envelope (a WS route is not a JSON one-shot
 *     endpoint).
 *
 * @param mixed $payload  decoded request body (expected array<string, mixed>)
 * @param array<string, mixed> $options
 * @return array<string, mixed> normalized envelope
 * @throws InferenceRequestValidationError on any rule violation
 */
function model_inference_validate_infer_request($payload, array $options = []): array
{
    if (!is_array($payload)) {
        throw new InferenceRequestValidationError(
            'invalid_request_envelope',
            '',
            'payload must be a JSON object',
            gettype($payload)
        );
    }

    $transport = (string) ($options['transport'] ?? 'http');
    if (!in_array($transport, ['http', 'ws'], true)) {
        throw new InvalidArgumentException("unknown transport '{$transport}'");
    }

    $allowedKeys = model_inference_request_allowed_top_level_keys();
    foreach (array_keys($payload) as $key) {
        if (!is_string($key) || !in_array($key, $allowedKeys, true)) {
            throw new InferenceRequestValidationError(
                'invalid_request_envelope',
                (string) $key,
                'unknown top-level key (allowed: ' . implode(',', $allowedKeys) . ')',
                $key
            );
        }
    }

    $sessionId = model_inference_request_require_string($payload, 'session_id', 1, 128);
    if (preg_match('/^[A-Za-z0-9_.:\-]+$/', $sessionId) !== 1) {
        throw new InferenceRequestValidationError(
            'invalid_request_envelope',
            'session_id',
            'must match [A-Za-z0-9_.:-]+',
            $sessionId
        );
    }

    $selector = model_inference_request_require_object($payload, 'model_selector');
    $modelName = model_inference_request_require_string($selector, 'model_name', 1, 128, 'model_selector.');
    $quantization = model_inference_request_require_string($selector, 'quantization', 1, 16, 'model_selector.');
    if (!in_array($quantization, model_inference_request_allowed_quantizations(), true)) {
        throw new InferenceRequestValidationError(
            'invalid_request_envelope',
            'model_selector.quantization',
            'must be one of ' . implode(',', model_inference_request_allowed_quantizations()),
            $quantization
        );
    }
    $preferLocal = true;
    if (array_key_exists('prefer_local', $selector)) {
        if (!is_bool($selector['prefer_local'])) {
            throw new InferenceRequestValidationError(
                'invalid_request_envelope',
                'model_selector.prefer_local',
                'must be a boolean',
                $selector['prefer_local']
            );
        }
        $preferLocal = $selector['prefer_local'];
    }
    foreach (array_keys($selector) as $key) {
        if (!in_array($key, ['model_name', 'quantization', 'prefer_local'], true)) {
            throw new InferenceRequestValidationError(
                'invalid_request_envelope',
                "model_selector.{$key}",
                'unknown key inside model_selector',
                $key
            );
        }
    }

    $messages = null;
    if (array_key_exists('messages', $payload)) {
        $messages = model_inference_request_require_messages_array($payload['messages']);
    }

    $promptRequired = ($messages === null);
    $prompt = null;
    if ($promptRequired) {
        $prompt = model_inference_request_require_string($payload, 'prompt', 1, 131072);
    } elseif (array_key_exists('prompt', $payload)) {
        if (!is_string($payload['prompt'])) {
            throw new InferenceRequestValidationError(
                'invalid_request_envelope', 'prompt', 'must be a string when present', gettype($payload['prompt'])
            );
        }
        if (strlen($payload['prompt']) < 1 || strlen($payload['prompt']) > 131072) {
            throw new InferenceRequestValidationError(
                'invalid_request_envelope', 'prompt', 'must be 1..131072 chars', strlen($payload['prompt'])
            );
        }
        $prompt = $payload['prompt'];
    }

    $system = null;
    if (array_key_exists('system', $payload)) {
        if (!is_string($payload['system'])) {
            throw new InferenceRequestValidationError(
                'invalid_request_envelope',
                'system',
                'must be a string when present',
                $payload['system']
            );
        }
        if ($payload['system'] !== '') {
            if (strlen($payload['system']) > 32768) {
                throw new InferenceRequestValidationError(
                    'invalid_request_envelope',
                    'system',
                    'must be <= 32768 chars',
                    strlen($payload['system'])
                );
            }
            $system = $payload['system'];
        }
    }

    $sampling = model_inference_request_require_object($payload, 'sampling');
    $temperature = model_inference_request_require_float($sampling, 'temperature', 0.0, 2.0, 'sampling.');
    $topP = model_inference_request_require_float($sampling, 'top_p', 0.0, 1.0, 'sampling.');
    $topK = model_inference_request_require_int($sampling, 'top_k', 0, 1024, 'sampling.');
    $maxTokens = model_inference_request_require_int($sampling, 'max_tokens', 1, 8192, 'sampling.');
    $seed = null;
    if (array_key_exists('seed', $sampling)) {
        $seedValue = $sampling['seed'];
        if ($seedValue !== null) {
            if (!is_int($seedValue) || $seedValue < 0 || $seedValue > 4294967295) {
                throw new InferenceRequestValidationError(
                    'invalid_request_envelope',
                    'sampling.seed',
                    'must be an int in [0, 4294967295] when present',
                    $seedValue
                );
            }
            $seed = $seedValue;
        }
    }

    // T-3: repetition penalties (OpenAI-compatible). llama.cpp's
    // /v1/chat/completions accepts these and applies them in the sampler
    // stack to push the model away from copying recent tokens — the fix
    // for small-model mode collapse like "assistant always echoes the
    // previous reply".
    $frequencyPenalty = 0.0;
    if (array_key_exists('frequency_penalty', $sampling)) {
        $frequencyPenalty = model_inference_request_require_float(
            $sampling, 'frequency_penalty', -2.0, 2.0, 'sampling.'
        );
    }
    $presencePenalty = 0.0;
    if (array_key_exists('presence_penalty', $sampling)) {
        $presencePenalty = model_inference_request_require_float(
            $sampling, 'presence_penalty', -2.0, 2.0, 'sampling.'
        );
    }

    foreach (array_keys($sampling) as $key) {
        if (!in_array($key, ['temperature', 'top_p', 'top_k', 'max_tokens', 'seed', 'frequency_penalty', 'presence_penalty'], true)) {
            throw new InferenceRequestValidationError(
                'invalid_request_envelope',
                "sampling.{$key}",
                'unknown key inside sampling',
                $key
            );
        }
    }

    if (!array_key_exists('stream', $payload)) {
        throw new InferenceRequestValidationError(
            'invalid_request_envelope',
            'stream',
            'required boolean',
            null
        );
    }
    if (!is_bool($payload['stream'])) {
        throw new InferenceRequestValidationError(
            'invalid_request_envelope',
            'stream',
            'must be a boolean',
            $payload['stream']
        );
    }
    $stream = $payload['stream'];

    if ($transport === 'http' && $stream === true) {
        throw new InferenceRequestValidationError(
            'invalid_request_envelope',
            'stream',
            'stream=true is not valid on POST /api/infer; use the WS infer.start event instead',
            $stream
        );
    }
    if ($transport === 'ws' && $stream === false) {
        throw new InferenceRequestValidationError(
            'invalid_request_envelope',
            'stream',
            'stream=false is not valid on WS infer.start; use POST /api/infer for one-shot JSON responses',
            $stream
        );
    }

    return [
        'session_id' => $sessionId,
        'model_selector' => [
            'model_name' => $modelName,
            'quantization' => $quantization,
            'prefer_local' => $preferLocal,
        ],
        'prompt' => $prompt,
        'system' => $system,
        'messages' => $messages,
        'sampling' => [
            'temperature' => $temperature,
            'top_p' => $topP,
            'top_k' => $topK,
            'max_tokens' => $maxTokens,
            'seed' => $seed,
            'frequency_penalty' => $frequencyPenalty,
            'presence_penalty' => $presencePenalty,
        ],
        'stream' => $stream,
    ];
}

/**
 * Validate the optional messages[] field: 1..64 items of {role, content}
 * where role ∈ {system, user, assistant} and content is 1..32768 chars.
 * The final message's role MUST be user (the turn the model is responding
 * to) unless prompt is also present (in which case the server will append
 * the prompt as the final user turn).
 *
 * @param mixed $raw
 * @return array<int, array{role: string, content: string}>
 */
function model_inference_request_require_messages_array($raw): array
{
    if (!is_array($raw) || $raw !== array_values($raw)) {
        throw new InferenceRequestValidationError(
            'invalid_request_envelope', 'messages', 'must be a JSON array', gettype($raw)
        );
    }
    $count = count($raw);
    if ($count < 1) {
        throw new InferenceRequestValidationError(
            'invalid_request_envelope', 'messages', 'must contain at least 1 item', $count
        );
    }
    if ($count > 64) {
        throw new InferenceRequestValidationError(
            'invalid_request_envelope', 'messages', 'must contain at most 64 items', $count
        );
    }
    $allowedRoles = model_inference_request_allowed_message_roles();
    $result = [];
    foreach ($raw as $i => $item) {
        if (!is_array($item)) {
            throw new InferenceRequestValidationError(
                'invalid_request_envelope', "messages[{$i}]", 'must be an object', gettype($item)
            );
        }
        foreach (array_keys($item) as $k) {
            if (!in_array($k, ['role', 'content'], true)) {
                throw new InferenceRequestValidationError(
                    'invalid_request_envelope', "messages[{$i}].{$k}", 'unknown key inside message', $k
                );
            }
        }
        if (!isset($item['role']) || !is_string($item['role']) || !in_array($item['role'], $allowedRoles, true)) {
            throw new InferenceRequestValidationError(
                'invalid_request_envelope', "messages[{$i}].role",
                'must be one of ' . implode(',', $allowedRoles),
                $item['role'] ?? null
            );
        }
        if (!isset($item['content']) || !is_string($item['content'])) {
            throw new InferenceRequestValidationError(
                'invalid_request_envelope', "messages[{$i}].content", 'required string', $item['content'] ?? null
            );
        }
        $len = strlen($item['content']);
        if ($len < 1 || $len > 32768) {
            throw new InferenceRequestValidationError(
                'invalid_request_envelope', "messages[{$i}].content", 'must be 1..32768 chars', $len
            );
        }
        $result[] = ['role' => $item['role'], 'content' => $item['content']];
    }
    return $result;
}

/**
 * @param array<string, mixed> $payload
 */
function model_inference_request_require_string(array $payload, string $field, int $minLen, int $maxLen, string $prefix = ''): string
{
    $fullName = $prefix . $field;
    if (!array_key_exists($field, $payload)) {
        throw new InferenceRequestValidationError('invalid_request_envelope', $fullName, "required string", null);
    }
    $value = $payload[$field];
    if (!is_string($value)) {
        throw new InferenceRequestValidationError('invalid_request_envelope', $fullName, 'must be a string', $value);
    }
    $len = strlen($value);
    if ($len < $minLen) {
        throw new InferenceRequestValidationError('invalid_request_envelope', $fullName, "must be at least {$minLen} chars", $len);
    }
    if ($len > $maxLen) {
        throw new InferenceRequestValidationError('invalid_request_envelope', $fullName, "must be at most {$maxLen} chars", $len);
    }
    return $value;
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function model_inference_request_require_object(array $payload, string $field): array
{
    if (!array_key_exists($field, $payload)) {
        throw new InferenceRequestValidationError('invalid_request_envelope', $field, 'required object', null);
    }
    $value = $payload[$field];
    if (!is_array($value)) {
        throw new InferenceRequestValidationError('invalid_request_envelope', $field, 'must be an object', $value);
    }
    return $value;
}

/**
 * @param array<string, mixed> $payload
 */
function model_inference_request_require_float(array $payload, string $field, float $min, float $max, string $prefix = ''): float
{
    $fullName = $prefix . $field;
    if (!array_key_exists($field, $payload)) {
        throw new InferenceRequestValidationError('invalid_request_envelope', $fullName, 'required numeric', null);
    }
    $value = $payload[$field];
    if (is_int($value)) {
        $value = (float) $value;
    }
    if (!is_float($value)) {
        throw new InferenceRequestValidationError('invalid_request_envelope', $fullName, 'must be a number', $payload[$field]);
    }
    if ($value < $min || $value > $max) {
        throw new InferenceRequestValidationError('invalid_request_envelope', $fullName, "must be within [{$min}, {$max}]", $value);
    }
    return $value;
}

/**
 * @param array<string, mixed> $payload
 */
function model_inference_request_require_int(array $payload, string $field, int $min, int $max, string $prefix = ''): int
{
    $fullName = $prefix . $field;
    if (!array_key_exists($field, $payload)) {
        throw new InferenceRequestValidationError('invalid_request_envelope', $fullName, 'required integer', null);
    }
    $value = $payload[$field];
    if (is_float($value) && floor($value) === $value) {
        $value = (int) $value;
    }
    if (!is_int($value)) {
        throw new InferenceRequestValidationError('invalid_request_envelope', $fullName, 'must be an integer', $payload[$field]);
    }
    if ($value < $min || $value > $max) {
        throw new InferenceRequestValidationError('invalid_request_envelope', $fullName, "must be within [{$min}, {$max}]", $value);
    }
    return $value;
}
