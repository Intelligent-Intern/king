Configuration
=============

KING has two configuration layers.

Runtime Config
--------------

Application code passes arrays or ``King\Config`` objects into runtime entry
points such as ``king_connect()``, server listeners, subsystem initializers,
object-store initialization, telemetry initialization, autoscaling setup, and
Semantic DNS setup.

The detailed runtime reference lives in:

* :repo:`documentation/configuration-handbook.md`
* :repo:`documentation/runtime-configuration.md`

System INI
----------

Deployment-level settings use ``king.*`` INI directives. The active config
modules are under ``extension/include/config`` and cover:

* app HTTP/3, WebSocket, and WebTransport-adjacent behavior
* bare-metal tuning
* cloud autoscale
* cluster and process behavior
* dynamic admin API
* high-performance compute and AI settings
* HTTP/2
* IIBIN
* MCP and orchestrator
* native CDN
* native object store
* OpenTelemetry
* QUIC transport
* router and load balancer
* security and traffic policy
* Semantic DNS and semantic geometry
* SSH over QUIC
* state management
* TCP transport
* TLS and crypto

The INI reference lives in :repo:`documentation/system-ini-reference.md`.

Validation
----------

Config validators live under ``extension/src/validation/config_param`` and
``extension/include/validation/config_param``. They keep runtime and INI values
inside explicit type, range, path, host, allowlist, CORS, scheduler, shard,
niceness, and CPU-affinity contracts.
