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

- `#4 Implement incoming trace-context extraction on HTTP server requests.`

## Active Executable Items

- [x] `#1 Validate span lifecycle under sustained request and worker churn.`
- [x] `#2 Validate metric lifecycle under sustained request and worker churn.`
- [x] `#3 Validate log lifecycle under sustained request and worker churn.`
- [ ] `#4 Implement incoming trace-context extraction on HTTP server requests.`
- [ ] `#5 Propagate extracted incoming trace context into request-root spans and child work.`
- [ ] `#6 Finalize outgoing trace-context injection on HTTP client transports.`
- [ ] `#7 Preserve span hierarchies correctly across process and worker boundaries.`
- [ ] `#8 Finalize telemetry sampling semantics where sampling is publicly claimed.`
- [ ] `#9 Monitor and bound telemetry CPU cost under load.`
- [ ] `#10 Enforce OTLP request-size limits before exporter dispatch.`
- [ ] `#11 Enforce OTLP response-size handling against real collectors.`
- [ ] `#12 Finalize permanent network failure behavior for telemetry exporters.`
- [ ] `#13 Implement telemetry queue replay after process restart where the delivery contract requires it.`
- [ ] `#14 Define export ordering guarantees across queued telemetry batches.`
- [ ] `#15 Define exporter idempotency behavior across retry and replay paths.`
- [ ] `#16 Finalize telemetry batch formation under mixed span, metric, and log pressure.`
- [ ] `#17 Validate OTLP JSON payloads against reference collectors.`
- [ ] `#18 Provide complete export failure diagnostics across transport, TLS, HTTP, and collector errors.`
- [ ] `#19 Finalize telemetry export endpoint and credential security boundaries.`
- [ ] `#20 Implement the pipeline telemetry adapter contract for run, partition, batch, retry, and failure identity.`

## Notes

- This batch was pulled explicitly from `READYNESS_TRACKER.md`.
- This telemetry wave replaces the exhausted HTTP/CDN/discovery wave after the user explicitly requested the next executable batch.
- This batch pulls every currently open telemetry-related tracker item from `READYNESS_TRACKER.md` sections `J`, `K`, and the open dataflow telemetry-adapter contract.
- Keep this wave visible until the telemetry batch is exhausted; do not refill it with non-telemetry work mid-flight.
- If a task is not listed here, it is not the current repo-local execution item.
