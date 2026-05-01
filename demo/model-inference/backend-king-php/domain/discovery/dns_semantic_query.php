<?php

declare(strict_types=1);

require_once __DIR__ . '/semantic_discover.php';

/**
 * Overlay that combines the existing keyword-based king_semantic_dns_discover_service
 * result set with vector-ranked results from the service_embeddings table.
 *
 * Rationale: the C-level Semantic-DNS surface stays intact (the S-batch plan's
 * hard rule — keyword API unchanged, semantic path additive). We compose on top
 * in PHP by intersecting the DNS candidate list with the ranked embedding
 * results, so only services that are BOTH registered with the live topology
 * AND score above min_semantic_score are returned.
 *
 * Callers provide the already-embedded $queryVector (same embedding worker
 * as the discover endpoints) so this function stays synchronous and cheap.
 *
 * @param array<int, float> $queryVector
 * @return array<string, mixed>
 */
function model_inference_dns_discover_with_semantic_query(
    PDO $pdo,
    string $serviceType,
    string $semanticQuery,
    array $queryVector,
    float $minSemanticScore = 0.0,
    int $topK = 10
): array {
    if ($topK < 1) {
        throw new InvalidArgumentException('top_k must be >= 1');
    }
    if ($minSemanticScore < -1.0 || $minSemanticScore > 1.0) {
        throw new InvalidArgumentException('min_semantic_score must be within [-1.0, 1.0]');
    }
    if (count($queryVector) === 0) {
        throw new InvalidArgumentException('query_vector must be non-empty');
    }

    $dnsCandidates = [];
    if (function_exists('king_semantic_dns_discover_service')) {
        try {
            $dnsResult = king_semantic_dns_discover_service($serviceType);
            if (is_array($dnsResult)) {
                $dnsCandidates = (array) ($dnsResult['services'] ?? []);
            }
        } catch (Throwable $ignored) {
            $dnsCandidates = [];
        }
    }

    $ranked = model_inference_semantic_discover(
        $pdo,
        $queryVector,
        $serviceType,
        $topK,
        $minSemanticScore
    );

    $dnsByServiceId = [];
    foreach ($dnsCandidates as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $sid = $entry['service_id'] ?? null;
        if (!is_string($sid) || $sid === '') {
            continue;
        }
        $dnsByServiceId[$sid] = $entry;
    }

    $merged = [];
    foreach ($ranked['results'] as $r) {
        $sid = (string) $r['service_id'];
        if (!isset($dnsByServiceId[$sid])) {
            continue; // semantic match but not registered in DNS
        }
        $dnsEntry = $dnsByServiceId[$sid];
        $merged[] = [
            'service_id' => $sid,
            'service_type' => (string) $r['service_type'],
            'semantic_score' => (float) $r['score'],
            'dns_entry' => $dnsEntry,
            'descriptor' => $r['descriptor'],
        ];
    }

    return [
        'results' => $merged,
        'result_count' => count($merged),
        'query' => $semanticQuery,
        'min_semantic_score' => $minSemanticScore,
        'candidates_scanned_dns' => count($dnsCandidates),
        'candidates_scanned_embeddings' => (int) $ranked['candidates_scanned'],
        'semantic_search_ms' => (int) $ranked['search_ms'],
        'keyword_path_available' => function_exists('king_semantic_dns_discover_service'),
    ];
}
