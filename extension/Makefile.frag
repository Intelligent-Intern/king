# The active King extension build must not bootstrap Quiche or Cargo artifacts.
# HTTP/3 links through the C-stack detection in config.m4 when enabled.

build-modules: king-codesign-module

king-codesign-module: $(PHP_MODULES)
	@if test -f "$(builddir)/modules/king.so" && command -v codesign >/dev/null 2>&1; then \
		echo "Re-signing macOS extension bundle: $(builddir)/modules/king.so"; \
		codesign -f -s - "$(builddir)/modules/king.so"; \
	fi
