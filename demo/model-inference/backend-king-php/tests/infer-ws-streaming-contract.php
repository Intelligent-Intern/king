<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/object_store.php';
require_once __DIR__ . '/../support/token_frame.php';
require_once __DIR__ . '/../domain/registry/model_registry.php';
require_once __DIR__ . '/../domain/inference/inference_session.php';
require_once __DIR__ . '/../domain/inference/inference_stream.php';

function model_inference_ws_stream_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[infer-ws-streaming-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    model_inference_ws_stream_contract_assert(extension_loaded('king'), 'king extension must be loaded');

    $llamaHome = (string) (getenv('LLAMA_CPP_HOME') ?: '/opt/llama-cpp/llama-b8802');
    $ggufFixture = (string) (getenv('MODEL_INFERENCE_GGUF_FIXTURE_PATH') ?: '/workspace/demo/model-inference/backend-king-php/.local/fixtures/SmolLM2-135M-Instruct-Q4_K_S.gguf');
    model_inference_ws_stream_contract_assert(is_executable($llamaHome . '/llama-server'), "llama-server missing at {$llamaHome}/llama-server");
    model_inference_ws_stream_contract_assert(is_file($ggufFixture), "GGUF missing at {$ggufFixture}");

    $tmpRoot = sys_get_temp_dir() . '/king-model-inference-ws-' . bin2hex(random_bytes(6));
    $storageRoot = $tmpRoot . '/object-store';
    $ggufCacheRoot = $tmpRoot . '/gguf-cache';
    @mkdir($tmpRoot, 0775, true);
    @mkdir($storageRoot, 0775, true);
    @mkdir($ggufCacheRoot, 0775, true);

    model_inference_object_store_init($storageRoot, 1024 * 1024 * 1024);
    $pdo = model_inference_open_sqlite_pdo($tmpRoot . '/registry.sqlite');
    model_inference_registry_schema_migrate($pdo);
    $fh = fopen($ggufFixture, 'rb');
    $entry = model_inference_registry_create_from_stream($pdo, [
        'model_name' => 'SmolLM2-135M-Instruct',
        'family' => 'smollm2',
        'quantization' => 'Q4_K',
        'parameter_count' => 135000000,
        'context_length' => 2048,
        'license' => 'apache-2.0',
        'min_ram_bytes' => 268435456,
        'min_vram_bytes' => 0,
        'prefers_gpu' => false,
        'source_url' => null,
    ], $fh);
    fclose($fh);

    $session = new InferenceSession($llamaHome . '/llama-server', $llamaHome, $ggufCacheRoot);
    $worker = $session->workerFor((string) $entry['model_id'], (string) $entry['artifact']['object_store_key'], 1024, 16);

    $validated = [
        'session_id' => 'sess-ws-contract',
        'model_selector' => ['model_name' => 'SmolLM2-135M-Instruct', 'quantization' => 'Q4_K', 'prefer_local' => true],
        'prompt' => 'Write the single word OK and stop.',
        'system' => null,
        'sampling' => ['temperature' => 0.0, 'top_p' => 1.0, 'top_k' => 1, 'max_tokens' => 8, 'seed' => 1],
        'stream' => true,
    ];

    /** @var array<int, string> $capturedFrames */
    $capturedFrames = [];
    $sendBinaryFrame = static function (string $frameBytes) use (&$capturedFrames): void {
        $capturedFrames[] = $frameBytes;
    };

    $requestId = 'req_wscontract01';
    $summary = model_inference_stream_completion($worker, $validated, $requestId, $sendBinaryFrame, 8);

    // 1. At least one delta + one end frame captured.
    model_inference_ws_stream_contract_assert(count($capturedFrames) >= 2, 'expected at least 2 captured frames (delta(s) + end); got ' . count($capturedFrames));

    // 2. Every captured frame decodes cleanly via TokenFrame.
    $decodedHeaders = [];
    $concatenated = '';
    $endSeen = false;
    foreach ($capturedFrames as $idx => $bytes) {
        [$header, $payload] = TokenFrame::decode($bytes);
        $decodedHeaders[] = $header;

        model_inference_ws_stream_contract_assert($header['magic'] === TokenFrame::MAGIC, "frame #{$idx} magic");
        model_inference_ws_stream_contract_assert($header['version'] === TokenFrame::VERSION, "frame #{$idx} version");
        model_inference_ws_stream_contract_assert($header['request_id_crc32'] === TokenFrame::requestIdCrc32($requestId), "frame #{$idx} request_id_crc32 must equal crc32(request_id)");

        if ($header['frame_type'] === TokenFrame::FRAME_TYPE_DELTA) {
            model_inference_ws_stream_contract_assert($payload !== '', "delta frame #{$idx} must carry a non-empty UTF-8 payload");
            $concatenated .= $payload;
        } elseif ($header['frame_type'] === TokenFrame::FRAME_TYPE_END) {
            model_inference_ws_stream_contract_assert(!$endSeen, 'only one end frame expected');
            $endSeen = true;
            $body = json_decode($payload, true);
            model_inference_ws_stream_contract_assert(is_array($body), 'end frame body must be JSON object');
            foreach (['tokens_in', 'tokens_out', 'ttft_ms', 'duration_ms'] as $req) {
                model_inference_ws_stream_contract_assert(array_key_exists($req, $body), "end body missing '{$req}'");
                model_inference_ws_stream_contract_assert(is_int($body[$req]) && $body[$req] >= 0, "end body.{$req} must be int >= 0");
            }
            model_inference_ws_stream_contract_assert((int) $body['tokens_out'] >= 1, 'end body.tokens_out must be >= 1');
        } else {
            model_inference_ws_stream_contract_assert(false, "unexpected frame_type={$header['frame_type']} in stream");
        }
    }

    model_inference_ws_stream_contract_assert($endSeen, 'stream must terminate with a frame_type=end frame');

    // 3. Monotonic sequence enforced by TokenFrame::assertMonotonicSequence.
    TokenFrame::assertMonotonicSequence($decodedHeaders);

    // 4. Concatenated delta payload equals the summary's concatenated_text.
    model_inference_ws_stream_contract_assert($concatenated === $summary['concatenated_text'], 'concatenated delta payloads must equal summary concatenated_text');
    model_inference_ws_stream_contract_assert(strlen($concatenated) > 0, 'concatenated text must be non-empty');

    // 5. end frame is the last frame and carries frame_type=1.
    $lastHeader = $decodedHeaders[count($decodedHeaders) - 1];
    model_inference_ws_stream_contract_assert($lastHeader['frame_type'] === TokenFrame::FRAME_TYPE_END, 'final frame must be frame_type=end');

    // 6. Summary token counts > 0 and timings non-negative.
    model_inference_ws_stream_contract_assert($summary['tokens_in'] >= 1, 'summary.tokens_in must be >= 1');
    model_inference_ws_stream_contract_assert($summary['tokens_out'] >= 1, 'summary.tokens_out must be >= 1');
    model_inference_ws_stream_contract_assert($summary['ttft_ms'] >= 0, 'summary.ttft_ms must be >= 0');
    model_inference_ws_stream_contract_assert($summary['duration_ms'] >= 0, 'summary.duration_ms must be >= 0');
    model_inference_ws_stream_contract_assert($summary['total_frames'] === count($capturedFrames), 'summary.total_frames must equal captured frame count');

    // 7. Deterministic parity with the non-streaming path at seed=1, temp=0:
    //    spin up a fresh worker and call completeNonStreaming with the same
    //    envelope; assert the streamed concatenation matches the non-stream
    //    text. This is the leaf's headline claim: streaming === batching.
    $session->drainAll();
    $worker2 = $session->workerFor((string) $entry['model_id'], (string) $entry['artifact']['object_store_key'], 1024, 16);
    $validatedHttp = $validated;
    $validatedHttp['stream'] = false;
    $nonStream = $session->completeNonStreaming($worker2, $validatedHttp, 8);
    model_inference_ws_stream_contract_assert(
        $nonStream['content'] === $summary['concatenated_text'],
        'streamed concatenation must equal non-streaming completion at seed=1, temp=0 (streamed: ' . json_encode($summary['concatenated_text']) . ' vs batched: ' . json_encode($nonStream['content']) . ')'
    );

    $session->drainAll();

    // Cleanup.
    foreach (glob($storageRoot . '/*') ?: [] as $p) {
        if (is_dir($p)) {
            foreach (glob($p . '/*') ?: [] as $i) {
                @unlink($i);
            }
            @rmdir($p);
        } else {
            @unlink($p);
        }
    }
    foreach (glob($ggufCacheRoot . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($storageRoot);
    @rmdir($ggufCacheRoot);
    @unlink($tmpRoot . '/registry.sqlite');
    @rmdir($tmpRoot);

    fwrite(STDOUT, sprintf(
        "[infer-ws-streaming-contract] PASS (streamed %d frames, tokens_in=%d tokens_out=%d; streamed==batched for seed=1, temp=0)\n",
        (int) $summary['total_frames'],
        (int) $summary['tokens_in'],
        (int) $summary['tokens_out']
    ));
    exit(0);
} catch (Throwable $error) {
    if (isset($session) && $session instanceof InferenceSession) {
        $session->drainAll();
    }
    fwrite(STDERR, '[infer-ws-streaming-contract] ERROR: ' . $error->getMessage() . ' @ ' . $error->getFile() . ':' . $error->getLine() . "\n");
    exit(1);
}
