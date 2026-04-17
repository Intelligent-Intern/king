<?php

declare(strict_types=1);

require_once __DIR__ . '/retrieval_pipeline.php';
require_once __DIR__ . '/../embedding/embedding_session.php';
require_once __DIR__ . '/../inference/inference_session.php';
require_once __DIR__ . '/../registry/model_registry.php';

/**
 * @param array<string, mixed> $validated  validated RAG request
 * @return array<string, mixed>
 */
function model_inference_rag_execute(
    PDO $pdo,
    array $validated,
    EmbeddingSession $embeddingSession,
    InferenceSession $inferenceSession,
    array $embeddingModel,
    array $chatModel
): array {
    $t0 = microtime(true);

    // 1. Embed the query.
    $embT0 = microtime(true);
    $embWorker = $embeddingSession->workerFor(
        (string) $embeddingModel['model_id'],
        (string) $embeddingModel['artifact']['object_store_key'],
        max(256, (int) $embeddingModel['context_length'])
    );
    $embResult = $embeddingSession->embed($embWorker, [$validated['query']], true);
    $embeddingMs = (int) round((microtime(true) - $embT0) * 1000);

    $queryVector = $embResult['embeddings'][0] ?? [];
    if (count($queryVector) === 0) {
        throw new RuntimeException('embedding produced empty vector for query');
    }

    // 2. Retrieve relevant chunks.
    $retrievalResult = model_inference_retrieval_search(
        $pdo,
        $queryVector,
        $validated['document_ids'],
        $validated['top_k'],
        $validated['min_score']
    );

    // 3. Build augmented prompt.
    $contextTexts = [];
    foreach ($retrievalResult['results'] as $result) {
        $text = (string) ($result['text'] ?? '');
        if ($text !== '') {
            $contextTexts[] = $text;
        }
    }

    $augmentedPrompt = model_inference_rag_build_prompt(
        $validated['query'],
        $contextTexts,
        $validated['system'] ?? null
    );

    // 4. Inference.
    $inferT0 = microtime(true);
    $sampling = $validated['sampling'];
    $inferWorker = $inferenceSession->workerFor(
        (string) $chatModel['model_id'],
        (string) $chatModel['artifact']['object_store_key'],
        max(256, (int) $chatModel['context_length']),
        (int) $sampling['max_tokens']
    );

    $inferEnvelope = [
        'prompt' => $augmentedPrompt,
        'system' => null,
        'sampling' => $sampling,
    ];
    $inferResult = $inferenceSession->completeNonStreaming($inferWorker, $inferEnvelope, (int) $sampling['max_tokens']);
    $inferenceMs = (int) round((microtime(true) - $inferT0) * 1000);

    $totalMs = (int) round((microtime(true) - $t0) * 1000);

    return [
        'completion' => (string) $inferResult['content'],
        'context' => [
            'chunks_used' => count($contextTexts),
            'results' => $retrievalResult['results'],
            'vectors_scanned' => $retrievalResult['vectors_scanned'],
        ],
        'model' => [
            'chat' => [
                'model_id' => (string) $chatModel['model_id'],
                'model_name' => (string) $chatModel['model_name'],
                'quantization' => (string) $chatModel['quantization'],
            ],
            'embedding' => [
                'model_id' => (string) $embeddingModel['model_id'],
                'model_name' => (string) $embeddingModel['model_name'],
                'quantization' => (string) $embeddingModel['quantization'],
            ],
        ],
        'timing' => [
            'embedding_ms' => $embeddingMs,
            'retrieval_ms' => $retrievalResult['search_ms'],
            'inference_ms' => $inferenceMs,
            'total_ms' => $totalMs,
        ],
        'inference' => [
            'tokens_in' => (int) $inferResult['tokens_in'],
            'tokens_out' => (int) $inferResult['tokens_out'],
            'ttft_ms' => (int) $inferResult['ttft_ms'],
            'duration_ms' => (int) $inferResult['duration_ms'],
        ],
    ];
}

/**
 * @param array<int, string> $contextTexts
 */
function model_inference_rag_build_prompt(string $query, array $contextTexts, ?string $systemOverride = null): string
{
    if (count($contextTexts) === 0) {
        return $query;
    }

    $contextBlock = '';
    foreach ($contextTexts as $i => $text) {
        $contextBlock .= "[" . ($i + 1) . "] " . trim($text) . "\n\n";
    }

    $system = $systemOverride ?? 'Answer the question using only the provided context. If the context does not contain enough information, say so.';

    return $system . "\n\n---\nContext:\n" . $contextBlock . "---\nQuestion: " . $query;
}

/** @return array<string, mixed>|null */
function model_inference_validate_rag_request(array $payload): ?array
{
    if (!isset($payload['query']) || !is_string($payload['query'])) {
        return null;
    }
    $query = $payload['query'];
    if (strlen($query) < 1 || strlen($query) > 32768) {
        return null;
    }

    if (!isset($payload['model_selector']) || !is_array($payload['model_selector'])) {
        return null;
    }
    $selector = $payload['model_selector'];

    if (!isset($selector['chat']) || !is_array($selector['chat'])) {
        return null;
    }
    if (!isset($selector['chat']['model_name']) || !is_string($selector['chat']['model_name'])) {
        return null;
    }
    if (!isset($selector['chat']['quantization']) || !is_string($selector['chat']['quantization'])) {
        return null;
    }

    if (!isset($selector['embedding']) || !is_array($selector['embedding'])) {
        return null;
    }
    if (!isset($selector['embedding']['model_name']) || !is_string($selector['embedding']['model_name'])) {
        return null;
    }
    if (!isset($selector['embedding']['quantization']) || !is_string($selector['embedding']['quantization'])) {
        return null;
    }

    $allowedQuants = ['Q2_K', 'Q3_K', 'Q4_0', 'Q4_K', 'Q5_K', 'Q6_K', 'Q8_0', 'F16'];
    if (!in_array($selector['chat']['quantization'], $allowedQuants, true)) {
        return null;
    }
    if (!in_array($selector['embedding']['quantization'], $allowedQuants, true)) {
        return null;
    }

    $documentIds = null;
    if (array_key_exists('document_ids', $payload) && $payload['document_ids'] !== null) {
        if (!is_array($payload['document_ids'])) {
            return null;
        }
        $documentIds = [];
        foreach ($payload['document_ids'] as $id) {
            if (!is_string($id) || $id === '') {
                return null;
            }
            $documentIds[] = $id;
        }
    }

    $topK = 5;
    if (array_key_exists('top_k', $payload) && is_int($payload['top_k'])) {
        $topK = $payload['top_k'];
        if ($topK < 1 || $topK > 100) {
            return null;
        }
    }

    $minScore = 0.0;
    if (array_key_exists('min_score', $payload)) {
        $ms = $payload['min_score'];
        if (is_int($ms)) {
            $ms = (float) $ms;
        }
        if (!is_float($ms) || $ms < 0.0 || $ms > 1.0) {
            return null;
        }
        $minScore = $ms;
    }

    $system = null;
    if (array_key_exists('system', $payload) && is_string($payload['system']) && $payload['system'] !== '') {
        $system = $payload['system'];
    }

    $sampling = [
        'temperature' => 0.7,
        'top_p' => 0.9,
        'top_k' => 40,
        'max_tokens' => 512,
    ];
    if (array_key_exists('sampling', $payload) && is_array($payload['sampling'])) {
        $s = $payload['sampling'];
        if (isset($s['temperature']) && (is_float($s['temperature']) || is_int($s['temperature']))) {
            $sampling['temperature'] = (float) $s['temperature'];
        }
        if (isset($s['top_p']) && (is_float($s['top_p']) || is_int($s['top_p']))) {
            $sampling['top_p'] = (float) $s['top_p'];
        }
        if (isset($s['top_k']) && is_int($s['top_k'])) {
            $sampling['top_k'] = $s['top_k'];
        }
        if (isset($s['max_tokens']) && is_int($s['max_tokens'])) {
            $sampling['max_tokens'] = $s['max_tokens'];
        }
    }

    return [
        'query' => $query,
        'model_selector' => [
            'chat' => [
                'model_name' => $selector['chat']['model_name'],
                'quantization' => $selector['chat']['quantization'],
            ],
            'embedding' => [
                'model_name' => $selector['embedding']['model_name'],
                'quantization' => $selector['embedding']['quantization'],
            ],
        ],
        'document_ids' => $documentIds,
        'top_k' => $topK,
        'min_score' => $minScore,
        'system' => $system,
        'sampling' => $sampling,
    ];
}
