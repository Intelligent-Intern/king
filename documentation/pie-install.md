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

King is not only `king.so`. The active runtime also needs the bundled QUIC
artifacts. That means the PIE package shape for King does all of this
in one install:

- build the extension from `extension/`
- compile the bundled QUIC runtime with Cargo and Rust (mandatory)
- install `king.so`
- install `libquiche.so`
- install `quiche-server`

This is why King uses a pre-packaged source asset instead of pretending that
the default repository ZIP is enough.

This is also why a casual "just publish it to PECL" answer would be too loose.
King needs the extension and the runtime artifacts to travel together, so the
installer story has to be explicit and honest.

## User Install Shape

Once the package is on Packagist and the matching GitHub release contains the
PIE source asset, the intended install command is:

```bash
pie install intelligent-intern/king-ext
```

The current beta install track is `v1.0.0-beta`.
After the matching tag is available in GitHub Releases, users can use the same
command for PIE installation.

PIE then enters the King build path under `extension/`, runs `phpize`,
`./configure`, `make`, and `make install`, and the King build hook compiles and
installs the QUIC runtime artifacts beside the extension by default.

If a build runs in CI/non-interactive mode and your Rust toolchain is missing or
outdated, set:

```bash
KING_QUICHE_TOOLCHAIN_CONFIRM=yes pie install intelligent-intern/king-ext
```

for automatic in-script upgrade attempts, or:

```bash
KING_QUICHE_TOOLCHAIN_CONFIRM=no pie install intelligent-intern/king-ext
```

to fail fast with clear instructions.

Default behavior (if the variable is unset) is interactive `prompt` mode.

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
- `cargo` and `rustc` (always, because Quiche is a mandatory runtime component)
- `KING_QUICHE_TOOLCHAIN_CONFIRM` (`prompt`, `yes`, or `no`) for installer automation
- libcurl development headers and libraries

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

4. After the release is published, ensure that the release merge path used is:
   - only from `develop/v...` branches into `main`
   - with the release tag generated as `v<version>`, where `<version>` is the
     release branch suffix (for example `develop/v1.0.0-beta` → `v1.0.0-beta`)

5. Submit the repository to Packagist using the root `composer.json`.
