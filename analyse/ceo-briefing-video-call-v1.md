# CEO Briefing: Video Call v1

Stand: 2026-05-10

## Summary

KingRT is not release-ready yet. The blocker is the video-call media runtime.
The product has many strong components, but the active media path is overloaded:
SFU, Gossip, MediaSecurity, Background, reconnect recovery, keyframe recovery,
backpressure handling and quality experiments are all still visible in the call
runtime.

The immediate v1 goal should be a small, observable streaming contract:
capabilities in, orchestrated state out, then 720p30 keyframes and deltas over
the selected gossip path. Failed frames should be logged and the stream should
continue. Backpressure should reduce cadence once or twice, then stop sending
and expose the reason. No hidden fallback should mask the actual failure.

## Readiness

| Area | Status | Reason |
| --- | --- | --- |
| Auth, calls, lobby | Yellow | Broad implementation exists; guest/lobby behavior still needs v1 proof. |
| Call apps | Yellow | Packages are in the right folder and runtime exists; diagnostics/audit gaps remain. |
| Diagnostics | Yellow | Good data surface, but not yet a clean readiness gate. |
| Video media runtime | Red | Too many competing media paths and automatic recovery behaviors. |
| Tests | Red/Yellow | Many tests still pin SFU/Gossip/Background/regression behavior. |
| Ops/deploy scripts | Yellow | Useful smoke tooling exists, but not a release gate. |

## Main Finding

The call does not currently have one source of truth for media state. There are
multiple producers and repair paths:

- legacy and rich `room/snapshot` payloads,
- best-effort capability ingestion,
- derived media session plans,
- independent SFU admission/publish behavior,
- Gossip topology and relay behavior outside the plan,
- MediaSecurity participant-set recovery in the active path,
- background/focus/reconnect logic that can change transport behavior.

## CEO Decision Needed

For v1, do not fund more fallback behavior. Fund the hard contract:

1. capability exchange,
2. backend orchestrator,
3. canonical call media state,
4. one active streaming path,
5. clear failure states.

Everything else is parked until it can plug into that contract without becoming
an independent recovery system.

## Practical Next Sprint

The next sprint should be "Video Call v1 State Contract":

- one `room/snapshot`,
- durable capability store,
- monotonic media-plan epoch,
- explicit participant media states,
- no active SFU fallback,
- no background-tab media policy,
- no auto-quality/regression repair gate,
- backpressure ladder: pause, 50 percent, 25 percent, then stuck with reason.

