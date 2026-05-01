<?php

declare(strict_types=1);

require_once __DIR__ . '/tool_descriptor_store.php';
require_once __DIR__ . '/hybrid_discover.php';
require_once __DIR__ . '/../retrieval/cosine_similarity.php';

/**
 * Brute-force cosine ranking across tool embeddings.
 *
 * @param array<int, float> $queryVector
 * @return array<string, mixed>
 */
function model_inference_tool_semantic_discover(
    PDO $pdo,
    array $queryVector,
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
    $rows = model_inference_tool_embedding_load_all($pdo);
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
            'tool_id' => (string) $row['tool_id'],
            'vector_id' => (string) $row['vector_id'],
            'dimensions' => (int) $row['dimensions'],
            'mcp_target' => is_array($descriptor['mcp_target'] ?? null) ? $descriptor['mcp_target'] : null,
            'score' => $score,
            'descriptor' => $descriptor,
        ];
    }
    usort($scored, static function (array $a, array $b): int {
        $cmp = $b['score'] <=> $a['score'];
        if ($cmp !== 0) return $cmp;
        return strcmp((string) $a['tool_id'], (string) $b['tool_id']);
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

/**
 * Hybrid (cosine + BM25) ranking across tool embeddings.
 *
 * @param array<int, float> $queryVector
 * @return array<string, mixed>
 */
function model_inference_tool_hybrid_discover(
    PDO $pdo,
    array $queryVector,
    string $queryText,
    int $topK = 5,
    float $minScore = 0.0,
    float $alpha = 0.5
): array {
    if ($topK < 1) {
        throw new InvalidArgumentException('top_k must be >= 1');
    }
    if ($minScore < 0.0 || $minScore > 1.0) {
        throw new InvalidArgumentException('min_score must be within [0, 1] for hybrid mode');
    }
    if ($alpha < 0.0 || $alpha > 1.0) {
        throw new InvalidArgumentException('alpha must be within [0, 1]');
    }

    $k1 = 1.2;
    $b = 0.75;
    $t0 = microtime(true);
    $rows = model_inference_tool_embedding_load_all($pdo);
    $candidates = [];
    $totalTokens = 0;
    foreach ($rows as $row) {
        $vec = $row['vector'] ?? [];
        if (!is_array($vec) || count($vec) === 0) {
            continue;
        }
        $descriptor = is_array($row['descriptor'] ?? null) ? $row['descriptor'] : [];
        $tokens = model_inference_hybrid_tokenize_tool_descriptor($descriptor);
        $totalTokens += count($tokens);
        $candidates[] = [
            'tool_id' => (string) $row['tool_id'],
            'vector_id' => (string) $row['vector_id'],
            'dimensions' => (int) $row['dimensions'],
            'vector' => $vec,
            'descriptor' => $descriptor,
            'tokens' => $tokens,
        ];
    }
    $N = count($candidates);
    $avgdl = $N > 0 ? ((float) $totalTokens) / $N : 0.0;
    $queryTokens = model_inference_hybrid_tokenize($queryText);
    $docFreq = [];
    foreach ($candidates as $cand) {
        $seen = [];
        foreach ($cand['tokens'] as $tok) {
            if (isset($seen[$tok])) continue;
            $seen[$tok] = true;
            $docFreq[$tok] = ($docFreq[$tok] ?? 0) + 1;
        }
    }
    $semanticRaw = [];
    $bm25Raw = [];
    foreach ($candidates as $i => $cand) {
        $semanticRaw[$i] = model_inference_cosine_similarity($queryVector, $cand['vector']);
        $bm25Raw[$i] = model_inference_hybrid_bm25_score(
            $queryTokens, $cand['tokens'], $docFreq, $N, $avgdl, $k1, $b
        );
    }
    $semanticNorm = model_inference_hybrid_minmax_normalize($semanticRaw);
    $bm25Norm = model_inference_hybrid_minmax_normalize($bm25Raw);
    $scored = [];
    foreach ($candidates as $i => $cand) {
        $s = $semanticNorm[$i];
        $kk = $bm25Norm[$i];
        $fused = $alpha * $s + (1.0 - $alpha) * $kk;
        if ($fused < $minScore) continue;
        $scored[] = [
            'tool_id' => $cand['tool_id'],
            'vector_id' => $cand['vector_id'],
            'dimensions' => $cand['dimensions'],
            'mcp_target' => is_array($cand['descriptor']['mcp_target'] ?? null) ? $cand['descriptor']['mcp_target'] : null,
            'score' => $fused,
            'semantic_score' => $s,
            'keyword_score' => $kk,
            'semantic_raw' => $semanticRaw[$i],
            'keyword_raw' => $bm25Raw[$i],
            'descriptor' => $cand['descriptor'],
        ];
    }
    usort($scored, static function (array $a, array $b): int {
        $cmp = $b['score'] <=> $a['score'];
        if ($cmp !== 0) return $cmp;
        return strcmp((string) $a['tool_id'], (string) $b['tool_id']);
    });
    $top = array_slice($scored, 0, $topK);
    $searchMs = (int) round((microtime(true) - $t0) * 1000);
    return [
        'results' => $top,
        'candidates_scanned' => $N,
        'result_count' => count($top),
        'search_strategy' => 'hybrid_cosine_bm25',
        'search_ms' => $searchMs,
        'alpha' => $alpha,
        'bm25_k1' => $k1,
        'bm25_b' => $b,
    ];
}
