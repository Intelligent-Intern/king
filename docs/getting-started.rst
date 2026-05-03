Getting Started
===============

Build KING from a repository checkout when you need the extension and the
matching in-tree QUIC runtime artifacts:

.. code-block:: bash

   git clone --recurse-submodules https://github.com/Intelligent-Intern/king.git
   cd king
   ./infra/scripts/build-profile.sh release
   php -d extension="$(pwd)/extension/modules/king.so" -r 'echo king_version(), PHP_EOL;'

For extension-only local development, use:

.. code-block:: bash

   ./infra/scripts/build-extension.sh

For tagged release assets that include the PIE source package, the intended
installer path is:

.. code-block:: bash

   pie install intelligent-intern/king-ext

Read the repo-local handbook for the first runnable local examples:

* :repo:`documentation/getting-started.md`
* :repo:`documentation/solution-blueprints.md`
* :repo:`documentation/operations-and-release.md`

Runtime Model
-------------

KING separates work into four planes:

Realtime
   WebSocket, IIBIN, chat, presence, room state, and bidirectional control
   messages.

Transport and media
   HTTP/1, HTTP/2, HTTP/3, QUIC, TLS, session tickets, cancellation,
   timeouts, stream lifecycle, and RTP/media helper surfaces.

Control
   MCP, pipeline orchestration, queue-backed execution, remote workers,
   resumable run state, and restart-aware control flow.

State and fleet
   Object storage, CDN hooks, Semantic DNS, telemetry, autoscaling, router
   policy, load-balancer policy, and system lifecycle.
