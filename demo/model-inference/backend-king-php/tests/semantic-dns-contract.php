<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/semantic_dns.php';

function semantic_dns_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[semantic-dns-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    // 1. Function signatures exist.
    semantic_dns_contract_assert(
        function_exists('model_inference_semantic_dns_register'),
        'model_inference_semantic_dns_register must exist'
    );
    semantic_dns_contract_assert(
        function_exists('model_inference_semantic_dns_update_status'),
        'model_inference_semantic_dns_update_status must exist'
    );
    semantic_dns_contract_assert(
        function_exists('model_inference_semantic_dns_deregister'),
        'model_inference_semantic_dns_deregister must exist'
    );
    semantic_dns_contract_assert(
        function_exists('model_inference_semantic_dns_heartbeat_after_ready'),
        'model_inference_semantic_dns_heartbeat_after_ready must exist'
    );

    // 2. Graceful fallback when king_semantic_dns_* are unavailable.
    //    Outside the extension, these functions don't exist, so each
    //    wrapper returns false without throwing.
    $profile = [
        'gpu' => [
            'present' => false,
            'kind' => 'none',
            'vram_total_bytes' => 0,
            'vram_free_bytes' => 0,
        ],
        'capabilities' => [
            'supports_streaming' => true,
            'supports_quantizations' => ['Q4_K'],
        ],
    ];

    $registerResult = model_inference_semantic_dns_register(
        'node_test_contract',
        '127.0.0.1',
        18090,
        $profile
    );
    // Without the extension, should return false (graceful).
    if (!function_exists('king_semantic_dns_register_service')) {
        semantic_dns_contract_assert(
            $registerResult === false,
            'register must return false when extension is unavailable'
        );
    }

    $updateResult = model_inference_semantic_dns_update_status(
        'node_test_contract',
        'draining',
        0,
        42,
        50
    );
    if (!function_exists('king_semantic_dns_update_service_status')) {
        semantic_dns_contract_assert(
            $updateResult === false,
            'update_status must return false when extension is unavailable'
        );
    }

    $deregisterResult = model_inference_semantic_dns_deregister('node_test_contract');
    if (!function_exists('king_semantic_dns_update_service_status')) {
        semantic_dns_contract_assert(
            $deregisterResult === false,
            'deregister must return false when extension is unavailable'
        );
    }

    $heartbeatResult = model_inference_semantic_dns_heartbeat_after_ready(
        'node_test_contract',
        1,
        1000
    );
    if (!function_exists('king_semantic_dns_discover_service')) {
        semantic_dns_contract_assert(
            $heartbeatResult === false,
            'heartbeat must return false when extension is unavailable'
        );
    }

    // 3. Service type constant is correct.
    $ref = new ReflectionFunction('model_inference_semantic_dns_register');
    $source = file_get_contents($ref->getFileName());
    semantic_dns_contract_assert(
        is_string($source) && str_contains($source, "'king.inference.v1'"),
        'register must use service_type king.inference.v1'
    );
    semantic_dns_contract_assert(
        str_contains($source, "'king-model-inference'"),
        'register must use service_name king-model-inference'
    );

    fwrite(STDOUT, "[semantic-dns-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[semantic-dns-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
