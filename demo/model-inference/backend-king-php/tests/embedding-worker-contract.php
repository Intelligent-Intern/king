<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/embedding/embedding_session.php';

function embedding_worker_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[embedding-worker-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    // 1. EmbeddingSession class exists and is final.
    embedding_worker_contract_assert(
        class_exists('EmbeddingSession'),
        'EmbeddingSession class must exist'
    );
    $ref = new ReflectionClass('EmbeddingSession');
    embedding_worker_contract_assert(
        $ref->isFinal(),
        'EmbeddingSession must be final'
    );

    // 2. Required public methods exist.
    $requiredMethods = ['workerFor', 'embed', 'drainAll', 'diagnostics'];
    foreach ($requiredMethods as $methodName) {
        embedding_worker_contract_assert(
            $ref->hasMethod($methodName),
            "EmbeddingSession must have public method {$methodName}"
        );
        $method = $ref->getMethod($methodName);
        embedding_worker_contract_assert(
            $method->isPublic(),
            "EmbeddingSession::{$methodName} must be public"
        );
    }

    // 3. workerFor accepts (modelId, objectStoreKey, contextTokens).
    $workerForParams = $ref->getMethod('workerFor')->getParameters();
    embedding_worker_contract_assert(
        count($workerForParams) >= 2,
        'workerFor must accept at least modelId and objectStoreKey'
    );
    embedding_worker_contract_assert(
        $workerForParams[0]->getName() === 'modelId',
        'workerFor first param must be modelId'
    );
    embedding_worker_contract_assert(
        $workerForParams[1]->getName() === 'objectStoreKey',
        'workerFor second param must be objectStoreKey'
    );

    // 4. embed accepts (worker, texts, normalize).
    $embedParams = $ref->getMethod('embed')->getParameters();
    embedding_worker_contract_assert(
        count($embedParams) >= 2,
        'embed must accept at least worker and texts'
    );
    embedding_worker_contract_assert(
        $embedParams[0]->getType()->getName() === 'LlamaCppWorker',
        'embed first param must be typed LlamaCppWorker'
    );

    // 5. Constructor requires llamaBinaryPath, llamaLibraryPath, ggufCacheRoot.
    $ctorParams = $ref->getConstructor()->getParameters();
    embedding_worker_contract_assert(
        count($ctorParams) >= 3,
        'EmbeddingSession constructor must accept at least 3 params'
    );
    $ctorParamNames = array_map(static fn($p) => $p->getName(), $ctorParams);
    embedding_worker_contract_assert(
        $ctorParamNames[0] === 'llamaBinaryPath',
        'constructor first param must be llamaBinaryPath'
    );
    embedding_worker_contract_assert(
        $ctorParamNames[1] === 'llamaLibraryPath',
        'constructor second param must be llamaLibraryPath'
    );
    embedding_worker_contract_assert(
        $ctorParamNames[2] === 'ggufCacheRoot',
        'constructor third param must be ggufCacheRoot'
    );

    // 6. The --embedding flag is present in the source.
    $source = file_get_contents($ref->getFileName());
    embedding_worker_contract_assert(
        is_string($source) && str_contains($source, "'--embedding'"),
        'EmbeddingSession must pass --embedding flag to worker start'
    );

    // 7. The /v1/embeddings endpoint is referenced.
    embedding_worker_contract_assert(
        str_contains($source, '/v1/embeddings'),
        'EmbeddingSession must call llama.cpp /v1/embeddings endpoint'
    );

    // 8. L2 normalization is implemented.
    embedding_worker_contract_assert(
        str_contains($source, 'l2Normalize'),
        'EmbeddingSession must implement L2 normalization'
    );

    // 9. diagnostics returns an array.
    $tmpDir = sys_get_temp_dir() . '/embedding-worker-contract-' . bin2hex(random_bytes(4));
    mkdir($tmpDir, 0775, true);
    try {
        $llamaBin = $tmpDir . '/llama-server';
        file_put_contents($llamaBin, '#!/bin/sh' . "\n" . 'echo dummy');
        chmod($llamaBin, 0755);
        $session = new EmbeddingSession($llamaBin, $tmpDir, $tmpDir . '/cache');
        $diag = $session->diagnostics();
        embedding_worker_contract_assert(
            is_array($diag) && count($diag) === 0,
            'diagnostics on fresh session must return empty array'
        );
        $session->drainAll();
    } finally {
        @unlink($tmpDir . '/llama-server');
        @rmdir($tmpDir . '/cache');
        @rmdir($tmpDir);
    }

    fwrite(STDOUT, "[embedding-worker-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[embedding-worker-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
