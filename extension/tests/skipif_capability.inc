<?php

function king_skipif(string $message): void
{
    echo 'skip ' . $message;
    exit;
}

function king_skipif_require_functions(array $functions): void
{
    foreach ($functions as $function) {
        if (!function_exists($function)) {
            king_skipif($function . ' is required');
        }
    }
}

function king_skipif_require_loopback_bind(string $transport): void
{
    $flags = STREAM_SERVER_BIND;
    if ($transport === 'tcp' || $transport === 'tls') {
        $flags |= STREAM_SERVER_LISTEN;
    }

    $probe = @stream_socket_server(
        $transport . '://127.0.0.1:0',
        $errno,
        $errstr,
        $flags
    );

    if ($probe === false) {
        $reason = is_string($errstr) && $errstr !== ''
            ? $errstr
            : ('errno ' . (string) $errno);
        king_skipif('loopback ' . $transport . ' bind is unavailable: ' . $reason);
    }

    fclose($probe);
}
