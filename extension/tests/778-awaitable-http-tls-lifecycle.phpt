--TEST--
King async HTTP verifies TLS trust and hostname, frees cyclic results and rejects unsupported modes
--SKIPIF--
<?php
if (!function_exists('proc_open')) die('skip proc_open required');
if (trim((string) shell_exec('command -v node')) === '') die('skip node required');
if (trim((string) shell_exec('command -v openssl')) === '') die('skip openssl required');
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
function check($ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo $label, "\n";
}
$dir = sys_get_temp_dir().'/king-async-tls-'.bin2hex(random_bytes(5));
mkdir($dir,0700);
$cert = $dir.'/cert.pem'; $key = $dir.'/key.pem';
exec('openssl req -x509 -newkey rsa:2048 -nodes -keyout '.escapeshellarg($key)
    .' -out '.escapeshellarg($cert).' -sha256 -days 1 -subj /CN=localhost -addext '
    .escapeshellarg('subjectAltName=DNS:localhost').' 2>/dev/null', $output, $code);
if ($code !== 0) throw new RuntimeException('fixture certificate creation failed');
$server = proc_open(['node',__DIR__.'/async_http2_server.js',$cert,$key],
    [['pipe','r'],['pipe','w'],['pipe','w']], $pipes);
try {
    $port = (int)trim(fgets($pipes[1]));
    $url = 'https://localhost:'.$port;
    $untrusted = king_http2_request_send_async($url);
    try { $untrusted->await(1500); throw new RuntimeException('untrusted TLS accepted'); }
    catch (King\TlsException $e) { echo "untrusted TLS certificate rejected\n"; }
    $config = king_new_config(['tls_default_ca_file'=>$cert]);
    $options = ['connection_config'=>$config,'timeout_ms'=>1500];
    $trusted = king_http2_request_send_async($url.'/?ms=20', 'GET',null,null,$options);
    $mismatch = king_http2_request_send_async('https://127.0.0.1:'.$port, 'GET',null,null,$options);
    $states = King\Awaitable::all(['trusted'=>$trusted,'mismatch'=>$mismatch])->await(2000);
    check($states['trusted']['status']==='resolved', 'configured CA trust permits matching hostname');
    check($states['mismatch']['status']==='rejected', 'hostname mismatch rejected independently');
    try {
        king_http2_request_send_async($url)->await(1500);
        throw new RuntimeException('trusted connection reused without matching trust profile');
    } catch (King\TlsException $e) { echo "connection reuse preserves trust isolation\n"; }
    $http1 = king_http1_request_send_async($url, 'GET',null,null,$options);
    check($http1->await(2000)['protocol']==='http/1.1', 'HTTP1 async HTTPS also honors CA trust');
    foreach (['response_stream','follow_redirects','capture_push'] as $mode) {
        try {
            king_http1_request_send_async($url, 'GET',null,null,[$mode=>true]);
            throw new RuntimeException('unsupported mode accepted: '.$mode);
        } catch (King\ProtocolException $e) { echo $mode," explicitly rejected\n"; }
    }
    class BadBody { public function __toString(): string { throw new RuntimeException('body conversion failed'); } }
    try {
        king_http2_request_send_async($url, 'POST',null,new BadBody(),$options);
        throw new RuntimeException('body conversion exception lost');
    } catch (RuntimeException $e) { check($e->getMessage()==='body conversion failed', 'body conversion failure cleans up native state'); }
    function result_cycle_handler(array $context): array { return ['output'=>['future'=>$GLOBALS['cyclic_future']]]; }
    king_pipeline_orchestrator_register_tool('result-cycle', ['model'=>'test']);
    king_pipeline_orchestrator_register_handler('result-cycle', 'result_cycle_handler');
    $GLOBALS['cyclic_future'] = king_pipeline_orchestrator_run_async([], [['tool'=>'result-cycle']]);
    $weak = WeakReference::create($GLOBALS['cyclic_future']);
    $GLOBALS['cyclic_future']->await();
    unset($GLOBALS['cyclic_future']);
    gc_collect_cycles();
    check($weak->get()===null, 'GC sees native Awaitable result cycles');
    $ok = king_http2_request_send_async($url, 'GET',null,null,$options)->await(1000);
    check($ok['status']===200, 'event loop remains usable after validation failures');
    // Leave live requests and batches for the request shutdown hook.
    $pending = king_http2_request_send_async($url.'/?ms=1800', 'GET',null,null,$options);
    $batch = king_http2_request_send_multi_async([['url'=>$url.'/?ms=1800']],$options);
} finally {
    foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
    proc_terminate($server); proc_close($server);
    unlink($cert); unlink($key); rmdir($dir);
}
?>
--EXPECT--
untrusted TLS certificate rejected
configured CA trust permits matching hostname
hostname mismatch rejected independently
connection reuse preserves trust isolation
HTTP1 async HTTPS also honors CA trust
response_stream explicitly rejected
follow_redirects explicitly rejected
capture_push explicitly rejected
body conversion failure cleans up native state
GC sees native Awaitable result cycles
event loop remains usable after validation failures
