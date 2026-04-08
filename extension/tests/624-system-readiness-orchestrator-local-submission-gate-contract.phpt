--TEST--
King coordinated runtime gates new local orchestrator submissions while the system is not ready
--FILE--
<?php
function king_system_readiness_orchestrator_handler(array $context): array
{
    $input = $context['input'] ?? [];
    if (!is_array($input)) {
        throw new RuntimeException('unexpected orchestrator input');
    }

    $input['handled_by'] = $context['tool']['name'] ?? null;
    $input['execution_backend'] = $context['run']['execution_backend'] ?? null;
    return ['output' => $input];
}

function king_system_readiness_wait_until_ready_for_orchestrator(int $maxSeconds = 8): void
{
    for ($i = 0; $i < $maxSeconds; $i++) {
        $status = king_system_get_status();
        if (($status['lifecycle'] ?? null) === 'ready') {
            return;
        }

        sleep(1);
    }

    throw new RuntimeException('system did not become ready before orchestrator readiness gate scenario');
}

function king_system_readiness_wait_until_stopped_for_orchestrator(int $maxSeconds = 8): array
{
    for ($i = 0; $i < $maxSeconds; $i++) {
        $status = king_system_get_status();
        if (($status['initialized'] ?? true) === false) {
            return $status;
        }

        sleep(1);
    }

    throw new RuntimeException('system did not stop after orchestrator shutdown request');
}

var_dump(king_system_init(['component_timeout_seconds' => 1]));
king_system_readiness_wait_until_ready_for_orchestrator();
var_dump(king_pipeline_orchestrator_register_tool('summarizer', [
    'model' => 'gpt-sim',
    'max_tokens' => 64,
]));
var_dump(king_pipeline_orchestrator_register_handler(
    'summarizer',
    'king_system_readiness_orchestrator_handler'
));
var_dump(king_system_restart_component('telemetry'));

$status = king_system_get_status();
var_dump($status['lifecycle']);
var_dump($status['admission']['orchestrator_submissions']);

$blockedExceptionClass = '';
$blockedExceptionMessage = '';
try {
    king_pipeline_orchestrator_run(
        ['text' => 'blocked'],
        [['tool' => 'summarizer']]
    );
} catch (Throwable $e) {
    $blockedExceptionClass = get_class($e);
    $blockedExceptionMessage = $e->getMessage();
}

var_dump($blockedExceptionClass);
var_dump(str_contains($blockedExceptionMessage, 'cannot admit orchestrator_submissions'));
var_dump(str_contains($blockedExceptionMessage, "lifecycle is 'draining'"));
var_dump(str_contains(king_get_last_error(), 'cannot admit orchestrator_submissions'));

sleep(1);
$status = king_system_get_status();
var_dump($status['lifecycle']);
var_dump($status['admission']['orchestrator_submissions']);

sleep(1);
$status = king_system_get_status();
var_dump($status['lifecycle']);
var_dump($status['admission']['orchestrator_submissions']);

$result = king_pipeline_orchestrator_run(
    ['text' => 'ready'],
    [['tool' => 'summarizer']]
);
var_dump(($result['text'] ?? null) === 'ready');
var_dump(($result['handled_by'] ?? null) === 'summarizer');
var_dump(($result['execution_backend'] ?? null) === 'local');
var_dump(king_get_last_error());

$info = king_system_get_component_info('pipeline_orchestrator');
$run = king_pipeline_orchestrator_get_run($info['configuration']['last_run_id']);
var_dump($run['status']);
var_dump(($run['result']['handled_by'] ?? null) === 'summarizer');
var_dump(king_system_shutdown());
var_dump(king_system_readiness_wait_until_stopped_for_orchestrator()['initialized']);
?>
--EXPECTF--
bool(true)
bool(true)
bool(true)
bool(true)
string(8) "draining"
bool(false)
string(%d) "King\RuntimeException"
bool(true)
bool(true)
bool(true)
string(8) "starting"
bool(false)
string(5) "ready"
bool(true)
bool(true)
bool(true)
bool(true)
string(0) ""
string(9) "completed"
bool(true)
bool(true)
bool(false)
