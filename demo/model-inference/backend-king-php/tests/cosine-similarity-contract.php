<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/retrieval/cosine_similarity.php';

function cosine_similarity_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[cosine-similarity-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    $rulesAsserted = 0;

    // 1. Identical vectors → similarity 1.0.
    $v = [1.0, 0.0, 0.0];
    $score = model_inference_cosine_similarity($v, $v);
    cosine_similarity_contract_assert(abs($score - 1.0) < 1e-6, "identical vectors must score 1.0 (got {$score})");
    $rulesAsserted++;

    // 2. Orthogonal vectors → similarity 0.0.
    $score = model_inference_cosine_similarity([1.0, 0.0], [0.0, 1.0]);
    cosine_similarity_contract_assert(abs($score) < 1e-6, "orthogonal vectors must score 0.0 (got {$score})");
    $rulesAsserted++;

    // 3. Opposite vectors → similarity -1.0.
    $score = model_inference_cosine_similarity([1.0, 0.0], [-1.0, 0.0]);
    cosine_similarity_contract_assert(abs($score - (-1.0)) < 1e-6, "opposite vectors must score -1.0 (got {$score})");
    $rulesAsserted++;

    // 4. Empty vectors → 0.0.
    $score = model_inference_cosine_similarity([], []);
    cosine_similarity_contract_assert($score === 0.0, 'empty vectors must score 0.0');
    $rulesAsserted++;

    // 5. Mismatched dimensions → 0.0.
    $score = model_inference_cosine_similarity([1.0, 0.0], [1.0]);
    cosine_similarity_contract_assert($score === 0.0, 'mismatched dimensions must score 0.0');
    $rulesAsserted++;

    // 6. Zero vector → 0.0.
    $score = model_inference_cosine_similarity([0.0, 0.0], [1.0, 0.0]);
    cosine_similarity_contract_assert(abs($score) < 1e-6, 'zero vector must score 0.0');
    $rulesAsserted++;

    // 7. Known cosine: [1,1] vs [1,0] → 1/√2 ≈ 0.7071.
    $score = model_inference_cosine_similarity([1.0, 1.0], [1.0, 0.0]);
    cosine_similarity_contract_assert(abs($score - 0.7071) < 0.001, "45-degree angle must score ~0.7071 (got {$score})");
    $rulesAsserted++;

    // 8. L2-normalized vectors: cosine = dot product.
    $a = [0.6, 0.8];
    $b = [0.8, 0.6];
    $dot = $a[0] * $b[0] + $a[1] * $b[1];
    $score = model_inference_cosine_similarity($a, $b);
    cosine_similarity_contract_assert(abs($score - $dot) < 1e-6, "normalized vectors: cosine must equal dot product (got {$score}, expected {$dot})");
    $rulesAsserted++;

    // 9. vector_search: returns top-K sorted by score desc.
    $query = [1.0, 0.0, 0.0];
    $candidates = [
        ['vector_id' => 'v1', 'chunk_id' => 'c1', 'document_id' => 'd1', 'vector' => [1.0, 0.0, 0.0]],
        ['vector_id' => 'v2', 'chunk_id' => 'c2', 'document_id' => 'd1', 'vector' => [0.0, 1.0, 0.0]],
        ['vector_id' => 'v3', 'chunk_id' => 'c3', 'document_id' => 'd1', 'vector' => [0.7071, 0.7071, 0.0]],
    ];
    $results = model_inference_vector_search($query, $candidates, 2);
    cosine_similarity_contract_assert(count($results) === 2, 'top-2 must return 2 results');
    cosine_similarity_contract_assert($results[0]['vector_id'] === 'v1', 'best match must be v1 (identical)');
    cosine_similarity_contract_assert($results[1]['vector_id'] === 'v3', 'second match must be v3 (45-degree)');
    $rulesAsserted += 3;

    // 10. vector_search: min_score filtering.
    $results = model_inference_vector_search($query, $candidates, 10, 0.9);
    cosine_similarity_contract_assert(count($results) === 1, 'min_score=0.9 must filter to 1 result');
    cosine_similarity_contract_assert($results[0]['vector_id'] === 'v1', 'only v1 scores >= 0.9');
    $rulesAsserted += 2;

    // 11. vector_search: empty candidates.
    $results = model_inference_vector_search($query, [], 5);
    cosine_similarity_contract_assert(count($results) === 0, 'empty candidates must return 0 results');
    $rulesAsserted++;

    // 12. vector_search: rejects topK < 1.
    $rejected = false;
    try {
        model_inference_vector_search($query, $candidates, 0);
    } catch (InvalidArgumentException $e) {
        $rejected = true;
    }
    cosine_similarity_contract_assert($rejected, 'topK < 1 must be rejected');
    $rulesAsserted++;

    // 13. Higher-dimensional vectors.
    $dim = 768;
    $a = array_fill(0, $dim, 1.0 / sqrt($dim));
    $b = $a;
    $score = model_inference_cosine_similarity($a, $b);
    cosine_similarity_contract_assert(abs($score - 1.0) < 1e-4, "768-dim identical normalized vectors must score ~1.0 (got {$score})");
    $rulesAsserted++;

    fwrite(STDOUT, "[cosine-similarity-contract] PASS ({$rulesAsserted} rules asserted)\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[cosine-similarity-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
