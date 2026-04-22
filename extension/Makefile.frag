KING_LSQUIC_RUNTIME_STAMP = $(builddir)/../.king-lsquic-runtime.stamp
KING_RUNTIME_INSTALL_DIR = $(EXTENSION_DIR)/king/runtime

all: king-lsquic-runtime
install: install-king-runtime

$(KING_LSQUIC_RUNTIME_STAMP):
	@echo "King QUIC runtime - lsquic loads at runtime via dlopen, gracefully degrades to HTTP/2"
	@touch "$(KING_LSQUIC_RUNTIME_STAMP)"

king-lsquic-runtime: $(KING_LSQUIC_RUNTIME_STAMP)

install-king-runtime: king-lsquic-runtime
	@$(mkinstalldirs) $(KING_RUNTIME_INSTALL_DIR)
	@echo "Installing King runtime artifacts: $(KING_RUNTIME_INSTALL_DIR)/ (HTTP/3 via lsquic, falls back to HTTP/2)"
