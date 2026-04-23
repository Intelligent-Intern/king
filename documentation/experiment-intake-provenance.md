# Experiment Intake Provenance

Purpose:
- Keep contributor credit visible while selected experiment-branch work is ported into the current release branch.
- Avoid importing experiment artifacts, generated files, local machine paths, or weaker contracts just to preserve history.

Rules:
- Prefer `git cherry-pick -x` when a source commit can be ported without weakening current contracts.
- If a manual port is required, include the source commit hash in the commit body.
- Keep the original Git author visible when the port is materially based on a source commit.
- If the recorded author identity is later clarified, add a valid `Co-authored-by` trailer in the port commit.

## Q-13 IIBIN/Proto Batch And Varint Sources

Source range:
- `origin/experiments/v1.0.6-beta` through `4e58bef`, available locally through the experiment ancestry used for this sprint.

Recorded source commits:
- `3267785485ad61706170f9122f7af5997cc42202` - Alice-and-Bob `<sasha@MacBook-Pro-2.local>` - `perf: optimize varint encode with branchless algorithm`
- `a669b0964382e23eb316125132f59ff86cd42c71` - Alice-and-Bob `<sasha@MacBook-Pro-2.local>` - `perf: optimize varint decode with ARM64 unrolling`
- `e16af6f7e02f1826c11554dd68c49964bc7a7cd2` - Alice-and-Bob `<sasha@MacBook-Pro-2.local>` - `perf: consolidate float/double to shared header`
- `c9f6cf63986d770b72405ca1a494aaccc6f9a67e` - Alice-and-Bob `<sasha@MacBook-Pro-2.local>` - `perf: add batch encode to amortize PHP<->C boundary`
- `2914b0316e6138ec8a442d27b85b7d25e701ac22` - Alice-and-Bob `<sasha@MacBook-Pro-2.local>` - `perf: add batch decode to amortize PHP<->C boundary`
- `b6507fcc83a89d4b4770cce021efd0efbb8c81f9` - Alice-and-Bob `<sasha@MacBook-Pro-2.local>` - `bench: add batch encode/decode benchmarks`
- `8e0a539b837cd0e397b58528329c95f44c98e5cc` - Alice-and-Bob `<sasha@MacBook-Pro-2.local>` - `bench: update benchmarks with batch operations`
- `79df7a971ff10fe1d7a9bef64e0be63a4e9d2758` - Alice-and-Bob `<sasha@MacBook-Pro-2.local>` - `fixed king_proto_encode_varint; now batch processing`

Porting notes:
- Port code only after validating it against the current IIBIN/proto contracts.
- Do not carry generated benchmark results into the repo.
- Do not add public API surfaces until arginfo, stubs, function tables, docs, and PHPT coverage match.

Port status:
- `3267785485ad61706170f9122f7af5997cc42202` and `a669b0964382e23eb316125132f59ff86cd42c71` were reviewed for the varint port. The encode patch from `3267785` is not cherry-picked because its small multi-byte cases write non-canonical continuation bytes. The current port keeps the source context, ports the bounded/unrolled encode intent manually, and adds uint64 overflow-safe decode behavior without architecture-specific unaligned reads.
- The ARM64-specific varint decode unrolling from `a669b0964382e23eb316125132f59ff86cd42c71` remains out of the production path for now. The current production helper is architecture-neutral C with compiler-assisted length calculation where available. A future ARM64 helper needs a dedicated guard, benchmark, sanitizer coverage, and parity PHPT before it is enabled.
- `e16af6f7e02f1826c11554dd68c49964bc7a7cd2` was ported for float/double bit conversion consolidation: encode/decode now use the shared `iibin_internal.h` helpers instead of local duplicate helpers.
- `c9f6cf63986d770b72405ca1a494aaccc6f9a67e` and `2914b0316e6138ec8a442d27b85b7d25e701ac22` were reviewed for the public batch API. The stable public surface is ported as `king_proto_encode_batch()` and `king_proto_decode_batch()` plus `King\IIBIN::encodeBatch()` and `King\IIBIN::decodeBatch()`; it delegates to internal `king_iibin_encode_batch()` / `king_iibin_decode_batch()` helpers, pre-sizes output arrays, fails the whole batch on the first invalid record, and adds batch-index context while preserving the original lower-level exception as `previous`.
- `b6507fcc83a89d4b4770cce021efd0efbb8c81f9` and `8e0a539b837cd0e397b58528329c95f44c98e5cc` were reviewed for benchmark coverage. The standalone experiment script was not copied verbatim because the current tree has a canonical benchmark runner, budgets, docs, and result-hygiene rules. The useful intent is ported as clean source-only benchmark cases for batch encode/decode and varint-vs-Elias-omega comparison, with no generated result snapshots committed.
