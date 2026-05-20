# King Call App Packages

Call Apps are installable collaborative applications that can be attached to a
King video call. Each package is discoverable through Semantic DNS, describes
itself through MCP metadata, launches in a sandboxed iframe, and synchronizes
shared state through a King CRDT envelope.

Repository source root decision:

- The canonical repository source root is plural `demo/call-apps/`.
- `demo/call-app/` is not a Call App source root and must not be introduced as
  a parallel package tree.
- Runtime/public Call App URLs remain `/call-app/<app-key>/...`; that URL
  contract is separate from the repository source root name.

Package layout:

```text
demo/call-apps/<app-key>/
  call-app.manifest.json
  mcp.descriptor.json
  crdt.schema.json
  health.descriptor.json
  public/index.html
  public/<app-key>.css
  public/<app-key>.js
```

Package boundary:

- `demo/call-apps/<app-key>/` is the source package boundary for each Call App.
  App sources, package manifests, MCP descriptors, CRDT schemas, health
  descriptors, and iframe assets belong here.
- `demo/call-app/` is intentionally not used. Keeping the plural source root
  matches the package family while preserving the existing runtime URL contract.
- `demo/video-chat/frontend-vue/src/domain/realtime/callApps` is the video-call
  host integration. It owns the host shell, iframe bridge, launch context,
  permission handoff, and parent/app protocol handling; it is not the app source
  package.
- `demo/video-chat/backend-king-php/domain/call_apps` is the King runtime
  domain. It owns availability, runtime/session state, launch tokens, CRDT
  envelopes, snapshots, replay, audit, grants, and Marketplace installation
  behavior; it is not the app source package.
- `demo/video-chat/frontend-vue/dist/call-app` is a generated build artifact for
  served Call App assets. Do not treat it as source and do not make source
  changes there.
- Call App package notes and checks belong in this package tree, active
  `SPRINT.md`/`BACKLOG.md` items, or archived documentation. Do not add
  repository-root special-purpose Markdown files for individual Call Apps.

Required package contracts:

- `call-app.manifest.json` is the canonical package manifest.
- `mcp.descriptor.json` exposes the metadata methods used by discovery and the
  Marketplace.
- `crdt.schema.json` defines app document kinds, operation kinds, envelope
  fields, replay policy, and snapshot policy.
- `health.descriptor.json` defines package health checks for discovery.
- `public/index.html` is the iframe launch entrypoint.
- `public/*.css` and `public/*.js` hold the app runtime assets used by the
  sandbox entrypoint.

Included packages:

- `whiteboard`: shared drawing, notes, shapes, cursors, and exports.
- `planning-image`: shared image upload with pan/zoom canvas review.
- `text-document`: collaborative document editing with ODT and PDF export.
- `presentation`: collaborative slide editing with PowerPoint-compatible PPTX
  export.
- `spreadsheet`: collaborative cells, formulas, selection presence, and CSV or
  SpreadsheetML export.
- `call-diagnostics`: shared live diagnostic tail for WebSocket, ICE, STUN,
  TURN, SFU, and Call App bridge events.

Runtime invariants:

- A Call App iframe receives a short-lived launch token, never the user's primary
  session token.
- Parent/app messages use the `king.call_app.iframe.v1` bridge protocol with
  strict origin checks implemented by the host integration.
- Public iframe and static asset requests use `/call-app/<app-key>/...`.
- Marketplace orders and installations are scoped to an organization.
- App CRDT operations use app-specific semantics inside a King-owned envelope.
- The app package may own document semantics, but King owns admission,
  persistence, snapshots, audit, and replay safety.
