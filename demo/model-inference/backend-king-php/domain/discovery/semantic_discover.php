<?php

declare(strict_types=1);

require_once __DIR__ . '/service_embedding_store.php';
require_once __DIR__ . '/../retrieval/cosine_similarity.php';

/**
 * Brute-force cosine-similarity ranking of service embeddings against a
 * query vector. Honest linear scan, no ANN. Matches the #R-9 contract in
 * shape but uses service_id instead of chunk_id.
 *
 * @param array<int, float> $queryVector
 * @return array{
 *     results: array<int, array<string, mixed>>,
 *     candidates_scanned: int,
 *     result_count: int,
 *     search_strategy: string,
 *     search_ms: int
 * }
 */
function model_inference_semantic_discover(
    PDO $pdo,
    array $queryVector,
    ?string $serviceType,
    int $topK = 5,
    float $minScore = 0.0
): array {
    if ($topK < 1) {
        throw new InvalidArgumentException('top_k must be >= 1');
    }
    if ($minScore < -1.0 || $minScore > 1.0) {
        throw new InvalidArgumentException('min_score must be within [-1.0, 1.0]');
    }

    $t0 = microtime(true);
    $rows = model_inference_service_embedding_load_all($pdo, $serviceType);
    $scored = [];
    foreach ($rows as $row) {
        $vec = $row['vector'] ?? [];
        if (!is_array($vec) || count($vec) === 0) {
            continue;
        }
        $score = model_inference_cosine_similarity($queryVector, $vec);
        if ($score < $minScore) {
            continue;
        }
        $descriptor = is_array($row['descriptor'] ?? null) ? $row['descriptor'] : [];
        $scored[] = [
            'service_id' => (string) $row['service_id'],
            'service_type' => (string) $row['service_type'],
            'vector_id' => (string) $row['vector_id'],
            'dimensions' => (int) $row['dimensions'],
            'score' => $score,
            'descriptor' => $descriptor,
        ];
    }

    usort($scored, static function (array $a, array $b): int {
        $cmp = $b['score'] <=> $a['score'];
        if ($cmp !== 0) {
            return $cmp;
        }
        return strcmp((string) $a['service_id'], (string) $b['service_id']);
    });

    $top = array_slice($scored, 0, $topK);
    $searchMs = (int) round((microtime(true) - $t0) * 1000);

    return [
        'results' => $top,
        'candidates_scanned' => count($rows),
        'result_count' => count($top),
        'search_strategy' => 'brute_force_cosine',
        'search_ms' => $searchMs,
    ];
}
