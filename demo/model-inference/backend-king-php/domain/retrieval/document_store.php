<?php

declare(strict_types=1);

function model_inference_document_generate_id(): string
{
    return 'doc-' . bin2hex(random_bytes(8));
}

function model_inference_document_schema_migrate(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS documents (
        document_id TEXT PRIMARY KEY,
        object_store_key TEXT NOT NULL UNIQUE,
        byte_length INTEGER NOT NULL,
        sha256_hex TEXT NOT NULL,
        content_type TEXT NOT NULL DEFAULT \'text/plain\',
        ingested_at TEXT NOT NULL
    )');
}

/**
 * @return array<string, mixed>
 */
function model_inference_document_ingest(PDO $pdo, string $plainText): array
{
    $documentId = model_inference_document_generate_id();
    $objectKey = $documentId;
    $byteLength = strlen($plainText);
    $sha256Hex = hash('sha256', $plainText);

    if (!function_exists('king_object_store_put')) {
        throw new RuntimeException('king_object_store_put not available; load the King extension.');
    }

    $ok = king_object_store_put($objectKey, $plainText, ['content_type' => 'text/plain; charset=utf-8']);
    if ($ok !== true) {
        throw new RuntimeException('document_write_failed:object_store_put_failed');
    }

    $ingestedAt = gmdate('c');
    $insert = $pdo->prepare('INSERT INTO documents (
        document_id, object_store_key, byte_length, sha256_hex, content_type, ingested_at
    ) VALUES (
        :document_id, :object_store_key, :byte_length, :sha256_hex, :content_type, :ingested_at
    )');
    $insert->execute([
        ':document_id' => $documentId,
        ':object_store_key' => $objectKey,
        ':byte_length' => $byteLength,
        ':sha256_hex' => $sha256Hex,
        ':content_type' => 'text/plain',
        ':ingested_at' => $ingestedAt,
    ]);

    return [
        'document_id' => $documentId,
        'byte_length' => $byteLength,
        'sha256_hex' => $sha256Hex,
        'content_type' => 'text/plain',
        'ingested_at' => $ingestedAt,
    ];
}

/** @return array<string, mixed>|null */
function model_inference_document_get(PDO $pdo, string $documentId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM documents WHERE document_id = :id');
    $stmt->execute([':id' => $documentId]);
    $row = $stmt->fetch();
    if ($row === false) {
        return null;
    }
    return [
        'document_id' => (string) $row['document_id'],
        'byte_length' => (int) $row['byte_length'],
        'sha256_hex' => (string) $row['sha256_hex'],
        'content_type' => (string) $row['content_type'],
        'ingested_at' => (string) $row['ingested_at'],
    ];
}

/** @return array<int, array<string, mixed>> */
function model_inference_document_list(PDO $pdo): array
{
    $rows = $pdo->query('SELECT * FROM documents ORDER BY ingested_at DESC, document_id ASC')->fetchAll();
    $result = [];
    foreach ($rows as $row) {
        $result[] = [
            'document_id' => (string) $row['document_id'],
            'byte_length' => (int) $row['byte_length'],
            'sha256_hex' => (string) $row['sha256_hex'],
            'content_type' => (string) $row['content_type'],
            'ingested_at' => (string) $row['ingested_at'],
        ];
    }
    return $result;
}

function model_inference_document_load_text(string $documentId): ?string
{
    if (!function_exists('king_object_store_get')) {
        return null;
    }
    try {
        $raw = king_object_store_get($documentId);
        return is_string($raw) ? $raw : null;
    } catch (Throwable $error) {
        return null;
    }
}
