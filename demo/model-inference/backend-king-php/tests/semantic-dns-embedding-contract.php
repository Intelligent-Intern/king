<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/semantic_dns.php';

function semantic_dns_embedding_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[semantic-dns-embedding-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    // 1. Source contains embedding capability attributes.
    $ref = new ReflectionFunction('model_inference_semantic_dns_register');
    $source = file_get_contents($ref->getFileName());
    semantic_dns_embedding_contract_assert(
        is_string($source),
        'source must be readable'
    );

    $requiredAttributes = [
        'supports_embedding',
        'supports_retrieval',
        'supports_rag',
        'embedding_dimensions',
    ];
    foreach ($requiredAttributes as $attr) {
        semantic_dns_embedding_contract_assert(
            str_contains($source, "'{$attr}'"),
            "register must include '{$attr}' attribute"
        );
    }

    // 2. Graceful fallback when king extension unavailable.
    $profile = [
        'gpu' => ['present' => false, 'kind' => 'none', 'vram_total_bytes' => 0, 'vram_free_bytes' => 0],
        'capabilities' => [
            'supports_streaming' => true,
            'supports_quantizations' => ['Q4_K'],
            'supports_embedding' => true,
            'supports_retrieval' => true,
            'supports_rag' => true,
            'embedding_dimensions' => 768,
        ],
    ];

    $result = model_inference_semantic_dns_register('node_r13_test', '127.0.0.1', 18090, $profile);
    if (!function_exists('king_semantic_dns_register_service')) {
        semantic_dns_embedding_contract_assert(
            $result === false,
            'register must return false when extension is unavailable'
        );
    }

    // 3. Original M-batch attributes still present.
    $originalAttributes = [
        'node_id',
        'gpu_kind',
        'gpu_present',
        'vram_total_bytes',
        'vram_free_bytes',
        'supports_streaming',
        'supports_quantizations',
    ];
    foreach ($originalAttributes as $attr) {
        semantic_dns_embedding_contract_assert(
            str_contains($source, "'{$attr}'"),
            "register must still include M-batch attribute '{$attr}'"
        );
    }

    // 4. Service type unchanged.
    semantic_dns_embedding_contract_assert(
        str_contains($source, "'king.inference.v1'"),
        'service_type must remain king.inference.v1 (not a separate type for embedding)'
    );

    fwrite(STDOUT, "[semantic-dns-embedding-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[semantic-dns-embedding-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
