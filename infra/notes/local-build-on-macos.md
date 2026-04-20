# Local Build on macOS (Apple Silicon)

King is a Linux-only project. The README advertises `Linux x86_64 / arm64`,
and CI (`.github/workflows/ci.yml`) only tests `ubuntu-24.04` runners. Native
macOS builds of `./infra/scripts/build-profile.sh release` fail — and the
failure is a real portability gap in the source, not a missing dependency.

This note captures what works, what doesn't, and the pitfalls we hit while
getting a working `king.so` on an M-series Mac.

## TL;DR

```bash
# One-time: build a local Linux image that can compile the extension.
docker build --platform linux/arm64 \
  --build-arg PHP_VERSION=8.3 \
  -f infra/king-build.Dockerfile \
  -t king-builder:local .

# Each build: run the upstream build script inside that image.
docker run --rm --platform linux/arm64 \
  -v "$PWD":/workspace -w /workspace \
  king-builder:local \
  ./infra/scripts/build-profile.sh release
```

Artifacts land in `extension/build/profiles/release/`:

- `king.so` — the PHP extension (linux-arm64 ELF)
- `libquiche.so` — the QUIC runtime
- `quiche-server` — the QUIC reference server

All three are Linux arm64 binaries. They run inside any Linux arm64
environment (including other containers based on the runtime image), not on
macOS directly.

## Why Native macOS Builds Fail

Running the build script on macOS hits a compile error:

```
extension/src/king_init/ticket_ring/support.inc:13:
  error: use of undeclared identifier 'getrandom'
```

`getrandom(2)` is a Linux-only syscall (glibc). macOS has no such function —
the equivalents are `getentropy(3)` or `arc4random_buf(3)`. The code has no
`__APPLE__` fallback, and porting is unlikely to stop here: the extension
and surrounding infrastructure make liberal use of Linux-only APIs
(`epoll`, `io_uring`, robust pthread mutexes, `/proc/*` introspection, etc.).

A cross-platform patch would be a sustained porting effort, not a one-line
fix. Running the build inside Linux is the pragmatic answer.

## Why We Don't Use `infra/php-matrix-runner.Dockerfile` Directly

The upstream CI image `infra/php-matrix-runner.Dockerfile` fails to build on
Apple Silicon with a Rosetta error during `phpize --version`:

```
rosetta error: failed to open elf at /lib64/ld-linux-x86-64.so.2
Trace/breakpoint trap
exit code: 133
```

The root cause is line 55 of that Dockerfile — Node.js is pinned to the
`linux-x64` (amd64) tarball and extracted to `/opt/node`, which is placed at
the **front** of `PATH`:

```dockerfile
ENV PATH=/root/.cargo/bin:/opt/node/bin:/usr/local/bin:/usr/sbin:/usr/bin:...
```

When BuildKit runs the final sanity-check (`php --version && phpize --version && node --version`), something under `phpize`'s shell script path-resolves to an x86_64 binary in `/opt/node/bin`, which Docker Desktop then attempts to emulate via Rosetta. Inside the arm64 container there is no
`/lib64/ld-linux-x86-64.so.2`, so Rosetta aborts.

The isolated reproduction confirmed this: the exact same `apt install` +
`phpize --version` sequence runs cleanly under `docker run --platform linux/arm64`, but fails under `docker build`. Node is only needed for JavaScript test
tooling in CI — the extension itself does not need it — so the local image
simply omits Node.

That local image lives at [`infra/king-build.Dockerfile`](../king-build.Dockerfile).

## Pitfalls We Hit

### 1. `tee` masks the real exit code

Running `docker build ... 2>&1 | tee /tmp/log` always reports exit 0 from the
pipeline, even when `docker build` failed with a nonzero code. The Claude
Code background-task notifications picked up that `0` and falsely reported
success three times in a row.

**Fix:** either drop the `tee` and redirect with `>`, or enable
`set -o pipefail` in the shell, or verify the image/artifact actually exists
after the build rather than trusting the exit code.

### 2. Stale BuildKit layer cache

After the first failed build (with PHP 8.5), subsequent rebuilds reused the
broken layer even with `--build-arg PHP_VERSION=8.3`. Passing `--no-cache` is
required when the failing layer is parameterised by build args.

### 3. `docker run ... &` doesn't survive the harness's shell

Using shell `&` inside a tool call that also sets `run_in_background: true`
led the wrapper shell to exit immediately, which generated a premature
completion notification while the actual container kept running. Use
`docker wait <container-id>` in a separate backgrounded call to get a real
completion signal.

### 4. `phpize --version` isn't what you'd think

ondrej/php's `phpize` (a shell script) does not honour `--version` — it
unconditionally prints the `Configuring for: PHP Api Version: ...` banner and
then does its normal work. If the script's sub-tools get confused (see the
Rosetta issue above), this is where the failure surfaces.

## Dependencies Installed on the Host

For completeness, these were installed on the macOS host during the initial
(native-build) attempts. They are **not** required for the containerised
build — the container has everything it needs. Listed here only so you can
clean them up if you don't use them for other projects:

- `pkg-config`
- `curl` (Homebrew version)
- `cmake`
- `rustup` (+ pinned toolchain `1.86.0`)

```bash
# If you want to remove them:
brew uninstall rustup cmake
# pkg-config and curl are commonly useful; leave them unless you're sure.
```

## Verifying the Build

Inside any Linux arm64 container, you can smoke-test the built extension:

```bash
docker run --rm --platform linux/arm64 \
  -v "$PWD":/workspace -w /workspace \
  king-builder:local \
  php -d extension=./extension/build/profiles/release/king.so \
      -r 'echo king_version(), PHP_EOL;'
```

If that prints a version string, `king.so` loads cleanly.
