<?php

declare(strict_types=1);

/**
 * @return array<int, array<string, mixed>>
 */
function model_inference_chunk_text(string $text, string $documentId, array $options = []): array
{
    $chunkSize = (int) ($options['chunk_size'] ?? 512);
    $overlap = (int) ($options['overlap'] ?? 64);

    if ($chunkSize < 1) {
        throw new InvalidArgumentException('chunk_size must be >= 1');
    }
    if ($overlap < 0) {
        throw new InvalidArgumentException('overlap must be >= 0');
    }
    if ($overlap >= $chunkSize) {
        throw new InvalidArgumentException('overlap must be < chunk_size');
    }

    $textLength = strlen($text);
    if ($textLength === 0) {
        return [];
    }

    $step = $chunkSize - $overlap;
    $chunks = [];
    $sequence = 0;
    $offset = 0;

    while ($offset < $textLength) {
        $slice = substr($text, $offset, $chunkSize);
        $sliceBytes = strlen($slice);

        if ($sliceBytes === 0) {
            break;
        }

        $chunkId = model_inference_chunk_generate_id($documentId, $sequence);
        $chunks[] = [
            'chunk_id' => $chunkId,
            'document_id' => $documentId,
            'sequence' => $sequence,
            'text' => $slice,
            'byte_offset' => $offset,
            'byte_length' => $sliceBytes,
            'char_length' => mb_strlen($slice, 'UTF-8'),
            'metadata' => [
                'strategy' => 'fixed_size',
                'chunk_size' => $chunkSize,
                'overlap' => $overlap,
            ],
        ];

        $sequence++;
        $offset += $step;
    }

    return $chunks;
}

function model_inference_chunk_generate_id(string $documentId, int $sequence): string
{
    $prefix = substr(str_replace('doc-', '', $documentId), 0, 8);
    return sprintf('chk-%s-%04d', $prefix, $sequence);
}

function model_inference_chunk_schema_migrate(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS chunks (
        chunk_id TEXT PRIMARY KEY,
        document_id TEXT NOT NULL,
        sequence INTEGER NOT NULL,
        byte_offset INTEGER NOT NULL,
        byte_length INTEGER NOT NULL,
        char_length INTEGER NOT NULL,
        strategy TEXT NOT NULL DEFAULT \'fixed_size\',
        chunk_size INTEGER NOT NULL,
        overlap INTEGER NOT NULL,
        created_at TEXT NOT NULL,
        FOREIGN KEY (document_id) REFERENCES documents(document_id)
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_chunks_document_id ON chunks(document_id, sequence)');
}

/**
 * @param array<int, array<string, mixed>> $chunks
 */
function model_inference_chunk_persist(PDO $pdo, array $chunks): void
{
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO chunks (
        chunk_id, document_id, sequence, byte_offset, byte_length, char_length,
        strategy, chunk_size, overlap, created_at
    ) VALUES (
        :chunk_id, :document_id, :sequence, :byte_offset, :byte_length, :char_length,
        :strategy, :chunk_size, :overlap, :created_at
    )');
    $now = gmdate('c');
    foreach ($chunks as $chunk) {
        $meta = (array) ($chunk['metadata'] ?? []);
        $stmt->execute([
            ':chunk_id' => $chunk['chunk_id'],
            ':document_id' => $chunk['document_id'],
            ':sequence' => (int) $chunk['sequence'],
            ':byte_offset' => (int) $chunk['byte_offset'],
            ':byte_length' => (int) $chunk['byte_length'],
            ':char_length' => (int) $chunk['char_length'],
            ':strategy' => (string) ($meta['strategy'] ?? 'fixed_size'),
            ':chunk_size' => (int) ($meta['chunk_size'] ?? 512),
            ':overlap' => (int) ($meta['overlap'] ?? 64),
            ':created_at' => $now,
        ]);
    }
}

/**
 * @param array<int, array<string, mixed>> $chunks
 */
function model_inference_chunk_store_texts(array $chunks): void
{
    if (!function_exists('king_object_store_put')) {
        return;
    }
    foreach ($chunks as $chunk) {
        $key = (string) $chunk['chunk_id'];
        $text = (string) $chunk['text'];
        try {
            king_object_store_put($key, $text, ['content_type' => 'text/plain; charset=utf-8']);
        } catch (Throwable $error) {
            // Non-fatal: metadata in SQLite is authoritative; object store is for retrieval.
        }
    }
}

function model_inference_chunk_load_text(string $chunkId): ?string
{
    if (!function_exists('king_object_store_get')) {
        return null;
    }
    try {
        $raw = king_object_store_get($chunkId);
        return is_string($raw) ? $raw : null;
    } catch (Throwable $error) {
        return null;
    }
}

/** @return array<int, array<string, mixed>> */
function model_inference_chunk_list_by_document(PDO $pdo, string $documentId): array
{
    $stmt = $pdo->prepare('SELECT * FROM chunks WHERE document_id = :doc_id ORDER BY sequence ASC');
    $stmt->execute([':doc_id' => $documentId]);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = [
            'chunk_id' => (string) $row['chunk_id'],
            'document_id' => (string) $row['document_id'],
            'sequence' => (int) $row['sequence'],
            'byte_offset' => (int) $row['byte_offset'],
            'byte_length' => (int) $row['byte_length'],
            'char_length' => (int) $row['char_length'],
            'metadata' => [
                'strategy' => (string) $row['strategy'],
                'chunk_size' => (int) $row['chunk_size'],
                'overlap' => (int) $row['overlap'],
            ],
            'created_at' => (string) $row['created_at'],
        ];
    }
    return $rows;
}
