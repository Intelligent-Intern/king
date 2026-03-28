# King Issues

> This file is the single moving roadmap and execution queue for repo-local
> King v1.
> It distills `READYNESS_TRACKER.md` down to the next `20` executable leaves
> that are both still open and realistically implementable in this repository.
> Closed work lives in `PROJECT_ASSESSMENT.md`.
> `EPIC.md` stays the stable charter and release bar.

## Working Rules

- keep only open work here
- every item must be narrow enough to implement and verify inside this repo
- if a tracker item is still too broad, split it before adding it here
- when a leaf closes, update code, tests, docs, and `PROJECT_ASSESSMENT.md` in the same change
- do not shrink a meaningful v1 contract just to make tests, CI, or docs easier; if the intended contract matters, build the missing backend work or ask explicitly before reducing scope

## Current Next Leaf

- [ ] Validate throttling / rate-limit behavior for the real object-store `cloud_s3` backend.

## Active Executable Items

### 1. Compatibility And Release Confidence

1. [x] Add a release-artifact upgrade compatibility gate in CI.
   done when: a packaged artifact from the previous supported line is installed and smoke-tested against the current compatibility target automatically in CI.

2. [x] Add a release-artifact downgrade compatibility gate in CI.
   done when: the current packaged artifact is exercised against the previous supported compatibility target and regressions block release confidence.

3. [x] Add a persisted-state migration gate in CI.
   done when: representative persisted state is upgraded across supported versions and verified automatically instead of by local spot checks.

4. [x] Add an old/new configuration-state compatibility matrix.
   done when: the runtime is verified against representative old and new config snapshots and config drift becomes an explicit gate.

### 2. Transport And Listener Failure Depth

5. [x] Validate HTTP/1 bodiless responses against real servers.
   done when: `1xx`, `204`, `304`, and `HEAD` response behavior are exercised on-wire against real peers without body-length ambiguity.

6. [x] Validate HTTP/1 failure paths against real connection aborts.
   done when: mid-response connection close and early socket abort cases are reproduced on-wire and mapped to stable public failure behavior.

7. [x] Validate HTTP/2 session pooling under load.
   done when: repeated mixed-load request bursts prove reuse, fairness, and cleanup across pooled h2 sessions instead of one-shot multiplex-only slices.

8. [x] Validate HTTP/2 failure paths on stream reset and connection abort.
   done when: `RST_STREAM` and whole-connection teardown paths are exercised against real peers and surfaced through stable client semantics.

9. [x] Validate HTTP/3 failure paths on transport abort and handshake failure.
   done when: QUIC transport-close and handshake-failure cases are reproduced against real peers and mapped to stable runtime behavior.

10. [x] Validate HTTP/3 connection reuse and session-ticket behavior.
    done when: repeated direct and dispatcher requests prove reuse, ticket persistence, and healthy recovery instead of only one-shot success slices.

11. [x] Validate WebSocket protocol-violation handling on-wire.
    done when: malformed opcode, frame-shape, and close-sequence violations from real peers are rejected through stable public errors.

12. [x] Validate WebSocket network-abort behavior on-wire.
    done when: peer disconnect, half-close, and abrupt socket-loss cases are exercised without leaks, hangs, or corrupted follow-up sessions.

13. [x] Validate the HTTP/2 server listener as a real network listener.
    done when: server-side HTTP/2 listener setup, request handling, response flow, and shutdown semantics are proven against real clients.

14. [x] Validate the HTTP/3 server listener as a real network listener.
    done when: server-side HTTP/3 listener setup, request handling, response flow, and shutdown semantics are proven against real QUIC clients.

### 3. MCP And Orchestrator Durability

15. [x] Make MCP transfer identifiers permanently safe and collision-free.
    done when: transfer keys are encoded so path safety, separator ambiguity, and identifier collisions are impossible by construction.

16. [x] Persist and rehydrate MCP transfer state after restart.
    done when: queued local transfer state survives restart with verified lookup, download, and cleanup semantics instead of process-local only behavior.

17. [x] Finalize MCP error mapping across protocol, transport, and backend failures.
    done when: remote protocol failures, socket/timeout failures, and local backend failures land in stable, distinguishable public error classes.

18. [x] Implement pipeline continuation after process restart.
    done when: an interrupted orchestrator run can resume from persisted state after controller restart instead of stopping at snapshot recovery only.

### 4. Smart-DNS Distributed Correctness

19. [x] Validate Smart-DNS routing decisions against real load and health data.
    done when: route choice is proven against live health/load inputs rather than only registry-local score calculations.

20. [x] Validate Smart-DNS service and status updates under concurrent writes.
    done when: parallel registration/status churn preserves coherent discovery and routing results without stale or torn state.

## Next-Up Clusters After The Top 20

- telemetry cleanup, cross-request residue hardening, load bounds, self-metrics, and richer export diagnostics
- autoscaling decision logic under real load, live drain-before-delete, and automated post-bootstrap registration/readiness
- orchestrator continuation after host restart, richer error classification, multi-worker execution depth, and broader observability
- Smart-DNS split-brain, failure/recovery, and broader distributed-topology depth
- broader QUIC lifecycle, stats, resumption, and recovery validation beyond the current HTTP/3 client slices
- object-store cloud backend failure slices beyond the now-real `cloud_s3` payload path: throttling, migration, and recovery

## Notes

- The active queue now intentionally carries the next `20` executable leaves instead of a one-item placeholder list.
- Items still open in `READYNESS_TRACKER.md` but not listed here are either derivative of these leaves, already fenced honestly, or still too broad to be a useful execution item today.
- If an item is not listed here, it is not the current repo-local priority.
