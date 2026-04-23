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
