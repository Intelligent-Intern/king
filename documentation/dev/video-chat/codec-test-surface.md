# Codec Test Surface Reconciliation

Purpose:
- Keep the current stronger transport/runtime/media-security proof surface.
- Port only codec-focused checks from `origin/experiments/1.0.7-video-codec` that still match the current architecture.
- Explicitly reject tests that prove an obsolete pipeline or transport shape.

## Keep As Production Contracts

- `demo/video-chat/frontend-vue/tests/contract/media-security-contract.mjs`
- `demo/video-chat/frontend-vue/tests/contract/native-audio-bridge-contract.mjs`
- `demo/video-chat/frontend-vue/tests/contract/sfu-binary-tile-wire-contract.mjs`
- `demo/video-chat/frontend-vue/tests/contract/sfu-selective-tile-runtime-contract.mjs`
- `demo/video-chat/frontend-vue/tests/contract/sfu-selective-tile-value-contract.mjs`
- `demo/video-chat/frontend-vue/tests/contract/sfu-tile-cache-generation-contract.mjs`
- `demo/video-chat/frontend-vue/tests/contract/sfu-transport-metrics-contract.mjs`
- `demo/video-chat/frontend-vue/tests/contract/sfu-origin-room-binding-contract.mjs`
- `demo/video-chat/frontend-vue/tests/contract/wlvc-codec-port-contract.mjs`
- `demo/video-chat/frontend-vue/tests/contract/wlvc-binding-recovery-contract.mjs`
- `demo/video-chat/frontend-vue/tests/contract/wlvc-hybrid-fallback-contract.mjs`
- `demo/video-chat/backend-king-php/tests/realtime-sfu-contract.php`
- `demo/video-chat/backend-king-php/tests/wlvc-wire-contract.php`

Reason:
- These prove the stronger merged contract: binary transport, protected media, runtime fallback, tile/cache correctness, and backend relay safety.

## Port From `experiments/1.0.7-video-codec`

- `wavelet-codec-contract.mjs` -> ported as `wavelet-codec-header-contract.mjs`

Reason:
- This is the useful codec-only proof from the experiment branch.
- It validates the header-v2 layout, byte offsets, and decode-side parsing assumptions without dragging back the weaker transport/runtime stack.

## Do Not Port As-Is

- `wavelet-pipeline-contract.mjs`
- `blur-processor-contract.mjs`

Reason:
- The experiment branch proved a different local preprocessing pipeline:
  - `blur-processor.ts`
  - `processor-pipeline.ts` with `BackgroundBlurProcessor`
- The current branch does not use that exact production path anymore.
- Porting those tests verbatim would prove an obsolete implementation shape rather than the current merged contract.

## Replacement Rule

- If blur/background preprocessing becomes part of the merged production path again, add new contracts against the current modules.
- Do not reintroduce experiment-era tests that pin to files or classes that no longer define the runtime contract.
