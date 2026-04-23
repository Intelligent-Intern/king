# GossipMesh Runtime

GossipMesh is the video-chat topology and routing helper for the current King runtime path. It is not a standalone browser mesh, not a replacement for the SFU gateway, and not a public C/PHP extension surface yet.

The production contract is backend-authoritative:
- The backend owns room identity, call identity, admission, topology, relay
  choice, and downgrade policy.
- Browser code may consume assigned topology or relay hints only after `/sfu`
  has admitted the session for the bound `call_id` and `room_id`.
- Media/control payloads that leave `transport_only` must use the protected
  media transport envelope.
- The current runtime path is `wlvc_sfu`.

## Active Files

The active implementation is deliberately small:
- `demo/video-chat/backend-king-php/domain/realtime/realtime_gossipmesh.php`
  plans topology, validates protected envelopes, suppresses duplicates,
  selects forward targets, ranks relay candidates, and plans relay fallback.
- `demo/video-chat/backend-king-php/tests/realtime-gossipmesh-runtime-contract.php`
  proves the runtime behavior.
- `extension/tests/723-gossipmesh-compatible-runtime-port-contract.phpt`,
  `724-gossipmesh-frontend-client-decision-contract.phpt`,
  `725-gossipmesh-runtime-coverage-contract.phpt`, and
  `726-gossipmesh-production-doc-contract.phpt` pin the intake decisions and
  documentation boundary.

## Runtime Model

`videochat_gossipmesh_plan_topology()` accepts server-provided admitted members
and returns a bounded topology plan:
- `contract`: `king-video-chat-gossipmesh-runtime`
- `authority`: `server`
- `runtime_path`: `wlvc_sfu`
- `envelope_contract`: `king-video-chat-protected-media-transport-envelope`
- `call_id` and `room_id`
- `ttl`, `forward_count`, `members`, `topology`, `relay_candidates`, and
  `rejected_members`

The planner rejects members that are not admitted or that carry secret,
socket, network, SDP, ICE, or plaintext fields. The output is stable for a
given `call_id`, `room_id`, seed, and member set.

`videochat_gossipmesh_plan_message_route()` validates a protected transport envelope, applies duplicate suppression, honors TTL, selects bounded forward targets, and uses relay fallback when a direct target is marked failed and a safe relay candidate exists.

## SFU Constraints

The current `/sfu` gateway remains the production media-signaling entry point.
It admits a socket only after handshake validation, session auth, RBAC,
explicit `room_id` binding, optional normalized `call_id` binding, and either
current room membership or DB-backed participant admission.

Process-local `$sfuClients` and `$sfuRooms` are live socket indexes only. They
are not room identity, call identity, participant state, or admission state.
Client frames are decoded against the already-bound room, so browser code
cannot switch rooms or invent call state after the gateway admits a socket.

## Payload Protection

Transport security alone is not enough for this contract. WebSocket, TLS,
DTLS, SRTP, or DataChannel protection can only honestly describe a
`transport_only` state.

GossipMesh routing requires the same application-level payload boundary as the
video-chat media path:
- `envelope_contract` must be
  `king-video-chat-protected-media-transport-envelope`.
- `protected_frame` must be present, bounded, and base64url-shaped.
- `data`, raw media keys, shared secrets, decoded audio/video, plaintext
  frames, SDP, ICE candidates, sockets, IPs, and ports are rejected.
- Relay/SFU code may inspect bounded public metadata only. It must not decrypt
  or see plaintext media.

## Failure Behavior

The current contract fails closed:
- Missing `call_id` or `room_id` fails topology planning.
- Unknown publishers fail route planning.
- Missing protected-envelope contract fails routing.
- Legacy plaintext `data` fails routing.
- Duplicate frames are rejected and classified as duplicates.
- TTL `0` accepts local delivery but produces no forward targets.
- Failed direct targets require a safe relay candidate.
- If no relay candidate is available, route planning fails with
  `relay_unavailable`.

## What Is Not Active

The experiment branch included useful research, but these behaviors are not
active product semantics:
- Browser-created peer identity.
- Browser-created room or topology state.
- Browser-selected relay authority.
- Direct browser peer-to-peer media forwarding.
- Public STUN/TURN defaults baked into the client.
- Process-local SFU room maps as authority.
- JSON/plaintext downgrade for protected frames.
- Raw debug console behavior in the hot path.
- Importing `extension/src/gossip_mesh/*` as product API.

Any future WebRTC-native runtime must be specified separately under the same
backend-authoritative admission, revocation, rekey, relay, and downgrade-test
rules before it can leave research status.

## Frontend Integration

The experiment `gossip_mesh_client.js` is not ported. Compatible future client
behavior must be folded into
`demo/video-chat/frontend-vue/src/lib/sfu/sfuClient.ts`, because that client
already preserves backend-origin failover, `call_id`/`room_id` binding,
`protected_frame` carriage, `protection_mode` honesty, and current `sfu/frame`
parsing.

## Provenance

Contributor credit and source hashes for the experiment work are recorded in
`documentation/experiment-intake-provenance.md`. Material ports must continue
to preserve that context with `git cherry-pick -x` where possible or explicit
source-hash notes where a manual port is required.
