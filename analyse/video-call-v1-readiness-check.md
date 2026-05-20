# Video Call v1 Readiness Check

Stand: 2026-05-10

## Verdict

Not ready. The codebase has enough pieces to build v1, but the current runtime
is not a clean v1 streaming system.

## Readiness Checklist

| Check | Status | Evidence |
| --- | --- | --- |
| Every demo file inventoried | Done | `demo-program-file-inventory.tsv` covers 5,419 files. |
| Generated/vendor files separated | Done | `node_modules`, `dist`, `test-results`, `.local`, public WASM/vendor assets classified separately. |
| Call apps in canonical source folder | Done | `demo/call-apps/*` packages exist and package-layout contract passed. |
| One authoritative room snapshot | Missing | Legacy snapshot and rich snapshot both exist. |
| Capabilities are backend-visible | Partial | Capability frame exists, persistence is best-effort. |
| Orchestrator owns media state | Missing | Plan is derived on snapshot read, not a durable state machine. |
| 720p30 is the active target | Partial | Strict constants exist, but auto-quality profiles and SFU profile paths remain. |
| No active SFU fallback | Missing | SFU and Gossip/SFU fallback tests/scripts still active. |
| No background in active stream path | Missing | Background pipeline and background-tab policy are still wired. |
| No MediaSecurity gate in current stream | Missing | Sender-key/participant-set recovery is in active runtime. |
| Backpressure never reconnects | Missing | Backpressure controller can emit `SOCKET_RESTART`. |
| Focus/UI click does not reconnect | Not proven | Foreground recovery can call `connectSocket()`. |
| Tests match new v1 target | Missing | Many active tests still prove parked SFU/Gossip/Background/regression behavior. |

## Open Gap Count

| Severity | Count | Theme |
| --- | ---: | --- |
| Critical | 5 | state authority, capability persistence, SFU/Gossip independence, reconnect behavior |
| High | 6 | MediaSecurity active gate, background policy, auto-quality, backpressure restart, stale tests, large coupled files |
| Medium | 7 | call-app diagnostics whitelist, stale docs assertions, assets, deploy script size, duplicate generated copies |

## Release Bar For This Target

The target is ready only when:

- all participants have an explicit media state,
- the state comes from the backend orchestrator,
- clients never silently pick another media strategy,
- backpressure follows the agreed ladder,
- failed sends are observable,
- parked systems are not release gates.

