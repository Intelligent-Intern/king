Extension Source
================

The native extension lives under ``extension/``.

Build Files
-----------

``extension/config.m4`` is the PHP build entry point. It enables the extension,
validates PHP version requirements, wires optional LSQUIC and BoringSSL paths,
and keeps the active source list compiled into one shared module.

The supported local shapes are:

* repository scripts such as ``./infra/scripts/build-extension.sh``
* profile builds such as ``./infra/scripts/build-profile.sh release``
* direct ``phpize``/``./configure --enable-king`` workflows for extension
  developers
* explicit dependency overrides through ``KING_LSQUIC_*`` and
  ``KING_BORINGSSL_*`` variables when local dependency paths are needed

Headers
-------

``extension/include`` is grouped by subsystem:

* ``client`` for request, session, TLS, WebSocket, and cancellation surfaces
* ``server`` for listeners, admin APIs, CORS, TLS, and upgrades
* ``config`` for namespaced default, INI, base-layer, and runtime config modules
* ``iibin``, ``mcp``, ``object_store``, ``pipeline_orchestrator`` for data and control-plane subsystems
* ``semantic_dns``, ``autoscaling``, ``telemetry`` for fleet behavior
* ``validation`` for config parameter validators

Runtime Source
--------------

``extension/src`` is organized around the same subsystem boundaries:

* ``php_king`` registers classes, functions, resources, exceptions, lifecycle hooks, and module metadata
* ``core`` provides version, health, and introspection helpers
* ``config`` materializes validated runtime configuration
* ``client`` and ``server`` implement transport-facing behavior
* ``iibin`` implements schema, enum, encode, decode, batch, and object hydration support
* ``object_store`` implements local and cloud-facing object storage behavior
* ``mcp`` and ``pipeline_orchestrator`` implement control-plane movement and workflow execution
* ``semantic_dns``, ``autoscaling``, and ``telemetry`` implement topology, fleet, metrics, traces, and logs
* ``media`` contains RTP helper support used by the realtime media path

Tests
-----

``extension/tests`` contains PHPT tests and helpers. Coverage is contract-heavy
and includes load, config validation, client/server protocol behavior,
WebSocket, HTTP/2, HTTP/3, object-store backends, Semantic DNS, telemetry,
autoscaling, MCP, orchestrator behavior, system lifecycle, IIBIN, fuzz/stress
contracts, and media codec boundaries.

Run the standard suite with:

.. code-block:: bash

   make test

For release confidence, combine PHPTs with static checks, fuzz/stress,
benchmark, profile smoke, and container matrix targets described in
:doc:`operations`.
