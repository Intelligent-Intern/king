# Experiment Audit: 1.0.7 SFU Media Closure

Date: 2026-04-28

Scope:
- `origin/experiments/1.0.7-video-codec`
- `origin/experiments/1.0.7-gossip-mesh`
- `origin/experiments/1.0.7-voltron`

This audit is for the current video-chat SFU media closure sprint. It is not a
general merge plan for unrelated AI, marketplace, or userland experiments.

## `origin/experiments/1.0.7-video-codec`

Decision: keep current runtime, keep already ported codec improvements, do not
replace the current SFU transport with the experiment branch.

Reasoning:
- The experiment branch has useful WLVC/WASM codec surface ideas, but its
  video-chat transport path does not prove a stronger runtime than the current
  binary `KSFB` envelope, room-bound `/sfu` gateway, protected-frame handling,
  bounded queue, and cross-worker live relay.
- Its IIBIN material is package/documentation oriented for video-chat; it does
  not provide an active King PHP SFU control/metadata path.
- The current sprint implements the IIBIN SFU control/metadata boundary in
  native King PHP via `king_proto_*`, not by importing the package-only branch
  transport.

Residual classification:
- `keep`: current WLVC/WASM runtime protections, codec regression contracts,
  WASM MIME/cache behavior, debug abstraction, and binary SFU media envelope.
- `port`: only codec-level changes already covered by the active WLVC contracts.
- `reject for this sprint`: JSON/media-array SFU transport, package-only IIBIN
  assumptions, and broad workspace reshaping as a media transport fix.

## `origin/experiments/1.0.7-gossip-mesh`

Decision: keep only the already ported server-authoritative ideas; reject
experiment behavior that weakens room admission or media protection.

Reasoning:
- Reusable ideas are topology planning, duplicate suppression, TTL/fanout
  limits, relay candidate ranking, relay fallback, and protected envelope
  requirements.
- The accepted implementation lives under the current backend-authoritative
  `wlvc_sfu` contract, not as browser-owned P2P state or process-local admission.
- Direct P2P/DataChannel, client-invented call/room state, plaintext payloads,
  raw socket endpoints, public STUN/TURN defaults, and debug-control behavior
  stay rejected for this release path.

Residual classification:
- `keep`: `realtime_gossipmesh.php`, `documentation/gossipmesh.md`, and the
  active GossipMesh contracts that preserve room/call/security guarantees.
- `reject for this sprint`: standalone browser client, direct P2P forwarding,
  process-local room identity, plaintext or transport-only security claims.

## `origin/experiments/1.0.7-voltron`

Decision: do not import Voltron into this SFU media sprint.

Reasoning:
- A real remote-tracking ref now exists, but its visible contract delta is
  distributed/model-inference/userland Voltron work: partitioning, GGUF loading,
  llama-fork/KV-cache transfer, runner smoke contracts, and model inference
  orchestration.
- That delta does not address the current video-chat blocker: SFU media frame
  transport, protected-media metadata, binary continuation receive, two-browser
  HD acceptance, or backend-authoritative room binding.
- Importing it here would broaden the sprint without improving the active SFU
  media contract.

Residual classification:
- `park`: AI/model-inference/userland Voltron work belongs to the model platform
  backlog, not this SFU media closure.
- `reject for this sprint`: any Voltron import into video-chat unless a future
  audit identifies a concrete video-chat/SFU contract delta with tests.
