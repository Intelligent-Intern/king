# King Issues

> This file is the single moving roadmap and execution queue for repo-local
> King v1.
> It distills `READYNESS_TRACKER.md` down to the next `20` executable leaves
> that are both still open and realistically implementable in this repository.
> Closed work lives in `PROJECT_ASSESSMENT.md`.
> `EPIC.md` stays the stable charter and release bar.

## Working Rules

- read `CONTRIBUTE.md` before starting, replenishing, or reshaping any `20`-issue batch
- keep only open work here
- every item must be narrow enough to implement and verify inside this repo
- if a tracker item is still too broad, split it before adding it here
- when a leaf closes, update code, tests, docs, and `PROJECT_ASSESSMENT.md` in the same change
- do not pull new items from `READYNESS_TRACKER.md` into this file unless the user explicitly asks for the next `20`-issue batch
- when the current batch is exhausted, stop and wait instead of refilling it automatically
- complete one checkbox per commit while this active batch is in flight
- do not shrink a meaningful v1 contract just to make tests, CI, or docs easier; if the intended contract matters, build the missing backend work or ask explicitly before reducing scope

## Current Next Leaf

- Validate Smart-DNS real on-wire DNS listener behavior.

## Active Executable Items

### 1. Transport And Listener Failure Depth

1. [x] Validate HTTP/3 early-data / session-ticket behavior.
   done when: resumed QUIC sessions prove honest early-data acceptance, rejection, and fallback semantics instead of silently collapsing all resumed traffic into the ordinary request path.

2. [x] Validate HTTP/3 retransmit / loss behavior under injected packet loss.
   done when: injected-loss peers prove bounded retransmit, recovery, and post-loss follow-up behavior instead of only timeout and transport-abort slices.

3. [x] Fully validate QUIC/TLS interaction across handshake, resumption, and live listener churn.
   done when: the runtime proves coherent TLS and QUIC behavior across fresh handshakes, resumed sessions, and churn-sensitive listener cycles instead of leaving the broader interaction model implicit.

4. [x] Validate `King\WebSocket\Server` shutdown and drain behavior.
   done when: live websocket server sessions drain, reject new work, and release runtime ownership cleanly under explicit shutdown without dangling peers or half-closed state.

5. [x] Validate server admin API auth / mTLS / reload / failure paths under real traffic.
   done when: the active admin listener proves authentication, mTLS gating, reload behavior, and failure reporting against real clients instead of only local marker slices.

### 2. Object-Store Provider Failure Normalization

6. [x] Classify provider quota-limit failures across real cloud backends.
   done when: real quota and exhaustion signals stay distinct from generic transport or credential failures across the active cloud adapters.

7. [x] Normalize quota and throttling failures across the public object-store surface.
   done when: reads, writes, deletes, and upload-session paths expose the same typed quota and backoff-worthy throttling story across `cloud_s3`, `cloud_gcs`, and `cloud_azure` instead of provider-specific string archaeology.

8. [x] Validate quota and rate-limit behavior across resumable upload recovery.
   done when: restart rehydration, append, complete, and abort preserve the same honest quota and throttling classification instead of degrading into generic backend failure during upload recovery.

### 3. Control Plane Distributed Depth

9. [x] Implement an MCP failover harness for real peer recovery scenarios.
   done when: the repo can inject and verify MCP peer loss, rejoin, and partial-topology breakage through one repeatable harness instead of one-off ad hoc tests.

10. [x] Implement an orchestrator failover harness for controller, worker, and remote-peer loss.
    done when: the repo can inject and verify orchestrator recovery across controller loss, worker loss, and remote-peer return without relying on hand-built scenario tests each time.

11. [x] Finalize orchestrator observability depth for distributed execution.
    done when: persisted run state and runtime introspection expose enough queue, claim, retry, recovery, and remote-step context to explain distributed outcomes without log archaeology.

12. [x] Finalize orchestrator compensation semantics where publicly claimed.
    done when: multi-step failure and retry paths preserve an explicit compensation contract instead of leaving rollback and cleanup behavior implicit or caller-guessable.

### 4. Smart-DNS Distributed Recovery

13. [ ] Validate Smart-DNS real on-wire DNS listener behavior.
    done when: the live DNS listener path is exercised on-wire with honest request, timeout, truncation, and recovery behavior instead of only the current bounded local query helper.

14. [ ] Validate Smart-DNS distributed recovery after stale-peer rejoin and partial durable-state loss.
    done when: a stale or partially reset node converges back to the current service and mother-node view without poisoning routing or discovery state.

15. [ ] Validate Smart-DNS distributed mother-node churn under concurrent re-election pressure.
    done when: concurrent mother-node departure, rejoin, and replacement preserve honest coordination counters and routing stability beyond the current local churn slice.

### 5. Telemetry And Fleet Operation Depth

16. [ ] Eliminate cross-request telemetry residue and prove cleanup under load.
    done when: telemetry state is cleaned up correctly across request and worker boundaries under sustained load without stale span or log carry-over or lifetime hazards.

17. [ ] Enforce telemetry memory bounds and self-metrics under degraded exporter load.
    done when: telemetry stays memory-bounded during collector slowdown or outage and exposes enough self-metrics to make queue growth, drops, and retry pressure observable.

18. [ ] Validate autoscaling decision logic under real load patterns.
    done when: scaling decisions are exercised against real load shapes and the runtime can explain why it scaled or held instead of only reacting to synthetic single-signal slices.

19. [ ] Validate autoscaling recovery after partial fleet-state loss and fresh-node bootstrap propagation.
    done when: autoscaling recovers coherently after partial fleet-state loss and newly provisioned nodes receive the expected runtime bootstrap and registration state automatically.

20. [ ] Refresh repo-root markdown after this batch closes.
    done when: `README.md`, `PROJECT_ASSESSMENT.md`, `READYNESS_TRACKER.md`, `CONTRIBUTE.md`, and `ISSUES.md` match the verified post-batch runtime without stale open/closed drift.

## Next-Up Clusters After The Top 20

- broader websocket server runtime materialization beyond the drain-focused failure proof in this batch
- deeper object-store cross-backend failure normalization beyond quota/throttling and resumable-upload recovery
- broader Smart-DNS distributed-topology validation once stale-peer, partial-loss, and re-election pressure are proven
- longer-haul telemetry exporter ordering and autoscaling fleet-behavior proof beyond the bounded cleanup, load, and recovery leaves in this batch
- release, compatibility, supply-chain, and final security-review closure that remain outside this explicitly requested repo-local sprint batch

## Notes

- The active queue is administered in explicit `20`-issue batches, not by automatic replenishment from `READYNESS_TRACKER.md`.
- This batch was pulled explicitly from the still-open caveats in `PROJECT_ASSESSMENT.md`.
- When the current batch is exhausted, work stops until the next `20`-issue batch is explicitly pulled in.
- The active queue intentionally carries the next `20` open executable leaves, not a historical list of already-closed wins.
- Items still open in `READYNESS_TRACKER.md` but not listed here are either derivative of these leaves, already fenced honestly, or still too broad to be a useful execution item today.
- If an item is not listed here, it is not the current repo-local priority.
