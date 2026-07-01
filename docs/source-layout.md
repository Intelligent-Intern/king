# Source Layout

King is a PHP extension repository. The native runtime is intentionally scoped
under `extension/` instead of placing C sources and headers directly at the
repository root.

```text
extension/
+-- config.m4
+-- include/
|   +-- php_king.h
|   +-- php_king/
|   +-- awaitable/
|   +-- inference/
|   +-- mcp/
|   +-- xslt/
|   +-- ...
+-- src/
    +-- php_king.c
    +-- php_king/
    +-- awaitable/
    +-- inference/
    +-- mcp/
    +-- xslt/
    +-- ...
```

`extension/config.m4` adds both `extension/` and `extension/include/` to the C
include path. Inside extension sources, module headers are therefore included
as `awaitable/index.h`, `inference/index.h`, `mcp/index.h`, `xslt/index.h`, and
so on.

## Public Header Root

The public extension header root is `extension/include/`.

- `extension/include/php_king.h` is the central extension header.
- `extension/include/php_king/` contains bootstrap-owned declarations,
  constants, global state, helper contracts, and root registration metadata.
- `extension/include/awaitable/` contains the `King\Awaitable` object contract,
  cancel-token declarations, arginfo, class entries, and function entries.
- `extension/include/inference/` contains the native inference umbrella header
  and splits class metadata, function entries, registration hooks, arginfo, and
  model/stream contracts into focused subdirectories.
- `extension/include/mcp/` contains public MCP server and King-internal MCP peer
  contracts, resource identifiers, arginfo, class entries, and function
  entries.
- `extension/include/xslt/` contains the XSLT processor contract, arginfo,
  class entries, and function entries.

There is no separate repository-root `include/` directory in the current
layout. Adding one would either duplicate extension headers or create a second
include root for the same ABI surface. The active contract is one native
extension include root: `extension/include/`.

## Runtime Source Root

The active runtime implementation root is `extension/src/`.

- `extension/src/php_king.c` is the single extension translation unit.
- `extension/src/php_king/` contains root extension bootstrap glue: module
  bindings, function table, class registration, lifecycle hooks, resources, and
  shared exception registration.
- `extension/src/awaitable/`, `extension/src/inference/`,
  `extension/src/mcp/`, and `extension/src/xslt/` are subsystem roots. They are
  not supposed to live under `extension/src/php_king/`.

The `php_king/` source directory is therefore not a primitive namespace. It is
the extension bootstrap layer that includes and registers the primitive
subsystems.

## Adding A Primitive

New primitives should follow this shape:

```text
extension/include/<primitive>/
+-- index.h
+-- <primitive>.h
+-- arginfo/
|   +-- index.h
|   +-- arginfo.h
+-- function_entries.h
+-- class_entries.h
+-- class_method_entries.h
+-- class_methods.h

extension/src/<primitive>/
+-- <primitive>.c
+-- binding/
|   +-- php_binding.inc
+-- core/
|   +-- registration.inc
|   +-- state.inc
+-- focused implementation leaves
```

The root extension header `extension/include/php_king.h` should include the
primitive umbrella header, and `extension/src/php_king/function_table.inc` or
the relevant class registration table should include the primitive function and
method entries. Active `.c` files must be listed in `extension/config.m4`.

This keeps the ABI surface, PHP registration metadata, and runtime
implementation discoverable without flattening every subsystem into one large
directory.

## Inference Header Shape

Inference is larger than the smaller primitives, so its public header surface is
split by PHP-extension responsibility:

```text
extension/include/inference/
+-- index.h
+-- arginfo/
|   +-- index.h
|   +-- arginfo.h
+-- classes/
|   +-- class_entries.h
|   +-- class_method_entries.h
|   +-- class_methods.h
+-- functions/
|   +-- function_entries.h
+-- registration/
|   +-- registration.h
+-- surface/
    +-- inference.h
```

`index.h` is the umbrella include. Subsystem bootstrap code should prefer that
umbrella unless it explicitly owns one narrower table such as function entries
or class method entries.

## Inference Source Shape

The inference primitive is intentionally split by ownership instead of keeping a
large flat implementation directory:

```text
extension/src/inference/
+-- inference.c
+-- binding/
|   +-- bootstrap/
|   +-- stacks/
|       +-- api/
|       +-- cuda/
|       +-- model/
|       +-- openai/
|       +-- request/
|       +-- runtime/
|       +-- stream/
+-- api/
|   +-- async/
|   +-- oop/
|   |   +-- model/
|   |   +-- stream/
|   +-- procedural/
|   +-- surface/
+-- backends/
|   +-- contracts/
|   +-- local/
|   +-- native/
|   +-- registry/
+-- core/
|   +-- bootstrap/
|   +-- classes/
|   +-- support/
+-- runtime/
|   +-- cache/
|   +-- config/
|   +-- events/
|   +-- memory/
|   +-- policy/
+-- gguf/
|   +-- loader/
|   +-- metadata/
+-- tokenizer/
+-- tensor/
|   +-- core/
|   +-- graph/
|   |   +-- builder/
|   |   +-- core/
|   |   +-- ops/
|   +-- resolvers/
|       +-- attention/
|       |   +-- components/
|       |       +-- metadata/
|       |       +-- shape/
+-- openai/
|   +-- chat/
|   +-- http/
|   +-- resources/
|   +-- runtime/
|       +-- backend/
|       +-- compat/
|       +-- options/
|       +-- stream/
+-- cuda/
    +-- runtime/
    |   +-- context/
    |   +-- policy/
    |   +-- status/
    |   +-- weights/
    +-- kernels/
    |   +-- attention/
    |   +-- device/
    |   +-- embedding/
    |   +-- ffn/
    |   +-- matvec/
    |   +-- norm/
    |   +-- projection/
    |   +-- rope/
    +-- decoder_graph/
    |   +-- core/
    |   +-- device/
    |   |   +-- compare/
    |   |   |   +-- embedding/
    |   |   |   +-- matvec/
    |   |   |   +-- norm/
    |   |   |   +-- projection/
    |   |   +-- core/
    |   +-- prefill/
    |   |   +-- ffn/
    |   |   +-- kv/
    |   |   +-- residual/
    |   +-- ops/
    |       +-- attention/
    |       +-- control/
    |       +-- ffn/
    |       +-- final/
    |       +-- kv/
    |       +-- projection/
    |       +-- residual/
    |       +-- rope/
    +-- prompt/
        +-- loop/
        +-- prefill/
            +-- attention/
            +-- batch/
            +-- ffn/
            +-- kv/
            +-- query/
            +-- remaining/
```

Decoder graph files are grouped by the operation family they own. Device
execution code may include those families, but individual operation files should
not drift back into a mixed `ops/` catch-all directory.

Inference folders should stay responsibility-oriented. Aggregator files belong
under `binding/bootstrap/` or one of the `binding/stacks/*/` folders; backend
implementations belong under their runtime family; runtime support is separated
into cache, config, memory, events, and policy leaves; CUDA runtime code is
split into context, policy, status, and weight-upload ownership. Do not add new
inference implementation files back to the primitive root or to a mixed
catch-all directory.
