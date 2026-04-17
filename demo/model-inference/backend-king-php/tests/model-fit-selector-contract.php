<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/registry/model_fit_selector.php';

function model_inference_selector_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[model-fit-selector-contract] FAIL: {$message}\n");
    exit(1);
}

/** @return array<string, mixed> */
function model_inference_selector_make_profile(array $overrides): array
{
    $defaults = [
        'node_id' => 'node_fixture',
        'king_version' => '1.0.6',
        'platform' => ['os' => 'linux', 'arch' => 'x86_64', 'kernel' => '6.5.0'],
        'cpu' => ['logical_count' => 8, 'physical_count' => 8, 'brand' => 'fixture'],
        'memory' => ['total_bytes' => 16 * 1024 ** 3, 'available_bytes' => 12 * 1024 ** 3, 'page_size' => 4096],
        'gpu' => ['present' => false, 'kind' => 'none', 'device_count' => 0, 'vram_total_bytes' => 0, 'vram_free_bytes' => 0],
        'capabilities' => [
            'loadable_models' => [],
            'max_context_tokens' => 0,
            'supports_streaming' => true,
            'supports_quantizations' => ['Q2_K', 'Q3_K', 'Q4_0', 'Q4_K', 'Q5_K', 'Q6_K', 'Q8_0', 'F16'],
        ],
        'service' => ['service_type' => 'king.inference.v1', 'health_url' => 'http://127.0.0.1:18090/health', 'status' => 'ready'],
        'published_at' => gmdate('c'),
    ];
    return array_replace_recursive($defaults, $overrides);
}

/** @return array<string, mixed> */
function model_inference_selector_make_entry(array $overrides): array
{
    $defaults = [
        'model_id' => 'mdl-0000000000000000',
        'model_name' => 'fixture-model',
        'family' => 'llama',
        'parameter_count' => 1_000_000_000,
        'quantization' => 'Q4_0',
        'context_length' => 2048,
        'artifact' => [
            'object_store_key' => 'mdl-0000000000000000',
            'byte_length' => 1,
            'sha256_hex' => str_repeat('0', 64),
            'uploaded_at' => gmdate('c'),
        ],
        'requirements' => [
            'min_ram_bytes' => 2 * 1024 ** 3,
            'min_vram_bytes' => 0,
            'prefers_gpu' => false,
        ],
        'license' => 'apache-2.0',
        'source_url' => null,
        'registered_at' => gmdate('c'),
    ];
    return array_replace_recursive($defaults, $overrides);
}

try {
    // Fixture registry covering small CPU-friendly, medium, large, and VRAM-only models.
    $tiny1B_Q4_0 = model_inference_selector_make_entry([
        'model_id' => 'mdl-aaaaaaaaaaaaaaa1',
        'model_name' => 'TinyLlama-1.1B',
        'parameter_count' => 1_100_000_000,
        'quantization' => 'Q4_0',
        'requirements' => ['min_ram_bytes' => 2 * 1024 ** 3, 'min_vram_bytes' => 0, 'prefers_gpu' => false],
    ]);
    $tiny1B_Q8_0 = model_inference_selector_make_entry([
        'model_id' => 'mdl-aaaaaaaaaaaaaaa2',
        'model_name' => 'TinyLlama-1.1B',
        'parameter_count' => 1_100_000_000,
        'quantization' => 'Q8_0',
        'requirements' => ['min_ram_bytes' => 4 * 1024 ** 3, 'min_vram_bytes' => 0, 'prefers_gpu' => false],
    ]);
    $mid7B_Q4_K = model_inference_selector_make_entry([
        'model_id' => 'mdl-bbbbbbbbbbbbbbb1',
        'model_name' => 'Llama3-7B',
        'parameter_count' => 7_000_000_000,
        'quantization' => 'Q4_K',
        'context_length' => 8192,
        'requirements' => ['min_ram_bytes' => 8 * 1024 ** 3, 'min_vram_bytes' => 0, 'prefers_gpu' => true],
    ]);
    $mid7B_Q8_0_gpu = model_inference_selector_make_entry([
        'model_id' => 'mdl-bbbbbbbbbbbbbbb2',
        'model_name' => 'Llama3-7B',
        'parameter_count' => 7_000_000_000,
        'quantization' => 'Q8_0',
        'context_length' => 8192,
        'requirements' => ['min_ram_bytes' => 4 * 1024 ** 3, 'min_vram_bytes' => 8 * 1024 ** 3, 'prefers_gpu' => true],
    ]);
    $big13B_Q6_K_gpu = model_inference_selector_make_entry([
        'model_id' => 'mdl-ccccccccccccccc1',
        'model_name' => 'Llama3-13B',
        'parameter_count' => 13_000_000_000,
        'quantization' => 'Q6_K',
        'context_length' => 8192,
        'requirements' => ['min_ram_bytes' => 10 * 1024 ** 3, 'min_vram_bytes' => 16 * 1024 ** 3, 'prefers_gpu' => true],
    ]);
    $registry = [$tiny1B_Q4_0, $tiny1B_Q8_0, $mid7B_Q4_K, $mid7B_Q8_0_gpu, $big13B_Q6_K_gpu];

    // 1. Darwin with Metal present but VRAM unreadable (per #M-4 profile
    // honesty rule), 6 GiB available RAM. Only TinyLlama entries fit;
    // 7B Q4_K is rejected for RAM (8 GiB > 6 GiB), 7B Q8_0 and 13B are
    // rejected because min_vram > 0 cannot be satisfied when VRAM is
    // unreadable (CPU-fallback requires min_vram == 0). Winner: TinyLlama
    // Q8_0 (higher precision) over Q4_0 with equal parameter_count.
    $cpuSmall = model_inference_selector_make_profile([
        'platform' => ['os' => 'darwin'],
        'memory' => ['available_bytes' => 6 * 1024 ** 3],
        'gpu' => ['present' => true, 'kind' => 'metal', 'device_count' => 1, 'vram_total_bytes' => 0, 'vram_free_bytes' => 0],
    ]);
    $pick = model_inference_select_model_fit($cpuSmall, $registry);
    model_inference_selector_contract_assert($pick['winner'] !== null, '[darwin 6G Metal-unreadable] expected a winner');
    model_inference_selector_contract_assert(($pick['winner']['model_id'] ?? null) === 'mdl-aaaaaaaaaaaaaaa2', '[darwin 6G Metal-unreadable] expected Q8_0 1.1B (higher precision tiebreak), got ' . ($pick['winner']['model_id'] ?? 'null'));
    model_inference_selector_contract_assert(count($pick['candidates']) === 2, '[darwin 6G Metal-unreadable] expected 2 candidates (Q4_0 and Q8_0 tiny), got ' . count($pick['candidates']));

    // Assert the right entries were rejected with the right reasons.
    $rejectIndex = [];
    foreach ($pick['rejected'] as $row) {
        $rejectIndex[$row['entry']['model_id']] = $row['reason'];
    }
    model_inference_selector_contract_assert(($rejectIndex['mdl-bbbbbbbbbbbbbbb1'] ?? null) === 'ram_budget_exceeded', '[darwin 6G] 7B Q4_K should be rejected for RAM');
    model_inference_selector_contract_assert(($rejectIndex['mdl-bbbbbbbbbbbbbbb2'] ?? null) === 'vram_unreadable_cpu_fallback_requires_zero_vram', '[darwin 6G] 7B Q8_0 with min_vram should be rejected under unreadable VRAM');
    model_inference_selector_contract_assert(($rejectIndex['mdl-ccccccccccccccc1'] ?? null) === 'ram_budget_exceeded', '[darwin 6G] 13B should be rejected (RAM budget also exceeded; evaluated before VRAM rule)');

    // 2. CUDA linux 24 GiB free VRAM + 64 GiB available RAM: picks the 13B
    // Q6_K (largest params); Q8_0 7B would win on precision but has fewer
    // params. Q4_K 7B fits too. Rejections: none expected.
    $cudaBig = model_inference_selector_make_profile([
        'platform' => ['os' => 'linux'],
        'memory' => ['available_bytes' => 64 * 1024 ** 3],
        'gpu' => ['present' => true, 'kind' => 'cuda', 'device_count' => 1, 'vram_total_bytes' => 24 * 1024 ** 3, 'vram_free_bytes' => 24 * 1024 ** 3],
    ]);
    $pick = model_inference_select_model_fit($cudaBig, $registry);
    model_inference_selector_contract_assert(($pick['winner']['model_id'] ?? null) === 'mdl-ccccccccccccccc1', '[cuda 24G/64G] expected 13B Q6_K as largest winner; got ' . ($pick['winner']['model_id'] ?? 'null'));
    model_inference_selector_contract_assert(count($pick['candidates']) === 5, '[cuda 24G/64G] expected all 5 entries to fit, got ' . count($pick['candidates']));
    model_inference_selector_contract_assert(count($pick['rejected']) === 0, '[cuda 24G/64G] expected no rejections');

    // 3. CUDA linux 4 GiB VRAM free: 13B and 7B Q8_0 both exceed VRAM; 7B
    // Q4_K min_vram is 0 (CPU-loadable with GPU offload preference) so it
    // still fits. Q4_K 7B wins over tiny 1.1B.
    $cudaLow = model_inference_selector_make_profile([
        'platform' => ['os' => 'linux'],
        'memory' => ['available_bytes' => 16 * 1024 ** 3],
        'gpu' => ['present' => true, 'kind' => 'cuda', 'device_count' => 1, 'vram_total_bytes' => 4 * 1024 ** 3, 'vram_free_bytes' => 4 * 1024 ** 3],
    ]);
    $pick = model_inference_select_model_fit($cudaLow, $registry);
    model_inference_selector_contract_assert(($pick['winner']['model_id'] ?? null) === 'mdl-bbbbbbbbbbbbbbb1', '[cuda 4G/16G] expected 7B Q4_K; got ' . ($pick['winner']['model_id'] ?? 'null'));

    // 4. Low-RAM edge 2 GiB available + no GPU: only the 2 GiB-footprint
    // TinyLlama Q4_0 entry fits; Q8_0 1.1B asks for 4 GiB RAM.
    $edge = model_inference_selector_make_profile([
        'memory' => ['available_bytes' => 2 * 1024 ** 3],
        'gpu' => ['present' => false, 'kind' => 'none', 'vram_total_bytes' => 0, 'vram_free_bytes' => 0],
    ]);
    $pick = model_inference_select_model_fit($edge, $registry);
    model_inference_selector_contract_assert(($pick['winner']['model_id'] ?? null) === 'mdl-aaaaaaaaaaaaaaa1', '[edge 2G no-GPU] expected TinyLlama Q4_0; got ' . ($pick['winner']['model_id'] ?? 'null'));
    model_inference_selector_contract_assert(count($pick['candidates']) === 1, '[edge 2G no-GPU] expected exactly 1 fitting candidate');

    // 5. Quantization filter: strip Q8_0 from supports_quantizations on the
    // 2 GiB no-GPU edge profile. Without the filter case 4 already proved
    // the Q4_0 winner; adding this case proves that *even if* the Q8_0
    // 1.1B variant WERE cheap enough, it would still be filtered out by
    // the supports_quantizations policy. We verify by checking both
    // candidates and rejections.
    $edgeNoQ8 = model_inference_selector_make_profile([
        'memory' => ['available_bytes' => 4 * 1024 ** 3], // enough for Q8_0 1.1B (4 GiB)
        'gpu' => ['present' => false, 'kind' => 'none', 'vram_total_bytes' => 0, 'vram_free_bytes' => 0],
        'capabilities' => ['supports_quantizations' => ['Q2_K', 'Q3_K', 'Q4_0', 'Q4_K', 'Q5_K', 'Q6_K', 'F16']],
    ]);
    $pick = model_inference_select_model_fit($edgeNoQ8, $registry);
    model_inference_selector_contract_assert(($pick['winner']['model_id'] ?? null) === 'mdl-aaaaaaaaaaaaaaa1', '[edge 4G no-Q8_0] expected Q4_0 winner after Q8_0 filtered; got ' . ($pick['winner']['model_id'] ?? 'null'));
    $q8Rejected = false;
    foreach ($pick['rejected'] as $row) {
        if (($row['entry']['model_id'] ?? null) === 'mdl-aaaaaaaaaaaaaaa2' && $row['reason'] === 'quantization_not_supported') {
            $q8Rejected = true;
        }
    }
    model_inference_selector_contract_assert($q8Rejected, '[edge 4G no-Q8_0] Q8_0 1.1B must be rejected with quantization_not_supported');

    // 6. No-candidate case: 1 GiB RAM only. Every entry asks for >= 2 GiB.
    $tinyHost = model_inference_selector_make_profile([
        'memory' => ['available_bytes' => 1 * 1024 ** 3],
        'gpu' => ['present' => false, 'kind' => 'none'],
    ]);
    $pick = model_inference_select_model_fit($tinyHost, $registry);
    model_inference_selector_contract_assert($pick['winner'] === null, '[tiny host] expected winner=null');
    model_inference_selector_contract_assert(count($pick['candidates']) === 0, '[tiny host] expected 0 candidates');
    model_inference_selector_contract_assert(count($pick['rejected']) === 5, '[tiny host] expected all 5 rejected');

    // 7. model_name filter: restrict to Llama3-7B name.
    $pick = model_inference_select_model_fit($cudaBig, $registry, ['model_name' => 'Llama3-7B']);
    model_inference_selector_contract_assert($pick['winner'] !== null, '[cuda + model_name=Llama3-7B] expected a winner');
    model_inference_selector_contract_assert(($pick['winner']['model_name'] ?? null) === 'Llama3-7B', '[cuda + model_name=Llama3-7B] winner must match filter');
    model_inference_selector_contract_assert(($pick['winner']['quantization'] ?? null) === 'Q8_0', '[cuda + model_name=Llama3-7B] Q8_0 beats Q4_K on tiebreak at equal param count');

    // 8. min_context_tokens filter.
    $pick = model_inference_select_model_fit($cudaBig, $registry, ['min_context_tokens' => 4096]);
    model_inference_selector_contract_assert($pick['winner'] !== null, '[ctx>=4096] expected a winner');
    model_inference_selector_contract_assert(((int) ($pick['winner']['context_length'] ?? 0)) >= 4096, '[ctx>=4096] winner context_length must be >= 4096');

    // 9. Deterministic tiebreak by model_id: two entries with identical
    // params + quantization should sort by model_id ASC.
    $twinA = model_inference_selector_make_entry(['model_id' => 'mdl-1111111111111111', 'model_name' => 'twin', 'parameter_count' => 500_000_000, 'quantization' => 'Q4_0', 'requirements' => ['min_ram_bytes' => 1024 ** 3, 'min_vram_bytes' => 0, 'prefers_gpu' => false]]);
    $twinB = model_inference_selector_make_entry(['model_id' => 'mdl-2222222222222222', 'model_name' => 'twin', 'parameter_count' => 500_000_000, 'quantization' => 'Q4_0', 'requirements' => ['min_ram_bytes' => 1024 ** 3, 'min_vram_bytes' => 0, 'prefers_gpu' => false]]);
    $pickA = model_inference_select_model_fit($edge, [$twinB, $twinA]);
    $pickB = model_inference_select_model_fit($edge, [$twinA, $twinB]);
    model_inference_selector_contract_assert(($pickA['winner']['model_id'] ?? null) === 'mdl-1111111111111111', '[tiebreak] winner must be lowest model_id regardless of input order (got A=' . ($pickA['winner']['model_id'] ?? 'null') . ')');
    model_inference_selector_contract_assert(($pickB['winner']['model_id'] ?? null) === 'mdl-1111111111111111', '[tiebreak] winner must be lowest model_id regardless of input order (got B=' . ($pickB['winner']['model_id'] ?? 'null') . ')');

    // 10. Quantization precision rank sanity.
    model_inference_selector_contract_assert(model_inference_quantization_precision_rank('F16') > model_inference_quantization_precision_rank('Q8_0'), 'F16 must rank above Q8_0');
    model_inference_selector_contract_assert(model_inference_quantization_precision_rank('Q8_0') > model_inference_quantization_precision_rank('Q4_K'), 'Q8_0 must rank above Q4_K');
    model_inference_selector_contract_assert(model_inference_quantization_precision_rank('Q4_K') > model_inference_quantization_precision_rank('Q2_K'), 'Q4_K must rank above Q2_K');
    model_inference_selector_contract_assert(model_inference_quantization_precision_rank('unknown-tag') === 0, 'unknown quantization tags must rank at 0');

    // 11. rules_applied trace must surface VRAM handling decisions.
    $rules = $pick['rules_applied'];
    model_inference_selector_contract_assert(
        in_array('memory.available_bytes must be >= entry.requirements.min_ram_bytes', $rules, true),
        'rules_applied must document the RAM invariant'
    );

    fwrite(STDOUT, "[model-fit-selector-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[model-fit-selector-contract] ERROR: ' . $error->getMessage() . ' @ ' . $error->getFile() . ':' . $error->getLine() . "\n");
    exit(1);
}
