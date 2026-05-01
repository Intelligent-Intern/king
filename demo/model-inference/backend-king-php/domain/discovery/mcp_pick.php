<?php

declare(strict_types=1);

require_once __DIR__ . '/tool_discover.php';

final class McpPickNoMatchException extends RuntimeException
{
    public function __construct(public int $candidatesScanned, public float $minScore)
    {
        parent::__construct("no_semantic_match:candidates_scanned={$candidatesScanned}:min_score={$minScore}");
    }
}

/**
 * Retrieval-backed MCP resolver.
 *
 * Embed → rank tools → return the top mcp_target plus the picked score
 * metadata. Fails closed with McpPickNoMatchException when no tool scores
 * above min_score so callers never receive an arbitrary default target.
 *
 * The caller provides $queryVector and $queryText upstream (typically by
 * calling the same embedding worker as the discover endpoints). Kept pure
 * so it can be composed from the HTTP layer or invoked directly from a
 * host-side orchestrator step.
 *
 * @param array<int, float> $queryVector
 * @return array{
 *     tool_id: string,
 *     mcp_target: array<string, mixed>,
 *     score: float,
 *     mode: string,
 *     candidates_scanned: int,
 *     search_ms: int,
 *     descriptor: array<string, mixed>
 * }
 */
function model_inference_mcp_pick(
    PDO $pdo,
    array $queryVector,
    string $queryText,
    string $mode = 'semantic',
    float $minScore = 0.0,
    float $alpha = 0.5
): array {
    if (!in_array($mode, ['semantic', 'hybrid'], true)) {
        throw new InvalidArgumentException("mode must be semantic or hybrid (got {$mode})");
    }

    $ranked = $mode === 'hybrid'
        ? model_inference_tool_hybrid_discover($pdo, $queryVector, $queryText, 1, $minScore, $alpha)
        : model_inference_tool_semantic_discover($pdo, $queryVector, 1, $minScore);

    if ($ranked['result_count'] === 0 || empty($ranked['results'])) {
        throw new McpPickNoMatchException($ranked['candidates_scanned'], $minScore);
    }

    $top = $ranked['results'][0];
    if (!is_array($top['mcp_target'] ?? null)) {
        throw new McpPickNoMatchException($ranked['candidates_scanned'], $minScore);
    }

    return [
        'tool_id' => (string) $top['tool_id'],
        'mcp_target' => $top['mcp_target'],
        'score' => (float) $top['score'],
        'mode' => $mode,
        'candidates_scanned' => (int) $ranked['candidates_scanned'],
        'search_ms' => (int) $ranked['search_ms'],
        'descriptor' => is_array($top['descriptor'] ?? null) ? $top['descriptor'] : [],
    ];
}
