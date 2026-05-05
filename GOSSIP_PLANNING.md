# Gossip Mesh Planning

Last updated: 2026-05-05

## Operating Rule

Every gossip mesh iteration must update both:

- `GOSSIP_CURRENT_BUILD.md`
- `GOSSIP_PLANNING.md`

Every gossip mesh iteration must also add or update executable regression coverage and run it successfully before the step is considered complete.

## Ranked Next Tasks

### 1. Bind Gossip Data Transport To Live Native WebRTC Peers

Impact: very high.

Complexity: high.

Status: complete for the current scaffold.

Why:

This is the main missing piece between a decentralized controller boundary and a real peer-to-peer media data lane. The existing native WebRTC signaling path can create peer connections; the gossip data lane needs to bind only assigned neighbors to `GossipRtcDataChannelTransport`.

Done when:

- Assigned gossip neighbors get RTCDataChannel bindings. Complete for native peer connections behind `shadow`/`active`.
- Remote RTCDataChannel data messages feed `GossipController.handleData()`. Complete in `active`.
- Local deliveries from `onDataMessage()` can enter the remote frame path behind a feature flag. Complete for `sfu/frame`.
- Non-neighbor peers are not opened solely for gossip data. Complete through server-assigned neighbor filtering.
- New executable contracts pass. Complete for native peer binding, cleanup, server topology ingestion, and outbound live publication.

### 2. Add Server-Style Topology Snapshot Contract

Impact: high.

Complexity: medium.

Status: complete.

Why:

The server should be the source of admission and topology hints, not the frame distributor. The client needs a stable topology payload shape before live integration.

Done when:

- A topology hint message shape is documented in the wire contract.
- Client can apply a server-provided neighbor set.
- Local deterministic selection remains available for harness/testing fallback.
- New executable contract passes.

### 3. Feature Flag The Gossip Data Lane

Impact: high.

Complexity: medium.

Status: complete.

Why:

The gossip data lane should be introduced without destabilizing the current SFU path. A feature flag allows side-by-side telemetry and rollback.

Done when:

- Gossip data lane can be enabled independently from SFU publishing.
- Default production behavior remains conservative.
- Diagnostics indicate whether a frame arrived by SFU or gossip.
- New executable contract passes.

### 4. Wire Local Deliveries Into Remote Frame Decode

Impact: high.

Complexity: high.

Status: complete for active inbound `sfu/frame` gossip deliveries.

Why:

Decentralized transport is only useful once delivered gossip frames can be decoded/rendered by the existing remote video path.

Done when:

- `GossipController.onDataMessage()` feeds the same validation/decode path as server frames.
- Late-frame dropping is explicit.
- Keyframe requirements are respected.
- New executable contract passes.

### 5. Add Neighbor Health And Topology Repair

Impact: medium-high.

Complexity: medium-high.

Status: complete for client request and backend requester-scoped replacement hints.

Why:

Real peer links fail. The mesh needs pressure signals, lost carrier detection, and server-assisted replacement neighbors.

Done when:

- RTCDataChannel close/error updates carrier state. Complete for assigned gossip neighbors.
- Lost neighbor triggers reconnect/topology repair request over ops lane. Complete for `gossip/topology-repair/request`.
- Backend consumes repair requests, validates context/membership/authenticated peer, and emits bounded replacement topology hints. Complete.
- Cooldowns prevent reconnect storms. Complete with per-neighbor client cooldown.
- New executable contract passes. Complete.

### 6. Add Gossip Telemetry

Impact: medium.

Complexity: medium.

Status: complete for local controller/workspace/RTC transport telemetry.

Why:

The scaling win needs proof. We need counts for server fanout avoided, peer outbound fanout, duplicates, TTL exhaustion, late drops, and per-hop latency.

Done when:

- Diagnostics expose gossip send/receive/forward/drop counters.
- Events distinguish in-memory harness transport vs RTCDataChannel transport.
- Counters include avoided server fanout, peer outbound fanout, duplicates, TTL exhaustion, stale generation drops, RTC queue late drops, and hop latency when timestamp metadata is available.
- New executable contract passes.

## Current Priority

The previous open gossip implementation tasks are complete for the current scaffold:

- Backend topology repair handling now consumes `gossip/topology-repair/request`, validates room/call membership and authenticated peer context, rejects media/signaling/secret fields, and emits bounded replacement topology hints without becoming a media distributor.
- Gossip telemetry now exposes local controller/workspace/RTC transport counters and transport-kind events through executable frontend contracts.

The next highest-impact step is continued conservative rollout observation. The backend now records repair/link-health observations, reads recent object_store health records back into topology planning, avoids recent failed pairs across reconnects/processes, and exposes diagnostic-only rollout gates from sanitized room telemetry aggregates while preserving SFU-first behavior until active-mode gossip quality is proven.

Prerequisite now complete:

- The live gossip data lane must use IIBIN binary envelopes, not JSON text frames.
- Native/server relay work must align with King object_store for control-plane/topology persistence.
- Native/server relay and fallback work must keep LSQUIC/HTTP3 and King binary WebSocket compatibility in the transport contract.
- Browser peer links can remain RTCDataChannel, but server-assisted relay/repair should prefer the native LSQUIC stack where available.
- The King PHP extension builds and loads locally when passed explicitly to PHP. Targeted native PHPTs pass; the full extension suite still has broader existing failures and LSQUIC migration gaps that should stay separate from frontend gossip rollout contracts.

Recent completed step:

- Topology repair planning now reads bounded recent King object_store health observations for the room/call, validates schema/version/kind/context/peer fields, rejects unsafe media/signaling/secret-bearing records, ignores malformed/stale records, and feeds recent failed pairs into topology avoidance.
- Gossip rollout gates now derive duplicate, TTL exhaustion, late-drop, repair-rate, and RTC/topology readiness metrics from sanitized telemetry aggregates or acks; frontend diagnostics remain observational and active mode is only allowed when explicit active mode plus readiness thresholds are met.
- Persistent topology-health records now write sanitized failed-link observations to King object_store-compatible keys and avoid fresh failed pairs during replacement topology generation.
- Room-level gossip telemetry aggregation now accepts sanitized `gossip/telemetry/snapshot` ops-lane messages, validates counters/transport labels, aggregates by room in presence state, and returns `gossip/telemetry/ack`.
- Native linker selector cleanup now lets `make -C extension` link on macOS without passing ELF-only `-soname`; Linux keeps soname behavior.
- Production gossip fanout now defaults to degree 4 and clamps to degree 3..5 in frontend routing and backend topology/repair/forward planning, preventing eligible rooms from degrading into degree-2 cycle graphs.
- The standalone four-peer local gossip harness now has adjustable fanout, defaults to degree 4, clamps to degree 3..5 plus available peers, and is covered by `gossip-harness-faults-contract.mjs` inside `npm run test:contract:gossip`.
- Backend topology repair handling is implemented and covered by `realtime-gossipmesh-runtime-contract.sh`.
- Gossip telemetry is implemented and covered by `gossip-telemetry-contract.mjs` inside `npm run test:contract:gossip`.
- Outbound live gossip publication now runs only after successful conservative SFU send and only when the gossip data lane is `active`.
- Live native gossip data channels are now bound only for server-assigned gossip neighbors.
- Executable contracts cover outbound live publication and server topology ingestion.
- Assigned-neighbor RTCDataChannel health now updates gossip carrier state and requests cooldown-bound topology repair over the ops lane in active mode.
- Live gossip workspace glue has been extracted into `workspace/callWorkspace/gossipDataLane.js`; shell/sidebar viewport state has been extracted into `workspace/callWorkspace/shellViewport.js`; diagnostics context registration has been moved into `clientDiagnostics.js`; the refactor-boundary contract now passes with `CallWorkspaceView.vue` at 2149 lines.

Current failure and warning follow-up:

- Backend topology repair handling is no longer open for the current scaffold.
- Gossip telemetry is no longer open for the current scaffold.
- Persistent topology-health records, object_store readback, room-level telemetry aggregation, and rollout-gate diagnostics are no longer open for the current scaffold.
- The previous Vite `CallWorkspaceView` large route chunk warning is resolved through manual route-graph chunks and a new build-size contract.
- Native MCP remote-control tests `340` and `341` now pass after aligning native runtime-control monotonic milliseconds with PHP `hrtime(true)` via `zend_hrtime() / 1000000`.
- Native cleanup removed generated/autotools/no-op extension churn from the worktree. The macOS `-soname` linker blocker is fixed for `make -C extension`.
- Native curl/pkg-config prerequisite cleanup is complete. Top-level `make build` reaches configure/compile with `pkg-config` absent by selecting the documented Homebrew curl include path on this host.
- Native OpenSSL header/library prerequisite cleanup is complete. Top-level `make build` now selects the documented Homebrew OpenSSL include/library paths on this host and stages release artifacts successfully.
- Native compiler/linker warning cleanup is complete. `make build` now passes on this macOS host without compiler/linker warnings; Darwin libtool generation avoids deprecated `-undefined suppress`, and native prerequisite selectors now explicitly cover Linux, macOS, and Windows candidate families.

## Next Candidate Tasks

### 1. Windows Native Build CI Runner

Impact: medium.

Complexity: medium-high.

Status: future-blocked.

Why:

The native build scripts now have explicit Windows selector branches for Cygwin/MINGW/MSYS-style environments and vcpkg/MSYS2 curl/OpenSSL candidates, but this repository cannot prove the full Windows native extension build without a real Windows runner.

Blocked on:

- Access to a Windows CI runner or equivalent hosted Windows native build environment.
- A decided Windows toolchain target, for example MSYS2/MinGW, Cygwin, or a PHP SDK/vcpkg-based flow.

Done when:

- CI has a Windows native build lane that runs the relevant prerequisite selector contracts.
- The Windows lane exercises `make build` or the Windows-equivalent native extension build path.
- Any Windows-specific curl/OpenSSL/LSQUIC path differences are contracted.
- The lane is documented as future validation, not a local developer requirement.

### 2. Native Compiler Warning Cleanup

Impact: low-medium.

Complexity: medium.

Status: complete.

Why:

Top-level `make build` now succeeds, but it still emits existing compiler warnings. Warning cleanup should be separate from prerequisite/build unblocking so it can be reviewed safely.

Done when:

- macOS deprecated `syscall` diagnostics are resolved or intentionally isolated behind platform selectors. Complete with `pthread_threadid_np()` on Darwin and `SYS_gettid` on Linux.
- `zend_long` format warnings use the correct portable format specifier/cast. Complete across `extension/src`.
- Focused native PHPTs and `make build` still pass. Complete.

### 3. Native OpenSSL Header/Library Prerequisite Cleanup

Impact: medium.

Complexity: medium.

Status: complete.

Why:

The curl/pkg-config blocker was resolved for this host and `make build` then reached extension compilation. The next native blocker was missing OpenSSL headers at `extension/src/media/rtp.c`.

Done when:

- Build scripts select a documented vendored/system OpenSSL header path on macOS and Linux. Complete.
- The failure message names the exact install/bootstrap command. Complete.
- `make build` reaches the next compile/link stage on a clean macOS checkout when system OpenSSL headers are installed. Complete; it now builds and stages release artifacts on this host.
- Existing focused native PHPTs still pass. Complete.

### 4. Native Curl/pkg-config Build Prerequisite Cleanup

Impact: medium.

Complexity: medium.

Status: complete.

Why:

The macOS `-soname` linker failure is fixed, and `make -C extension` passes. The top-level `make build` path still fails early when curl headers are missing and `pkg-config` is unavailable.

Done when:

- Build scripts select a documented vendored/system curl header path on macOS and Linux. Complete.
- Build scripts document matching macOS/Linux libcurl library/runtime candidates without adding a hard libcurl link. Complete.
- The failure message names the exact install/bootstrap command. Complete.
- `make build` reaches configure/compile on a clean macOS checkout when system curl headers are installed but `pkg-config` is absent. Complete on this host with `-I/opt/homebrew/opt/curl/include`.
- Existing focused PHPTs still pass. Complete.

Known remaining native follow-up:

- Windows native build validation remains future-blocked because no Windows machine/runner is currently available locally.

## Step Checklist Template

For each step:

1. Update implementation.
2. Add or update executable contract/test.
3. Run the focused new check.
4. Run adjacent existing checks.
5. Update `GOSSIP_CURRENT_BUILD.md`.
6. Update `GOSSIP_PLANNING.md`.
7. Record known gaps honestly.
