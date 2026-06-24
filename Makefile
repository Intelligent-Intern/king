.PHONY: build test unit fuzz stub-parity help clean static-checks profile-release profile-debug profile-asan profile-ubsan profile-smoke-release profile-smoke-debug profile-smoke-asan profile-smoke-ubsan release-package release-package-verify container-smoke docker-php-matrix tree ext-tree infra-tree tests-tree

build:
	bash infra/scripts/build-extension.sh

test:
	bash infra/scripts/test-extension.sh

unit: test

fuzz:
	bash infra/scripts/fuzz-runtime.sh

stub-parity:
	bash infra/scripts/check-stub-parity.sh

static-checks:
	bash infra/scripts/static-checks.sh

profile-release:
	bash infra/scripts/build-profile.sh release

profile-debug:
	bash infra/scripts/build-profile.sh debug

profile-asan:
	bash infra/scripts/build-profile.sh asan

profile-ubsan:
	bash infra/scripts/build-profile.sh ubsan

profile-smoke-release:
	bash infra/scripts/smoke-profile.sh release

profile-smoke-debug:
	bash infra/scripts/smoke-profile.sh debug

profile-smoke-asan:
	bash infra/scripts/smoke-profile.sh asan

profile-smoke-ubsan:
	bash infra/scripts/smoke-profile.sh ubsan

release-package:
	bash infra/scripts/package-release.sh

release-package-verify:
	bash infra/scripts/package-release.sh --verify-reproducible

container-smoke:
	bash infra/scripts/container-smoke-matrix.sh

docker-php-matrix:
	bash infra/scripts/php-version-docker-matrix.sh

clean:
	bash infra/scripts/clean.sh

tree:
	bash -c "tree -I 'index\\.html|rest|infra|tests|get|build|bin|vendor|extension-windows|modules|quiche|test_certs\\\\.git|404\\.html\\\\.gitignore|LICENSE|azure-sp\\\\.json|composer\\\\.lock|package\\\\.xml|Makefile\\\\.am|config\\\\.h\\\\.in~|configure~'"

ext-tree:
	bash -c "tree -I 'config\\.h\\.in~|config\\.log|config\\.nice|config\\.status|configure~|configure\\.ac|libtool|Makefile\\.fragments|Makefile\\.objects|modules|king\\.la|cancel\\.dep|cancel\\.lo|connect\\.dep|connect\\.lo|http3\\.dep|http3\\.lo|php_king\\.dep|php_king\\.lo|poll\\.dep|poll\\.lo|session\\.dep|session\\.lo|tls\\.dep|tls\\.lo|autom4te\\.cache|build|config\\\\.h\\\\.in' extension"

infra-tree:
	bash -c "tree infra"

tests-tree:
	bash -c "tree tests"

help:
	@printf '%s\n' \
		'King make targets:' \
		'  build                  Build the extension' \
		'  test                   Run the PHPT suite' \
		'  fuzz                   Run the canonical fuzz/stress subset' \
		'  stub-parity            Check the public PHP stub surface' \
		'  static-checks          Run repo-local structural checks' \
		'  release-package        Build the release archive' \
		'  release-package-verify Build the release archive and verify reproducibility' \
		'  container-smoke        Build runtime containers and run install smoke' \
		'  docker-php-matrix      Run build/test/runtime checks across PHP 8.1-8.5 containers' \
		'  clean                  Remove generated build and artifact output'
