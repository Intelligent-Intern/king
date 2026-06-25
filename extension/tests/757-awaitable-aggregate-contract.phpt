--TEST--
King Awaitable aggregate APIs expose keyed any/all envelopes
--FILE--
<?php
function aggregate_handler(array $context): array
{
    $input = $context['input'] ?? [];
    if (!is_array($input)) {
        throw new RuntimeException('unexpected input payload');
    }

    $input['history'][] = 'aggregate-handler';
    return ['output' => $input];
}

foreach ([
    'king_awaitable_any',
    'king_awaitable_all',
] as $function) {
    var_dump(function_exists($function));
}

foreach ([
    [King\Awaitable::class, 'any'],
    [King\Awaitable::class, 'all'],
] as [$class, $method]) {
    $methodReflection = new ReflectionMethod($class, $method);
    var_dump($methodReflection->isPublic());
    var_dump($methodReflection->isStatic());
}

var_dump(king_pipeline_orchestrator_register_tool('aggregate-tool', [
    'model' => 'gpt-sim',
]));
var_dump(king_pipeline_orchestrator_register_handler('aggregate-tool', 'aggregate_handler'));

$first = king_pipeline_orchestrator_run_async(
    ['history' => ['first']],
    [['tool' => 'aggregate-tool']]
);
$second = king_pipeline_orchestrator_run_async(
    ['history' => ['second']],
    [['tool' => 'aggregate-tool']]
);
$any = king_awaitable_any([
    'first' => $first,
    'second' => $second,
]);
var_dump($any instanceof King\Awaitable);
var_dump($any->poll(0));
$ready = king_await($any);
var_dump($ready['key']);
var_dump($ready['status']);
var_dump($ready['operation']);
var_dump($ready['value']['history']);

$left = King\PipelineOrchestrator::runAsync(
    ['history' => ['left']],
    [['tool' => 'aggregate-tool']]
);
$right = King\PipelineOrchestrator::runAsync(
    ['history' => ['right']],
    [['tool' => 'aggregate-tool']]
);
$all = King\Awaitable::all([
    'left' => $left,
    'right' => $right,
]);
var_dump($all instanceof King\Awaitable);
var_dump($all->poll(0));
$allReady = $all->await();
var_dump(array_keys($allReady));
var_dump($allReady['left']['status']);
var_dump($allReady['left']['operation']);
var_dump($allReady['left']['value']['history']);
var_dump($allReady['right']['status']);
var_dump($allReady['right']['value']['history']);

try {
    king_awaitable_any([]);
    echo "no-empty-exception\n";
} catch (ValueError $e) {
    var_dump(str_contains($e->getMessage(), 'at least one'));
}

try {
    King\Awaitable::all([new stdClass()]);
    echo "no-type-exception\n";
} catch (TypeError $e) {
    var_dump(str_contains($e->getMessage(), 'King\\Awaitable'));
}
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
string(5) "first"
string(8) "resolved"
string(36) "king_pipeline_orchestrator_run_async"
array(2) {
  [0]=>
  string(5) "first"
  [1]=>
  string(17) "aggregate-handler"
}
bool(true)
bool(true)
array(2) {
  [0]=>
  string(4) "left"
  [1]=>
  string(5) "right"
}
string(8) "resolved"
string(36) "king_pipeline_orchestrator_run_async"
array(2) {
  [0]=>
  string(4) "left"
  [1]=>
  string(17) "aggregate-handler"
}
string(8) "resolved"
array(2) {
  [0]=>
  string(5) "right"
  [1]=>
  string(17) "aggregate-handler"
}
bool(true)
bool(true)
