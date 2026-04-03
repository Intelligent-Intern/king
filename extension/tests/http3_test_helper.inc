<?php

if (!defined('KING_HTTP3_TESTS_DIR')) {
    define('KING_HTTP3_TESTS_DIR', __DIR__);
}

include __DIR__ . '/http3_test_helper/core_fixture_and_server.inc';
include __DIR__ . '/http3_test_helper/ticket_server_and_capture.inc';
include __DIR__ . '/http3_test_helper/retry_and_silent_udp.inc';
include __DIR__ . '/http3_test_helper/helper_binaries.inc';
include __DIR__ . '/http3_test_helper/helper_clients.inc';
include __DIR__ . '/http3_test_helper/helper_peers.inc';
