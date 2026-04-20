<?php

declare(strict_types=1);

require_once __DIR__ . '/service_embedding_store.php';
require_once __DIR__ . '/semantic_discover.php';
require_once __DIR__ . '/../retrieval/cosine_similarity.php';

/**
 * Hybrid discovery: cosine over service embeddings + BM25 over the
 * descriptor's (name ∪ description ∪ capabilities ∪ tags) tokens, fused
 * linearly via alpha (alpha = weight of semantic, 1-alpha = weight of BM25).
 *
 * BM25 parameters are pinned (k1 = 1.2, b = 0.75) and both component
 * scores are min-max normalized into [0, 1] across the current candidate
 * set *before* fusion, so scores are comparable regardless of the raw
 * dynamic range of each component.
 *
 * @param array<int, float> $queryVector
 * @return array{
 *     results: array<int, array<string, mixed>>,
 *     candidates_scanned: int,
 *     result_count: int,
 *     search_strategy: string,
 *     search_ms: int,
 *     alpha: float,
 *     bm25_k1: float,
 *     bm25_b: float
 * }
 */
function model_inference_hybrid_discover(
    PDO $pdo,
    array $queryVector,
    string $queryText,
    ?string $serviceType,
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
    $rows = model_inference_service_embedding_load_all($pdo, $serviceType);

    $candidates = [];
    $totalTokens = 0;
    foreach ($rows as $row) {
        $vec = $row['vector'] ?? [];
        if (!is_array($vec) || count($vec) === 0) {
            continue;
        }
        $descriptor = is_array($row['descriptor'] ?? null) ? $row['descriptor'] : [];
        $tokens = model_inference_hybrid_tokenize_descriptor($descriptor);
        $totalTokens += count($tokens);
        $candidates[] = [
            'service_id' => (string) $row['service_id'],
            'service_type' => (string) $row['service_type'],
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
            if (isset($seen[$tok])) {
                continue;
            }
            $seen[$tok] = true;
            $docFreq[$tok] = ($docFreq[$tok] ?? 0) + 1;
        }
    }

    $semanticRaw = [];
    $bm25Raw = [];
    foreach ($candidates as $i => $cand) {
        $semanticRaw[$i] = model_inference_cosine_similarity($queryVector, $cand['vector']);
        $bm25Raw[$i] = model_inference_hybrid_bm25_score(
            $queryTokens,
            $cand['tokens'],
            $docFreq,
            $N,
            $avgdl,
            $k1,
            $b
        );
    }

    $semanticNorm = model_inference_hybrid_minmax_normalize($semanticRaw);
    $bm25Norm = model_inference_hybrid_minmax_normalize($bm25Raw);

    $scored = [];
    foreach ($candidates as $i => $cand) {
        $s = $semanticNorm[$i];
        $k = $bm25Norm[$i];
        $fused = $alpha * $s + (1.0 - $alpha) * $k;
        if ($fused < $minScore) {
            continue;
        }
        $scored[] = [
            'service_id' => $cand['service_id'],
            'service_type' => $cand['service_type'],
            'vector_id' => $cand['vector_id'],
            'dimensions' => $cand['dimensions'],
            'score' => $fused,
            'semantic_score' => $s,
            'keyword_score' => $k,
            'semantic_raw' => $semanticRaw[$i],
            'keyword_raw' => $bm25Raw[$i],
            'descriptor' => $cand['descriptor'],
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
        'candidates_scanned' => $N,
        'result_count' => count($top),
        'search_strategy' => 'hybrid_cosine_bm25',
        'search_ms' => $searchMs,
        'alpha' => $alpha,
        'bm25_k1' => $k1,
        'bm25_b' => $b,
    ];
}

/** @return array<int, string> */
function model_inference_hybrid_tokenize(string $text): array
{
    $lower = strtolower($text);
    $normalized = preg_replace('/[^a-z0-9]+/', ' ', $lower);
    if (!is_string($normalized)) {
        return [];
    }
    $parts = preg_split('/\s+/', trim($normalized));
    if ($parts === false) {
        return [];
    }
    $tokens = [];
    foreach ($parts as $p) {
        if ($p === '') {
            continue;
        }
        if (strlen($p) < 2) {
            continue;
        }
        $tokens[] = $p;
    }
    return $tokens;
}

/**
 * @param array<string, mixed> $descriptor already-validated descriptor
 * @return array<int, string>
 */
function model_inference_hybrid_tokenize_descriptor(array $descriptor): array
{
    $pieces = [];
    if (isset($descriptor['name']) && is_string($descriptor['name'])) {
        $pieces[] = $descriptor['name'];
    }
    if (isset($descriptor['description']) && is_string($descriptor['description'])) {
        $pieces[] = $descriptor['description'];
    }
    foreach ((array) ($descriptor['capabilities'] ?? []) as $cap) {
        if (is_string($cap)) {
            $pieces[] = $cap;
        }
    }
    foreach ((array) ($descriptor['tags'] ?? []) as $tag) {
        if (is_string($tag)) {
            $pieces[] = $tag;
        }
    }
    return model_inference_hybrid_tokenize(implode(' ', $pieces));
}

/**
 * @param array<int, string>        $queryTokens
 * @param array<int, string>        $docTokens
 * @param array<string, int>        $docFreq   token -> number of documents containing it
 * @param int                       $N         total candidates
 * @param float                     $avgdl     average document length across all candidates
 */
function model_inference_hybrid_bm25_score(
    array $queryTokens,
    array $docTokens,
    array $docFreq,
    int $N,
    float $avgdl,
    float $k1,
    float $b
): float {
    if ($N === 0 || count($docTokens) === 0 || count($queryTokens) === 0) {
        return 0.0;
    }
    $termFreq = [];
    foreach ($docTokens as $tok) {
        $termFreq[$tok] = ($termFreq[$tok] ?? 0) + 1;
    }
    $dl = (float) count($docTokens);
    $score = 0.0;
    $counted = [];
    foreach ($queryTokens as $qt) {
        if (isset($counted[$qt])) {
            continue;
        }
        $counted[$qt] = true;
        $df = $docFreq[$qt] ?? 0;
        if ($df === 0) {
            continue;
        }
        $idf = log((($N - $df + 0.5) / ($df + 0.5)) + 1.0);
        $f = (float) ($termFreq[$qt] ?? 0);
        if ($f === 0.0) {
            continue;
        }
        $denom = $f + $k1 * (1.0 - $b + $b * ($avgdl > 0.0 ? $dl / $avgdl : 1.0));
        $score += $idf * (($f * ($k1 + 1.0)) / ($denom > 0.0 ? $denom : 1.0));
    }
    return $score;
}

/**
 * Min-max normalize a set of raw scores into [0, 1]. If all values are
 * equal (including all zero), every output is 0.0.
 *
 * @param array<int, float> $raw  indexed by position (same index space as the input candidate array)
 * @return array<int, float>
 */
function model_inference_hybrid_minmax_normalize(array $raw): array
{
    if (count($raw) === 0) {
        return [];
    }
    $min = min($raw);
    $max = max($raw);
    $range = $max - $min;
    if ($range < 1e-12) {
        return array_map(static fn (): float => 0.0, $raw);
    }
    return array_map(static fn (float $v): float => ($v - $min) / $range, $raw);
}
