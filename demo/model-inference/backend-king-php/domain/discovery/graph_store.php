<?php

declare(strict_types=1);

/**
 * #G-batch — optional graph-aware metadata layer on top of S-batch
 * semantic discovery. Closes readiness tracker bullets W.8 (graph-aware
 * metadata + traversal) and W.9 (public contract boundary between core
 * semantic discovery and optional graph integrations).
 *
 * Scope contract (also pinned in contracts/v1/service-graph.contract.json):
 *
 *   Core discovery (#S-batch) = cosine + BM25 over service_embeddings.
 *   It stays authoritative and unchanged — any /api/discover call without
 *   graph_expand produces identical output to pre-G-batch behaviour.
 *
 *   Optional extension (#G-batch) = typed directional edges
 *   (from_service_id, edge_type, to_service_id) persisted in SQLite that
 *   enrich the core ranked result with one-hop (or two-hop) neighbors
 *   when the client opts in with `graph_expand`. Edges are never used as
 *   a ranking signal — they only widen the candidate set.
 *
 * No claim is made about MoE / expert routing, shortest-path solving, or
 * weighted graph scoring. Those are out-of-scope for the demo and stay
 * fenced under tracker V.5.
 */

function model_inference_graph_schema_migrate(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS service_edges (
        edge_id INTEGER PRIMARY KEY AUTOINCREMENT,
        from_service_id TEXT NOT NULL,
        to_service_id TEXT NOT NULL,
        edge_type TEXT NOT NULL,
        attributes_json TEXT NOT NULL DEFAULT '{}',
        created_at TEXT NOT NULL
    )");
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_service_edges_unique
        ON service_edges(from_service_id, to_service_id, edge_type)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_service_edges_from ON service_edges(from_service_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_service_edges_to ON service_edges(to_service_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_service_edges_type ON service_edges(edge_type)');
}

/**
 * Upsert a directional edge. Duplicate (from, to, type) triples update
 * `attributes_json` and leave created_at untouched.
 *
 * @param array<string, mixed> $attributes
 */
function model_inference_graph_upsert_edge(
    PDO $pdo,
    string $fromServiceId,
    string $toServiceId,
    string $edgeType,
    array $attributes = []
): int {
    if ($fromServiceId === '' || $toServiceId === '' || $edgeType === '') {
        throw new InvalidArgumentException('from, to, edge_type must all be non-empty');
    }
    if (strlen($fromServiceId) > 128 || strlen($toServiceId) > 128) {
        throw new InvalidArgumentException('service ids must be <= 128 chars');
    }
    if (strlen($edgeType) > 64) {
        throw new InvalidArgumentException('edge_type must be <= 64 chars');
    }
    if (preg_match('/^[a-z][a-z0-9_.-]*$/', $edgeType) !== 1) {
        throw new InvalidArgumentException('edge_type must match [a-z][a-z0-9_.-]*');
    }
    $attrJson = json_encode($attributes, JSON_UNESCAPED_SLASHES);
    if (!is_string($attrJson)) {
        throw new RuntimeException('failed to encode edge attributes as JSON');
    }

    $existing = $pdo->prepare('SELECT edge_id FROM service_edges
        WHERE from_service_id = :f AND to_service_id = :t AND edge_type = :e LIMIT 1');
    $existing->execute([':f' => $fromServiceId, ':t' => $toServiceId, ':e' => $edgeType]);
    $row = $existing->fetch();
    if ($row !== false) {
        $update = $pdo->prepare('UPDATE service_edges SET attributes_json = :a WHERE edge_id = :id');
        $update->execute([':a' => $attrJson, ':id' => (int) $row['edge_id']]);
        return (int) $row['edge_id'];
    }

    $insert = $pdo->prepare('INSERT INTO service_edges
        (from_service_id, to_service_id, edge_type, attributes_json, created_at)
        VALUES (:f, :t, :e, :a, :c)');
    $insert->execute([
        ':f' => $fromServiceId, ':t' => $toServiceId, ':e' => $edgeType,
        ':a' => $attrJson, ':c' => gmdate('c'),
    ]);
    return (int) $pdo->lastInsertId();
}

function model_inference_graph_delete_edge(
    PDO $pdo,
    string $fromServiceId,
    string $toServiceId,
    string $edgeType
): bool {
    $stmt = $pdo->prepare('DELETE FROM service_edges
        WHERE from_service_id = :f AND to_service_id = :t AND edge_type = :e');
    $stmt->execute([':f' => $fromServiceId, ':t' => $toServiceId, ':e' => $edgeType]);
    return $stmt->rowCount() > 0;
}

/**
 * List outgoing edges from a service, optionally filtered by edge_type.
 *
 * @param array<int, string>|null $edgeTypes null = any
 * @return array<int, array<string, mixed>>
 */
function model_inference_graph_list_outgoing(
    PDO $pdo,
    string $fromServiceId,
    ?array $edgeTypes = null
): array {
    if ($edgeTypes === null || count($edgeTypes) === 0) {
        $stmt = $pdo->prepare('SELECT * FROM service_edges
            WHERE from_service_id = :f ORDER BY edge_type, to_service_id');
        $stmt->execute([':f' => $fromServiceId]);
    } else {
        $placeholders = implode(',', array_fill(0, count($edgeTypes), '?'));
        $sql = 'SELECT * FROM service_edges
            WHERE from_service_id = ? AND edge_type IN (' . $placeholders . ')
            ORDER BY edge_type, to_service_id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$fromServiceId], $edgeTypes));
    }
    return model_inference_graph_rows_to_envelope($stmt->fetchAll());
}

/**
 * Return the set of service_ids reachable within `maxHops` outgoing hops
 * from any seed id, filtered by edge types. Capped to prevent runaway
 * traversal at demo scale.
 *
 * @param array<int, string>      $seedIds
 * @param array<int, string>|null $edgeTypes null = any type
 * @return array<int, string> unique service_ids (seeds excluded)
 */
function model_inference_graph_traverse_outgoing(
    PDO $pdo,
    array $seedIds,
    ?array $edgeTypes = null,
    int $maxHops = 1,
    int $maxVisitBudget = 512
): array {
    if ($maxHops < 1) {
        return [];
    }
    if ($maxHops > 3) {
        throw new InvalidArgumentException('max_hops capped at 3 for demo scale');
    }
    $seedSet = [];
    foreach ($seedIds as $s) {
        if (is_string($s) && $s !== '') {
            $seedSet[$s] = true;
        }
    }
    if (count($seedSet) === 0) {
        return [];
    }

    $visited = $seedSet;
    $frontier = array_keys($seedSet);
    $discovered = [];
    $visits = 0;

    for ($hop = 0; $hop < $maxHops && count($frontier) > 0; $hop++) {
        $nextFrontier = [];
        foreach ($frontier as $from) {
            if ($visits >= $maxVisitBudget) {
                break 2;
            }
            $visits++;
            $edges = model_inference_graph_list_outgoing($pdo, $from, $edgeTypes);
            foreach ($edges as $edge) {
                $to = $edge['to_service_id'];
                if (isset($visited[$to])) {
                    continue;
                }
                $visited[$to] = true;
                $discovered[$to] = true;
                $nextFrontier[] = $to;
            }
        }
        $frontier = $nextFrontier;
    }

    return array_keys($discovered);
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<string, mixed>>
 */
function model_inference_graph_rows_to_envelope(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        $attrs = json_decode((string) ($row['attributes_json'] ?? '{}'), true);
        $out[] = [
            'edge_id' => (int) $row['edge_id'],
            'from_service_id' => (string) $row['from_service_id'],
            'to_service_id' => (string) $row['to_service_id'],
            'edge_type' => (string) $row['edge_type'],
            'attributes' => is_array($attrs) ? $attrs : [],
            'created_at' => (string) $row['created_at'],
        ];
    }
    return $out;
}

function model_inference_graph_count(PDO $pdo): int
{
    $row = $pdo->query('SELECT COUNT(*) AS c FROM service_edges')->fetch();
    return (int) ($row['c'] ?? 0);
}
