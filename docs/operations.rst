Operations
==========

Common Make Targets
-------------------

.. code-block:: bash

   make build
   make test
   make static-checks
   make fuzz
   make benchmark
   make go-live-readiness
   make release-package
   make release-package-verify
   make container-smoke
   make demo-network-matrix
   make docker-php-matrix

Release And Packaging
---------------------

The release path is source-asset based. The root ``composer.json`` declares the
package as ``type = php-ext`` with ``build-path = extension``. Release assets
are prepared by ``infra/scripts/package-pie-source.sh`` and verified through
release package and install matrix scripts.

The active build contract uses the pinned LSQUIC bootstrap recorded in
``infra/scripts/lsquic-bootstrap.lock``. Do not replace that path with ad hoc
dependency checkouts in committed docs or scripts.

CI
--

GitHub Actions currently include:

* ``ci.yml`` for the core build and verification pipeline
* ``planning-branch-guard.yml`` for planning branch policy
* ``release-merge-publish.yml`` for release-oriented publishing
* ``docs.yml`` for this Sphinx site and GitHub Pages deployment

Deployment
----------

The documentation site builds from ``docs/`` and publishes the generated HTML
artifact with GitHub Pages. The expected public URL for the fork is:

``https://sashakolpakov.github.io/king/``

If the upstream ``Intelligent-Intern/king`` repository enables Pages for the
same workflow, its URL will be:

``https://intelligent-intern.github.io/king/``

Reference
---------

* :repo:`documentation/operations-and-release.md`
* :repo:`documentation/dependency-provenance.md`
* :repo:`documentation/pie-install.md`
* :repo:`documentation/19-release-package-verification/README.md`
* :repo:`documentation/20-fuzz-and-stress-harnesses/README.md`
