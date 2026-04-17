<?php

declare(strict_types=1);

/**
 * @param array<int, float> $a
 * @param array<int, float> $b
 */
function model_inference_cosine_similarity(array $a, array $b): float
{
    $n = count($a);
    if ($n === 0 || $n !== count($b)) {
        return 0.0;
    }

    $dot = 0.0;
    $normA = 0.0;
    $normB = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $dot += $a[$i] * $b[$i];
        $normA += $a[$i] * $a[$i];
        $normB += $b[$i] * $b[$i];
    }

    $denom = sqrt($normA) * sqrt($normB);
    if ($denom < 1e-12) {
        return 0.0;
    }

    return $dot / $denom;
}

/**
 * @param array<int, float> $queryVector
 * @param array<int, array{vector_id: string, chunk_id: string, document_id: string, vector: array<int, float>}> $candidates
 * @return array<int, array{vector_id: string, chunk_id: string, document_id: string, score: float}>
 */
function model_inference_vector_search(array $queryVector, array $candidates, int $topK = 5, float $minScore = 0.0): array
{
    if ($topK < 1) {
        throw new InvalidArgumentException('topK must be >= 1');
    }

    $scored = [];
    foreach ($candidates as $candidate) {
        $vec = $candidate['vector'] ?? [];
        if (!is_array($vec) || count($vec) === 0) {
            continue;
        }
        $score = model_inference_cosine_similarity($queryVector, $vec);
        if ($score < $minScore) {
            continue;
        }
        $scored[] = [
            'vector_id' => (string) ($candidate['vector_id'] ?? ''),
            'chunk_id' => (string) ($candidate['chunk_id'] ?? ''),
            'document_id' => (string) ($candidate['document_id'] ?? ''),
            'score' => $score,
        ];
    }

    usort($scored, static function (array $a, array $b): int {
        return $b['score'] <=> $a['score'];
    });

    return array_slice($scored, 0, $topK);
}
