<?php

declare(strict_types=1);

require_once __DIR__ . '/service_descriptor.php';
require_once __DIR__ . '/service_embedding_store.php';

/**
 * Compose descriptor validation + text assembly + embedding + persistence
 * into a single idempotent upsert. The embedder is injected so this path can
 * be exercised both against the real EmbeddingSession and against test fakes.
 *
 * @param array<string, mixed> $descriptorPayload raw payload, validated here
 * @param callable             $embedder          fn(string $text): array{
 *                                                    vector: array<int, float>,
 *                                                    duration_ms?: int,
 *                                                    tokens_used?: int
 *                                                }
 * @return array<string, mixed>
 * @throws ServiceDescriptorValidationError
 */
function model_inference_service_embedding_upsert(
    PDO $pdo,
    array $descriptorPayload,
    string $embeddingModelId,
    callable $embedder
): array {
    if ($embeddingModelId === '') {
        throw new InvalidArgumentException('embedding_model_id must be non-empty');
    }

    $descriptor = model_inference_validate_service_descriptor($descriptorPayload);
    $text = model_inference_service_descriptor_embedding_text($descriptor);

    $result = $embedder($text);
    if (!is_array($result) || !isset($result['vector']) || !is_array($result['vector'])) {
        throw new RuntimeException('service_embedding_upsert:embedder_returned_invalid_shape');
    }
    $vector = array_map('floatval', $result['vector']);
    if (count($vector) === 0) {
        throw new RuntimeException('service_embedding_upsert:empty_embedding_vector');
    }

    $stored = model_inference_service_embedding_store($pdo, $descriptor, $embeddingModelId, $vector);

    return [
        'service_id' => $descriptor['service_id'],
        'service_type' => $descriptor['service_type'],
        'embedding_model_id' => $embeddingModelId,
        'vector_id' => $stored['vector_id'],
        'dimensions' => $stored['dimensions'],
        'replaced' => $stored['replaced'],
        'embedding_duration_ms' => (int) ($result['duration_ms'] ?? 0),
        'tokens_used' => (int) ($result['tokens_used'] ?? 0),
        'updated_at' => $stored['updated_at'],
    ];
}

/**
 * Adapter from an EmbeddingSession + worker pair into the callable shape that
 * model_inference_service_embedding_upsert() expects.
 */
function model_inference_service_embedding_session_embedder(
    object $embeddingSession,
    object $worker,
    bool $normalize = true
): callable {
    return static function (string $text) use ($embeddingSession, $worker, $normalize): array {
        $out = $embeddingSession->embed($worker, [$text], $normalize);
        $embeddings = (array) ($out['embeddings'] ?? []);
        return [
            'vector' => is_array($embeddings[0] ?? null) ? $embeddings[0] : [],
            'duration_ms' => (int) ($out['duration_ms'] ?? 0),
            'tokens_used' => (int) ($out['tokens_used'] ?? 0),
        ];
    };
}
