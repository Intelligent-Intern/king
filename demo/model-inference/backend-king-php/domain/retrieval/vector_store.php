<?php

declare(strict_types=1);

function model_inference_vector_generate_id(): string
{
    return 'vec-' . bin2hex(random_bytes(8));
}

function model_inference_vector_schema_migrate(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS vectors (
        vector_id TEXT PRIMARY KEY,
        chunk_id TEXT NOT NULL,
        document_id TEXT NOT NULL,
        embedding_model_id TEXT NOT NULL,
        dimensions INTEGER NOT NULL,
        object_store_key TEXT NOT NULL UNIQUE,
        created_at TEXT NOT NULL,
        FOREIGN KEY (chunk_id) REFERENCES chunks(chunk_id),
        FOREIGN KEY (document_id) REFERENCES documents(document_id)
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_vectors_document_id ON vectors(document_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_vectors_chunk_id ON vectors(chunk_id)');
}

/**
 * @param array<int, float> $vector
 * @return array<string, mixed>
 */
function model_inference_vector_store(
    PDO $pdo,
    string $chunkId,
    string $documentId,
    string $embeddingModelId,
    array $vector
): array {
    $vectorId = model_inference_vector_generate_id();
    $objectKey = $vectorId;
    $dimensions = count($vector);

    if ($dimensions < 1) {
        throw new InvalidArgumentException('vector must have at least 1 dimension');
    }

    $encoded = json_encode($vector, JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        throw new RuntimeException('failed to encode vector as JSON');
    }

    if (!function_exists('king_object_store_put')) {
        throw new RuntimeException('king_object_store_put not available; load the King extension.');
    }

    $ok = king_object_store_put($objectKey, $encoded, ['content_type' => 'application/json']);
    if ($ok !== true) {
        throw new RuntimeException('vector_write_failed:object_store_put_failed');
    }

    $createdAt = gmdate('c');
    $insert = $pdo->prepare('INSERT INTO vectors (
        vector_id, chunk_id, document_id, embedding_model_id, dimensions, object_store_key, created_at
    ) VALUES (
        :vector_id, :chunk_id, :document_id, :embedding_model_id, :dimensions, :object_store_key, :created_at
    )');
    $insert->execute([
        ':vector_id' => $vectorId,
        ':chunk_id' => $chunkId,
        ':document_id' => $documentId,
        ':embedding_model_id' => $embeddingModelId,
        ':dimensions' => $dimensions,
        ':object_store_key' => $objectKey,
        ':created_at' => $createdAt,
    ]);

    return [
        'vector_id' => $vectorId,
        'chunk_id' => $chunkId,
        'document_id' => $documentId,
        'embedding_model_id' => $embeddingModelId,
        'dimensions' => $dimensions,
        'created_at' => $createdAt,
    ];
}

/** @return array<int, float>|null */
function model_inference_vector_load(string $vectorId): ?array
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
function model_inference_vector_list_by_document(PDO $pdo, string $documentId): array
{
    $stmt = $pdo->prepare('SELECT * FROM vectors WHERE document_id = :doc_id ORDER BY chunk_id ASC, created_at ASC');
    $stmt->execute([':doc_id' => $documentId]);
    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $result[] = [
            'vector_id' => (string) $row['vector_id'],
            'chunk_id' => (string) $row['chunk_id'],
            'document_id' => (string) $row['document_id'],
            'embedding_model_id' => (string) $row['embedding_model_id'],
            'dimensions' => (int) $row['dimensions'],
            'created_at' => (string) $row['created_at'],
        ];
    }
    return $result;
}

/** @return array<int, array{vector_id: string, chunk_id: string, document_id: string, embedding_model_id: string, dimensions: int, vector: array<int, float>}> */
function model_inference_vector_load_all_for_document(PDO $pdo, string $documentId): array
{
    $metas = model_inference_vector_list_by_document($pdo, $documentId);
    $result = [];
    foreach ($metas as $meta) {
        $vec = model_inference_vector_load($meta['vector_id']);
        if ($vec === null) {
            continue;
        }
        $meta['vector'] = $vec;
        $result[] = $meta;
    }
    return $result;
}

/** @return array<int, array{vector_id: string, chunk_id: string, document_id: string, embedding_model_id: string, dimensions: int, vector: array<int, float>}> */
function model_inference_vector_load_all(PDO $pdo): array
{
    $rows = $pdo->query('SELECT * FROM vectors ORDER BY document_id ASC, chunk_id ASC')->fetchAll();
    $result = [];
    foreach ($rows as $row) {
        $vec = model_inference_vector_load((string) $row['vector_id']);
        if ($vec === null) {
            continue;
        }
        $result[] = [
            'vector_id' => (string) $row['vector_id'],
            'chunk_id' => (string) $row['chunk_id'],
            'document_id' => (string) $row['document_id'],
            'embedding_model_id' => (string) $row['embedding_model_id'],
            'dimensions' => (int) $row['dimensions'],
            'vector' => $vec,
        ];
    }
    return $result;
}
