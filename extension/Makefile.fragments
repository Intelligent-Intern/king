KING_QUICHE_SOURCE_ROOT = $(srcdir)/../quiche
KING_QUICHE_TARGET_DIR = $(builddir)/../quiche/target
KING_QUICHE_PROFILE = release
KING_QUICHE_LIBRARY = $(KING_QUICHE_TARGET_DIR)/$(KING_QUICHE_PROFILE)/libquiche.so
KING_QUICHE_SERVER = $(KING_QUICHE_TARGET_DIR)/$(KING_QUICHE_PROFILE)/quiche-server
KING_QUICHE_RUNTIME_STAMP = $(KING_QUICHE_TARGET_DIR)/.king-quiche-runtime.stamp
KING_RUNTIME_INSTALL_DIR = $(INSTALL_ROOT)$(EXTENSION_DIR)/king/runtime
KING_CARGO_BUILD_COMPAT = $(srcdir)/../infra/scripts/cargo-build-compat.sh
KING_QUICHE_TOOLCHAIN_ENSURE = $(srcdir)/../infra/scripts/ensure-quiche-toolchain.sh
KING_QUICHE_TOOLCHAIN_CONFIRM ?= prompt

all: king-quiche-runtime
install: install-king-runtime

$(KING_QUICHE_RUNTIME_STAMP):
	@if test ! -f "$(KING_QUICHE_SOURCE_ROOT)/quiche/Cargo.toml"; then \
		echo "Bundled quiche sources are missing under $(KING_QUICHE_SOURCE_ROOT)." >&2; \
		echo "Use the PIE pre-packaged source asset or a checkout with the bundled QUIC tree present." >&2; \
		exit 1; \
	fi
	@KING_QUICHE_TOOLCHAIN_CONFIRM="$(KING_QUICHE_TOOLCHAIN_CONFIRM)" \
		$(KING_QUICHE_TOOLCHAIN_ENSURE) "$(KING_QUICHE_SOURCE_ROOT)/quiche/Cargo.toml"
	@echo "Building King QUIC runtime artifacts"
	@CARGO_TARGET_DIR="$(KING_QUICHE_TARGET_DIR)" $(KING_CARGO_BUILD_COMPAT) cargo build \
		--manifest-path "$(KING_QUICHE_SOURCE_ROOT)/quiche/Cargo.toml" \
		--package quiche \
		--release \
		--locked \
		--features ffi
	@CARGO_TARGET_DIR="$(KING_QUICHE_TARGET_DIR)" $(KING_CARGO_BUILD_COMPAT) cargo build \
		--manifest-path "$(KING_QUICHE_SOURCE_ROOT)/apps/Cargo.toml" \
		--release \
		--locked \
		--bin quiche-server
	@touch "$(KING_QUICHE_RUNTIME_STAMP)"

king-quiche-runtime: $(KING_QUICHE_RUNTIME_STAMP)

install-king-runtime: king-quiche-runtime
	@$(mkinstalldirs) $(KING_RUNTIME_INSTALL_DIR)
	@echo "Installing King runtime artifacts: $(KING_RUNTIME_INSTALL_DIR)/"
	@$(INSTALL_DATA) "$(KING_QUICHE_LIBRARY)" "$(KING_RUNTIME_INSTALL_DIR)/libquiche.so"
	@$(INSTALL) -m 755 "$(KING_QUICHE_SERVER)" "$(KING_RUNTIME_INSTALL_DIR)/quiche-server"
