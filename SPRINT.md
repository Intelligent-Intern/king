# King Active Issues

Purpose:
- This file contains only the active sprint extraction from `BACKLOG.md`.
- The complete open backlog is in `BACKLOG.md`.
- Completion notes go to `READYNESS_TRACKER.md`.

Active GitHub issue:
- #147 Batch 2: E2E, Encryption, And Security Claims (`1.0.7-beta`)

Rules:
- Keep active work small enough for clean commits and bisectable reviews.
- Do not mix ownership lanes unless the backlog item explicitly requires coordination.
- Do not weaken King v1 contracts to close a task faster.
- Preserve contributor credit when porting experiment-branch work.
- No path may be labeled secure, encrypted, E2EE, or post-quantum unless implementation, contracts, negative tests, and runtime/UI state prove the claim.

## Batch 2: E2E, Encryption, And Security Claims (`1.0.7-beta`)

### #Q-22 Video-Chat E2EE Threat Model, Contracts, And Runtime Honesty

Goal:
- Define the real media-security contract for video-chat so transport security, media-E2EE state, capability policy, and failure behavior are explicit and testable.

Checklist:
- [x] Publish `demo/video-chat/contracts/v1/e2ee-session.contract.json`.
- [x] Publish `demo/video-chat/contracts/v1/protected-media-frame.contract.json`.
- [x] Pin participant key state, epoch semantics, sender key id, receiver expectations, replay inputs, error codes, and rekey transitions.
- [x] Explicitly distinguish `transport_only`, `protected_not_ready`, `media_e2ee_active`, `blocked_capability`, `rekeying`, and `decrypt_error`.
- [x] Define one deterministic capability negotiation policy for `required | preferred | disabled`.
- [x] Define one shared security model across native WebRTC and WLVC/SFU paths.
- [x] Add negative tests for unsupported capability, mixed rooms, invalid control state, downgrade attempts, and malformed protected frames.
- [x] Make README, runtime notes, UI state, and telemetry wording match the contract exactly.
- [x] Remove any “E2EE” wording from paths that are only DTLS/TLS protected.

Done:
- [x] Media security claims are contract-first, runtime-honest, and testable.

### #Q-23 Video-Chat Native And SFU Media E2EE Implementation

Goal:
- Implement real media E2EE for both the native WebRTC path and the WLVC/SFU path so the server cannot decrypt protected media payloads.

Checklist:
- [x] Implement client-side session key establishment and media epoch state.
- [x] Keep raw media keys client-side only in normal operation.
- [x] Implement sender-side media encryption for the native WebRTC path before remote delivery.
- [x] Implement receiver-side decryption and integrity validation for the native WebRTC path.
- [x] Implement sender-side media encryption for the WLVC/SFU path before `sfu/frame` transit.
- [x] Implement receiver-side decryption and integrity validation for the WLVC/SFU path.
- [x] Add participant join/leave/admission/removal/reconnect rekey behavior.
- [x] Reject wrong epoch, wrong key id, replayed units, tampered payloads, and stale post-removal material.
- [x] Add wire/packet-path verification proving the SFU forwards ciphertext and bounded public metadata only.
- [x] Add CI coverage for native sender->receiver success, tamper rejection, and WLVC/SFU ciphertext-only transit.

Done:
- [x] The E2EE path protects media end-to-end and the server cannot decode call content.

### #Q-24 Video-Chat Protected Media Transport Cleanup

Goal:
- Clean up the media transport layer so protected media is carried in a pinned typed/binary envelope rather than ad-hoc plaintext-oriented payload conventions.

Checklist:
- [x] Separate codec-frame, transport-envelope, and protected-media contracts.
- [x] Replace any ad-hoc JSON byte-array carriage for protected media with a pinned typed or binary envelope.
- [x] Add bounded parse rules, malformed-frame rejection, and size ceilings for protected media transit.
- [x] Ensure `/sfu` never needs raw media keys and never accepts unauthenticated plaintext in E2EE mode.
- [x] Add contract tests for envelope parse/serialize parity and malformed-frame rejection.
- [x] Add relay-visible-field tests so only intentionally public metadata crosses the SFU.
- [x] Keep compatibility behavior explicit: no implicit fallback from protected envelope to plaintext media in `required` mode.

Done:
- [x] Protected media transit is pinned, bounded, and ready for stable E2EE rollout.

### #Q-25 Video-Chat Algorithm-Agile And Hybrid Post-Quantum Key Agreement

Goal:
- Make the media-E2EE design algorithm-agile and able to support hybrid classical + post-quantum key establishment without redesigning the media-protection layer.

Checklist:
- [x] Add a KEX abstraction independent from the protected-media frame format.
- [x] Pin the negotiated KEX suite in capability negotiation and session state.
- [x] Ship one production classical KEX path first on the shared abstraction.
- [x] Add hybrid classical + PQ suite negotiation behind explicit policy.
- [x] Bind transcript, room, participants, and selected suite into derived media epoch material.
- [x] Add downgrade rejection across KEX suites.
- [x] Add rejoin, reconnect, participant churn, and forced-rekey coverage under hybrid mode.
- [x] Add telemetry that distinguishes classical vs hybrid sessions without leaking secrets.
- [x] Document exactly what “post-quantum” means in this stack: key-establishment posture, not blanket secrecy of metadata, topology, or signaling.
- [x] Keep post-quantum wording out of README/security claims until suite agreement, transcript binding, and downgrade tests are green.

Done:
- [x] Media-key derivation is algorithm-agile and hybrid PQ works under the same pinned session-state contract as the classical path.
- [x] Downgrade across KEX suites fails closed and is CI-covered.

### #E2E-1 Video-Chat End-To-End Acceptance Matrix

Goal:
- Prove the demo as a user journey, not only as isolated endpoint contracts.

Checklist:
- [x] Add Playwright journey: owner creates call, invited user logs in from link, waits in join modal, owner admits, both see media and roster.
- [ ] Add Playwright journey: chat text, emoji, unread badge, attachment, and post-call read-only archive.
- [ ] Add Playwright journey: mobile call creation/editing with internal participant add.
- [ ] Add Playwright journey: websocket interruption, reconnect, room resync, and media/control recovery.
- [ ] Add release gate that fails when UI parity matrix or core video journeys are not covered.

Done:
- [ ] E2E journeys are deterministic enough to gate release readiness.
