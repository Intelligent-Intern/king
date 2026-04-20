<?php

declare(strict_types=1);

require_once __DIR__ . '/tool_descriptor.php';

function model_inference_tool_vector_generate_id(): string
{
    return 'tvec-' . bin2hex(random_bytes(8));
}

function model_inference_tool_embedding_schema_migrate(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS tool_embeddings (
        tool_id TEXT PRIMARY KEY,
        embedding_model_id TEXT NOT NULL,
        vector_id TEXT NOT NULL UNIQUE,
        dimensions INTEGER NOT NULL,
        object_store_key TEXT NOT NULL UNIQUE,
        descriptor_json TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');
}

/**
 * @param array<string, mixed> $descriptor validated tool descriptor
 * @param array<int, float>    $vector
 * @return array<string, mixed>
 */
function model_inference_tool_embedding_store(
    PDO $pdo,
    array $descriptor,
    string $embeddingModelId,
    array $vector
): array {
    $dimensions = count($vector);
    if ($dimensions < 1) {
        throw new InvalidArgumentException('vector must have at least 1 dimension');
    }
    $encoded = json_encode($vector, JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        throw new RuntimeException('failed to encode tool vector as JSON');
    }
    $descriptorJson = json_encode($descriptor, JSON_UNESCAPED_SLASHES);
    if (!is_string($descriptorJson)) {
        throw new RuntimeException('failed to encode tool descriptor as JSON');
    }
    if (!function_exists('king_object_store_put')) {
        throw new RuntimeException('king_object_store_put not available; load the King extension.');
    }

    $existing = model_inference_tool_embedding_load_row($pdo, (string) $descriptor['tool_id']);
    $vectorId = $existing !== null
        ? (string) $existing['vector_id']
        : model_inference_tool_vector_generate_id();
    $objectKey = $vectorId;
    $updatedAt = gmdate('c');

    $ok = king_object_store_put($objectKey, $encoded, ['content_type' => 'application/json']);
    if ($ok !== true) {
        throw new RuntimeException('tool_vector_write_failed:object_store_put_failed');
    }

    if ($existing !== null) {
        $update = $pdo->prepare('UPDATE tool_embeddings SET
            embedding_model_id = :embedding_model_id,
            dimensions = :dimensions,
            descriptor_json = :descriptor_json,
            updated_at = :updated_at
            WHERE tool_id = :tool_id');
        $update->execute([
            ':embedding_model_id' => $embeddingModelId,
            ':dimensions' => $dimensions,
            ':descriptor_json' => $descriptorJson,
            ':updated_at' => $updatedAt,
            ':tool_id' => (string) $descriptor['tool_id'],
        ]);
    } else {
        $insert = $pdo->prepare('INSERT INTO tool_embeddings (
            tool_id, embedding_model_id, vector_id, dimensions,
            object_store_key, descriptor_json, updated_at
        ) VALUES (
            :tool_id, :embedding_model_id, :vector_id, :dimensions,
            :object_store_key, :descriptor_json, :updated_at
        )');
        $insert->execute([
            ':tool_id' => (string) $descriptor['tool_id'],
            ':embedding_model_id' => $embeddingModelId,
            ':vector_id' => $vectorId,
            ':dimensions' => $dimensions,
            ':object_store_key' => $objectKey,
            ':descriptor_json' => $descriptorJson,
            ':updated_at' => $updatedAt,
        ]);
    }

    return [
        'tool_id' => (string) $descriptor['tool_id'],
        'embedding_model_id' => $embeddingModelId,
        'vector_id' => $vectorId,
        'dimensions' => $dimensions,
        'updated_at' => $updatedAt,
        'replaced' => $existing !== null,
    ];
}

/** @return array<string, mixed>|null */
function model_inference_tool_embedding_load_row(PDO $pdo, string $toolId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM tool_embeddings WHERE tool_id = :tool_id');
    $stmt->execute([':tool_id' => $toolId]);
    $row = $stmt->fetch();
    if ($row === false) {
        return null;
    }
    $descriptor = json_decode((string) $row['descriptor_json'], true);
    return [
        'tool_id' => (string) $row['tool_id'],
        'embedding_model_id' => (string) $row['embedding_model_id'],
        'vector_id' => (string) $row['vector_id'],
        'dimensions' => (int) $row['dimensions'],
        'descriptor' => is_array($descriptor) ? $descriptor : [],
        'updated_at' => (string) $row['updated_at'],
    ];
}

/** @return array<int, float>|null */
function model_inference_tool_vector_load(string $vectorId): ?array
{
    if (!function_exists('king_object_store_get')) {
        return null;
    }
    try {
        $raw = king_object_store_get($vectorId);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        return array_map('floatval', $decoded);
    } catch (Throwable $error) {
        return null;
    }
}

/** @return array<int, array<string, mixed>> */
function model_inference_tool_embedding_list(PDO $pdo): array
{
    $rows = $pdo->query('SELECT * FROM tool_embeddings ORDER BY tool_id ASC')->fetchAll();
    $out = [];
    foreach ($rows as $row) {
        $descriptor = json_decode((string) $row['descriptor_json'], true);
        $out[] = [
            'tool_id' => (string) $row['tool_id'],
            'embedding_model_id' => (string) $row['embedding_model_id'],
            'vector_id' => (string) $row['vector_id'],
            'dimensions' => (int) $row['dimensions'],
            'descriptor' => is_array($descriptor) ? $descriptor : [],
            'updated_at' => (string) $row['updated_at'],
        ];
    }
    return $out;
}

/** @return array<int, array<string, mixed>> */
function model_inference_tool_embedding_load_all(PDO $pdo): array
{
    $metas = model_inference_tool_embedding_list($pdo);
    $out = [];
    foreach ($metas as $meta) {
        $vec = model_inference_tool_vector_load($meta['vector_id']);
        if ($vec === null) {
            continue;
        }
        $meta['vector'] = $vec;
        $out[] = $meta;
    }
    return $out;
}

function model_inference_tool_embedding_delete(PDO $pdo, string $toolId): bool
{
    $existing = model_inference_tool_embedding_load_row($pdo, $toolId);
    if ($existing === null) {
        return false;
    }
    $pdo->prepare('DELETE FROM tool_embeddings WHERE tool_id = :tool_id')->execute([':tool_id' => $toolId]);
    if (function_exists('king_object_store_delete')) {
        try {
            king_object_store_delete((string) $existing['vector_id']);
        } catch (Throwable $error) {
            // best-effort
        }
    }
    return true;
}

/**
 * @param array<string, mixed> $descriptorPayload
 * @param callable             $embedder fn(string): array{vector: array<int, float>, ...}
 * @return array<string, mixed>
 */
function model_inference_tool_embedding_upsert(
    PDO $pdo,
    array $descriptorPayload,
    string $embeddingModelId,
    callable $embedder
): array {
    if ($embeddingModelId === '') {
        throw new InvalidArgumentException('embedding_model_id must be non-empty');
    }
    $descriptor = model_inference_validate_tool_descriptor($descriptorPayload);
    $text = model_inference_tool_descriptor_embedding_text($descriptor);
    $result = $embedder($text);
    if (!is_array($result) || !isset($result['vector']) || !is_array($result['vector'])) {
        throw new RuntimeException('tool_embedding_upsert:embedder_returned_invalid_shape');
    }
    $vector = array_map('floatval', $result['vector']);
    if (count($vector) === 0) {
        throw new RuntimeException('tool_embedding_upsert:empty_embedding_vector');
    }
    $stored = model_inference_tool_embedding_store($pdo, $descriptor, $embeddingModelId, $vector);
    return [
        'tool_id' => $descriptor['tool_id'],
        'embedding_model_id' => $embeddingModelId,
        'vector_id' => $stored['vector_id'],
        'dimensions' => $stored['dimensions'],
        'replaced' => $stored['replaced'],
        'embedding_duration_ms' => (int) ($result['duration_ms'] ?? 0),
        'tokens_used' => (int) ($result['tokens_used'] ?? 0),
        'updated_at' => $stored['updated_at'],
    ];
}
