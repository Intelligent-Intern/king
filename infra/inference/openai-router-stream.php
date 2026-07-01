<?php
declare(strict_types=1);

function king_openai_router_elapsed_ms(int $startedNs, ?int $nowNs = null): float
{
    return round((($nowNs ?? hrtime(true)) - $startedNs) / 1_000_000, 3);
}

function king_openai_router_stream_finish_reason(array $event): ?string
{
    $choice = $event['choices'][0] ?? null;
    if (!is_array($choice) || !array_key_exists('finish_reason', $choice)) {
        return null;
    }
    return is_string($choice['finish_reason']) ? $choice['finish_reason'] : null;
}

function king_openai_router_stream_metrics(mixed $stream): array
{
    if (!is_object($stream) || !method_exists($stream, 'getMetrics')) {
        return [];
    }
    try {
        $metrics = $stream->getMetrics();
    } catch (Throwable) {
        return [];
    }
    return is_array($metrics) ? $metrics : [];
}

function king_openai_router_timing_payload(
    int $startedNs,
    int $nowNs,
    int $eventIndex,
    int $generatedTokenChunks,
    ?int $firstTokenNs,
    ?int $previousTokenNs,
    ?int $previousEventNs,
    bool $buffered,
    bool $terminal,
    ?string $finishReason,
    array $streamMetrics
): array {
    $payload = [
        'schema_version' => 1,
        'transport' => 'http1_chunked_body_stream',
        'flush_contract' => 'one_sse_event_per_body_stream_callback',
        'body_stream_callback' => true,
        'buffered' => $buffered,
        'event_index' => $eventIndex,
        'generated_token_chunks' => $generatedTokenChunks,
        'request_elapsed_ms' => king_openai_router_elapsed_ms($startedNs, $nowNs),
        'event_delta_ms' => $previousEventNs !== null ? round(($nowNs - $previousEventNs) / 1_000_000, 3) : null,
        'first_token_elapsed_ms' => $firstTokenNs !== null ? king_openai_router_elapsed_ms($startedNs, $firstTokenNs) : null,
        'token_delta_ms' => $previousTokenNs !== null ? round(($nowNs - $previousTokenNs) / 1_000_000, 3) : null,
        'terminal' => $terminal,
        'finish_reason' => $finishReason,
    ];

    foreach ([
        'chunks',
        'bytes',
        'native_decoder_tokens',
        'native_decoder_last_token_id',
        'gpu_thermal_aborted',
        'gpu_power_aborted',
    ] as $key) {
        if (array_key_exists($key, $streamMetrics)) {
            $payload[$key] = $streamMetrics[$key];
        }
    }

    return $payload;
}

function king_openai_router_attach_stream_timing(array $event, array $timing): array
{
    $king = $event['x_king'] ?? [];
    if (!is_array($king)) {
        $king = [];
    }
    $king['streaming'] = $timing;
    $event['x_king'] = $king;
    return $event;
}

function king_openai_router_model_stream_status(mixed $model, bool $allowBufferedNativeStream): array
{
    $info = [];
    if (is_object($model) && function_exists('king_inference_model_info')) {
        try {
            $info = king_inference_model_info($model);
        } catch (Throwable) {
            $info = [];
        }
    }
    $backend = is_string($info['backend'] ?? null) ? $info['backend'] : 'unknown';
    $capabilities = is_array($info['backend_capabilities'] ?? null) ? $info['backend_capabilities'] : [];
    $immediateGpuStream = $backend === 'king_native_gpu' && !empty($capabilities['gpu_prompt_decoder_loop']);
    $nativeBuffered = in_array($backend, ['king_native_cpu', 'king_native_gpu'], true) && !$immediateGpuStream;

    return [
        'accepted' => !$nativeBuffered || $allowBufferedNativeStream,
        'backend' => $backend,
        'native_buffered' => $nativeBuffered,
        'immediate_token_streaming' => $immediateGpuStream,
        'reason' => $nativeBuffered
            ? 'buffered_native_stream_requires_explicit_opt_in'
            : 'stream_backend_accepted',
    ];
}

function king_openai_router_attach_stream_backend_status(array $event, array $status): array
{
    $king = $event['x_king'] ?? [];
    if (!is_array($king)) {
        $king = [];
    }
    $king['stream_backend'] = $status;
    $event['x_king'] = $king;
    return $event;
}

function king_openai_router_typed_stream_end_response(
    string $responseId,
    int $created,
    string $responseModel,
    int $startedNs,
    array $status
): array {
    $nowNs = hrtime(true);
    $message = 'King accepted this OpenAI-compatible streaming request, but the selected model exposes a buffered native generation path instead of an immediate token stream. The router ended the stream without blocking the client.';
    $initial = king_openai_router_attach_stream_timing(
        king_openai_router_initial_chunk($responseId, $created, $responseModel),
        king_openai_router_timing_payload($startedNs, $nowNs, 1, 0, null, null, null, false, false, null, [])
    );
    $content = king_openai_router_attach_stream_timing(
        king_openai_router_content_chunk($responseId, $created, $responseModel, $message),
        king_openai_router_timing_payload($startedNs, $nowNs, 2, 1, $nowNs, null, $nowNs, false, false, null, [])
    );
    $terminal = king_openai_router_attach_stream_timing(
        king_openai_router_terminal_chunk($responseId, $created, $responseModel),
        king_openai_router_timing_payload($startedNs, $nowNs, 3, 1, $nowNs, $nowNs, $nowNs, false, true, 'stop', [])
    );
    $initial = king_openai_router_attach_stream_backend_status($initial, $status);
    $content = king_openai_router_attach_stream_backend_status($content, $status);
    $terminal = king_openai_router_attach_stream_backend_status($terminal, $status);

    return [
        'status' => 200,
        'headers' => [
            'content-type' => 'text/event-stream',
            'cache-control' => 'no-cache',
            'x-accel-buffering' => 'no',
            'x-king-openai-router-path' => 'typed_stream_end',
            'x-king-openai-stream-api' => 'capability_preflight',
            'x-king-openai-stream-backend' => (string) ($status['reason'] ?? 'stream_backend_rejected'),
            'x-king-openai-tool-fields' => 'accepted_context_only',
        ],
        'body' => king_openai_router_sse($initial)
            . king_openai_router_sse($content)
            . king_openai_router_sse($terminal)
            . "data: [DONE]\n\n",
    ];
}

function king_openai_router_stream_response(
    array $models,
    array $payload,
    array $options,
    array $request,
    int $startedNs
): ?array {
    $requestedModel = $payload['model'] ?? null;
    if (is_string($requestedModel) && $requestedModel !== '') {
        if (!array_key_exists($requestedModel, $models)) {
            return null;
        }
        $model = $models[$requestedModel];
    } else {
        $model = reset($models);
        if ($model === false) {
            return null;
        }
    }

    $streamPayload = $payload;
    $responseModel = is_string($streamPayload['model'] ?? null) && $streamPayload['model'] !== ''
        ? $streamPayload['model']
        : (is_string($requestedModel) && $requestedModel !== '' ? $requestedModel : 'king-local');
    $responseId = king_openai_router_stream_id();
    $created = time();
    $allowBufferedNativeStream = isset($options['allow_buffered_native_stream']) && is_bool($options['allow_buffered_native_stream'])
        ? $options['allow_buffered_native_stream']
        : false;
    $streamStatus = king_openai_router_model_stream_status($model, $allowBufferedNativeStream);
    if (empty($streamStatus['accepted'])) {
        return king_openai_router_typed_stream_end_response(
            $responseId,
            $created,
            $responseModel,
            $startedNs,
            $streamStatus
        );
    }
    $streamOptions = [
        'openai_compatible' => true,
        'format' => 'openai_chat_completions',
    ];
    $readTimeoutMs = isset($options['read_timeout_ms']) && is_int($options['read_timeout_ms'])
        ? max(0, $options['read_timeout_ms'])
        : 250;
    $maxEvents = isset($options['max_events']) && is_int($options['max_events'])
        ? max(1, $options['max_events'])
        : 4096;

    $stream = null;
    $done = false;
    $sentInitial = false;
    $logged = false;
    $events = 0;
    $generatedTokenChunks = 0;
    $firstTokenNs = null;
    $previousTokenNs = null;
    $previousEventNs = null;
    $plainArtifactMode = king_openai_router_plain_artifact_requested($payload);
    $plainArtifactContent = '';

    return [
        'status' => 200,
        'headers' => [
            'content-type' => 'text/event-stream',
            'cache-control' => 'no-cache',
            'x-accel-buffering' => 'no',
            'x-king-openai-router-path' => 'php_body_stream',
            'x-king-openai-stream-api' => 'king_inference_openai_chat_stream',
            'x-king-openai-compat-drain' => 'false',
            'x-king-openai-stream-buffered' => $plainArtifactMode ? 'artifact-only' : 'false',
            'x-king-openai-stream-flush' => 'http1_chunked_body_stream',
            'x-king-openai-tool-fields' => 'accepted_context_only',
            'x-king-openai-plain-artifact' => $plainArtifactMode ? 'true' : 'false',
        ],
        'body_stream' => static function () use ($model, $streamPayload, $streamOptions, $readTimeoutMs, $maxEvents, $responseId, $created, $responseModel, $request, $models, $startedNs, $plainArtifactMode, &$plainArtifactContent, &$stream, &$done, &$sentInitial, &$logged, &$events, &$generatedTokenChunks, &$firstTokenNs, &$previousTokenNs, &$previousEventNs): ?string {
            if ($done) {
                return null;
            }
            if (!$sentInitial) {
                $sentInitial = true;
                $events++;
                $nowNs = hrtime(true);
                $event = king_openai_router_initial_chunk($responseId, $created, $responseModel);
                $event = king_openai_router_attach_stream_timing(
                    $event,
                    king_openai_router_timing_payload(
                        $startedNs,
                        $nowNs,
                        $events,
                        $generatedTokenChunks,
                        $firstTokenNs,
                        $previousTokenNs,
                        $previousEventNs,
                        false,
                        false,
                        null,
                        []
                    )
                );
                $previousEventNs = $nowNs;
                return king_openai_router_sse($event);
            }
            if ($events >= $maxEvents) {
                $done = true;
                if (!$logged) {
                    $logged = true;
                    king_inference_runtime_log_request_completed($request, $models, $startedNs, ['status' => 200]);
                }
                return "event: error\ndata: {\"message\":\"King inference stream exceeded the configured router event limit.\"}\n\n"
                    . "data: [DONE]\n\n";
            }

            try {
                if ($stream === null) {
                    $stream = king_inference_openai_chat_stream($model, $streamPayload, $streamOptions);
                }

                $event = king_inference_next($stream, $readTimeoutMs);
            } catch (Throwable $e) {
                $done = true;
                if (!$logged) {
                    $logged = true;
                    king_inference_runtime_log_request_completed($request, $models, $startedNs, ['status' => 200]);
                }
                return "event: error\ndata: " . json_encode(['message' => $e->getMessage()], JSON_UNESCAPED_SLASHES) . "\n\n"
                    . "data: [DONE]\n\n";
            }
            if ($event === null) {
                return ": king-keepalive\n\n";
            }
            if (!is_array($event)) {
                $done = true;
                return "event: error\ndata: {\"message\":\"King inference stream produced an invalid event.\"}\n\n"
                    . "data: [DONE]\n\n";
            }

            $event = king_openai_router_normalize_chunk($event, $responseId, $created, $responseModel);
            if (king_openai_router_role_only_chunk($event)) {
                return ": king-role-ack\n\n";
            }

            $delta = king_openai_router_event_delta_content($event);
            $terminal = king_openai_router_stream_terminal($event);
            $finishReason = king_openai_router_stream_finish_reason($event);
            $nowNs = hrtime(true);
            $metrics = king_openai_router_stream_metrics($stream);
            if ($delta !== '' && !$plainArtifactMode) {
                if ($firstTokenNs === null) {
                    $firstTokenNs = $nowNs;
                }
                $generatedTokenChunks++;
            }

            if ($plainArtifactMode) {
                $plainArtifactContent .= $delta;
                if (!$terminal) {
                    $previousEventNs = $nowNs;
                    return ": king-artifact-buffer\n\n";
                }

                $done = true;
                if (!$logged) {
                    $logged = true;
                    king_inference_runtime_log_request_completed($request, $models, $startedNs, ['status' => 200]);
                }

                $cleaned = king_openai_router_strip_artifact_markdown_fence($plainArtifactContent);
                $event = king_openai_router_clear_event_delta_content($event);
                $chunk = '';
                if ($cleaned !== '') {
                    $events++;
                    $generatedTokenChunks++;
                    if ($firstTokenNs === null) {
                        $firstTokenNs = $nowNs;
                    }
                    $contentEvent = king_openai_router_content_chunk($responseId, $created, $responseModel, $cleaned);
                    $contentEvent = king_openai_router_attach_stream_timing(
                        $contentEvent,
                        king_openai_router_timing_payload(
                            $startedNs,
                            $nowNs,
                            $events,
                            $generatedTokenChunks,
                            $firstTokenNs,
                            $previousTokenNs,
                            $previousEventNs,
                            true,
                            false,
                            null,
                            $metrics
                        )
                    );
                    $previousTokenNs = $nowNs;
                    $chunk .= king_openai_router_sse($contentEvent);
                }
                $events++;
                $event = king_openai_router_attach_stream_timing(
                    $event,
                    king_openai_router_timing_payload(
                        $startedNs,
                        $nowNs,
                        $events,
                        $generatedTokenChunks,
                        $firstTokenNs,
                        $previousTokenNs,
                        $previousEventNs,
                        true,
                        true,
                        $finishReason ?? 'stop',
                        $metrics
                    )
                );
                $chunk .= king_openai_router_sse($event);
                $chunk .= "data: [DONE]\n\n";
                $previousEventNs = $nowNs;
                return $chunk;
            }

            $events++;
            $event = king_openai_router_attach_stream_timing(
                $event,
                king_openai_router_timing_payload(
                    $startedNs,
                    $nowNs,
                    $events,
                    $generatedTokenChunks,
                    $firstTokenNs,
                    $delta !== '' ? $previousTokenNs : null,
                    $previousEventNs,
                    false,
                    $terminal,
                    $finishReason,
                    $metrics
                )
            );
            $chunk = king_openai_router_sse($event);
            if ($delta !== '') {
                $previousTokenNs = $nowNs;
            }
            $previousEventNs = $nowNs;
            if ($terminal) {
                $done = true;
                if (!$logged) {
                    $logged = true;
                    king_inference_runtime_log_request_completed($request, $models, $startedNs, ['status' => 200]);
                }
                $chunk .= "data: [DONE]\n\n";
            }
            return $chunk;
        },
    ];
}
