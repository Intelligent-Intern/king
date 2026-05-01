# Branch Compare: `origin/experiments/1.0.7-video-codec`

## Scope

Compare target:

- current branch: `feature/1.0.7-beta-iibin-media-transport`
- experiment branch: `origin/experiments/1.0.7-video-codec`

Focus:

- video codec implementation
- WASM codec bindings
- SFU wire transport
- backend SFU storage/gateway behavior
- IIBIN usage vs documentation/package-only presence
- contracts/tests that would be superseded, weakened, or need porting

## Analysis Plan

1. Inventory file-level deltas for codec, IIBIN, SFU wire transport, backend realtime store/gateway, and contracts/tests.
2. Compare implementation semantics, not just filenames: frame format, chunking, IIBIN usage, session/security envelopes, and recovery/backpressure behavior.
3. Classify branch-only behavior into `keep`, `port`, `delete`, or `superseded` so merge work can remove dead experiments without weakening the strongest correct contract.

## High-Level Conclusion

`origin/experiments/1.0.7-video-codec` is not a clean "better codec branch" that can simply replace the current tree.

It contains three different classes of change:

1. **Real codec improvements worth porting**
2. **Transport/runtime regressions that must not replace the current stronger contract**
3. **A large unrelated UI/runtime reshaping that is older/weaker than the current modularized workspace**

The branch is useful as a **codec source branch**, not as a direct runtime replacement branch.

## Complete Difference List By Category

### 1. IIBIN

#### What exists in the experiment branch

- A standalone package exists at `packages/iibin`.
- Release workflow publishes `@intelligentintern/iibin`.
- Documentation repeatedly claims WebSocket + IIBIN and says the video chat transport payload format is `@intelligentintern/iibin`.

Evidence:

- `.github/workflows/release-merge-publish.yml`
- `packages/iibin/src/iibin.ts`
- `documentation/dev/iibin-package.md`
- `documentation/dev/video-chat.md`

#### What does **not** exist in the experiment branch video-chat runtime

No actual runtime integration was found in `demo/video-chat/frontend-vue` or `demo/video-chat/backend-king-php`:

- no frontend import of `@intelligentintern/iibin`
- no usage of `IIBINEncoder` / `IIBINDecoder`
- no backend usage of `King\IIBIN` or `king_proto_*` on the video-chat transport path

Result:

- **IIBIN is present as package + docs + publish pipeline**
- **IIBIN is not actually used by the video-chat runtime path in this branch**

#### Classification

- `keep`: package and docs for separate evaluation
- `do not assume integrated`: video-chat runtime does not prove IIBIN transport usage
- `follow-up`: if you want IIBIN on signaling/control/media metadata, that is still a fresh integration task

### 2. SFU Wire Transport

#### Experiment branch behavior

Frontend `sfuClient.ts` sends frames as JSON:

- `type: 'sfu/frame'`
- `data: Array.from(new Uint8Array(frame.data))`
- `this.ws.send(JSON.stringify(msg))`

Backend `realtime_sfu_gateway.php` relays JSON `sfu/frame` messages.

Backend `realtime_sfu_store.php` stores:

- `data_json TEXT NOT NULL`
- no binary frame envelope requirement
- no `data_blob`
- no binary transport decode path
- no chunk assembler for large payloads

#### Current branch behavior

Current branch has a much stronger transport stack:

Frontend:

- binary envelope support in `src/lib/sfu/framePayload.ts`
- inbound assembler in `src/lib/sfu/inboundFrameAssembler.ts`
- outbound queue in `src/lib/sfu/outboundFrameQueue.ts`
- `sfuClient.ts` supports
  - binary `KSFB` envelope
  - legacy JSON chunk fallback
  - send queue metrics
  - backpressure handling
  - protected-frame transport
  - layout/tile metadata

Backend:

- `realtime_sfu_store.php` supports
  - binary frame magic `KSFB`
  - envelope versioning
  - `data_blob BLOB`
  - binary encode/decode helpers
  - `sfu/frame-chunk`
  - protected-frame parsing
  - layout/tile metadata
- `realtime_sfu_gateway.php` supports
  - chunk reassembly
  - protected frame handling
  - payload validation and metadata checks

#### Difference summary

Experiment branch transport is:

- simpler
- easier to read
- significantly weaker on wire efficiency and metadata semantics

Current branch transport is:

- more complex
- more operationally correct
- closer to a real media transport contract

#### Classification

- `do not replace current transport with experiment transport`
- `keep current stronger transport contract`
- `port only codec-level improvements from the experiment branch`

### 3. Video Codec: TypeScript Wavelet Codec

File:

- experiment: `demo/video-chat/frontend-vue/src/lib/wavelet/codec.ts`
- current: [codec.ts](/home/jochen/projects/king.site/king/demo/video-chat/frontend-vue/src/lib/wavelet/codec.ts)

#### Common base

Both versions share:

- 2D separable Haar DWT
- YUV 4:2:0
- temporal residuals on Y channel
- RLE-based coefficient packing
- custom WLVC payload format

#### Experiment branch improvements over current

The experiment branch codec carries a richer on-wire header:

- version `2` instead of current version `1`
- header size `33` instead of `28`
- explicit fields for:
  - `wavelet_type`
  - `color_space`
  - `entropy_coding`
  - `flags` (`motion_estimation`, `blur_background`)
  - `blur_radius`

The experiment branch also documents and implements:

- DWT-based background blur support directly in codec metadata
- a wider codec configuration model intended to flow into the encoder/decoder stack

Current branch is missing those header fields and still exposes the older payload version.

#### What that means

This is a **real codec delta**, not cosmetic churn.

The experiment branch has the stronger codec contract here.

#### Classification

- `port`: WLVC payload/header v2 improvements
- `port`: codec metadata fields and blur-related framing semantics
- `keep current`: do not throw away the current transport/runtime stack just because codec header v2 is better

### 4. Video Codec: WASM Wrapper And Native Codec Surface

Files:

- experiment: `demo/video-chat/frontend-vue/src/lib/wasm/wasm-codec.ts`
- current: [wasm-codec.ts](/home/jochen/projects/king.site/king/demo/video-chat/frontend-vue/src/lib/wasm/wasm-codec.ts)
- experiment/current native code:
  - `src/lib/wasm/cpp/codec.cpp`
  - `src/lib/wasm/cpp/codec.h`
  - `src/lib/wasm/cpp/exports.cpp`
  - `src/lib/wasm/wlvc.wasm`

#### Experiment branch behavior

The experiment branch WASM wrapper exposes richer configuration:

- `waveletType`
- `entropyCoding`
- `dwtLevels`
- `colorSpace`
- `motionEstimation`
- `quality`
- `keyFrameInterval`

The wrapper passes these through to the native `Encoder` / `Decoder` constructors.

#### Current branch behavior

The current wrapper is narrower:

- width
- height
- quality
- keyFrameInterval

It does **not** pass through:

- wavelet type
- entropy mode
- DWT levels
- color space
- motion estimation

#### Meaning

The experiment branch native/WASM codec path is more expressive and closer to the intended codec surface.

#### Classification

- `port`: richer WASM/native codec configuration surface
- `verify`: native implementation parity and runtime stability after port
- `do not port blindly`: confirm constructor/ABI expectations in the generated WASM module

### 5. Blur / Video Processing Pipeline

Files added or changed in experiment branch:

- `src/lib/wavelet/blur-processor.ts`
- `src/lib/wavelet/processor-pipeline.ts`
- `src/lib/wavelet/webrtc-shim.ts`
- `src/lib/kalman/processor.ts`

Current branch has separate background-filter / compositor work plus custom transport/runtime coupling.

Experiment branch appears to push more of the blur/processing responsibility into the wavelet/codec-adjacent pipeline.

#### Classification

- `port selectively`: blur processor and pipeline ideas if they reduce duplicated pre/post-processing
- `verify against current background-filter runtime`: avoid regressing current device/runtime handling

### 6. Backend SFU Store / Gateway

Files:

- experiment: `realtime_sfu_store.php`, `realtime_sfu_gateway.php`
- current: [realtime_sfu_store.php](/home/jochen/projects/king.site/king/demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_store.php), [realtime_sfu_gateway.php](/home/jochen/projects/king.site/king/demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_gateway.php)

#### Experiment branch

- stores JSON payloads only
- no binary envelope persistence
- no chunk assembler
- no layout/tile metadata
- no protected-frame persistence model
- no `data_blob`

#### Current branch

- JSON + binary persistence path
- chunking
- protected-frame envelope handling
- layout/tile metadata
- extra validation and diagnostics
- replay path can emit binary envelope

#### Classification

- `keep current backend transport/store contract`
- `do not downgrade to experiment branch backend behavior`

### 7. Media Security / Protected Media

Experiment branch removes or lacks current media-security runtime breadth:

- current has substantial media-security logic in:
  - [security.js](/home/jochen/projects/king.site/king/demo/video-chat/frontend-vue/src/domain/realtime/media/security.js)
  - [mediaSecurityRuntime.js](/home/jochen/projects/king.site/king/demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/mediaSecurityRuntime.js)
  - backend protected-frame parsing in [realtime_sfu_store.php](/home/jochen/projects/king.site/king/demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_store.php)
- experiment branch deletes current media-security contract files and related test coverage

#### Classification

- `keep current protected-media/media-security contract`
- `do not let branch merge remove this proof surface`

### 8. Contracts And Tests

The experiment branch adds codec-focused tests:

- `blur-processor-contract.mjs`
- `wavelet-codec-contract.mjs`
- `wavelet-pipeline-contract.mjs`
- `sfu-multi-participant-render-contract.mjs`

But it removes many stronger current transport/runtime tests, including:

- `media-security-contract.mjs`
- `native-audio-bridge-contract.mjs`
- `sfu-binary-tile-wire-contract.mjs`
- `sfu-selective-tile-runtime-contract.mjs`
- `sfu-transport-metrics-contract.mjs`
- `wlvc-binding-recovery-contract.mjs`
- `wlvc-codec-port-contract.mjs`
- `wlvc-hybrid-fallback-contract.mjs`
- `wlvc-runtime-regression-contract.mjs`
- plus several reconnect/diagnostic/runtime contracts

#### Meaning

The experiment branch improves codec proof but weakens transport/runtime proof.

#### Classification

- `port`: new codec/pipeline contracts where still relevant
- `keep`: current stronger transport/runtime/media-security contracts
- `reconcile`: some current tests may need rewriting after codec merge, but not silent deletion

### 9. Workspace / UI / Runtime Structure

The experiment branch is not aligned with the current modularized workspace refactor.

Current branch now has extracted modules such as:

- [mediaStack.js](/home/jochen/projects/king.site/king/demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/mediaStack.js)
- [nativeStack.js](/home/jochen/projects/king.site/king/demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/nativeStack.js)
- [socketLifecycle.js](/home/jochen/projects/king.site/king/demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/socketLifecycle.js)
- [participantUi.js](/home/jochen/projects/king.site/king/demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/participantUi.js)
- [roomState.js](/home/jochen/projects/king.site/king/demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/roomState.js)

Experiment branch diff shows these modules as absent/deleted from that branch perspective.

#### Classification

- `keep current modular structure`
- `do not re-monolithize the workspace`

## Direct Merge Risk Assessment

### Safe to port

- WLVC payload/header v2 semantics
- richer WASM/native codec configuration
- blur processor / wavelet pipeline ideas
- wavelet-focused tests

### Unsafe to replace

- current SFU binary transport
- current protected-media / media-security path
- current chunking / backpressure / diagnostics
- current modularized workspace structure
- current stronger transport/runtime contracts

## Recommended Merge Strategy

1. **Do not merge the experiment branch wholesale.**
2. **Treat it as a codec donor branch.**
3. Port in this order:
   - codec TS header/version/config deltas
   - WASM/native codec parameter surface
   - blur / processor pipeline pieces
   - codec-focused tests
4. Keep the current transport/store/security/runtime contract and adapt it to the improved codec.
5. Re-run and update the current stronger test surface instead of deleting it.

## Bottom Line

### About the codec

Alexander's branch does appear to contain **real codec improvements**. That part is worth taking seriously.

### About IIBIN

The branch proves **IIBIN package work exists**, but **does not prove that video-chat currently runs on IIBIN transport**. Right now it looks like package/docs/release work, not actual runtime integration on the video path.

### About cleanup

Yes, a lot of the later transport experiments are likely superseded **at the codec layer**.
No, that does **not** mean the current stronger transport/store/security/runtime contracts should be thrown away.

The correct cleanup path is:

- keep the strongest correct runtime contract
- port the better codec pieces into it
- delete only the parts that become objectively redundant after that port
