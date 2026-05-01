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

## Parked After 1.0.7 SFU Media Closure

1. [ ] Decide whether topology observability (`#Q-31`) is still needed for `1.0.7-beta` or can stay parked until the next beta.
2. [ ] Selective tile/background transport survived the online HD gate; evaluate a second-pass ROI optimization after release instead of changing the current proven heuristics now.
3. [ ] The binary media envelope is proven by the online HD gate; revisit long-term packet/header compaction after `1.0.7-beta`, not during the current release closure.
4. [ ] The native King PHP IIBIN SFU control/metadata boundary is proven; plan deeper runtime integration only after the shipped media path remains stable.
5. [ ] Do a second cleanup pass over superseded experiment artifacts after the `1.0.7` closure is merged.

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
