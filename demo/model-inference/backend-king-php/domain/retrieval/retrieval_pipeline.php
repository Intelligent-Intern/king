<?php

declare(strict_types=1);

require_once __DIR__ . '/cosine_similarity.php';
require_once __DIR__ . '/vector_store.php';
require_once __DIR__ . '/text_chunker.php';

/**
 * @param array<int, float> $queryEmbedding
 * @param array<string>|null $documentIds null = search all documents
 * @return array<string, mixed>
 */
function model_inference_retrieval_search(
    PDO $pdo,
    array $queryEmbedding,
    ?array $documentIds,
    int $topK = 5,
    float $minScore = 0.0
): array {
    $t0 = microtime(true);

    if ($documentIds !== null && count($documentIds) > 0) {
        $candidates = [];
        foreach ($documentIds as $docId) {
            $docVectors = model_inference_vector_load_all_for_document($pdo, $docId);
            foreach ($docVectors as $v) {
                $candidates[] = $v;
            }
        }
    } else {
        $candidates = model_inference_vector_load_all($pdo);
    }

    $vectorsScanned = count($candidates);
    $ranked = model_inference_vector_search($queryEmbedding, $candidates, $topK, $minScore);

    $results = [];
    foreach ($ranked as $match) {
        $chunkText = model_inference_chunk_load_text($match['chunk_id']);
        $results[] = [
            'chunk_id' => $match['chunk_id'],
            'document_id' => $match['document_id'],
            'text' => $chunkText ?? '',
            'score' => $match['score'],
            'vector_id' => $match['vector_id'],
        ];
    }

    $searchMs = (int) round((microtime(true) - $t0) * 1000);

    return [
        'results' => $results,
        'result_count' => count($results),
        'search_strategy' => 'brute_force_cosine',
        'vectors_scanned' => $vectorsScanned,
        'search_ms' => $searchMs,
    ];
}
