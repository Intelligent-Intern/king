--TEST--
King native HTTP/2 Awaitables multiplex, nest, collect batches and isolate errors
--SKIPIF--
<?php
if (!function_exists('proc_open')) die('skip proc_open required');
if (trim((string) shell_exec('command -v node')) === '') die('skip node required');
?>
--FILE--
<?php
function check($ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo $label, "\n";
}
$server = proc_open(['node', __DIR__.'/async_http2_server.js'],
    [['pipe','r'],['pipe','w'],['pipe','w']], $pipes);
try {
    $url = 'http://127.0.0.1:'.(int)trim(fgets($pipes[1]));
    $slow = king_http2_request_send_async($url.'/?ms=280');
    $fast = king_http2_request_send_async($url.'/?ms=40');
    $nested = King\Awaitable::all(['slow'=>$slow]);
    $first = King\Awaitable::any(['nested'=>$nested,'fast'=>$fast])->await(150);
    check($first['key']==='fast', 'nested aggregate does not block faster sibling');
    $responses = King\Awaitable::all(['slow'=>$slow,'fast'=>$fast])->await(500);
    $one = json_decode($responses['slow']['value']['body'],true);
    $two = json_decode($responses['fast']['value']['body'],true);
    check($one['session']===$two['session'] && $one['peak']>=2, 'HTTP2 requests overlap on one connection');
    check($responses['slow']['value']['protocol']==='http/2', 'strict HTTP2 response protocol retained');
    $older = King\Awaitable::all([$fast]);
    $later = king_http2_request_send_async($url.'/?ms=5');
    $later->await(200);
    $first = King\Awaitable::any(['later'=>$later,'older-group'=>$older])->await(50);
    check($first['key']==='older-group', 'nested group retains child completion order');
    $start = hrtime(true);
    $batch = king_http2_request_send_multi_async([
        ['url'=>$url.'/?ms=200'], ['url'=>$url.'/?ms=50', 'headers'=>null],
    ]);
    check((hrtime(true)-$start)/1e6 < 80 && !$batch->poll(0), 'HTTP2 batch starts without waiting');
    try { $batch->await(15); throw new RuntimeException('missing timeout'); }
    catch (King\TimeoutException $e) { echo "batch wait timeout preserves pending work\n"; }
    $batchResult = $batch->await(500);
    check(count($batchResult)===2 && str_contains(json_decode($batchResult[0]['body'],true)['path'],'ms=200'), 'batch preserves plain ordered response list');
    $single = new King\Client\Http2Client();
    $objectResult = $single->requestAsync('GET',$url.'/?ms=10')->await(200);
    check($objectResult instanceof King\Response && $objectResult->getStatusCode()===200, 'HTTP2 OO API returns response object');
    $bad = king_http2_request_send_async($url.'/?big=1');
    $good = king_http2_request_send_async($url.'/?ms=5&status=409');
    $results = King\Awaitable::all(['bad'=>$bad,'good'=>$good])->await(1000);
    check($results['bad']['status']==='rejected' && str_contains($results['bad']['error'],'size limit'), 'oversized body rejects bounded transfer');
    check($results['good']['status']==='resolved' && $results['good']['value']['status']===409, 'HTTP status response survives sibling transport failure');
    $token = new King\CancelToken();
    $token->cancel();
    $cancelled = $single->requestAsync('GET',$url.'/?ms=1',[],'',$token);
    check($cancelled->isCancelled(), 'pre-cancelled request is never attached');
    $batch = king_http2_request_send_multi_async([['url'=>$url.'/?ms=600']]);
    check($batch->cancel() && $batch->isCancelled(), 'batch cancellation detaches its private children');
    $ok = king_http2_request_send_async($url.'/?ms=5')->await(300);
    check($ok['status']===200, 'connection remains usable after cancellation and overflow');
    $single->close();
} finally {
    foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
    proc_terminate($server); proc_close($server);
}
?>
--EXPECT--
nested aggregate does not block faster sibling
HTTP2 requests overlap on one connection
strict HTTP2 response protocol retained
nested group retains child completion order
HTTP2 batch starts without waiting
batch wait timeout preserves pending work
batch preserves plain ordered response list
HTTP2 OO API returns response object
oversized body rejects bounded transfer
HTTP status response survives sibling transport failure
pre-cancelled request is never attached
batch cancellation detaches its private children
connection remains usable after cancellation and overflow
