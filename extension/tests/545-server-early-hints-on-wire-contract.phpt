--TEST--
King HTTP/1 one-shot listener emits server-side Early Hints on wire and into the active client pending-hints path
--SKIPIF--
<?php
if (!function_exists('proc_open') || !function_exists('stream_socket_server')) {
    echo "skip proc_open and stream_socket_server are required";
}
if (!extension_loaded('pcntl')) {
    echo "skip pcntl extension required";
}
?>
--FILE--
<?php
require __DIR__ . '/server_websocket_wire_helper.inc';

function king_server_early_hints_split_http1_response(string $response): array
{
    $blocks = [];
    $remaining = $response;

    while (true) {
        $headerEnd = strpos($remaining, "\r\n\r\n");
        if ($headerEnd === false) {
            break;
        }

        $head = substr($remaining, 0, $headerEnd);
        $remaining = (string) substr($remaining, $headerEnd + 4);
        $parsed = king_server_http1_wire_parse_response($head . "\r\n\r\n");
        $blocks[] = $parsed;

        if (($parsed['status'] ?? 0) < 100 || ($parsed['status'] ?? 0) >= 200) {
            $blocks[count($blocks) - 1]['body'] = $remaining;
            break;
        }
    }

    return $blocks;
}

function king_server_early_hints_request_stream_retry(int $port)
{
    $deadline = microtime(true) + 3.0;

    do {
        try {
            return king_http1_request_send(
                'http://127.0.0.1:' . $port . '/hints',
                'GET',
                null,
                null,
                [
                    'response_stream' => true,
                    'timeout_ms' => 1000,
                ]
            );
        } catch (King\NetworkException $e) {
            if (
                !str_contains($e->getMessage(), 'connect phase (errno 111)')
                || microtime(true) >= $deadline
            ) {
                throw $e;
            }

            usleep(50000);
        }
    } while (true);
}

$server = king_server_websocket_wire_start_server('early-hints');
try {
    $wireResponse = king_server_http1_wire_request_retry(
        $server['port'],
        "GET /hints HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n"
    );
} finally {
    $wireCapture = king_server_websocket_wire_stop_server($server);
}

$wireBlocks = king_server_early_hints_split_http1_response($wireResponse);

var_dump($wireCapture['listen_result']);
var_dump($wireCapture['listen_error']);
var_dump($wireCapture['early_hints_ok']);
var_dump($wireCapture['early_hints_error']);
var_dump(count($wireBlocks));
var_dump($wireBlocks[0]['status_line']);
var_dump($wireBlocks[0]['headers']['link']);
var_dump($wireBlocks[0]['headers']['x-trace']);
var_dump($wireBlocks[1]['status']);
var_dump($wireBlocks[1]['headers']['content-type']);
var_dump($wireBlocks[1]['headers']['x-mode']);
var_dump($wireBlocks[1]['body']);

$server = king_server_websocket_wire_start_server('early-hints');
try {
    $context = king_server_early_hints_request_stream_retry($server['port']);
    $pending = king_client_early_hints_get_pending($context);
    $response = king_receive_response($context);
} finally {
    $clientCapture = king_server_websocket_wire_stop_server($server);
}

var_dump($clientCapture['listen_result']);
var_dump($clientCapture['listen_error']);
var_dump($clientCapture['early_hints_ok']);
var_dump($clientCapture['early_hints_error']);
var_dump(count($pending));
var_dump($pending[0]['url']);
var_dump($pending[0]['rel']);
var_dump($pending[0]['as']);
var_dump($response->getStatusCode());
var_dump($response->getHeaders()['x-mode']);
var_dump($response->getBody());
?>
--EXPECT--
bool(true)
string(0) ""
bool(true)
string(0) ""
int(2)
string(24) "HTTP/1.1 103 Early Hints"
string(40) "</assets/app.css>; rel=preload; as=style"
string(6) "edge-1"
int(200)
string(10) "text/plain"
string(11) "early-hints"
string(16) "early-hints-body"
bool(true)
string(0) ""
bool(true)
string(0) ""
int(1)
string(15) "/assets/app.css"
string(7) "preload"
string(5) "style"
int(200)
string(11) "early-hints"
string(16) "early-hints-body"
