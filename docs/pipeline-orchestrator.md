# Pipeline Orchestrator

Der Orchestrator fuehrt Tool-Schritte aus, kann Runs persistieren und kennt
lokale, file-worker und remote-peer Backends. Handler sind process-local und
muessen im ausfuehrenden Prozess registriert werden.

## Function, Beispiel 1: lokaler zweistufiger Lauf

```php
<?php
king_pipeline_orchestrator_register_tool('read-invoice', ['kind' => 'php']);
king_pipeline_orchestrator_register_tool('normalize-vat', ['kind' => 'php']);

king_pipeline_orchestrator_register_handler('read-invoice', static function (array $ctx): array {
    return ['output' => [
        'invoice_id' => $ctx['input']['invoice_id'],
        'net' => 10000,
        'vat_rate' => 19,
    ]];
});

king_pipeline_orchestrator_register_handler('normalize-vat', static function (array $ctx): array {
    $invoice = $ctx['input'];
    $invoice['vat'] = (int) round($invoice['net'] * $invoice['vat_rate'] / 100);

    return ['output' => $invoice];
});

$result = king_pipeline_orchestrator_run(
    ['invoice_id' => 'INV-1001'],
    [['tool' => 'read-invoice'], ['tool' => 'normalize-vat']],
    ['trace_id' => 'invoice-INV-1001']
);

var_dump($result);
```

## Function, Beispiel 2: Queue fuer File Worker

```php
<?php
king_pipeline_orchestrator_register_tool('validate-ubl', ['kind' => 'php']);
king_pipeline_orchestrator_register_handler('validate-ubl', static function (array $ctx): array {
    $input = $ctx['input'];
    $input['validation'] = ['ok' => true, 'profile' => 'peppol-bis-billing-3'];

    return ['output' => $input];
});

$queued = king_pipeline_orchestrator_dispatch(
    ['object_id' => 'tenant-42/invoices/INV-1001.xml'],
    [['tool' => 'validate-ubl']],
    ['trace_id' => 'queued-ubl-validation-INV-1001']
);

$runId = $queued['run_id'];
$workerResult = king_pipeline_orchestrator_worker_run_next();
$snapshot = king_pipeline_orchestrator_get_run($runId);

var_dump($workerResult, $snapshot);
```

## OO, Beispiel 1: statische OO-Fassade

```php
<?php
use King\PipelineOrchestrator;

PipelineOrchestrator::registerTool('prepare', ['kind' => 'php']);
PipelineOrchestrator::registerHandler('prepare', static function (array $ctx): array {
    return ['output' => ['prepared' => true] + $ctx['input']];
});

$result = PipelineOrchestrator::run(
    ['invoice_id' => 'INV-1002'],
    [['tool' => 'prepare']],
    ['trace_id' => 'oo-prepare-INV-1002']
);

var_dump($result);
```

## OO, Beispiel 2: Async Run und Run-Snapshot

```php
<?php
use King\PipelineOrchestrator;

PipelineOrchestrator::registerTool('classify', ['kind' => 'php']);
PipelineOrchestrator::registerHandler('classify', static function (array $ctx): array {
    $input = $ctx['input'];
    $input['class'] = $input['amount'] < 0 ? 'credit-note' : 'invoice';

    return ['output' => $input];
});

$awaitable = PipelineOrchestrator::runAsync(
    ['invoice_id' => 'INV-1003', 'amount' => -2500],
    [['tool' => 'classify']],
    ['trace_id' => 'classify-INV-1003']
);

$result = $awaitable->await(2000);
$component = king_system_get_component_info('pipeline_orchestrator');
$runId = $component['configuration']['last_run_id'] ?? null;

if (is_string($runId)) {
    var_dump(PipelineOrchestrator::getRun($runId));
}

var_dump($result);
```
