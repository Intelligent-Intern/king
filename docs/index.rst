KING
====

KING is a native PHP extension for transport-heavy, realtime, and
infrastructure-aware applications. It brings protocol clients, server runtime
hooks, WebSocket, HTTP/2, HTTP/3, QUIC/TLS integration, Semantic DNS,
IIBIN binary encoding, MCP, object storage, CDN hooks, telemetry,
autoscaling, and pipeline orchestration into one PHP-visible runtime.

The project is in the ``1.0.8-beta`` line. The active build is Linux-focused,
supports PHP 8.1 through PHP 8.5, and is packaged as ``intelligent-intern/king-ext``
for PIE-compatible extension installation.

Contents
--------

.. toctree::
   :maxdepth: 2

   getting-started
   architecture
   api
   extension
   configuration
   documentation-handbook
   demo-video-chat
   packages-and-tools
   operations
   contributing

Repository Map
--------------

``extension/``
   Native extension source, headers, arginfo, object handlers, config modules,
   runtime subsystems, and PHPT tests.

``documentation/``
   The full Markdown handbook and reference corpus. This Sphinx site is the
   deployable entry point; the handbook remains the detailed source material.

``demo/video-chat/``
   The documented demo application. Other directories under ``demo/`` are not
   part of this public docs site by request.

``packages/iibin/``
   JavaScript and TypeScript IIBIN protocol package for browser and Node users.

``infra/``
   Build, packaging, release, Docker, matrix, smoke, provenance, and readiness
   scripts.

``benchmarks/``
   Canonical benchmark cases and budget files.

``stubs/``
   PHP stubs that mirror the exported extension surface for IDEs and static
   analysis.
