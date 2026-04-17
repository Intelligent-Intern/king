<?php

declare(strict_types=1);

/**
 * Pure model-fit selector.
 *
 * Given a node profile (from `model_inference_hardware_profile()`) and a list
 * of registry entries (from `model_inference_registry_list()`), deterministically
 * pick the single best-fitting GGUF for this node, or null when no entry
 * fits.
 *
 * Honesty rules:
 * - Never selects an entry whose min_ram_bytes exceeds the profile's
 *   memory.available_bytes.
 * - Never selects an entry whose quantization is not in
 *   profile.capabilities.supports_quantizations.
 * - On a GPU-less profile, only allows entries with
 *   requirements.min_vram_bytes == 0. An entry that prefers_gpu=true but
 *   asks for 0 VRAM is still allowed on CPU (its requirements say it can
 *   run CPU-only even if it would run better on a GPU).
 * - On a GPU-present profile where vram_free_bytes == 0 (unreadable probe
 *   per the #M-4 node-profile contract), the selector treats VRAM as
 *   unavailable and falls back to CPU-only filtering rather than assuming
 *   any fictional VRAM budget.
 * - Ordering is deterministic: (largest fitting parameter_count DESC,
 *   highest quantization precision DESC, model_id ASC). The third tiebreak
 *   guarantees reproducibility given identical inputs.
 *
 * @param array<string, mixed>        $profile   node-profile envelope
 * @param array<int, array<string, mixed>> $registry list of model-registry-entry envelopes
 * @param array<string, mixed>        $options   optional filters:
 *   - model_name:         string, restrict to a specific model_name
 *   - quantization:       string, restrict to a specific quantization tag
 *   - min_context_tokens: int, reject entries whose context_length is below
 * @return array{winner: array<string,mixed>|null, candidates: array<int,array<string,mixed>>, rejected: array<int,array{entry: array<string,mixed>, reason: string}>, rules_applied: array<int, string>}
 */
function model_inference_select_model_fit(array $profile, array $registry, array $options = []): array
{
    $rules = [];
    $rules[] = 'memory.available_bytes must be >= entry.requirements.min_ram_bytes';

    $availableRam = (int) (($profile['memory'] ?? [])['available_bytes'] ?? 0);
    $gpu = is_array($profile['gpu'] ?? null) ? $profile['gpu'] : [];
    $gpuPresent = (bool) ($gpu['present'] ?? false);
    $vramFree = (int) ($gpu['vram_free_bytes'] ?? 0);
    $vramReadable = $gpuPresent && $vramFree > 0;
    $supportedQuantizations = (array) (($profile['capabilities'] ?? [])['supports_quantizations'] ?? []);

    if (!$gpuPresent) {
        $rules[] = 'no-gpu: entry.requirements.min_vram_bytes must be 0';
    } elseif ($vramReadable) {
        $rules[] = 'gpu-present+vram-readable: entry.requirements.min_vram_bytes must be <= gpu.vram_free_bytes';
    } else {
        $rules[] = 'gpu-present+vram-unreadable: treat VRAM as unavailable, require min_vram_bytes == 0 (no fabricated budget)';
    }

    if ($supportedQuantizations !== []) {
        $rules[] = 'entry.quantization must be in profile.capabilities.supports_quantizations';
    }

    $modelNameFilter = isset($options['model_name']) ? trim((string) $options['model_name']) : '';
    if ($modelNameFilter !== '') {
        $rules[] = 'options.model_name filter active: ' . $modelNameFilter;
    }
    $quantizationFilter = isset($options['quantization']) ? trim((string) $options['quantization']) : '';
    if ($quantizationFilter !== '') {
        $rules[] = 'options.quantization filter active: ' . $quantizationFilter;
    }
    $minContextTokens = isset($options['min_context_tokens']) ? (int) $options['min_context_tokens'] : 0;
    if ($minContextTokens > 0) {
        $rules[] = 'options.min_context_tokens filter active: >= ' . $minContextTokens;
    }

    $candidates = [];
    $rejected = [];

    foreach ($registry as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $requirements = is_array($entry['requirements'] ?? null) ? $entry['requirements'] : [];
        $minRam = (int) ($requirements['min_ram_bytes'] ?? 0);
        $minVram = (int) ($requirements['min_vram_bytes'] ?? 0);
        $quantization = (string) ($entry['quantization'] ?? '');

        if ($modelNameFilter !== '' && (string) ($entry['model_name'] ?? '') !== $modelNameFilter) {
            $rejected[] = ['entry' => $entry, 'reason' => 'model_name_filter'];
            continue;
        }
        if ($quantizationFilter !== '' && $quantization !== $quantizationFilter) {
            $rejected[] = ['entry' => $entry, 'reason' => 'quantization_filter'];
            continue;
        }
        if ($minContextTokens > 0 && (int) ($entry['context_length'] ?? 0) < $minContextTokens) {
            $rejected[] = ['entry' => $entry, 'reason' => 'context_length_below_minimum'];
            continue;
        }
        if ($minRam > $availableRam) {
            $rejected[] = ['entry' => $entry, 'reason' => 'ram_budget_exceeded'];
            continue;
        }
        if ($supportedQuantizations !== [] && !in_array($quantization, $supportedQuantizations, true)) {
            $rejected[] = ['entry' => $entry, 'reason' => 'quantization_not_supported'];
            continue;
        }
        if (!$gpuPresent && $minVram > 0) {
            $rejected[] = ['entry' => $entry, 'reason' => 'gpu_required_but_none_present'];
            continue;
        }
        if ($gpuPresent && !$vramReadable && $minVram > 0) {
            $rejected[] = ['entry' => $entry, 'reason' => 'vram_unreadable_cpu_fallback_requires_zero_vram'];
            continue;
        }
        if ($gpuPresent && $vramReadable && $minVram > $vramFree) {
            $rejected[] = ['entry' => $entry, 'reason' => 'vram_budget_exceeded'];
            continue;
        }
        $candidates[] = $entry;
    }

    usort($candidates, static function (array $a, array $b): int {
        $aParams = (int) ($a['parameter_count'] ?? 0);
        $bParams = (int) ($b['parameter_count'] ?? 0);
        if ($aParams !== $bParams) {
            return $bParams <=> $aParams;
        }
        $aQuant = model_inference_quantization_precision_rank((string) ($a['quantization'] ?? ''));
        $bQuant = model_inference_quantization_precision_rank((string) ($b['quantization'] ?? ''));
        if ($aQuant !== $bQuant) {
            return $bQuant <=> $aQuant;
        }
        return strcmp((string) ($a['model_id'] ?? ''), (string) ($b['model_id'] ?? ''));
    });

    return [
        'winner' => $candidates[0] ?? null,
        'candidates' => $candidates,
        'rejected' => $rejected,
        'rules_applied' => $rules,
    ];
}

/**
 * Rank of a GGUF quantization tag by expected output quality. Higher is
 * better. Unknown tags get rank 0 so they tie at the bottom in a stable,
 * non-crashy way.
 */
function model_inference_quantization_precision_rank(string $tag): int
{
    return match ($tag) {
        'F16' => 100,
        'Q8_0' => 80,
        'Q6_K' => 60,
        'Q5_K' => 50,
        'Q4_K' => 40,
        'Q4_0' => 35,
        'Q3_K' => 30,
        'Q2_K' => 20,
        default => 0,
    };
}
