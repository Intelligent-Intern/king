Packages And Tools
==================

IIBIN JavaScript/TypeScript Package
-----------------------------------

``packages/iibin`` publishes ``@intelligentintern/iibin``. It is the JS/TS
protocol package for KING IIBIN payloads and exports ESM, CommonJS, and
TypeScript declaration builds from ``dist``.

Useful commands:

.. code-block:: bash

   cd packages/iibin
   npm run build
   npm run typecheck

Benchmarks
----------

``benchmarks`` contains canonical benchmark cases and budget files. Run:

.. code-block:: bash

   make benchmark

The detailed workflow is in :repo:`documentation/dev/benchmarks.md`.

Stubs
-----

``stubs/king.php`` mirrors the public PHP API for IDEs and static analysis.
Keep it aligned with C arginfo and the public function table:

.. code-block:: bash

   make stub-parity

Infra Scripts
-------------

``infra/scripts`` contains the operational toolbelt:

* extension build and test scripts
* profile build and profile smoke scripts
* static checks
* fuzz and soak runtime scripts
* release package and release verification scripts
* PIE source packaging
* container smoke and PHP version matrix scripts
* dependency provenance and CVE gates
* config compatibility, public contract, persistence migration, and artifact
  hygiene checks

``infra/demo-server`` and ``infra/local-image-push`` support local network
smoke and image-publishing workflows.

Root Planning Files
-------------------

The root planning files are part of the active engineering process:

* :repo:`EPIC.md`
* :repo:`BACKLOG.md`
* :repo:`SPRINT.md`
* :repo:`READYNESS_TRACKER.md`
* :repo:`CONTRIBUTE`
* :repo:`CODEOWNERS`
