# PIE Install

King can be prepared for installation through
[PIE](https://github.com/php/pie), the PHP Installer for Extensions.

The current King package shape for PIE is intentionally honest:

- PIE should build King from a pre-packaged source asset, not from the default
  repository ZIP, because King needs the bundled `quiche/` tree during build.
- the build still compiles the bundled QUIC runtime with Cargo and Rust as part
  of `make`
- the install path must keep `king.so`, `libquiche.so`, and `quiche-server`
  together under the PHP extension directory

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

4. Submit the repository to Packagist using the root `composer.json`.

## User Install Shape

Once the package is on Packagist and the release contains the source asset, the
intended install command is:

```bash
pie install intelligent-intern/king-ext
```

PIE then enters the King build path under `extension/`, runs `phpize`,
`./configure`, `make`, and `make install`, and the King build hook compiles and
installs the QUIC runtime artifacts beside the extension.

## Host Requirements

- PHP development toolchain for the target PHP version
- `cargo`
- `rustc`
- libcurl development headers/libraries

King currently excludes Windows from this first PIE path. The current v1 target
is Linux source installs first, then broader packaging later.
