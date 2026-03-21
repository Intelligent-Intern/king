.PHONY: build unit fuzz benchmark deploy server-build help clean

build:
	sudo bash infra/scripts/build.sh || exit 0

unit:
	bash infra/scripts/unit.sh || exit 0

fuzz:
	bash infra/scripts/fuzz.sh || exit 0

benchmark:
	bash infra/scripts/benchmark.sh || exit 0

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