API Overview
============

KING exposes both direct functions and namespaced classes. The procedural API
is useful for low-friction systems code. The object API is useful when the PHP
application wants typed composition and longer-lived runtime objects.

Core Functions
--------------

Version, health, config, session, and stats:

* ``king_version()``
* ``king_health()``
* ``king_new_config()``
* ``king_connect()``
* ``king_poll()``
* ``king_close()``
* ``king_get_stats()``
* ``king_get_last_error()``

HTTP client and stream work:

* ``king_send_request()``
* ``king_client_send_request()``
* ``king_http1_request_send()``
* ``king_http2_request_send()``
* ``king_http2_request_send_multi()``
* ``king_http3_request_send()``
* ``king_http3_request_send_multi()``
* ``king_receive_response()``
* ``king_cancel_stream()``

WebSocket and RTP:

* ``king_client_websocket_connect()``
* ``king_client_websocket_send()``
* ``king_client_websocket_receive()``
* ``king_client_websocket_ping()``
* ``king_client_websocket_close()``
* ``king_websocket_send()``
* ``king_rtp_bind()``, ``king_rtp_recv()``, ``king_rtp_send()``, ``king_rtp_close()``

Server runtime:

* ``king_http1_server_listen()`` and ``king_http1_server_listen_once()``
* ``king_http2_server_listen()`` and ``king_http2_server_listen_once()``
* ``king_http3_server_listen()`` and ``king_http3_server_listen_once()``
* ``king_server_listen()``
* ``king_server_on_cancel()``
* ``king_server_send_early_hints()``
* ``king_server_upgrade_to_websocket()``
* ``king_server_reload_tls_config()``
* ``king_admin_api_listen()``

Data, storage, control plane, and fleet:

* ``king_proto_*`` for IIBIN schema, enum, encode, decode, and batch APIs
* ``king_mcp_*`` for MCP connections, requests, uploads, and downloads
* ``king_object_store_*`` and ``king_cdn_*`` for durable objects and CDN hooks
* ``king_telemetry_*`` for spans, metrics, logs, context propagation, and flush
* ``king_autoscaling_*`` for node status, monitoring, and scale decisions
* ``king_semantic_dns_*`` for topology, service discovery, route selection, and status
* ``king_pipeline_orchestrator_*`` for tool registry, dispatch, workers, run state, and cancellation
* ``king_system_*`` for system init, health, metrics, restart, failure, recovery, and shutdown

Object API
----------

The public classes include:

* ``King\Config``
* ``King\Session``
* ``King\Stream``
* ``King\Response``
* ``King\CancelToken``
* ``King\MCP``
* ``King\IIBIN``
* ``King\ObjectStore``
* ``King\Autoscaling``
* ``King\Client\HttpClient``, ``King\Client\Http1Client``, ``King\Client\Http2Client``, ``King\Client\Http3Client``
* ``King\Server``
* ``King\WebSocket\Connection``
* ``King\WebSocket\Server``

Exception hierarchy
-------------------

KING-specific failures inherit from ``King\Exception``. Specialized branches
cover runtime, system, validation, timeout, network, TLS, QUIC, protocol,
stream, MCP, and WebSocket errors.

Reference Sources
-----------------

* :repo:`documentation/procedural-api.md`
* :repo:`documentation/object-api.md`
* :repo:`stubs/king.php`
* :repo:`extension/tests`
