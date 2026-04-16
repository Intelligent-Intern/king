<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/llama_cpp_worker.php';

function model_inference_llama_worker_contract_assert(bool $condition, string $message, ?LlamaCppWorker $worker = null): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[llama-cpp-worker-contract] FAIL: {$message}\n");
    if ($worker !== null) {
        fwrite(STDERR, "---- last log tail ----\n" . $worker->logTail(20) . "\n");
    }
    exit(1);
}

function model_inference_llama_worker_contract_pick_port(): int
{
    // Ephemeral loopback bind-and-close to let the kernel hand us a free port.
    $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!is_resource($socket)) {
        throw new RuntimeException("failed to allocate ephemeral port: {$errstr}");
    }
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    if ($name === false) {
        throw new RuntimeException('failed to read ephemeral port');
    }
    $pos = strrpos($name, ':');
    return (int) substr($name, $pos + 1);
}

try {
    $llamaHome = (string) (getenv('LLAMA_CPP_HOME') ?: '/opt/llama-cpp/llama-b8802');
    $ggufPath = (string) (getenv('MODEL_INFERENCE_GGUF_FIXTURE_PATH') ?: (__DIR__ . '/../.local/fixtures/SmolLM2-135M-Instruct-Q4_K_S.gguf'));
    $binaryPath = $llamaHome . '/llama-server';

    model_inference_llama_worker_contract_assert(is_executable($binaryPath), "llama-server binary not executable at {$binaryPath}");
    model_inference_llama_worker_contract_assert(is_file($ggufPath), "GGUF fixture missing at {$ggufPath}");

    $worker = new LlamaCppWorker($binaryPath, $llamaHome);

    // 1. Fresh worker reports state=stopped with no pid and port=0.
    model_inference_llama_worker_contract_assert($worker->state() === LlamaCppWorker::STATE_STOPPED, 'fresh worker must be stopped');
    model_inference_llama_worker_contract_assert($worker->pid() === null, 'fresh worker pid must be null');
    model_inference_llama_worker_contract_assert($worker->port() === 0, 'fresh worker port must be 0');

    // 2. start() succeeds with a real GGUF; state transitions to starting.
    $port = model_inference_llama_worker_contract_pick_port();
    $logPath = sys_get_temp_dir() . '/llama-worker-contract-' . getmypid() . '.log';
    @unlink($logPath);
    $worker->start($ggufPath, $port, [
        'context_tokens' => 512,
        'max_new_tokens' => 16,
        'log_path' => $logPath,
    ]);
    model_inference_llama_worker_contract_assert($worker->state() === LlamaCppWorker::STATE_STARTING, 'post-start state must be STARTING (got ' . $worker->state() . ')');
    model_inference_llama_worker_contract_assert(is_int($worker->pid()) && $worker->pid() > 0, 'post-start pid must be a positive int', $worker);
    model_inference_llama_worker_contract_assert($worker->port() === $port, 'port passthrough mismatch');
    model_inference_llama_worker_contract_assert(is_string($worker->startedAt()) && preg_match('/^\d{4}-\d{2}-\d{2}T/', $worker->startedAt()) === 1, 'startedAt must be rfc3339');

    // 3. Wait for READY within 30s; assert /health=200 with status=ok.
    $worker->waitForReady(30_000, 250);
    $ready = $worker->health(1_000);
    model_inference_llama_worker_contract_assert($ready['state'] === LlamaCppWorker::STATE_READY, '/health=200 must transition state to READY', $worker);
    model_inference_llama_worker_contract_assert($ready['http_status'] === 200, '/health http_status must be 200', $worker);
    $body = json_decode($ready['body'], true);
    model_inference_llama_worker_contract_assert(is_array($body) && ($body['status'] ?? null) === 'ok', '/health body must be {"status":"ok"}', $worker);

    // 4. diagnostics() exposes every field documented by the class.
    $diag = $worker->diagnostics();
    foreach (['state', 'pid', 'port', 'gguf_path', 'binary_path', 'library_path', 'log_path', 'started_at', 'health'] as $field) {
        model_inference_llama_worker_contract_assert(array_key_exists($field, $diag), "diagnostics() missing field {$field}", $worker);
    }
    model_inference_llama_worker_contract_assert($diag['state'] === LlamaCppWorker::STATE_READY, 'diagnostics.state must be READY after health probe', $worker);
    model_inference_llama_worker_contract_assert($diag['binary_path'] === $binaryPath, 'diagnostics.binary_path passthrough');
    model_inference_llama_worker_contract_assert($diag['gguf_path'] === $ggufPath, 'diagnostics.gguf_path passthrough');
    model_inference_llama_worker_contract_assert((int) $diag['health']['http_status'] === 200, 'diagnostics.health.http_status must be 200', $worker);

    // 5. drain() ends with state=stopped, pid cleared, port reset, process reaped.
    $preDrainPid = $worker->pid();
    $worker->drain(5_000);
    model_inference_llama_worker_contract_assert($worker->state() === LlamaCppWorker::STATE_STOPPED, 'post-drain state must be STOPPED (got ' . $worker->state() . ')', $worker);
    model_inference_llama_worker_contract_assert($worker->pid() === null, 'post-drain pid must be null');
    model_inference_llama_worker_contract_assert($worker->port() === 0, 'post-drain port must be 0');
    // The process must actually be gone. posix_kill with sig=0 succeeds iff
    // the pid is still live; a zero return code here would prove the child
    // was not reaped. We accept either ESRCH or EPERM (pid recycled).
    if (function_exists('posix_kill') && is_int($preDrainPid)) {
        $alive = @posix_kill($preDrainPid, 0);
        model_inference_llama_worker_contract_assert($alive === false, "pid {$preDrainPid} must be gone after drain()");
    }

    // 6. start() after drain must succeed on a fresh port; double-start guards fire.
    $port2 = model_inference_llama_worker_contract_pick_port();
    $worker->start($ggufPath, $port2, ['context_tokens' => 256, 'max_new_tokens' => 8, 'log_path' => $logPath]);
    $caught = false;
    try {
        $worker->start($ggufPath, $port2, ['log_path' => $logPath]);
    } catch (RuntimeException $dup) {
        $caught = true;
        model_inference_llama_worker_contract_assert(str_contains($dup->getMessage(), 'cannot start worker'), 'double-start error must name the guard');
    }
    model_inference_llama_worker_contract_assert($caught, 'double-start must throw RuntimeException');
    $worker->stop();
    model_inference_llama_worker_contract_assert($worker->state() === LlamaCppWorker::STATE_STOPPED, 'post-stop state must be STOPPED');

    // 7. Invalid GGUF path is rejected before proc_open.
    $caught = false;
    try {
        $worker->start('/does/not/exist.gguf', model_inference_llama_worker_contract_pick_port(), ['log_path' => $logPath]);
    } catch (RuntimeException $missing) {
        $caught = true;
        model_inference_llama_worker_contract_assert(str_contains($missing->getMessage(), 'GGUF artifact not found'), 'missing GGUF error must be specific');
    }
    model_inference_llama_worker_contract_assert($caught, 'missing GGUF must throw');
    model_inference_llama_worker_contract_assert($worker->state() === LlamaCppWorker::STATE_STOPPED, 'failed start must not leak state');

    // 8. Constructor refuses a missing binary.
    $caught = false;
    try {
        new LlamaCppWorker('/does/not/exist/llama-server', $llamaHome);
    } catch (RuntimeException $bin) {
        $caught = true;
    }
    model_inference_llama_worker_contract_assert($caught, 'constructor must refuse missing binary');

    @unlink($logPath);
    fwrite(STDOUT, "[llama-cpp-worker-contract] PASS (real llama.cpp spawn + /health ok + drain + reap)\n");
    exit(0);
} catch (Throwable $error) {
    if (isset($worker) && $worker instanceof LlamaCppWorker) {
        fwrite(STDERR, "---- last log tail ----\n" . $worker->logTail(40) . "\n");
        $worker->stop();
    }
    fwrite(STDERR, '[llama-cpp-worker-contract] ERROR: ' . $error->getMessage() . ' @ ' . $error->getFile() . ':' . $error->getLine() . "\n");
    exit(1);
}
