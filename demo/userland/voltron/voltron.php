#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Voltron CLI - partitioned model DAG execution through King orchestrator.
 *
 * Usage (from repo root):
 *   php -d extension=extension/modules/king.so demo/userland/voltron/voltron.php "your question"
 *   php -d extension=extension/modules/king.so demo/userland/voltron/voltron.php "your question" qwen2.5-coder:3b --dag
 *   php -d extension=extension/modules/king.so -d king.orchestrator_execution_backend=remote_peer \
 *       -d king.orchestrator_remote_host=127.0.0.1 -d king.orchestrator_remote_port=9444 \
 *       demo/userland/voltron/voltron.php "your question" --backend=remote_peer --dag
 *
 * Usage (from demo/userland/voltron):
 *   php -d extension=../../../extension/modules/king.so voltron.php "your question"
 */

$repoRoot = realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
$extFromRepoRoot = 'extension/modules/king.so';
$extPath = $repoRoot . DIRECTORY_SEPARATOR . $extFromRepoRoot;
$extPathFromHere = '../../../extension/modules/king.so';

if (!function_exists('king_pipeline_orchestrator_run')) {
    if (!file_exists($extPath)) {
        echo "ERROR: King extension not found.\n\n";
        echo "Build it first:\n";
        echo "  cd extension && ../infra/scripts/build-profile.sh release\n";
        echo "Then run:\n";
        echo "  # From repo root:\n";
        echo "  php -d extension={$extFromRepoRoot} demo/userland/voltron/voltron.php \"question\"\n";
        echo "  # From demo/userland/voltron:\n";
        echo "  php -d extension={$extPathFromHere} voltron.php \"question\"\n\n";
    } else {
        echo "ERROR: King extension not available.\n";
        echo "Use one of:\n";
        echo "  php -d extension={$extFromRepoRoot} demo/userland/voltron/voltron.php \"question\"\n";
        echo "  php -d extension={$extPathFromHere} voltron.php \"question\"\n";
    }
    exit(1);
}

$prompt = $argv[1] ?? 'What is 2+2?';
$model = 'qwen2.5-coder:3b';
$showDag = false;
$requiredBackend = null;
$traceId = 'voltron';
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

$usage = static function (): void {
    echo "Usage:\n";
    echo "  voltron.php \"prompt\" [model] [--dag] [--backend=local|remote_peer] [--trace-id=id] [--peers=peer-a,peer-b]\n";
    echo "             [--infer-model=name] [--infer-quant=Q4_K] [--infer-max-tokens=64] [--infer-temperature=0.2] [--infer-top-p=0.95] [--infer-top-k=40]\n";
    echo "             [--gguf=path/to/model.gguf] [--gguf-object=models/qwen2.5-coder:3b-q4_k.gguf]\n";
    echo "             [--kernel-hidden-dim=64] [--kernel-candidate-tokens=1024]\n";
};

$modelSet = false;
foreach (array_slice($argv, 2) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        $usage();
        exit(0);
    }

    if ($arg === '--dag') {
        $showDag = true;
        continue;
    }

    if (str_starts_with($arg, '--backend=')) {
        $backend = substr($arg, strlen('--backend='));
        if (!in_array($backend, ['local', 'remote_peer'], true)) {
            fwrite(STDERR, "ERROR: --backend must be local or remote_peer.\n");
            exit(1);
        }
        $requiredBackend = $backend;
        continue;
    }

    if (str_starts_with($arg, '--trace-id=')) {
        $trace = substr($arg, strlen('--trace-id='));
        if ($trace !== '') {
            $traceId = $trace;
        }
        continue;
    }

    if (str_starts_with($arg, '--peers=')) {
        $peersArg = substr($arg, strlen('--peers='));
        $preferredPeers = array_values(array_filter(
            array_map(static fn($p) => trim($p), explode(',', $peersArg)),
            static fn(string $p): bool => $p !== ''
        ));
        continue;
    }

    if (str_starts_with($arg, '--infer-model=')) {
        $value = trim(substr($arg, strlen('--infer-model=')));
        if ($value !== '') {
            $inferenceModelName = $value;
        }
        continue;
    }

    if (str_starts_with($arg, '--infer-quant=')) {
        $value = trim(substr($arg, strlen('--infer-quant=')));
        if ($value !== '') {
            $inferenceQuantization = $value;
        }
        continue;
    }

    if (str_starts_with($arg, '--infer-max-tokens=')) {
        $value = trim(substr($arg, strlen('--infer-max-tokens=')));
        if (!ctype_digit($value) || (int) $value <= 0) {
            fwrite(STDERR, "ERROR: --infer-max-tokens must be a positive integer.\n");
            exit(1);
        }
        $inferenceMaxTokens = (int) $value;
        continue;
    }

    if (str_starts_with($arg, '--infer-temperature=')) {
        $value = trim(substr($arg, strlen('--infer-temperature=')));
        if (!is_numeric($value)) {
            fwrite(STDERR, "ERROR: --infer-temperature must be numeric.\n");
            exit(1);
        }
        $inferenceTemperature = (float) $value;
        continue;
    }

    if (str_starts_with($arg, '--infer-top-p=')) {
        $value = trim(substr($arg, strlen('--infer-top-p=')));
        if (!is_numeric($value)) {
            fwrite(STDERR, "ERROR: --infer-top-p must be numeric.\n");
            exit(1);
        }
        $inferenceTopP = (float) $value;
        continue;
    }

    if (str_starts_with($arg, '--infer-top-k=')) {
        $value = trim(substr($arg, strlen('--infer-top-k=')));
        if (!ctype_digit($value)) {
            fwrite(STDERR, "ERROR: --infer-top-k must be an integer >= 0.\n");
            exit(1);
        }
        $inferenceTopK = (int) $value;
        continue;
    }

    if (str_starts_with($arg, '--gguf=')) {
        $value = trim(substr($arg, strlen('--gguf=')));
        if ($value !== '') {
            $ggufPath = $value;
        }
        continue;
    }

    if (str_starts_with($arg, '--gguf-object=')) {
        $value = trim(substr($arg, strlen('--gguf-object=')));
        if ($value !== '') {
            $ggufObjectId = $value;
        }
        continue;
    }

    if (str_starts_with($arg, '--kernel-hidden-dim=')) {
        $value = trim(substr($arg, strlen('--kernel-hidden-dim=')));
        if (!ctype_digit($value) || (int) $value <= 0) {
            fwrite(STDERR, "ERROR: --kernel-hidden-dim must be a positive integer.\n");
            exit(1);
        }
        $kernelHiddenDim = (int) $value;
        continue;
    }

    if (str_starts_with($arg, '--kernel-candidate-tokens=')) {
        $value = trim(substr($arg, strlen('--kernel-candidate-tokens=')));
        if (!ctype_digit($value) || (int) $value <= 0) {
            fwrite(STDERR, "ERROR: --kernel-candidate-tokens must be a positive integer.\n");
            exit(1);
        }
        $kernelCandidateTokens = (int) $value;
        continue;
    }

    if (str_starts_with($arg, '--')) {
        fwrite(STDERR, "ERROR: Unknown option {$arg}\n");
        $usage();
        exit(1);
    }

    if (!$modelSet) {
        $model = $arg;
        $modelSet = true;
    }
}

require __DIR__ . '/src/VoltronRunner.php';

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
$runner->run($prompt);
