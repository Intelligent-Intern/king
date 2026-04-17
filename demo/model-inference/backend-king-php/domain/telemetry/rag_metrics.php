<?php

declare(strict_types=1);

final class RagMetricsRing
{
    /** @var array<int, array<string, mixed>> */
    private array $entries = [];
    private int $capacity;

    public function __construct(int $capacity = 100)
    {
        if ($capacity < 1) {
            throw new InvalidArgumentException("capacity must be >= 1 (got {$capacity})");
        }
        $this->capacity = $capacity;
    }

    public function capacity(): int
    {
        return $this->capacity;
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /** @param array<string, mixed> $entry */
    public function record(array $entry): void
    {
        $normalized = [
            'request_id' => (string) ($entry['request_id'] ?? ''),
            'query_length' => (int) ($entry['query_length'] ?? 0),
            'embedding_ms' => (int) ($entry['embedding_ms'] ?? 0),
            'retrieval_ms' => (int) ($entry['retrieval_ms'] ?? 0),
            'inference_ms' => (int) ($entry['inference_ms'] ?? 0),
            'total_ms' => (int) ($entry['total_ms'] ?? 0),
            'chunks_used' => (int) ($entry['chunks_used'] ?? 0),
            'vectors_scanned' => (int) ($entry['vectors_scanned'] ?? 0),
            'tokens_in' => (int) ($entry['tokens_in'] ?? 0),
            'tokens_out' => (int) ($entry['tokens_out'] ?? 0),
            'chat_model' => (string) ($entry['chat_model'] ?? ''),
            'embedding_model' => (string) ($entry['embedding_model'] ?? ''),
            'recorded_at' => gmdate('c'),
        ];

        $this->entries[] = $normalized;
        if (count($this->entries) > $this->capacity) {
            array_shift($this->entries);
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function recent(int $limit = 100): array
    {
        if ($limit < 1) {
            return [];
        }
        $reversed = array_reverse($this->entries);
        return $limit < count($reversed) ? array_slice($reversed, 0, $limit) : $reversed;
    }
}
