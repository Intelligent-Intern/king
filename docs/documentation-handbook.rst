Handbook
========

The Markdown handbook in ``documentation/`` remains the detailed product,
developer, and operator reference. This Sphinx site is the deployable front
door and index.

Foundations
-----------

* :repo:`documentation/getting-started.md`
* :repo:`documentation/glossary.md`
* :repo:`documentation/platform-model.md`
* :repo:`documentation/configuration-handbook.md`
* :repo:`documentation/solution-blueprints.md`

Networking And Realtime
-----------------------

* :repo:`documentation/quic-and-tls.md`
* :repo:`documentation/http-clients-and-streams.md`
* :repo:`documentation/websocket.md`
* :repo:`documentation/gossipmesh.md`
* :repo:`documentation/server-runtime.md`
* :repo:`documentation/ssh-over-quic.md`

Data, Control Plane, And Operations
-----------------------------------

* :repo:`documentation/mcp.md`
* :repo:`documentation/iibin.md`
* :repo:`documentation/object-store-and-cdn.md`
* :repo:`documentation/flow-php-etl.md`
* :repo:`documentation/semantic-dns.md`
* :repo:`documentation/telemetry.md`
* :repo:`documentation/autoscaling.md`
* :repo:`documentation/pipeline-orchestrator.md`
* :repo:`documentation/router-and-load-balancer.md`
* :repo:`documentation/advanced-subsystems.md`
* :repo:`documentation/operations-and-release.md`

Reference
---------

* :repo:`documentation/procedural-api.md`
* :repo:`documentation/object-api.md`
* :repo:`documentation/runtime-configuration.md`
* :repo:`documentation/system-ini-reference.md`

Example Guides
--------------

The numbered guides under ``documentation/01-*`` through ``documentation/20-*``
cover edge bootstrap, realtime control planes, Semantic DNS, HTTP/2, HTTP/3,
WebSocket, streaming recovery, object store/CDN, MCP transfer persistence,
IIBIN object hydration, pipeline orchestrator tools, server upgrades, admin
TLS reload, config policy, cancellation, protocol compatibility, system
lifecycle, benchmarks, release package verification, and fuzz/stress harnesses.

Developer Notes
---------------

Developer notes under ``documentation/dev`` cover benchmark workflows,
Flow PHP, the IIBIN package, local image publishing, and the video-chat demo.
The public site documents only ``demo/video-chat`` from the ``demo`` tree.
