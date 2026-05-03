Contributing
============

Use the repository scripts instead of hand-rolled local commands whenever
possible. They encode the build, package, dependency, and verification
contracts that CI expects.

Before opening a change, run the focused checks for the area you changed and
then choose the broader gate that matches the risk:

.. code-block:: bash

   make static-checks
   make test
   make stub-parity

For release-facing or subsystem-wide changes, also run the relevant profile,
container, package, fuzz, benchmark, and readiness checks from
:doc:`operations`.

Project contribution details live in :repo:`CONTRIBUTE`.
