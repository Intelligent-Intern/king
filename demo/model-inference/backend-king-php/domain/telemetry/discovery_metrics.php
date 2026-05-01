<?php

declare(strict_types=1);

final class DiscoveryMetricsRing
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
            'mode' => (string) ($entry['mode'] ?? ''),
            'embedding_ms' => (int) ($entry['embedding_ms'] ?? 0),
            'search_ms' => (int) ($entry['search_ms'] ?? 0),
            'total_ms' => (int) ($entry['total_ms'] ?? 0),
            'candidates_scanned' => (int) ($entry['candidates_scanned'] ?? 0),
            'query_length' => (int) ($entry['query_length'] ?? 0),
            'service_type' => (string) ($entry['service_type'] ?? ''),
            'top_k' => (int) ($entry['top_k'] ?? 0),
            'min_score' => (float) ($entry['min_score'] ?? 0.0),
            'alpha' => (float) ($entry['alpha'] ?? 0.0),
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
