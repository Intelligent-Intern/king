<?php

declare(strict_types=1);

require_once __DIR__ . '/graph_store.php';
require_once __DIR__ . '/service_embedding_store.php';

/**
 * #G-batch — extend a ranked discovery result with graph neighbors.
 *
 * Given the top-N results from the core S-batch scorer, walk outgoing
 * edges (optionally filtered by edge_type) and append neighbor services
 * as `neighbor` entries tagged with the seed that reached them. Neighbors
 * do NOT compete with the core results on score; they are listed at a
 * null semantic_score and marked `source: "graph_expand"`.
 *
 * This respects the W.9 contract boundary: the core cosine/BM25 result
 * set is authoritative; graph is additive enrichment only.
 *
 * @param array<int, array<string, mixed>> $rankedResults   results from semantic/hybrid/keyword ranker
 * @param array<int, string>|null          $edgeTypes       null = any
 * @return array{
 *     results: array<int, array<string, mixed>>,
 *     expanded: array<int, array<string, mixed>>,
 *     neighbor_count: int,
 *     hops: int,
 *     edge_types: array<int, string>
 * }
 */
function model_inference_graph_expand_results(
    PDO $pdo,
    array $rankedResults,
    ?array $edgeTypes,
    int $maxHops
): array {
    if ($maxHops < 1) {
        return [
            'results' => $rankedResults,
            'expanded' => [],
            'neighbor_count' => 0,
            'hops' => 0,
            'edge_types' => $edgeTypes ?? [],
        ];
    }

    $seedIds = [];
    $seen = [];
    foreach ($rankedResults as $r) {
        $sid = (string) ($r['service_id'] ?? '');
        if ($sid === '' || isset($seen[$sid])) {
            continue;
        }
        $seedIds[] = $sid;
        $seen[$sid] = true;
    }

    $neighborIds = model_inference_graph_traverse_outgoing(
        $pdo, $seedIds, $edgeTypes, $maxHops
    );
    if (count($neighborIds) === 0) {
        return [
            'results' => $rankedResults,
            'expanded' => [],
            'neighbor_count' => 0,
            'hops' => $maxHops,
            'edge_types' => $edgeTypes ?? [],
        ];
    }

    $neighborMeta = [];
    foreach ($neighborIds as $nid) {
        $row = model_inference_service_embedding_load_row($pdo, $nid);
        if ($row === null) {
            $neighborMeta[] = [
                'service_id' => $nid,
                'service_type' => null,
                'source' => 'graph_expand',
                'semantic_score' => null,
                'descriptor' => null,
            ];
        } else {
            $neighborMeta[] = [
                'service_id' => (string) $row['service_id'],
                'service_type' => (string) $row['service_type'],
                'source' => 'graph_expand',
                'semantic_score' => null,
                'descriptor' => $row['descriptor'],
            ];
        }
    }

    return [
        'results' => $rankedResults,
        'expanded' => $neighborMeta,
        'neighbor_count' => count($neighborMeta),
        'hops' => $maxHops,
        'edge_types' => $edgeTypes ?? [],
    ];
}
