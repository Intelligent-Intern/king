--TEST--
King HTTP Awaitables overlap, collect first completion, refill slots, and respect wait budgets
--SKIPIF--
<?php
if (!function_exists('proc_open')) die('skip proc_open required');
exec('python3 --version 2>&1', $output, $code);
if ($code !== 0) die('skip python3 required');
?>
--FILE--
<?php
function verify($value, string $name): void {
    if (!$value) throw new RuntimeException($name);
    echo $name, "\n";
}
$process = proc_open(['python3', '-u', __DIR__.'/async_http_server.py'],
    [['pipe','r'],['pipe','w'],['pipe','w']], $pipes);
try {
    $port = (int) trim(fgets($pipes[1]));
    $url = 'http://127.0.0.1:'.$port;
    $client = new King\Client\Http1Client();
    $start = hrtime(true);
    $slow = $client->requestAsync('GET', $url.'/?ms=750');
    $fast = $client->requestAsync('GET', $url.'/?ms=70');
    $medium = $client->requestAsync('GET', $url.'/?ms=240');
    verify((hrtime(true)-$start)/1e6 < 200, 'submission does not wait for responses');
    $start = hrtime(true);
    verify(!$slow->poll(0), 'zero poll keeps unfinished request pending');
    verify((hrtime(true)-$start)/1e6 < 70, 'zero poll does not wait');
    $ready = King\Awaitable::any(['slow'=>$slow,'fast'=>$fast,'medium'=>$medium])->await(500);
    verify($ready['key']==='fast' && $ready['value'] instanceof King\Response, 'any returns fast second input first');
    verify($slow->isPending(), 'slow request is still in flight');
    $refill = $client->requestAsync('GET', $url.'/?ms=40');
    $ready = king_await(king_awaitable_any(['slow'=>$slow,'refill'=>$refill]), 300);
    verify($ready['key']==='refill', 'slot can be refilled before older request completes');
    $all = King\Awaitable::all(['slow'=>$slow,'medium'=>$medium]);
    $start = hrtime(true);
    try { $all->await(25); throw new RuntimeException('missing wait timeout'); }
    catch (King\TimeoutException $e) { verify((hrtime(true)-$start)/1e6 < 100, 'aggregate wait uses one bounded deadline'); }
    verify($slow->isPending(), 'wait timeout preserves active transfer');
    $result = $all->await(1200);
    verify(array_keys($result)===['slow','medium'], 'all preserves input keys');
    verify($result['slow']['status']==='resolved' && $result['medium']['status']==='resolved', 'all collects independent completions');
    $one = $client->requestAsync('GET', $url.'/?ms=350');
    $two = $client->requestAsync('GET', $url.'/?ms=450');
    $three = $client->requestAsync('GET', $url.'/?ms=550');
    $start = hrtime(true);
    $result = King\Awaitable::all([$one,$two,$three])->await(1800);
    verify((hrtime(true)-$start)/1e6 < 1000, 'elapsed time follows longest request instead of sum');
    verify(json_decode($result[2]['value']->getBody(),true)['peak']>=3, 'server observed overlapping requests');
    $cancel = new King\CancelToken();
    $cancelled = $client->requestAsync('GET', $url.'/?ms=800',[], '', $cancel);
    $survivor = $client->requestAsync('GET', $url.'/?ms=50');
    $cancel->cancel();
    $survivor->await(500);
    verify($cancelled->isCancelled(), 'cancellation token removes pending handle');
    verify($survivor->getStatus()==='resolved', 'cancelling one request does not cancel siblings');
    $client->close();
    verify($survivor->await()->getStatusCode()===200, 'settled response remains readable after client close');
    $direct = king_http1_request_send_async($url.'/?ms=40');
    verify(!$direct->poll(0) && $direct->await(500)['status']===200, 'direct HTTP1 async API shares native event loop');
    $binary = "\0\xff\x80data\0";
    verify(king_client_send_request_async($url, 'POST', ['Content-Type'=>'application/octet-stream'], $binary)->await(500)['body']===$binary, 'procedural async transport preserves binary bodies');
    $timeout = king_client_send_request_async($url.'/?ms=600', 'GET', null, null, ['timeout_ms'=>50]);
    $ok = king_client_send_request_async($url.'/?ms=20');
    $mixed = king_awaitable_all(['timeout'=>$timeout,'ok'=>$ok])->await(700);
    verify($mixed['timeout']['status']==='rejected' && $mixed['ok']['status']==='resolved', 'transport failure is isolated in aggregate envelope');
    try { $timeout->await(); throw new RuntimeException('timeout not rethrown'); }
    catch (King\TimeoutException $e) { echo "transport error retains exception type\n"; }
    $before = memory_get_usage();
    for ($i=0;$i<50;$i++) { $discard=king_client_send_request_async($url.'/?ms=1000'); unset($discard); }
    gc_collect_cycles();
    verify(memory_get_usage()-$before < 1000000, 'abandoned pending transfers release their buffers');
} finally {
    foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
    proc_terminate($process); proc_close($process);
}
?>
--EXPECT--
submission does not wait for responses
zero poll keeps unfinished request pending
zero poll does not wait
any returns fast second input first
slow request is still in flight
slot can be refilled before older request completes
aggregate wait uses one bounded deadline
wait timeout preserves active transfer
all preserves input keys
all collects independent completions
elapsed time follows longest request instead of sum
server observed overlapping requests
cancellation token removes pending handle
cancelling one request does not cancel siblings
settled response remains readable after client close
direct HTTP1 async API shares native event loop
procedural async transport preserves binary bodies
transport failure is isolated in aggregate envelope
transport error retains exception type
abandoned pending transfers release their buffers
