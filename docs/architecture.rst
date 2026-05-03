Architecture
============

KING is built as one PHP extension with a parallel procedural and object API.
The public surface sits on top of native subsystem kernels and shared runtime
state.

.. code-block:: text

   PHP userland
     -> procedural functions and King\* classes

   PHP extension surface
     -> arginfo, class entries, resources, object handlers, exceptions

   Native subsystem kernels
     -> client, server, WebSocket, RTP, IIBIN, MCP, object store,
        Semantic DNS, telemetry, autoscaling, pipeline orchestration

   Configuration and lifecycle
     -> defaults, INI, validation, config snapshots, startup, readiness,
        drain, shutdown, recovery

   External runtime inputs
     -> PHP build toolchain, libcurl, LSQUIC, BoringSSL, OS networking

Core Areas
----------

Client runtime
   ``extension/src/client`` implements request dispatch, HTTP/1, HTTP/2,
   HTTP/3, TLS, WebSocket client support, session objects, streams, response
   objects, cancellation, and early-hints handling.

Server runtime
   ``extension/src/server`` implements listener entry points, local listener
   handling, HTTP protocol listeners, WebSocket upgrade support, TLS reload,
   CORS, admin APIs, cancel hooks, and telemetry attachment.

Data and protocol runtime
   ``extension/src/iibin``, ``extension/src/mcp``, ``extension/src/object_store``,
   and ``extension/src/pipeline_orchestrator`` implement binary payloads,
   control-plane requests, durable objects, and multi-step workflow execution.

Fleet runtime
   ``extension/src/semantic_dns``, ``extension/src/autoscaling``,
   ``extension/src/telemetry``, and ``extension/src/integration`` implement
   topology, provider-facing scale decisions, metrics and traces, and system
   health/introspection.

Public Runtime Boundary
-----------------------

The public boundary is defined by:

* :repo:`stubs/king.php`
* :repo:`extension/src/php_king/function_table.inc`
* :repo:`extension/src/php_king/classes.inc`
* :repo:`extension/include/php_king_arginfo.h`

The source of truth for runtime behavior is still the C implementation and
PHPT coverage under ``extension/tests``. The stubs are documentation and tool
support, not a substitute for the native contract tests.
