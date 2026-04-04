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

- `#4 Add a public userland handler-registration API that binds a runtime handler to a registered orchestrator tool name.`

## Active Executable Items

- [x] `#1 Define the public userland tool-handler contract for application workflows on top of the pipeline orchestrator.`
- [x] `#2 Define the exact handler-identity and re-registration contract across local, file-worker, remote-peer, and restart boundaries.`
- [x] `#3 Reject unsupported non-rehydratable userland handler forms honestly instead of pretending closures survive restart or host boundaries.`
- [ ] `#4 Add a public userland handler-registration API that binds a runtime handler to a registered orchestrator tool name.`
- [ ] `#5 Execute registered userland handlers on the local orchestrator backend with persisted run-state parity.`
- [ ] `#6 Pass step input, tool config, run metadata, and step metadata into local userland handler execution with an explicit result contract.`
- [ ] `#7 Persist the durable handler-reference boundary needed for queued runs without serializing arbitrary PHP callables into state.`
- [ ] `#8 Rehydrate and validate handler readiness before file-worker claim or resume instead of failing late inside opaque worker execution.`
- [ ] `#9 Execute registered userland handlers on the file-worker backend after controller and worker restart under the explicit re-registration contract.`
- [ ] `#10 Define and implement the remote-peer userland handler contract without pretending controller memory crosses the TCP execution boundary.`
- [ ] `#11 Classify validation, runtime, timeout, cancellation, backend, and missing-handler failures for userland-backed orchestrator steps at step and run scope.`
- [ ] `#12 Propagate cancel, deadline, and timeout control into active userland handler execution wherever the public contract claims it.`
- [ ] `#13 Preserve completed-step, compensation, and terminal-state visibility for multi-step runs backed by userland handlers.`
- [ ] `#14 Expose userland handler readiness, missing-handler state, and active handler-contract metadata through orchestrator component status and inspection surfaces.`
- [ ] `#15 Add PHPT proof for local userland tool execution over a persisted run snapshot.`
- [ ] `#16 Add PHPT proof for file-worker userland tool execution with re-registration across processes.`
- [ ] `#17 Add PHPT proof for restart recovery when a queued or running userland-backed run outlives the original controller process.`
- [ ] `#18 Add PHPT proof for remote-peer userland tool execution or fail closed explicitly on unsupported remote-peer handler topologies.`
- [ ] `#19 Add handbook and procedural-API documentation for the userland tool-handler contract, including unsupported forms and restart duties.`
- [ ] `#20 Update PROJECT_ASSESSMENT.md and READYNESS_TRACKER.md once the userland orchestration surface is real, verified, and no longer caveated.`

## Deferred Previous Batch

- [ ] `#1 Validate autoscaling CPU / memory / RPS / queue / latency signals under real operation.`
- [ ] `#2 Validate autoscaling drain-before-delete under real live connections.`
- [ ] `#3 Validate autoscaling scale-up policy limits under burst load.`
- [ ] `#4 Validate autoscaling scale-down policy limits under active traffic.`
- [ ] `#5 Finalize autoscaling metrics and decision explanations.`
- [ ] `#6 Define the real Hetzner-only production contract and remove placeholder provider expectations from the live provisioning path.`
- [ ] `#7 Implement automated post-bootstrap node registration on the Hetzner path.`
- [ ] `#8 Make post-bootstrap node registration robust across retry, restart, and duplicate-success cases.`
- [ ] `#9 Implement automated post-bootstrap node readiness on the Hetzner path.`
- [ ] `#10 Make post-bootstrap node readiness robust across retry, restart, and duplicate-success cases.`
- [ ] `#11 Validate Hetzner node drain before delete under real traffic.`
- [ ] `#12 Make Hetzner node delete robust under failures and timeouts.`
- [ ] `#13 Classify and handle Hetzner provider API failures.`
- [ ] `#14 Classify and handle Hetzner provider rate limits.`
- [ ] `#15 Classify and handle Hetzner provider quota limits.`
- [ ] `#16 Securely load and rotate provider credentials where publicly claimed.`
- [ ] `#17 Validate firewalls, placement, labels, and networks against real provider APIs.`
- [ ] `#18 Either drop non-Hetzner provider claims from docs and surface or implement a second real provider path.`
- [ ] `#19 Remove or fully implement remaining simulated provider paths.`
- [ ] `#20 Define system-wide readiness transitions across startup, drain, and autoscaling boundaries.`

## Notes

- The previous telemetry wave is exhausted; its closed leaves now live in `PROJECT_ASSESSMENT.md`.
- This new active batch is an explicit user-priority override because current application-workflow work now depends on a real public userland orchestrator surface.
- The new active batch takes the next `20` leaves because `ISSUES.md` is organized as a `20`-issue execution batch and the user explicitly requested that this gap move ahead of the existing autoscaling wave.
- This userland orchestration batch is grounded in the open userland-facing integration direction in `READYNESS_TRACKER.md` section `Q`, but is narrowed here to the immediately blocking public orchestrator gap around application tool execution and recovery.
- Leaf `#1` is now closed by the contract-definition pass across the public orchestrator docs, procedural index, stub docblocks, and root status documents.
- Leaf `#2` is now closed by the identity/re-registration pass across the public orchestrator docs, procedural index, stub docblocks, and root status documents.
- Leaf `#3` is now closed by the fail-closed pass across the public orchestrator docs, procedural index, stub docblocks, and root status documents.
- The autoscaling / provisioning / readiness wave remains visible below as the deferred previous batch and resumes once the current userland orchestration batch is exhausted.
- If a task is not listed here, it is not the current repo-local execution item.
