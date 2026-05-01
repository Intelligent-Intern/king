<?php

declare(strict_types=1);

require_once __DIR__ . '/service_descriptor.php';

function model_inference_service_vector_generate_id(): string
{
    return 'svec-' . bin2hex(random_bytes(8));
}

function model_inference_service_embedding_schema_migrate(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS service_embeddings (
        service_id TEXT PRIMARY KEY,
        service_type TEXT NOT NULL,
        embedding_model_id TEXT NOT NULL,
        vector_id TEXT NOT NULL UNIQUE,
        dimensions INTEGER NOT NULL,
        object_store_key TEXT NOT NULL UNIQUE,
        descriptor_json TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_service_embeddings_service_type ON service_embeddings(service_type)');
}

/**
 * Persist (or replace) the embedding for a validated service descriptor.
 *
 * @param array<string, mixed> $descriptor validated via model_inference_validate_service_descriptor
 * @param array<int, float>    $vector     embedding vector
 * @return array<string, mixed>
 */
function model_inference_service_embedding_store(
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
        throw new RuntimeException('failed to encode vector as JSON');
    }
    $descriptorJson = json_encode($descriptor, JSON_UNESCAPED_SLASHES);
    if (!is_string($descriptorJson)) {
        throw new RuntimeException('failed to encode descriptor as JSON');
    }

    if (!function_exists('king_object_store_put')) {
        throw new RuntimeException('king_object_store_put not available; load the King extension.');
    }

    $existing = model_inference_service_embedding_load_row($pdo, $descriptor['service_id']);
    $vectorId = $existing !== null
        ? (string) $existing['vector_id']
        : model_inference_service_vector_generate_id();
    $objectKey = $vectorId;
    $updatedAt = gmdate('c');

    $ok = king_object_store_put($objectKey, $encoded, ['content_type' => 'application/json']);
    if ($ok !== true) {
        throw new RuntimeException('service_vector_write_failed:object_store_put_failed');
    }

    if ($existing !== null) {
        $update = $pdo->prepare('UPDATE service_embeddings SET
            service_type = :service_type,
            embedding_model_id = :embedding_model_id,
            dimensions = :dimensions,
            descriptor_json = :descriptor_json,
            updated_at = :updated_at
            WHERE service_id = :service_id');
        $update->execute([
            ':service_type' => (string) $descriptor['service_type'],
            ':embedding_model_id' => $embeddingModelId,
            ':dimensions' => $dimensions,
            ':descriptor_json' => $descriptorJson,
            ':updated_at' => $updatedAt,
            ':service_id' => (string) $descriptor['service_id'],
        ]);
    } else {
        $insert = $pdo->prepare('INSERT INTO service_embeddings (
            service_id, service_type, embedding_model_id, vector_id,
            dimensions, object_store_key, descriptor_json, updated_at
        ) VALUES (
            :service_id, :service_type, :embedding_model_id, :vector_id,
            :dimensions, :object_store_key, :descriptor_json, :updated_at
        )');
        $insert->execute([
            ':service_id' => (string) $descriptor['service_id'],
            ':service_type' => (string) $descriptor['service_type'],
            ':embedding_model_id' => $embeddingModelId,
            ':vector_id' => $vectorId,
            ':dimensions' => $dimensions,
            ':object_store_key' => $objectKey,
            ':descriptor_json' => $descriptorJson,
            ':updated_at' => $updatedAt,
        ]);
    }

    return [
        'service_id' => (string) $descriptor['service_id'],
        'service_type' => (string) $descriptor['service_type'],
        'embedding_model_id' => $embeddingModelId,
        'vector_id' => $vectorId,
        'dimensions' => $dimensions,
        'updated_at' => $updatedAt,
        'replaced' => $existing !== null,
    ];
}

/** @return array<string, mixed>|null */
function model_inference_service_embedding_load_row(PDO $pdo, string $serviceId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM service_embeddings WHERE service_id = :service_id');
    $stmt->execute([':service_id' => $serviceId]);
    $row = $stmt->fetch();
    if ($row === false) {
        return null;
    }
    $descriptor = json_decode((string) $row['descriptor_json'], true);
    return [
        'service_id' => (string) $row['service_id'],
        'service_type' => (string) $row['service_type'],
        'embedding_model_id' => (string) $row['embedding_model_id'],
        'vector_id' => (string) $row['vector_id'],
        'dimensions' => (int) $row['dimensions'],
        'descriptor' => is_array($descriptor) ? $descriptor : [],
        'updated_at' => (string) $row['updated_at'],
    ];
}

/** @return array<int, float>|null */
function model_inference_service_vector_load(string $vectorId): ?array
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

/**
 * List service embedding metadata rows, optionally scoped to a service_type.
 *
 * @return array<int, array<string, mixed>>
 */
function model_inference_service_embedding_list(PDO $pdo, ?string $serviceType = null): array
{
    if ($serviceType === null) {
        $stmt = $pdo->query('SELECT * FROM service_embeddings ORDER BY service_id ASC');
        $rows = $stmt->fetchAll();
    } else {
        $stmt = $pdo->prepare('SELECT * FROM service_embeddings
            WHERE service_type = :service_type ORDER BY service_id ASC');
        $stmt->execute([':service_type' => $serviceType]);
        $rows = $stmt->fetchAll();
    }
    $result = [];
    foreach ($rows as $row) {
        $descriptor = json_decode((string) $row['descriptor_json'], true);
        $result[] = [
            'service_id' => (string) $row['service_id'],
            'service_type' => (string) $row['service_type'],
            'embedding_model_id' => (string) $row['embedding_model_id'],
            'vector_id' => (string) $row['vector_id'],
            'dimensions' => (int) $row['dimensions'],
            'descriptor' => is_array($descriptor) ? $descriptor : [],
            'updated_at' => (string) $row['updated_at'],
        ];
    }
    return $result;
}

/**
 * Load all service embeddings (metadata + dense vector) for a service_type.
 *
 * @return array<int, array<string, mixed>>
 */
function model_inference_service_embedding_load_all(PDO $pdo, ?string $serviceType = null): array
{
    $metas = model_inference_service_embedding_list($pdo, $serviceType);
    $result = [];
    foreach ($metas as $meta) {
        $vector = model_inference_service_vector_load($meta['vector_id']);
        if ($vector === null) {
            continue;
        }
        $meta['vector'] = $vector;
        $result[] = $meta;
    }
    return $result;
}

function model_inference_service_embedding_delete(PDO $pdo, string $serviceId): bool
{
    $existing = model_inference_service_embedding_load_row($pdo, $serviceId);
    if ($existing === null) {
        return false;
    }
    $stmt = $pdo->prepare('DELETE FROM service_embeddings WHERE service_id = :service_id');
    $stmt->execute([':service_id' => $serviceId]);
    if (function_exists('king_object_store_delete')) {
        try {
            king_object_store_delete((string) $existing['vector_id']);
        } catch (Throwable $error) {
            // best-effort: orphaned object-store entries are acceptable at demo scale
        }
    }
    return true;
}
