# PIE Install

King can be installed through [PIE](https://github.com/php/pie), the PHP
Installer for Extensions.

## What PIE Is

PIE is the official installer for PHP extensions and the successor to PECL.
It is distributed as a PHAR and behaves more like Composer than like the old
PECL flow: you run PIE as a tool, and it installs compiled PHP extensions into
your PHP installation.

If you are new to PHP extensions, the shortest mental model is this:

- PECL was the older extension-distribution and install path many PHP users
  still remember
- PIE is the newer path you should think about first now
- old blog posts may still tell you to run `pecl install ...`
- for King, the packaging direction is PIE, not a fresh PECL story

The official PIE README is here:

- <https://github.com/php/pie>

The important idea for King users is simple:

- Composer installs PHP packages into a project
- PIE installs PHP extensions into a PHP runtime
- King is an extension, so PIE is the right packaging direction

## How To Get PIE

According to the official PIE docs, the current shape is:

1. Download `pie.phar` from the latest PIE release.
2. Optionally verify it with:

```bash
gh attestation verify --owner php pie.phar
```

3. Run it either as:

```bash
php pie.phar <command>
```

or move it into your `PATH`, for example:

```bash
chmod +x pie.phar
sudo mv pie.phar /usr/local/bin/pie
pie --help
```

PIE itself currently needs PHP `8.1` or newer to run, but it can target other
installed PHP versions.

## What King Expects From PIE

King is not only `king.so`. The source package also carries the pinned
LSQUIC/BoringSSL provenance that describes the HTTP/3 replacement stack. That
means the PIE package shape for King does all of this in one install:

- build the extension from `extension/`
- install `king.so`
- keep the HTTP/3 dependency pins traceable through
  `infra/scripts/lsquic-bootstrap.lock`
- support extended HTTP/3 linking through `pkg-config` or explicit
  `KING_LSQUIC_*` / `KING_BORINGSSL_*` environment overrides

This is why King uses a pre-packaged source asset instead of pretending that
the default repository ZIP is enough.

This is also why a casual "just publish it to PECL" answer would be too loose.
King needs the extension and LSQUIC/BoringSSL provenance to travel together, so
the installer story has to be explicit and honest.

## User Install Shape

Once the package is on Packagist and the matching GitHub release contains the
PIE source asset, the intended install command is:

```bash
pie install intelligent-intern/king-ext
```

The current beta install track is `v1.0.2-beta`.
After the matching tag is available in GitHub Releases, users can use the same
command for PIE installation.

PIE then enters the King build path under `extension/`, runs `phpize`,
`./configure`, `make`, and `make install`. No Rust or Cargo toolchain is
required for this PIE path.

If you want the optional extended HTTP/3 LSQUIC/BoringSSL link on a developer
machine, pass it through the same portable path contract used by the repository
build:

```bash
export PKG_CONFIG_PATH="/path/to/lsquic/lib/pkgconfig:/path/to/boringssl/lib/pkgconfig:${PKG_CONFIG_PATH:-}"
pie install intelligent-intern/king-ext
```

or use explicit `KING_LSQUIC_CFLAGS`, `KING_LSQUIC_LIBS`,
`KING_BORINGSSL_CFLAGS`, and `KING_BORINGSSL_LIBS` values. Do not bake local
package-manager paths into the source package. Homebrew/Cellar paths must stay
local and must be passed only through environment variables or `pkg-config`.

Depending on the target PHP installation and how PIE configures it, you may
also need to enable the extension explicitly in `php.ini`:

```ini
extension=king.so
```

If PIE already reports that the extension is enabled and loaded for the target
PHP binary, you are done.

## Host Requirements

For the current King release path, assume a Linux source install and make sure
the host has:

- PHP development toolchain for the target PHP version
- `phpize`
- `autoconf`
- `make`
- a C compiler
- libcurl development headers and libraries
- `pkg-config` when using system-provided LSQUIC/BoringSSL packages

King currently excludes Windows from this first PIE path. The current v1 target
is Linux source installs first, then broader packaging later.

## Maintainer Steps

1. Generate the PIE source asset:

```bash
./infra/scripts/package-pie-source.sh
```

This creates:

```text
dist/php_king-<version>-src.tgz
```

2. Publish a GitHub release for the matching King version tag.

3. Upload the generated `php_king-<version>-src.tgz` asset to that release.

4. After the release is published, verify that the release page exposes the
   matching `php_king-<version>-src.tgz` asset and that the published version
   is the same one intended for `pie install intelligent-intern/king-ext`.

5. Submit the repository to Packagist using the root `composer.json`.
