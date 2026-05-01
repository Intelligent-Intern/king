# Codec Merge Matrix: `origin/experiments/1.0.7-video-codec`

Purpose:
- Turn the branch comparison into a file-level merge decision list.
- Prevent accidental downgrade of the current transport, media-security, and runtime contract.
- Make cleanup explicit after codec-port work lands.

Legend:
- `keep`: keep the current branch implementation as the production contract
- `port`: bring ideas/code from the experiment branch into the current branch
- `merge carefully`: both sides matter; merge by semantic review, not file replacement
- `delete/superseded`: remove only after replacement is proven
- `not integrated`: exists in experiment branch, but not actually wired into runtime

## 1. Codec Core

| Area | Current file(s) | Experiment file(s) | Decision | Why |
| --- | --- | --- | --- | --- |
| WLVC TS codec header and payload semantics | `demo/video-chat/frontend-vue/src/lib/wavelet/codec.ts` | same path | `port` | Experiment branch has the stronger codec contract: header v2, richer metadata, blur-related fields. |
| Wavelet helpers | `demo/video-chat/frontend-vue/src/lib/wavelet/dwt.ts`, `quantize.ts`, `transform.ts`, `processor.ts`, `fast-codec.ts`, `index.ts` | same paths | `merge carefully` | These support the codec surface; pull only what is needed for header/config parity and verified runtime behavior. |
| Blur processor | current background/media pipeline elsewhere | `demo/video-chat/frontend-vue/src/lib/wavelet/blur-processor.ts` | `do not port now` | Current `BackgroundFilterController` + `mediaOrchestration` is the production path. Pulling in the experiment blur processor would duplicate preprocessing without replacing the real runtime contract. |
| Processor pipeline | current background/media pipeline elsewhere | `demo/video-chat/frontend-vue/src/lib/wavelet/processor-pipeline.ts` | `do not port now` | The experiment pipeline is not the production media path anymore. Keep the current background/media orchestration and avoid reviving a parallel blur/processor stack. |
| WebRTC shim | no direct equivalent as production contract | `demo/video-chat/frontend-vue/src/lib/wavelet/webrtc-shim.ts` | `evaluate, likely superseded` | Looks experimental; do not add another transport abstraction unless the current stack demonstrably needs it. |

## 2. WASM / Native Codec Surface

| Area | Current file(s) | Experiment file(s) | Decision | Why |
| --- | --- | --- | --- | --- |
| TS WASM wrapper | `demo/video-chat/frontend-vue/src/lib/wasm/wasm-codec.ts` | same path | `port` | Experiment branch exposes the richer codec configuration surface. |
| Native codec implementation | `demo/video-chat/frontend-vue/src/lib/wasm/cpp/codec.cpp`, `codec.h`, `exports.cpp` | same paths | `merge carefully` | Port constructor/config changes without breaking current ABI/runtime expectations. |
| Native helpers | `dwt.cpp`, `entropy.cpp`, `motion.cpp`, `quantize.cpp` and headers | same paths | `merge carefully` | Bring across only behavior required by the richer codec surface and confirmed by tests. |
| Built artifacts | `demo/video-chat/frontend-vue/src/lib/wasm/wlvc.js`, `wlvc.wasm`, `wlvc.d.ts` | same paths | `regenerate, do not cherry-pick blindly` | Built artifacts must match the merged source tree, not whichever branch produced them last. |

## 3. SFU Wire Transport

| Area | Current file(s) | Experiment file(s) | Decision | Why |
| --- | --- | --- | --- | --- |
| Main SFU client | `demo/video-chat/frontend-vue/src/lib/sfu/sfuClient.ts` | same path | `keep current, port codec hookups only` | Current branch has binary envelope, queueing, metrics, backpressure, protected-frame support; experiment branch is JSON-per-frame and weaker. |
| Binary frame envelope | `demo/video-chat/frontend-vue/src/lib/sfu/framePayload.ts` | none | `keep` | Stronger production transport contract. |
| Inbound assembly | `demo/video-chat/frontend-vue/src/lib/sfu/inboundFrameAssembler.ts` | none | `keep` | Required for binary/chunked inbound path. |
| Outbound queue | `demo/video-chat/frontend-vue/src/lib/sfu/outboundFrameQueue.ts` | none | `keep` | Required for real transport backpressure control. |
| Tile transport helpers | `demo/video-chat/frontend-vue/src/lib/sfu/selectiveTileTransport.ts`, `tilePatchMetadata.ts` | none | `keep pending proof` | Stay until the improved codec proves they are redundant. |
| Legacy JSON/base64 fallback in current client | contained in current `sfuClient.ts` | experiment branch uses JSON only | `delete/superseded after binary path is complete` | Current sprint explicitly removes fallback from hot path; do not inherit experiment JSON path. |

## 4. Backend SFU Store / Gateway

| Area | Current file(s) | Experiment file(s) | Decision | Why |
| --- | --- | --- | --- | --- |
| SFU store | `demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_store.php` | same path | `keep current, port codec metadata only if needed` | Current store supports binary frames, chunking, protected media, layout/tile metadata. |
| SFU gateway | `demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_gateway.php` | same path | `keep current, port codec metadata only if needed` | Current gateway is materially stronger than experiment JSON relay. |
| Signaling/runtime sidecars | `realtime_signaling.php`, `turn_ice.php`, `realtime_call_context.php`, `realtime_activity_layout.php` | same paths | `merge carefully` | Keep current contract; only accept experiment changes if they support codec merge without weakening runtime safety. |
| New diagnostics/runtime support | `client_diagnostics.php`, `realtime_asset_version.php`, `realtime_gossipmesh.php` on current branch | absent from experiment branch | `keep` | These are current runtime features/proof surface, not regressions. |

## 5. Media Security / Protected Media

| Area | Current file(s) | Experiment file(s) | Decision | Why |
| --- | --- | --- | --- | --- |
| Media security core | `demo/video-chat/frontend-vue/src/domain/realtime/media/security.js` | weaker/older branch state | `keep` | Current branch has the stronger protected-media contract. |
| Workspace media-security runtime | `demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/mediaSecurityRuntime.js` | absent/weaker | `keep` | Do not re-open handshake/runtime regressions while porting codec work. |
| Backend protected-frame parsing | `demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_store.php` | weaker/older | `keep` | Current branch is the only one with real protected-frame path breadth. |

## 6. Workspace / Runtime Structure

| Area | Current file(s) | Experiment file(s) | Decision | Why |
| --- | --- | --- | --- | --- |
| Modular call workspace | `demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/*` | absent in experiment branch | `keep` | Current branch already split the monolith; do not re-monolithize while merging codec changes. |
| Local media stack | `demo/video-chat/frontend-vue/src/domain/realtime/local/*` | older/weaker branch state | `keep, adapt codec integration points only` | Current stack reflects later fixes and modularization. |
| Native bridge stack | `demo/video-chat/frontend-vue/src/domain/realtime/native/*` | older/weaker branch state | `keep` | Current branch has later negotiation/recovery work and better separation. |
| SFU decode/runtime stack | `demo/video-chat/frontend-vue/src/domain/realtime/sfu/*` | older/weaker branch state | `keep, adapt codec/decode semantics only` | Current runtime structure is newer and more maintainable. |

## 7. IIBIN

| Area | Current file(s) | Experiment file(s) | Decision | Why |
| --- | --- | --- | --- | --- |
| IIBIN package | no current runtime use | `packages/iibin/**` | `not integrated` | Package exists, but video-chat runtime does not actually use it. |
| IIBIN docs/workflow | no equivalent runtime proof | `documentation/dev/iibin-package.md`, release workflow references | `keep for evaluation only` | Useful as package/provenance, not proof of runtime integration. |
| Video-chat transport migration to IIBIN | no proven branch implementation | claimed in docs only | `fresh task, not merge material` | Treat as future integration if still wanted after codec merge. |

## 8. Tests And Contracts

| Area | Current file(s) | Experiment file(s) | Decision | Why |
| --- | --- | --- | --- | --- |
| Codec-focused contracts | current: `wlvc-*` and runtime contracts; experiment: `wavelet-codec-contract.mjs`, `wavelet-pipeline-contract.mjs`, `blur-processor-contract.mjs` | experiment files under `demo/video-chat/frontend-vue/tests/contract/` | `port selectively` | Keep the genuinely useful codec/pipeline proof. |
| Current transport/runtime/media-security contracts | `media-security-contract.mjs`, `native-audio-bridge-contract.mjs`, `sfu-binary-tile-wire-contract.mjs`, `sfu-selective-tile-runtime-contract.mjs`, `sfu-transport-metrics-contract.mjs`, `wlvc-runtime-regression-contract.mjs`, backend contracts | weaker/absent in experiment branch | `keep` | Stronger proof surface must survive the merge. |
| Superseded experiment-only contracts | any experiment contract that proves JSON-only transport assumptions | experiment branch only | `delete/superseded after proof replacement` | Remove only after merged binary+codec path is covered. |
| Extension experiment-intake tests | `extension/tests/707-737` etc. | absent from experiment branch | `keep pending matrix review` | These document and protect the intake work; do not drop them casually. |

## 9. Immediate Execution Order

1. Port `wavelet/codec.ts` header/version/config semantics.
2. Port `wasm-codec.ts` and native constructor/config surface.
3. Regenerate WASM artifacts from merged source.
4. Adapt current SFU binary transport to the richer codec metadata; do not replace transport.
5. Port only the useful codec/pipeline tests.
6. Delete superseded fallback/tests only after merged-path proof is green.

## 10. Explicit Non-Goals

- Do not replace the current SFU transport with experiment JSON frame transport.
- Do not assume IIBIN is already integrated into video-chat runtime.
- Do not drop current media-security/runtime contracts because the experiment codec is better.
- Do not pull the old monolithic workspace/runtime structure back in.
