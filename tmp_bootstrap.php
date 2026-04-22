<?php
function flow_exec_remote_prepare(array $context): array {
    $input = $context['input'] ?? [];
    $input['history'][] = 'remote-prepare';
    return ['output' => $input];
}
function flow_exec_remote_finalize(array $context): array {
    $input = $context['input'] ?? [];
    $input['history'][] = 'remote-finalize';
    return ['output' => $input];
}
return [
    'prepare' => 'flow_exec_remote_prepare',
    'finalize' => 'flow_exec_remote_finalize',
];
?>