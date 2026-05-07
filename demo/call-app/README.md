# King Call App Packages

Call Apps are installable collaborative applications that can be attached to a
King video call. Each package is discoverable through Semantic DNS, describes
itself through MCP metadata, launches in a sandboxed iframe, and synchronizes
shared state through a King CRDT envelope.

Package layout:

```text
demo/call-app/<app-key>/
  call-app.manifest.json
  mcp.descriptor.json
  crdt.schema.json
  health.descriptor.json
  public/index.html
```

Required package contracts:

- `call-app.manifest.json` is the canonical package manifest.
- `mcp.descriptor.json` exposes the metadata methods used by discovery and the
  Marketplace.
- `crdt.schema.json` defines app document kinds, operation kinds, envelope
  fields, replay policy, and snapshot policy.
- `health.descriptor.json` defines package health checks for discovery.
- `public/index.html` is the iframe launch entrypoint.

Runtime invariants:

- A Call App iframe receives a short-lived launch token, never the user's primary
  session token.
- Parent/app messages use the `king.call_app.iframe.v1` bridge protocol with
  strict origin checks implemented by the host integration.
- Marketplace orders and installations are scoped to an organization.
- App CRDT operations use app-specific semantics inside a King-owned envelope.
- The app package may own document semantics, but King owns admission,
  persistence, snapshots, audit, and replay safety.
