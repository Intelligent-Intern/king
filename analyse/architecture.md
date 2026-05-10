# Current And Target Architecture

Stand: 2026-05-10

## Aktuelle Architektur

```mermaid
flowchart TB
  subgraph Client["Browser Client"]
    Vue["Vue Call Workspace"]
    Capture["Local Capture / 720p30 Policy"]
    RuntimeProbe["Local Runtime Probe"]
    NativeWebRTC["Native WebRTC Path"]
    WLVC["WLVC / WASM Path"]
    Background["Background Pipeline"]
    CallAppHost["Call App Host + Iframe Bridge"]
    ClientDiag["Client Diagnostics Queue"]
  end

  subgraph Edge["King Edge"]
    Static["Static Frontend"]
    ApiProxy["API Proxy"]
    WsProxy["WS Proxy"]
    CallAppStatic["Call App Asset Host"]
    SfuProxy["Optional SFU Proxy"]
  end

  subgraph Backend["King PHP Backend"]
    Api["HTTP API Router"]
    Ws["Realtime WebSocket Router"]
    Calls["Calls / IAM / Lobby"]
    Presence["Presence / Room Snapshot"]
    CallApps["Call Apps / CRDT / Grants"]
    Diagnostics["Client + Call Diagnostics"]
    Infra["Infrastructure + Operations Snapshots"]
    SfuModule["SFU Module"]
    Gossip["GossipMesh Module"]
    Database[("SQLite / King DB")]
  end

  Vue --> Capture
  Vue --> RuntimeProbe
  RuntimeProbe --> NativeWebRTC
  RuntimeProbe --> WLVC
  Vue --> Background
  Vue --> CallAppHost
  Vue --> ClientDiag

  Vue --> Static
  Vue --> ApiProxy
  Vue --> WsProxy
  CallAppHost --> CallAppStatic
  WLVC -. optional / env gated .-> SfuProxy

  ApiProxy --> Api
  WsProxy --> Ws
  SfuProxy --> SfuModule
  Api --> Calls
  Api --> CallApps
  Api --> Diagnostics
  Api --> Infra
  Ws --> Presence
  Ws --> Calls
  Ws --> Gossip
  Ws --> SfuModule
  Calls --> Database
  Presence --> Database
  CallApps --> Database
  Diagnostics --> Database
  Infra --> Database
```

### Lesart

Die Architektur ist funktional geschichtet, aber der Media-Teil ist kein einheitlicher v1-Vertrag. Der Client waehlt lokal Runtime-Pfade, der Server fuehrt Presence/Lobby/Call-App-State, und SFU/Gossip/MediaSecurity/Background sind noch als eigene Pfade im System sichtbar. Das erzeugt zu viele Stellen, die "helfen" oder "reparieren" koennen.

## Zielbild fuer v1

```mermaid
flowchart TB
  subgraph ClientA["Client A"]
    AJoin["Authenticated Join"]
    ACap["Send client.capabilities.v1"]
    APlan["Apply media_session_plan.v1"]
    AMedia["Send 720p30 if allowed"]
  end

  subgraph ClientB["Client B"]
    BJoin["Authenticated Join"]
    BCap["Send client.capabilities.v1"]
    BPlan["Apply media_session_plan.v1"]
    BMedia["Receive / Send if allowed"]
  end

  subgraph KingRT["KingRT Realtime Control Plane"]
    Auth["Auth + Lobby Admission"]
    CapabilityStore["Capability Store"]
    Orchestrator["Video Call Orchestrator"]
    StateMachine["Call Media State Machine"]
    Signaling["Signaling Fanout"]
    Telemetry["Diagnostics / Telemetry"]
  end

  subgraph State["Canonical Call State"]
    Participants[("Participants")]
    Capabilities[("Capabilities")]
    MediaPlan[("Media Session Plan")]
    Events[("Audit + Diagnostics Events")]
  end

  AJoin --> Auth
  BJoin --> Auth
  ACap --> CapabilityStore
  BCap --> CapabilityStore
  CapabilityStore --> Orchestrator
  Auth --> Orchestrator
  Orchestrator --> StateMachine
  StateMachine --> MediaPlan
  MediaPlan --> Signaling
  Signaling --> APlan
  Signaling --> BPlan
  APlan --> AMedia
  BPlan --> BMedia
  StateMachine --> Telemetry
  Auth --> Participants
  CapabilityStore --> Capabilities
  StateMachine --> Events
```

## State Machine Draft

```mermaid
stateDiagram-v2
  [*] --> waiting_for_lobby
  waiting_for_lobby --> admitted: admin_or_moderator_accepts
  admitted --> waiting_for_capabilities: websocket_joined
  waiting_for_capabilities --> media_ready: supports_720p30
  waiting_for_capabilities --> video_unavailable: lacks_720p30_or_camera
  waiting_for_capabilities --> receive_only: capture_denied_or_no_camera
  media_ready --> sending_720p30: plan_applied
  sending_720p30 --> receive_only: local_capture_lost
  receive_only --> waiting_for_capabilities: user_retries_device
  video_unavailable --> waiting_for_capabilities: capability_changed
  admitted --> left: user_hangup_or_kick
  sending_720p30 --> left: user_hangup_or_kick
  receive_only --> left: user_hangup_or_kick
  video_unavailable --> left: user_hangup_or_kick
```

## Wichtige Architekturentscheidung

Der v1-Orchestrator soll keine versteckten Downgrades machen. Er soll entscheiden und verteilen:

- `sending_720p30`
- `receive_only`
- `video_unavailable`
- `blocked_capability`
- `left`

Damit werden Fehler sichtbar und testbar, statt dass alte Pfade parallel versuchen, Medienfluss zu retten.
