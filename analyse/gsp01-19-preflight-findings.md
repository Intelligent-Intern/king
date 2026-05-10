# GSP01-19 Preflight Contract Inventory

Worker: GSP01-19B
Branch: `kingrt/gsp01-19-preflight-contracts`
Base checked: local `kingrt/prod-ready` ancestor `6275c398`
Date: 2026-05-10

## Scope

Ran the focused local Gossip, strict 720p30, screenshare, and capability
contract slice. No deploy, DNS, certbot, push, production mutation, or
prod-debug run was performed.

## Initial Failure Classes

1. Stale contract evidence checks still referenced retired `GSP-*` sprint
   numbers instead of active `GSP01-*` sprint proof.
2. `gossip_primary` tests still expected SFU fallback/mirroring after Gossip
   publication failure. Active GSP01 requires no SFU fallback in the stream
   path.
3. Stale target pruning contract still expected MediaSecurity repair keyframe
   behavior. Active GSP01 parks MediaSecurity as diagnostics and must not force
   repair loops into the Gossip stream.
4. Native binary/docs contracts required root `GOSSIP_CURRENT_BUILD.md` and
   `GOSSIP_PLANNING.md`; those files are now archived under
   `documentation/archive/root-md-2026-05-10/`.
5. At the worker's base commit, the active `test:contract:gossip` script still
   included the old
   `kingrt-three-user-regression-harness-contract.mjs`, which depends on
   Background/SFU regression planning and retired root Gossip docs. That is not
   an active Gossip v1 release gate.
6. At the worker's base commit, local build failed outside this task scope in
   `demo/video-chat/frontend-vue/src/domain/calls/access/callAccessSession.ts`
   because `hostName` is declared twice.

## Fixes Made

- Replaced stale `GSP-*` assertions with active GSP01 proof anchors.
- Updated Gossip-primary contracts to require
  `gossip_primary_publish_failed_no_sfu_fallback` and `sfuFallbackSuppressed`.
- Updated the media carrier smoke to prove `gossip_primary` never calls SFU
  fallback/mirror paths.
- Updated stale target pruning contract to prove pruning diagnostics/state
  cleanup without forced MediaSecurity keyframes.
- Pointed retired Gossip docs process checks at the archived root docs and
  asserted the files do not return to the repo root.
- The final integrated branch uses the newer GSP01-18 gate-cleanup result,
  which replaces the old three-user Background/SFU regression harness in
  `test:contract:gossip` with the current compact Gossip v1 proof set including
  `gsp01-18-gossip-primary-plan-frame-contract.mjs`.
- The duplicate `hostName` build blocker was fixed later in commit `50176df2`;
  `npm run build` passes on `kingrt/prod-ready`.

## Verification

Passing:

- `npm run test:contract:gossip`
- `npm run test:contract:vcap:capability-media-plan`
- `npm run test:contract:strict-720p30`
- `npm run test:contract:screenshare-fullscreen`
- `php demo/video-chat/backend-king-php/tests/media-capability-plan-gossip-contract.php`

Resolved on integration:

- `npm run build` previously failed on duplicate `hostName` declarations in
  `src/domain/calls/access/callAccessSession.ts`; commit `50176df2` removed the
  duplicate declaration and the build passes on `kingrt/prod-ready`.
