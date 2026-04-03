# King Issues

> This file is the single moving roadmap and execution queue for repo-local
> King v1.
> It carries the currently active executable batch, including leaves already
> closed inside that batch.
> Closed work lives in `PROJECT_ASSESSMENT.md`.
> `EPIC.md` stays the stable charter and release bar.

## Working Rules

- read `CONTRIBUTE.md` before starting, replenishing, or reshaping any `20`-issue batch
- keep the active batch visible here until it is exhausted; mark closed leaves as `[x]` instead of deleting them mid-batch
- every item must be narrow enough to implement and verify inside this repo
- if a tracker item is still too broad, split it before adding it here
- when a leaf closes, update code, touched comments/docblocks, tests, docs, `PROJECT_ASSESSMENT.md`, and `READYNESS_TRACKER.md` in the same change
- when a leaf closes, also verify the affected runtime with the strongest relevant tests/harnesses available before committing
- when a leaf closes, make exactly one commit for that checkbox; do not batch multiple checkbox closures into one commit
- do not pull new items from `READYNESS_TRACKER.md` into this file unless the user explicitly asks for the next `20`-issue batch or enables continuous batch execution
- when the current batch is exhausted, stop and wait instead of refilling it automatically unless continuous batch execution is explicitly enabled
- complete one checkbox per commit while an active batch is in flight
- do not shrink a meaningful v1 contract just to make tests, CI, or docs easier; if the intended contract matters, build the missing backend work or ask explicitly before reducing scope
- before opening, updating, or marking a PR ready, clear all outstanding GitHub AI findings for this repo at `https://github.com/Intelligent-Intern/king/security/quality/ai-findings`

## Per-Issue Closure Checklist

- update the runtime/backend code needed for the leaf
- update any touched comments, docblocks, headers, and contract wording so code and prose stay aligned
- add or tighten tests that prove the leaf on the strongest honest runtime path available
- update repo docs affected by the leaf
- update `PROJECT_ASSESSMENT.md`
- update `READYNESS_TRACKER.md`
- run the strongest relevant verification available for that leaf before committing
- make exactly one commit for the checkbox
- before any PR refresh or release-candidate handoff, re-check `https://github.com/Intelligent-Intern/king/security/quality/ai-findings` and fix every outstanding finding on the branch

## Batch Mode

- The user has explicitly requested continuous execution across batches.
- When the current `20`-issue batch is exhausted, immediately pull the next `20` executable leaves from `READYNESS_TRACKER.md` into this file instead of waiting.
- Keep preserving tracker order and split broad items into repo-local executable leaves before adding them here.

## Current Next Leaf

- `#15 Validate origin timeout / retry behavior.`

## Active Executable Items

- [x] `#1 Validate HTTP/1 header normalization under real traffic.`
- [x] `#2 Validate server-side Early Hints on-wire.`
- [x] `#3 Validate server TLS reload under live traffic.`
- [x] `#4 Validate server CORS and header behavior against real clients.`
- [x] `#5 Validate server multi-connection scheduling under load.`
- [x] `#6 Validate server fairness across competing clients.`
- [x] `#7 Validate server resource cleanup under crash and abort scenarios.`
- [x] `#8 Build end-to-end multi-host harness.`
- [x] `#9 Validate CDN cache paths against real object-store backends.`
- [x] `#10 Validate cache fill on miss against real backends.`
- [x] `#11 Validate cache invalidation under load.`
- [x] `#12 Validate stale-serve-on-error against real backend failures.`
- [x] `#13 Validate cache consistency after backend update.`
- [x] `#14 Validate edge-node inventory against real nodes where publicly claimed.`
- [ ] `#15 Validate origin timeout / retry behavior.`
- [ ] `#16 Validate cache memory limits under load.`
- [ ] `#17 Validate large objects in cache under memory pressure.`
- [ ] `#18 Validate cache recovery after restart.`
- [ ] `#19 Finalize cache metrics and observability.`
- [ ] `#20 Validate service registration against real distributed topology.`

## Notes

- This batch was pulled explicitly from `READYNESS_TRACKER.md`.
- This active wave keeps completed leaves visible instead of collapsing to only-open work.
- `#1` through `#10` are already closed and merged; they remain listed here so the running wave stays readable.
- It is intentionally ordered as: transport/server truth first, then CDN/object-store truth, then the next distributed Smart-DNS gap.
- If a task is not listed here, it is not the current repo-local execution item.
