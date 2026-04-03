# King Issues

> This file is the single moving roadmap and execution queue for repo-local
> King v1.
> It only carries the currently open executable batch.
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
- complete one checkbox per commit while an active batch is in flight
- do not shrink a meaningful v1 contract just to make tests, CI, or docs easier; if the intended contract matters, build the missing backend work or ask explicitly before reducing scope

## Current Next Leaf

- `#11 Finalize QUIC error mapping across transport, TLS, HTTP/3, timeout, and cancellation failures.`

## Active Executable Items

- [x] `#1 Validate QUIC connection handshake, open, drain, and close lifecycle against real peers.`
- [x] `#2 Validate QUIC idle-timeout and application-close propagation against real peers.`
- [x] `#3 Validate QUIC stream open, body, finish, and read-drain lifecycle against real peers.`
- [x] `#4 Validate QUIC reset and stop-sending lifecycle against real peers.`
- [x] `#5 Validate QUIC userland cancel propagation into active transport state.`
- [x] `#6 Validate QUIC remote abort and transport-close mapping into public exceptions.`
- [x] `#7 Validate QUIC poll/event-loop wake, idle, and timeout behavior under sustained runtime.`
- [x] `#8 Validate QUIC congestion-control behavior under sustained constrained links.`
- [x] `#9 Validate QUIC flow-control exhaustion and recovery behavior under sustained streams.`
- [x] `#10 Validate QUIC zero-RTT acceptance and fallback against real peers.`
- [ ] `#11 Finalize QUIC error mapping across transport, TLS, HTTP/3, timeout, and cancellation failures.`
- [ ] `#12 Validate QUIC stats fields against live runtime counters and peer-observed state.`
- [ ] `#13 Validate QUIC recovery after temporary network interruption and socket re-wake.`
- [ ] `#14 Back server-upgrade WebSocket resources with honest bidirectional frame I/O.`
- [ ] `#15 Back King\WebSocket\Server listen/accept lifecycle with fully real runtime behavior.`
- [ ] `#16 Back King\WebSocket\Server connection registry and targeted send semantics with real runtime behavior.`
- [ ] `#17 Back King\WebSocket\Server broadcast and shutdown semantics with real live connections.`
- [ ] `#18 Validate WebSocket upgrade on HTTP/2 where the public docs or surface claim it.`
- [ ] `#19 Validate WebSocket upgrade on HTTP/3 where the public docs or surface claim it.`
- [ ] `#20 Validate WebSocket resource cleanup across request boundaries and worker reuse.`

## Notes

- This batch was pulled explicitly from `READYNESS_TRACKER.md`.
- It is intentionally ordered as: QUIC runtime truth first, then WebSocket server/runtime truth.
- The previous explicit `20`-issue batch is closed and rolled into `PROJECT_ASSESSMENT.md`.
- If a task is not listed here, it is not the current repo-local execution item.
