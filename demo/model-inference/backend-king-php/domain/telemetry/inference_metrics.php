<?php

declare(strict_types=1);

/**
 * Process-local, bounded-FIFO ring buffer of per-request inference telemetry.
 *
 * Scope fences (honest by design):
 * - In-memory only. There is no durable persistence at this leaf; a
 *   backend restart clears the ring. Transcript persistence lives in
 *   #M-16 and is a different contract (prompt + completion + telemetry
 *   snapshot, keyed by request_id). Telemetry records are the fast
 *   observability surface; transcripts are the durable audit surface.
 * - No cardinality explosion: one entry per completed inference. The
 *   ring rejects nothing — eviction is FIFO against the configured
 *   capacity. A caller that wants a longer tail configures a bigger
 *   ring.
 * - Recording is synchronous and cheap; the handler calls record() on
 *   the way out of the successful HTTP (#M-10) and WS (#M-11) paths.
 *   Failed inferences intentionally do NOT record here — partial state
 *   would corrupt tokens/s averages.
 */
final class InferenceMetricsRing
{
    /** @var array<int, array<string, mixed>> */
    private array $entries = [];
    private int $capacity;

    public function __construct(int $capacity = 100)
    {
        if ($capacity < 1) {
            throw new InvalidArgumentException("capacity must be >= 1 (got {$capacity})");
        }
        $this->capacity = $capacity;
    }

    public function capacity(): int
    {
        return $this->capacity;
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function clear(): void
    {
        $this->entries = [];
    }

    /**
     * Record one completed inference. The caller is expected to pass the
     * normalized shape below; any missing field is filled with a safe
     * default (zero / empty string). Every entry stamp is server-owned
     * to keep the ring honest even if a caller forwards stale values.
     *
     * @param array<string, mixed> $entry
     */
    public function record(array $entry): void
    {
        $tokensOut = (int) ($entry['tokens_out'] ?? 0);
        $durationMs = (int) ($entry['duration_ms'] ?? 0);
        $tokensPerSecond = 0.0;
        if ($tokensOut > 0 && $durationMs > 0) {
            $tokensPerSecond = round($tokensOut / ($durationMs / 1000.0), 3);
        }

        $normalized = [
            'request_id' => (string) ($entry['request_id'] ?? ''),
            'session_id' => (string) ($entry['session_id'] ?? ''),
            'transport' => in_array(($entry['transport'] ?? null), ['http', 'ws'], true) ? $entry['transport'] : 'http',
            'model_id' => (string) ($entry['model_id'] ?? ''),
            'model_name' => (string) ($entry['model_name'] ?? ''),
            'quantization' => (string) ($entry['quantization'] ?? ''),
            'node_id' => (string) ($entry['node_id'] ?? ''),
            'tokens_in' => (int) ($entry['tokens_in'] ?? 0),
            'tokens_out' => $tokensOut,
            'ttft_ms' => (int) ($entry['ttft_ms'] ?? 0),
            'duration_ms' => $durationMs,
            'tokens_per_second' => $tokensPerSecond,
            'vram_total_bytes' => (int) ($entry['vram_total_bytes'] ?? 0),
            'vram_free_bytes' => (int) ($entry['vram_free_bytes'] ?? 0),
            'gpu_kind' => (string) ($entry['gpu_kind'] ?? 'none'),
            'recorded_at' => gmdate('c'),
        ];

        $this->entries[] = $normalized;
        if (count($this->entries) > $this->capacity) {
            array_shift($this->entries);
        }
    }

    /**
     * Return the most recent entries, newest-first (LIFO over the FIFO ring).
     *
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit = 100): array
    {
        if ($limit < 1) {
            return [];
        }
        $reversed = array_reverse($this->entries);
        if ($limit < count($reversed)) {
            $reversed = array_slice($reversed, 0, $limit);
        }
        return $reversed;
    }
}

/**
 * Derive the telemetry entry from a completed HTTP /api/infer response
 * envelope (#M-10 shape) plus the profile snapshot used by the handler.
 *
 * @param array<string, mixed> $envelope response envelope returned by module_inference
 * @param array<string, mixed> $profile  node-profile envelope (#M-4)
 * @return array<string, mixed> shape accepted by InferenceMetricsRing::record
 */
function model_inference_metrics_entry_from_http(array $envelope, array $profile): array
{
    $gpu = (array) ($profile['gpu'] ?? []);
    return [
        'request_id' => (string) ($envelope['request_id'] ?? ''),
        'session_id' => (string) ($envelope['session_id'] ?? ''),
        'transport' => 'http',
        'model_id' => (string) (($envelope['model'] ?? [])['model_id'] ?? ''),
        'model_name' => (string) (($envelope['model'] ?? [])['model_name'] ?? ''),
        'quantization' => (string) (($envelope['model'] ?? [])['quantization'] ?? ''),
        'node_id' => (string) ($profile['node_id'] ?? ''),
        'tokens_in' => (int) (($envelope['completion'] ?? [])['tokens_in'] ?? 0),
        'tokens_out' => (int) (($envelope['completion'] ?? [])['tokens_out'] ?? 0),
        'ttft_ms' => (int) (($envelope['completion'] ?? [])['ttft_ms'] ?? 0),
        'duration_ms' => (int) (($envelope['completion'] ?? [])['duration_ms'] ?? 0),
        'vram_total_bytes' => (int) ($gpu['vram_total_bytes'] ?? 0),
        'vram_free_bytes' => (int) ($gpu['vram_free_bytes'] ?? 0),
        'gpu_kind' => (string) ($gpu['kind'] ?? 'none'),
    ];
}

/**
 * Derive the telemetry entry from a completed WS stream summary
 * (#M-11 shape) plus the profile snapshot used by the handler.
 *
 * @param array<string, mixed> $streamSummary output of model_inference_stream_completion()
 * @param array<string, mixed> $sessionHeader client-side envelope that started the stream
 * @param array<string, mixed> $modelEnvelope registry row
 * @param array<string, mixed> $profile       node-profile envelope (#M-4)
 * @return array<string, mixed>
 */
function model_inference_metrics_entry_from_ws(
    array $streamSummary,
    array $sessionHeader,
    array $modelEnvelope,
    array $profile
): array {
    $gpu = (array) ($profile['gpu'] ?? []);
    return [
        'request_id' => (string) ($streamSummary['request_id'] ?? ''),
        'session_id' => (string) ($sessionHeader['session_id'] ?? ''),
        'transport' => 'ws',
        'model_id' => (string) ($modelEnvelope['model_id'] ?? ''),
        'model_name' => (string) ($modelEnvelope['model_name'] ?? ''),
        'quantization' => (string) ($modelEnvelope['quantization'] ?? ''),
        'node_id' => (string) ($profile['node_id'] ?? ''),
        'tokens_in' => (int) ($streamSummary['tokens_in'] ?? 0),
        'tokens_out' => (int) ($streamSummary['tokens_out'] ?? 0),
        'ttft_ms' => (int) ($streamSummary['ttft_ms'] ?? 0),
        'duration_ms' => (int) ($streamSummary['duration_ms'] ?? 0),
        'vram_total_bytes' => (int) ($gpu['vram_total_bytes'] ?? 0),
        'vram_free_bytes' => (int) ($gpu['vram_free_bytes'] ?? 0),
        'gpu_kind' => (string) ($gpu['kind'] ?? 'none'),
    ];
}
