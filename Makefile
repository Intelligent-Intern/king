.PHONY: build unit fuzz benchmark deploy server-build help clean static-checks profile-release profile-debug profile-asan profile-ubsan profile-smoke-release profile-smoke-debug profile-smoke-asan profile-smoke-ubsan release-package release-package-verify

build:
	sudo bash infra/scripts/build.sh || exit 0

unit:
	bash infra/scripts/unit.sh || exit 0

fuzz:
	bash infra/scripts/fuzz.sh

benchmark:
	bash benchmarks/run-canonical.sh

static-checks:
	bash extension/scripts/static-checks.sh

profile-release:
	bash extension/scripts/build-profile.sh release

profile-debug:
	bash extension/scripts/build-profile.sh debug

profile-asan:
	bash extension/scripts/build-profile.sh asan

profile-ubsan:
	bash extension/scripts/build-profile.sh ubsan

profile-smoke-release:
	bash extension/scripts/smoke-profile.sh release

profile-smoke-debug:
	bash extension/scripts/smoke-profile.sh debug

profile-smoke-asan:
	bash extension/scripts/smoke-profile.sh asan

profile-smoke-ubsan:
	bash extension/scripts/smoke-profile.sh ubsan

release-package:
	bash infra/scripts/package.sh

release-package-verify:
	bash infra/scripts/package.sh --verify-reproducible

deploy:
	bash infra/scripts/deploy.sh || exit 0

clean:
	bash infra/scripts/clean.sh || exit 0

tree:
	bash -c "tree -I 'index\\.html|rest|infra|tests|get|build|bin|vendor|extension-windows|modules|quiche|test_certs\\\\.git|404\\.html\\\\.gitignore|LICENSE|azure-sp\\\\.json|composer\\\\.lock|package\\\\.xml|Makefile\\\\.am|config\\\\.h\\\\.in~|configure~'"

ext-tree:
	bash -c "tree -I 'config\\.h\\.in~|config\\.log|config\\.nice|config\\.status|configure~|configure\\.ac|libtool|Makefile\\.fragments|Makefile\\.objects|modules|king\\.la|cancel\\.dep|cancel\\.lo|connect\\.dep|connect\\.lo|http3\\.dep|http3\\.lo|php_king\\.dep|php_king\\.lo|poll\\.dep|poll\\.lo|session\\.dep|session\\.lo|tls\\.dep|tls\\.lo|autom4te\\.cache|build|config\\\\.h\\\\.in' extension"

infra-tree:
	bash -c "tree infra"

tests-tree:
	bash -c "tree tests"

help:
	bash infra/scripts/help.sh
