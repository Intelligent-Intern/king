#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Batch smoke run for Voltron:
 * - asks general and coding prompts
 * - captures full runner output for each prompt
 * - writes everything into a timestamped log file
 *
 * Usage:
 *   php -d extension=extension/modules/king.so demo/userland/voltron/smoke_questions.php
 *   php -d extension=extension/modules/king.so demo/userland/voltron/smoke_questions.php --dag --log=demo/userland/voltron/logs/run.log
 *   php -d extension=extension/modules/king.so demo/userland/voltron/smoke_questions.php --backend=remote_peer --peers=peer-a,peer-b
 */

if (!function_exists('king_pipeline_orchestrator_run')) {
    fwrite(STDERR, "ERROR: King extension is required.\n");
    fwrite(STDERR, "Run from repo root:\n");
    fwrite(STDERR, "  php -d extension=extension/modules/king.so demo/userland/voltron/smoke_questions.php\n");
    fwrite(STDERR, "Or from demo/userland/voltron:\n");
    fwrite(STDERR, "  php -d extension=../../../extension/modules/king.so smoke_questions.php\n");
    exit(1);
}

require __DIR__ . '/src/VoltronRunner.php';

/**
 * @param resource $fh
 */
function write_log_line($fh, string $line = ''): void
{
    fwrite($fh, $line . PHP_EOL);
}

$showDag = false;
$model = 'qwen2.5-coder:3b';
$requiredBackend = null;
$preferredPeers = [];
$inferenceModelName = null;
$inferenceQuantization = null;
$inferenceMaxTokens = null;
$inferenceTemperature = null;
$inferenceTopP = null;
$inferenceTopK = null;
$ggufPath = null;
$ggufObjectId = null;
$kernelHiddenDim = null;
$kernelCandidateTokens = null;
$defaultLogDir = is_dir('demo/userland/voltron') ? 'demo/userland/voltron/logs' : 'logs';
$logPath = $defaultLogDir . '/voltron-smoke-' . date('Ymd-His') . '.log';

$usage = static function (): void {
    echo "Usage:\n";
    echo "  smoke_questions.php [--dag] [--model=name] [--backend=local|remote_peer] [--peers=peer-a,peer-b] [--log=path]\n";
    echo "                    [--infer-model=name] [--infer-quant=Q4_K] [--infer-max-tokens=64] [--infer-temperature=0.2] [--infer-top-p=0.95] [--infer-top-k=40]\n";
    echo "                    [--gguf=path/to/model.gguf] [--gguf-object=models/qwen2.5-coder:3b-q4_k.gguf]\n";
    echo "                    [--kernel-hidden-dim=64] [--kernel-candidate-tokens=1024]\n";
};

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        $usage();
        exit(0);
    }
    if ($arg === '--dag') {
        $showDag = true;
        continue;
    }
    if (str_starts_with($arg, '--model=')) {
        $candidate = trim(substr($arg, strlen('--model=')));
        if ($candidate !== '') {
            $model = $candidate;
        }
        continue;
    }
    if (str_starts_with($arg, '--backend=')) {
        $candidate = trim(substr($arg, strlen('--backend=')));
        if (!in_array($candidate, ['local', 'remote_peer'], true)) {
            fwrite(STDERR, "ERROR: --backend must be local or remote_peer.\n");
            exit(1);
        }
        $requiredBackend = $candidate;
        continue;
    }
    if (str_starts_with($arg, '--peers=')) {
        $peersArg = substr($arg, strlen('--peers='));
        $preferredPeers = array_values(array_filter(
            array_map(static fn(string $peer): string => trim($peer), explode(',', $peersArg)),
            static fn(string $peer): bool => $peer !== ''
        ));
        continue;
    }
    if (str_starts_with($arg, '--log=')) {
        $candidate = trim(substr($arg, strlen('--log=')));
        if ($candidate !== '') {
            $logPath = $candidate;
        }
        continue;
    }
    if (str_starts_with($arg, '--infer-model=')) {
        $candidate = trim(substr($arg, strlen('--infer-model=')));
        if ($candidate !== '') {
            $inferenceModelName = $candidate;
        }
        continue;
    }
    if (str_starts_with($arg, '--infer-quant=')) {
        $candidate = trim(substr($arg, strlen('--infer-quant=')));
        if ($candidate !== '') {
            $inferenceQuantization = $candidate;
        }
        continue;
    }
    if (str_starts_with($arg, '--infer-max-tokens=')) {
        $candidate = trim(substr($arg, strlen('--infer-max-tokens=')));
        if (!ctype_digit($candidate) || (int) $candidate <= 0) {
            fwrite(STDERR, "ERROR: --infer-max-tokens must be a positive integer.\n");
            exit(1);
        }
        $inferenceMaxTokens = (int) $candidate;
        continue;
    }
    if (str_starts_with($arg, '--infer-temperature=')) {
        $candidate = trim(substr($arg, strlen('--infer-temperature=')));
        if (!is_numeric($candidate)) {
            fwrite(STDERR, "ERROR: --infer-temperature must be numeric.\n");
            exit(1);
        }
        $inferenceTemperature = (float) $candidate;
        continue;
    }
    if (str_starts_with($arg, '--infer-top-p=')) {
        $candidate = trim(substr($arg, strlen('--infer-top-p=')));
        if (!is_numeric($candidate)) {
            fwrite(STDERR, "ERROR: --infer-top-p must be numeric.\n");
            exit(1);
        }
        $inferenceTopP = (float) $candidate;
        continue;
    }
    if (str_starts_with($arg, '--infer-top-k=')) {
        $candidate = trim(substr($arg, strlen('--infer-top-k=')));
        if (!ctype_digit($candidate)) {
            fwrite(STDERR, "ERROR: --infer-top-k must be an integer >= 0.\n");
            exit(1);
        }
        $inferenceTopK = (int) $candidate;
        continue;
    }
    if (str_starts_with($arg, '--gguf=')) {
        $candidate = trim(substr($arg, strlen('--gguf=')));
        if ($candidate !== '') {
            $ggufPath = $candidate;
        }
        continue;
    }
    if (str_starts_with($arg, '--gguf-object=')) {
        $candidate = trim(substr($arg, strlen('--gguf-object=')));
        if ($candidate !== '') {
            $ggufObjectId = $candidate;
        }
        continue;
    }
    if (str_starts_with($arg, '--kernel-hidden-dim=')) {
        $candidate = trim(substr($arg, strlen('--kernel-hidden-dim=')));
        if (!ctype_digit($candidate) || (int) $candidate <= 0) {
            fwrite(STDERR, "ERROR: --kernel-hidden-dim must be a positive integer.\n");
            exit(1);
        }
        $kernelHiddenDim = (int) $candidate;
        continue;
    }
    if (str_starts_with($arg, '--kernel-candidate-tokens=')) {
        $candidate = trim(substr($arg, strlen('--kernel-candidate-tokens=')));
        if (!ctype_digit($candidate) || (int) $candidate <= 0) {
            fwrite(STDERR, "ERROR: --kernel-candidate-tokens must be a positive integer.\n");
            exit(1);
        }
        $kernelCandidateTokens = (int) $candidate;
        continue;
    }

    fwrite(STDERR, "ERROR: Unknown option {$arg}\n");
    $usage();
    exit(1);
}

$logDir = dirname($logPath);
if ($logDir !== '' && $logDir !== '.' && !is_dir($logDir)) {
    if (!mkdir($logDir, 0777, true) && !is_dir($logDir)) {
        fwrite(STDERR, "ERROR: Failed to create log directory: {$logDir}\n");
        exit(1);
    }
}

$fh = fopen($logPath, 'ab');
if (!is_resource($fh)) {
    fwrite(STDERR, "ERROR: Failed to open log file: {$logPath}\n");
    exit(1);
}

$questions = [
    ['kind' => 'general', 'prompt' => 'Explain AI'],
    ['kind' => 'general', 'prompt' => 'What is the capital of Japan and one famous food there?'],
    ['kind' => 'general', 'prompt' => 'Give me 3 practical time-management tips.'],
    ['kind' => 'coding', 'prompt' => 'Write a Python function that checks whether a string is a palindrome.'],
    ['kind' => 'coding', 'prompt' => 'Explain Big O of binary search and show a short JavaScript example.'],
    ['kind' => 'coding', 'prompt' => 'Write a SQL query to find the top 5 customers by total spend.'],
];

$startedAt = date('c');
$passCount = 0;
$failCount = 0;

write_log_line($fh, "=== Voltron Smoke Questions ===");
write_log_line($fh, "started_at={$startedAt}");
write_log_line($fh, "model={$model}");
write_log_line($fh, "dag=" . ($showDag ? 'true' : 'false'));
write_log_line($fh, "required_backend=" . ($requiredBackend ?? 'none'));
write_log_line($fh, "preferred_peers=" . ($preferredPeers === [] ? '-' : implode(',', $preferredPeers)));
write_log_line($fh, "inference_model_name=" . ($inferenceModelName ?? (getenv('VOLTRON_INFERENCE_MODEL_NAME') ?: $model)));
write_log_line($fh, "inference_quantization=" . ($inferenceQuantization ?? (getenv('VOLTRON_INFERENCE_QUANTIZATION') ?: 'Q4_K')));
write_log_line($fh, "gguf_path=" . ($ggufPath ?? (getenv('VOLTRON_GGUF_PATH') ?: '-')));
write_log_line($fh, "gguf_object_id=" . ($ggufObjectId ?? (getenv('VOLTRON_GGUF_OBJECT_ID') ?: '-')));
write_log_line($fh, '');

echo "Running " . count($questions) . " prompts through Voltron...\n";
echo "Log file: {$logPath}\n";

foreach ($questions as $index => $question) {
    $ordinal = $index + 1;
    $kind = $question['kind'];
    $prompt = $question['prompt'];
    $traceId = 'voltron-smoke-' . $ordinal;

    echo sprintf("[%d/%d] %s: %s\n", $ordinal, count($questions), strtoupper($kind), $prompt);

    $capturedOut = '';
    $status = 'PASS';
    $errorText = '';
    $response = '';
    $provenanceText = '';

    ob_start();
    try {
        $runner = new King\Voltron\VoltronRunner(
            $model,
            $showDag,
            [
                'require_backend' => $requiredBackend,
                'trace_id' => $traceId,
                'peers' => $preferredPeers,
                'inference_model_name' => $inferenceModelName,
                'inference_quantization' => $inferenceQuantization,
                'inference_max_tokens' => $inferenceMaxTokens,
                'inference_temperature' => $inferenceTemperature,
                'inference_top_p' => $inferenceTopP,
                'inference_top_k' => $inferenceTopK,
                'gguf_path' => $ggufPath,
                'gguf_object_id' => $ggufObjectId,
                'kernel_hidden_dim' => $kernelHiddenDim,
                'kernel_candidate_tokens' => $kernelCandidateTokens,
            ]
        );
        $result = $runner->run($prompt);
        $dagResult = is_array($result['dag_result'] ?? null) ? $result['dag_result'] : [];
        $response = is_string($dagResult['response'] ?? null) ? $dagResult['response'] : '';
        $prov = is_array($dagResult['response_provenance'] ?? null) ? $dagResult['response_provenance'] : [];
        $provenanceText = sprintf(
            'source=%s tool=%s backend=%s step=%s',
            is_string($prov['source'] ?? null) ? $prov['source'] : 'unknown',
            is_string($prov['tool'] ?? null) ? $prov['tool'] : 'unknown',
            is_string($prov['backend'] ?? null) ? $prov['backend'] : 'unknown',
            is_string($prov['step_id'] ?? null) ? $prov['step_id'] : 'unknown'
        );
        $passCount++;
    } catch (Throwable $e) {
        $status = 'FAIL';
        $errorText = $e->getMessage();
        $failCount++;
    } finally {
        $capturedOut = (string) ob_get_clean();
    }

    write_log_line($fh, str_repeat('-', 80));
    write_log_line($fh, sprintf('question_index=%d kind=%s status=%s', $ordinal, $kind, $status));
    write_log_line($fh, 'prompt=' . $prompt);
    if ($status === 'PASS') {
        write_log_line($fh, 'provenance=' . $provenanceText);
        write_log_line($fh, 'response=' . $response);
    } else {
        write_log_line($fh, 'error=' . $errorText);
    }
    write_log_line($fh, '');
    write_log_line($fh, '[captured_runner_output_begin]');
    fwrite($fh, $capturedOut);
    if (!str_ends_with($capturedOut, PHP_EOL)) {
        write_log_line($fh, '');
    }
    write_log_line($fh, '[captured_runner_output_end]');
    write_log_line($fh, '');

    if ($status === 'PASS') {
        echo "  PASS\n";
    } else {
        echo "  FAIL: {$errorText}\n";
    }
}

$finishedAt = date('c');
write_log_line($fh, str_repeat('=', 80));
write_log_line($fh, "finished_at={$finishedAt}");
write_log_line($fh, "summary_pass={$passCount}");
write_log_line($fh, "summary_fail={$failCount}");
fclose($fh);

echo "Completed. pass={$passCount} fail={$failCount}\n";
echo "Log written to {$logPath}\n";

exit($failCount > 0 ? 1 : 0);
