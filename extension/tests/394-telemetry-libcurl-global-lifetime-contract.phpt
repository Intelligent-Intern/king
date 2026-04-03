--TEST--
King telemetry keeps libcurl global init and cleanup on the system lifetime instead of per export
--FILE--
<?php
$runtimeSource = file_get_contents(__DIR__ . '/../src/telemetry/telemetry/runtime_and_libcurl.inc');
$httpSource = file_get_contents(__DIR__ . '/../src/telemetry/telemetry/php_api_and_http_transport.inc');

preg_match(
    '/static zend_result king_telemetry_ensure_libcurl_ready\\(void\\)\\s*\\{(?P<body>.*?)^\\}/ms',
    $runtimeSource,
    $ensureMatches
);
preg_match(
    '/static void king_telemetry_shutdown_libcurl_runtime\\(void\\)\\s*\\{(?P<body>.*?)^\\}/ms',
    $runtimeSource,
    $shutdownMatches
);
preg_match(
    '/static int king_telemetry_http_post\\([^\\)]*\\)\\s*\\{(?P<body>.*?)^\\}/ms',
    $httpSource,
    $postMatches
);

var_dump(isset($ensureMatches['body']));
var_dump(str_contains($ensureMatches['body'], 'curl_global_init_fn(CURL_GLOBAL_DEFAULT)'));
var_dump(isset($shutdownMatches['body']));
var_dump(str_contains($shutdownMatches['body'], 'curl_global_cleanup_fn()'));
var_dump(isset($postMatches['body']));
var_dump(str_contains($postMatches['body'], 'king_telemetry_ensure_libcurl_ready()'));
var_dump(str_contains($postMatches['body'], 'curl_global_init_fn('));
var_dump(str_contains($postMatches['body'], 'curl_global_cleanup_fn('));
var_dump(substr_count($runtimeSource, 'curl_global_init_fn(CURL_GLOBAL_DEFAULT)'));
var_dump(substr_count($runtimeSource, 'curl_global_cleanup_fn();'));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
bool(false)
int(1)
int(1)
