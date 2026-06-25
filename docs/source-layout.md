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
- `extension/include/inference/` contains the native inference model and stream
  contracts plus OpenAI-compatible procedural export metadata.
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
+-- php_binding.inc
+-- registration.inc
+-- state.inc
+-- focused implementation leaves
```

The root extension header `extension/include/php_king.h` should include the
primitive umbrella header, and `extension/src/php_king/function_table.inc` or
the relevant class registration table should include the primitive function and
method entries. Active `.c` files must be listed in `extension/config.m4`.

This keeps the ABI surface, PHP registration metadata, and runtime
implementation discoverable without flattening every subsystem into one large
directory.
