<?php
declare(strict_types=1);

require_once __DIR__ . '/demo/userland/voltron/src/VoltronHandlers.php';
King\Voltron\voltron_register_handlers();

$params = [
    'block_type' => 'embed',
    'layers' => [0, 35],
    'inference_model_name' => 'qwen2.5-coder:3b',
    'inference_quantization' => 'Q4_K',
    'inference_max_tokens' => 64,
    'inference_temperature' => 0.2,
    'inference_top_p' => 0.95,
    'inference_top_k' => 40,
];

$steps = [['id' => 't.embed', 'tool' => 'voltron.execute_model_block', 'params' => $params]];

$state = null;

// Iteration 0
$result0 = king_pipeline_orchestrator_run(
    ['run_id' => 'trace-iter0', 'prompt' => 'hello world', 'voltron_state' => $state, 'decode_iteration' => 0],
    $steps,
    ['trace_id' => 'trace']
);

echo "=== Iter 0 ===\n";
if (is_array($result0)) {
    foreach ($result0 as $k => $v) {
        if (is_array($v)) {
            echo "  $k: [array]\n";
        } elseif (is_string($v)) {
            echo "  $k: " . bin2hex($v) . " (\"$v\")\n";
        } else {
            echo "  $k: " . var_export($v, true) . "\n";
        }
    }
}
$state = is_array($result0['voltron_state'] ?? null) ? $result0['voltron_state'] : null;
if ($state) {
    echo "State: position=" . ($state['position'] ?? '?') . " last_token=" . ($state['last_token_id'] ?? '?') . " gen=" . bin2hex($state['generated_text'] ?? '') . "\n";
}

// Iteration 1
$result1 = king_pipeline_orchestrator_run(
    ['run_id' => 'trace-iter1', 'prompt' => 'hello world', 'voltron_state' => $state, 'decode_iteration' => 1],
    $steps,
    ['trace_id' => 'trace']
);

echo "\n=== Iter 1 ===\n";
if (is_array($result1)) {
    foreach ($result1 as $k => $v) {
        if (is_array($v)) {
            echo "  $k: [array]\n";
        } elseif (is_string($v)) {
            echo "  $k: " . bin2hex($v) . " (\"$v\")\n";
        } else {
            echo "  $k: " . var_export($v, true) . "\n";
        }
    }
}
$state = is_array($result1['voltron_state'] ?? null) ? $result1['voltron_state'] : null;
if ($state) {
    echo "State: position=" . ($state['position'] ?? '?') . " last_token=" . ($state['last_token_id'] ?? '?') . " gen=" . bin2hex($state['generated_text'] ?? '') . "\n";
}