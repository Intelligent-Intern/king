# King Backlog

Purpose:
- This file is the parked and future backlog only.
- `SPRINT.md` is the only list of active top-priority work.
- `READYNESS_TRACKER.md` is the completion log.
- Historical detail stays in git history, not in this file.

Rules:
- Do not duplicate active sprint items here.
- Do not keep completed items here.
- Do not weaken the strongest correct King v1 contract to simplify cleanup.
- If an item becomes release-critical, move it into `SPRINT.md` and remove it from this file.

## Parked After Current Codec Merge Sprint

1. [ ] After the codec merge stabilizes, decide whether topology observability (`#Q-31`) is still needed for `1.0.7-beta` or can stay parked until the next beta.
2. [ ] If selective tile/segmentation survives the merged codec path, evaluate a second-pass optimization for smarter ROI selection instead of keeping today's heuristics by default.
3. [ ] Revisit long-term packet/header compaction after the binary media envelope is proven stable; do not churn the wire contract again during the current merge.
4. [ ] If IIBIN is proven useful after the branch merge audit, plan a real runtime integration path instead of keeping package-only experiment code around.
5. [ ] Do a second cleanup pass over superseded experiment artifacts only after the keep/port/delete matrix is complete and merged.

### #Q-19 Video-Chat Admin Operations And Production Deploy Readiness

- Compatibility anchor for existing smoke/deployment contracts.
- Active release work lives in `SPRINT.md`.
- Completion evidence and rollout history live in `READYNESS_TRACKER.md`.
- If new production-readiness work becomes active again, move it into `SPRINT.md` instead of expanding this parked section.
- Keep Hetzner-specific discovery behind provider abstractions.
- Correct live call and participant counts.
- Ensure a fresh production deploy is repeatable.

## AI / SLM / Fine-Tuning Platform (`#149`)

1. [ ] Distributed model placement and inference execution.
2. [ ] Prompt, cache, and checkpoint persistence.
3. [ ] Fine-tuning and training-data workflows.
4. [ ] Advanced model extensions.

## Future Product Work / MarketView (`#150`)

1. [ ] MarketView product boundary and data contract.
2. [ ] Market feed, aggregation, and fanout.
3. [ ] MarketView frontend UX.
4. [ ] Paper trading flow.
5. [ ] MarketView packaging and operations.

## Cleanup Notes

- Old batch items from the previous backlog were removed because they were either completed, replaced by the new active sprint, or too stale to keep as live backlog entries.
- If a removed item still matters, restore it with a current problem statement and evidence instead of reintroducing old checklist archaeology.
