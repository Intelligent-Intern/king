<?php

declare(strict_types=1);

require_once __DIR__ . '/../../support/token_frame.php';
require_once __DIR__ . '/inference_session.php';

/**
 * Stream a completion from llama.cpp's /completion?stream=true endpoint
 * and re-emit each token delta as an IIBIN-style binary token frame
 * (see contracts/v1/token-frame.contract.json) via the caller-supplied
 * $sendBinaryFrame callable.
 *
 * The caller is responsible for wiring $sendBinaryFrame: in production
 * it wraps king_websocket_send($ws, $frame, true) with is_binary=true;
 * in tests it pushes the bytes onto an array for decode-and-assert.
 * Transport failures raise RuntimeException; the caller maps them into
 * an appropriate frame_type=error frame or a WS close.
 *
 * No mock mode. llama.cpp is the real execution engine; if the worker
 * dies mid-stream the function throws and the caller surfaces a real
 * worker_unavailable error.
 *
 * @param array<string, mixed> $validatedEnvelope  output of
 *        model_inference_validate_infer_request(..., transport='ws')
 * @return array<string, mixed> stream summary
 */
function model_inference_stream_completion(
    LlamaCppWorker $worker,
    array $validatedEnvelope,
    string $requestId,
    callable $sendBinaryFrame,
    int $effectiveMaxTokens,
    int $completionTimeoutMs = 60_000
): array {
    $sampling = (array) $validatedEnvelope['sampling'];
    $prompt = (string) $validatedEnvelope['prompt'];
    if (isset($validatedEnvelope['system']) && is_string($validatedEnvelope['system']) && $validatedEnvelope['system'] !== '') {
        $prompt = $validatedEnvelope['system'] . "\n\n" . $prompt;
    }

    $body = [
        'prompt' => $prompt,
        'n_predict' => $effectiveMaxTokens,
        'temperature' => (float) $sampling['temperature'],
        'top_p' => (float) $sampling['top_p'],
        'top_k' => (int) $sampling['top_k'],
        'stream' => true,
        'cache_prompt' => false,
    ];
    if (isset($sampling['seed']) && is_int($sampling['seed'])) {
        $body['seed'] = (int) $sampling['seed'];
    }
    $jsonBody = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($jsonBody)) {
        throw new RuntimeException('failed to encode streaming completion body');
    }

    $sock = @stream_socket_client(
        sprintf('tcp://127.0.0.1:%d', $worker->port()),
        $errno,
        $errstr,
        5,
        STREAM_CLIENT_CONNECT
    );
    if (!is_resource($sock)) {
        throw new RuntimeException("stream connect failed: {$errstr}");
    }
    stream_set_timeout($sock, max(1, (int) ceil($completionTimeoutMs / 1000)));

    $request = "POST /completion HTTP/1.1\r\n"
        . "Host: 127.0.0.1\r\n"
        . "Content-Type: application/json\r\n"
        . "Content-Length: " . strlen($jsonBody) . "\r\n"
        . "Accept: text/event-stream\r\n"
        . "Connection: close\r\n"
        . "\r\n"
        . $jsonBody;
    fwrite($sock, $request);

    // Parse response status + headers.
    $statusLine = fgets($sock);
    if (!is_string($statusLine) || preg_match('#^HTTP/\S+\s+(\d+)#', $statusLine, $m) !== 1) {
        fclose($sock);
        throw new RuntimeException('no status line from worker /completion');
    }
    $status = (int) $m[1];
    while (true) {
        $line = fgets($sock);
        if ($line === false || $line === "\r\n" || $line === "\n") {
            break;
        }
    }
    if ($status < 200 || $status >= 300) {
        $tail = stream_get_contents($sock);
        fclose($sock);
        throw new RuntimeException("worker /completion returned HTTP {$status}: " . substr((string) $tail, 0, 200));
    }

    $requestIdCrc = TokenFrame::requestIdCrc32($requestId);
    $sequence = 0;
    $fullText = '';
    $tokensIn = 0;
    $tokensOut = 0;
    $ttftMs = 0;
    $durationMs = 0;
    $stopType = '';
    $stopWord = '';
    $truncated = false;

    try {
        // Chunked transfer decode loop.
        while (!feof($sock)) {
            $sizeLine = fgets($sock);
            if ($sizeLine === false) {
                break;
            }
            $hex = trim($sizeLine);
            if ($hex === '') {
                continue;
            }
            $chunkSize = hexdec($hex);
            if (!is_int($chunkSize) && !is_float($chunkSize)) {
                throw new RuntimeException('invalid chunk size from worker stream: ' . $hex);
            }
            $chunkSize = (int) $chunkSize;
            if ($chunkSize === 0) {
                // Terminator chunk; drain trailing CRLF and stop.
                fgets($sock);
                break;
            }
            $chunkBody = '';
            $read = 0;
            while ($read < $chunkSize && !feof($sock)) {
                $slice = fread($sock, $chunkSize - $read);
                if (!is_string($slice) || $slice === '') {
                    break;
                }
                $chunkBody .= $slice;
                $read += strlen($slice);
            }
            // Drain the trailing CRLF after the chunk body.
            fread($sock, 2);

            // Each SSE event is one or more "data: ..." lines separated by \n.
            foreach (preg_split('/\r?\n/', $chunkBody) ?: [] as $line) {
                if (!str_starts_with($line, 'data:')) {
                    continue;
                }
                $json = ltrim(substr($line, 5));
                if ($json === '') {
                    continue;
                }
                $decoded = json_decode($json, true);
                if (!is_array($decoded)) {
                    continue;
                }

                $content = (string) ($decoded['content'] ?? '');
                $stopFlag = (bool) ($decoded['stop'] ?? false);
                $tokenArray = $decoded['tokens'] ?? [];
                $chunkTokenCount = is_array($tokenArray) ? count($tokenArray) : 0;

                if ($content !== '') {
                    $sequence++;
                    $fullText .= $content;
                    $frame = TokenFrame::encodeDelta(
                        $sequence,
                        $requestIdCrc,
                        max(1, $chunkTokenCount),
                        $content
                    );
                    $sendBinaryFrame($frame);
                }

                if ($stopFlag) {
                    $timings = is_array($decoded['timings'] ?? null) ? $decoded['timings'] : [];
                    $tokensIn = (int) ($decoded['tokens_evaluated'] ?? ($timings['prompt_n'] ?? 0));
                    $tokensOut = (int) ($decoded['tokens_predicted'] ?? ($timings['predicted_n'] ?? 0));
                    $promptMs = (float) ($timings['prompt_ms'] ?? 0.0);
                    $predictedMs = (float) ($timings['predicted_ms'] ?? 0.0);
                    $ttftMs = (int) round($promptMs);
                    $durationMs = (int) round($promptMs + $predictedMs);
                    $stopType = (string) ($decoded['stop_type'] ?? '');
                    $stopWord = (string) ($decoded['stopping_word'] ?? '');
                    $truncated = (bool) ($decoded['truncated'] ?? false);
                }
            }
        }
    } finally {
        if (is_resource($sock)) {
            fclose($sock);
        }
    }

    $sequence++;
    $endFrame = TokenFrame::encodeEnd(
        $sequence,
        $requestIdCrc,
        [
            'tokens_in' => $tokensIn,
            'tokens_out' => $tokensOut,
            'ttft_ms' => $ttftMs,
            'duration_ms' => $durationMs,
        ]
    );
    $sendBinaryFrame($endFrame);

    return [
        'request_id' => $requestId,
        'request_id_crc32' => $requestIdCrc,
        'total_frames' => $sequence,
        'tokens_in' => $tokensIn,
        'tokens_out' => $tokensOut,
        'ttft_ms' => $ttftMs,
        'duration_ms' => $durationMs,
        'concatenated_text' => $fullText,
        'stop' => [
            'type' => $stopType,
            'word' => $stopWord,
            'truncated' => $truncated,
        ],
    ];
}
