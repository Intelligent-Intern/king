Demo: Video Chat
================

``demo/video-chat`` is the public demo documented by this site. Other
directories under ``demo/`` are intentionally omitted.

What It Demonstrates
--------------------

The video-chat demo is a full collaboration application built around KING
runtime capabilities:

* PHP backend modules for auth, users, calls, invites, operations, realtime
  WebSocket traffic, attachments, infrastructure inventory, and marketplace
  surfaces
* Vue frontend workspace for login, call scheduling, dashboards, admin users,
  marketplace, call entry, and live call workspaces
* Realtime room state, chat, typing, reactions, presence, lobby admission,
  SFU/GossipMesh routing, recovery requests, and admin sync
* Protected media envelope contracts, media key exchange contracts, WLVC frame
  contracts, protected API semantics, and UI parity matrices
* Edge gateway support and deployment scripts for local and Hetzner-oriented
  paths
* Contract tests and Playwright journeys for backend and frontend behavior

Layout
------

``backend-king-php``
   PHP backend. ``public/index.php`` enters the app, ``server.php`` and
   ``run-dev.sh`` support local runtime use, ``http`` contains route modules,
   ``domain`` contains business logic, ``support`` contains auth, database,
   hardening, and envelope helpers, and ``tests`` contains contract tests.

``frontend-vue``
   Vue/Vite frontend. The ``src/domain`` tree is organized by auth, calls,
   marketplace, realtime, and users. The realtime tree includes local capture,
   SFU transport, media preferences, security helpers, native bridge helpers,
   background processing, layout strategies, chat attachments, and workspace
   integration. Playwright tests live in ``tests/e2e``.

``contracts/v1``
   JSON contracts for API/WebSocket catalog, E2EE sessions, media key
   exchange, protected API semantics, protected media frames and envelopes, UI
   parity, and WLVC frames.

``edge``
   Edge service Dockerfile and PHP entry point.

``ops``
   Metrics and alert catalog for operational surfaces.

``scripts``
   Compose, deployment, smoke, backup, restore, TURN, NAT matrix, secret
   management, edge deployment, and hardening checks.

Run Locally
-----------

Use the demo-local compose wrapper when you want the whole demo stack:

.. code-block:: bash

   cd demo/video-chat
   ./scripts/compose-v1.sh up

Use the backend and frontend scripts directly when iterating on one side:

.. code-block:: bash

   cd demo/video-chat/backend-king-php
   ./run-dev.sh

.. code-block:: bash

   cd demo/video-chat/frontend-vue
   npm install
   npm run dev

Verification
------------

Backend contract tests live under
``demo/video-chat/backend-king-php/tests``. Frontend browser tests live under
``demo/video-chat/frontend-vue/tests/e2e``. Demo-level smoke and deployment
checks live under ``demo/video-chat/scripts``.

Related Handbook Pages
----------------------

* :repo:`documentation/dev/video-chat.md`
* :repo:`documentation/dev/video-chat-backend.md`
* :repo:`documentation/dev/video-chat-frontend.md`
* :repo:`documentation/dev/video-chat-codec-test.md`
* :repo:`documentation/dev/video-chat-wavelet-codec.md`
* :repo:`documentation/dev/video-chat/real-media-plane-architecture.md`
* :repo:`documentation/dev/video-chat/multi-node-runtime-architecture.md`
* :repo:`documentation/dev/video-chat/edge-deployment.md`
* :repo:`documentation/dev/video-chat/ops-hardening.md`
* :repo:`documentation/dev/video-chat/security-hardening.md`
* :repo:`documentation/global-chat-video-platform.md`
