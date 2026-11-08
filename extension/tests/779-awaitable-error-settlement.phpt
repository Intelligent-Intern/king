--TEST--
King Awaitable settles deferred exceptions once and preserves Throwable identity across collection
--FILE--
<?php
function check($ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo $label, "\n";
}
$GLOBALS['calls'] = 0;
$GLOBALS['expected'] = new DomainException('deferred operation failed');
function deferred_failure(array $context): array { ++$GLOBALS['calls']; throw $GLOBALS['expected']; }
function deferred_success(array $context): array { return ['output'=>['ok'=>true]]; }
king_pipeline_orchestrator_register_tool('failure-once', ['model'=>'test']);
king_pipeline_orchestrator_register_handler('failure-once', 'deferred_failure');
king_pipeline_orchestrator_register_tool('success', ['model'=>'test']);
king_pipeline_orchestrator_register_handler('success', 'deferred_success');
$bad = king_pipeline_orchestrator_run_async([], [['tool'=>'failure-once']]);
$good = king_pipeline_orchestrator_run_async([], [['tool'=>'success']]);
check($bad->poll(0) && $bad->getStatus()==='rejected', 'deferred exception settles rejected state');
$results = King\Awaitable::all(['bad'=>$bad,'good'=>$good])->await();
check($results['bad']['status']==='rejected' && $results['bad']['error']==='deferred operation failed', 'aggregate retains deferred failure envelope');
check($results['good']['status']==='resolved' && $results['good']['value']['ok'], 'failed deferred child does not lose sibling result');
for ($i=0;$i<2;$i++) {
    try { $bad->await(); throw new RuntimeException('exception lost'); }
    catch (DomainException $e) { check($e===$GLOBALS['expected'], 'await rethrows original exception instance'); }
}
check($GLOBALS['calls']===1, 'repeated await never reruns failed operation');
$first = King\Awaitable::any(['good'=>$good,'bad'=>$bad])->await();
check($first['key']==='bad', 'any includes earliest rejected completion');
?>
--EXPECT--
deferred exception settles rejected state
aggregate retains deferred failure envelope
failed deferred child does not lose sibling result
await rethrows original exception instance
await rethrows original exception instance
repeated await never reruns failed operation
any includes earliest rejected completion
