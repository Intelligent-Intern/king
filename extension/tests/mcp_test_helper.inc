<?php

/* Shared helper utilities for local MCP protocol, transfer-state, and repo-wide multi-host harnesses. */

require_once __DIR__ . '/multi_host_test_helper.inc';

function king_mcp_test_format_tcp_endpoint(string $host, int $port): string
{
    return king_multi_host_test_format_tcp_endpoint($host, $port);
}

function king_mcp_test_start_server_script(
    string $scriptPath,
    ?int $port = null,
    array $extraArgs = [],
    string $bindHost = '127.0.0.1'
): array
{
    return king_multi_host_test_start_server_script(
        $scriptPath,
        $port,
        $extraArgs,
        $bindHost
    );
}

function king_mcp_test_start_server(?int $port = null, string $bindHost = '127.0.0.1'): array
{
    return king_mcp_test_start_server_script(__DIR__ . '/mcp_test_server.inc', $port, [], $bindHost);
}

function king_mcp_test_start_parallel_server(?int $port = null, string $bindHost = '127.0.0.1'): array
{
    return king_mcp_test_start_server_script(__DIR__ . '/mcp_parallel_test_server.inc', $port, [], $bindHost);
}

function king_mcp_test_start_persisted_server(
    string $statePath,
    ?int $port = null,
    string $bindHost = '127.0.0.1'
): array
{
    return king_mcp_test_start_server_script(
        __DIR__ . '/mcp_test_server.inc',
        $port,
        [$statePath],
        $bindHost
    );
}

function king_mcp_test_finalize_server(array $server): array
{
    return king_multi_host_test_finalize_server($server);
}

function king_mcp_test_stop_server(array $server): array
{
    $stop = @stream_socket_client(
        king_mcp_test_format_tcp_endpoint(
            (string) ($server['host'] ?? '127.0.0.1'),
            (int) $server['port']
        ),
        $errno,
        $errstr,
        0.5
    );
    if (is_resource($stop)) {
        fwrite($stop, "STOP\n");
        fflush($stop);
        fclose($stop);
    }

    return king_mcp_test_finalize_server($server);
}

function king_mcp_test_crash_server(array $server, int $signal = 9): array
{
    if (is_resource($server['process'])) {
        proc_terminate($server['process'], $signal);
    }

    $deadline = microtime(true) + 2.0;
    while (microtime(true) < $deadline) {
        $status = proc_get_status($server['process']);
        if (!is_array($status) || !$status['running']) {
            break;
        }
        usleep(10000);
    }

    return king_mcp_test_finalize_server($server);
}

function king_mcp_schedule_cancel_signal(
    King\CancelToken $token,
    int $delayUs = 100000,
    int $signal = SIGUSR1
): int {
    pcntl_async_signals(true);
    pcntl_signal($signal, static function () use ($token): void {
        $token->cancel();
    });

    $pid = pcntl_fork();
    if ($pid < 0) {
        throw new RuntimeException('failed to fork MCP cancel helper');
    }

    if ($pid === 0) {
        usleep($delayUs);
        posix_kill(posix_getppid(), $signal);
        exit(0);
    }

    return $pid;
}

function king_mcp_wait_cancel_signal(int $pid, int $signal = SIGUSR1): void
{
    pcntl_waitpid($pid, $status);
    pcntl_signal($signal, SIG_DFL);
}

function king_mcp_test_detect_non_loopback_ipv4(): string
{
    return king_multi_host_test_detect_non_loopback_ipv4();
}

function king_mcp_test_run_namespaced_client_script(
    string $scriptPath,
    int $port,
    array $extraArgs = []
): array
{
    return king_multi_host_test_run_namespaced_php_script(
        $scriptPath,
        $port,
        $extraArgs,
        ['king.mcp_allowed_peer_hosts={gateway_host}'],
        'MCP multi-host harness'
    );
}
