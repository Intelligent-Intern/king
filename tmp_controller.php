<?php
require_once '/Users/sasha/king/demo/userland/flow-php/src/ExecutionBackend.php';
use King\Flow\OrchestratorExecutionBackend;
$backend = new OrchestratorExecutionBackend();
$backend->registerTool('prepare', ['label' => 'prepare-config', 'max_tokens' => 64]);
$backend->registerTool('finalize', ['label' => 'finalize-config', 'max_tokens' => 64]);
$backend->registerHandler('prepare', function (array $context) {
    $input = $context['input'] ?? [];
    $input['history'][] = 'controller-prepare';
    return ['output' => $input];
});
$backend->registerHandler('finalize', function (array $context) {
    $input = $context['input'] ?? [];
    $input['history'][] = 'controller-finalize';
    return ['output' => $input];
});
$backend->start(['text' => 'flow-remote-execution', 'history' => []],
    [
        ['tool' => 'prepare'],
        ['tool' => 'finalize', 'delay_ms' => 5000],
    ],
    ['trace_id' => 'flow-execution-remote-610']
);
?>