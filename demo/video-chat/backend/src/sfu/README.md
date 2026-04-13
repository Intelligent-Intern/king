# SFU Server

Selective Forwarding Unit for the King video workspace. Implemented as a WebSocket signalling layer — it tracks publisher/subscriber relationships and relays track metadata. The actual media forwarding is handled by the browser's WebRTC stack (peer-to-peer); the SFU does not touch media packets.

## Connection

```
ws://host/sfu?token=<jwt>&userId=<id>&name=<display name>
```

The JWT is validated against `userId` on connection. Mismatches or missing tokens are rejected with close code `1008`.

On successful connection the server sends:

```json
{ "type": "sfu/welcome", "userId": "...", "name": "...", "serverTime": 1234567890 }
```

## Message Protocol

### Client → Server

| Message | Required fields | Description |
|---------|----------------|-------------|
| `sfu/join` | `roomId`, `role` (`publisher`\|`subscriber`) | Join a room in the given role. |
| `sfu/publish` | `kind` (`audio`\|`video`), `label` | Announce a new track. `trackId` is auto-generated if omitted. |
| `sfu/unpublish` | `trackId` | Remove a previously published track. |
| `sfu/subscribe` | `publisherId` | Subscribe to all tracks from a publisher in the same room. |
| `sfu/leave` | — | Leave the current room. |

### Server → Client

| Message | Description |
|---------|-------------|
| `sfu/joined` | Confirms room join. Includes `publishers[]` currently in the room. |
| `sfu/published` | Confirms a track was registered. Returns the `trackId`. |
| `sfu/tracks` | Sent to all subscribers when a publisher adds tracks, and to a new subscriber on `sfu/subscribe`. Contains publisher identity and full track list. |
| `sfu/unpublished` | Notifies subscribers when a specific track is removed. |
| `sfu/publisher_left` | Broadcast to subscribers when a publisher disconnects or leaves. |
| `error` | `{ code, message }` for invalid operations. |

## Room Model

- A room is created on first join and persists as long as clients are connected.
- Each client is either a **publisher** or a **subscriber** — set at `sfu/join` time.
- Publishers maintain a `Map<trackId, MediaTrack>` of their active tracks.
- Subscribers maintain a `Set<publisherId>` of publishers they are subscribed to.
- Track metadata (kind, label) flows through the SFU; media flows peer-to-peer.

## State

All state is in-process (no persistence):

| Variable | Type | Contents |
|----------|------|----------|
| `sfuClients` | `Map<WebSocket, SFUClient>` | All connected clients |
| `sfuRooms` | `Map<string, SFURoom>` | Active rooms |

## Stats

`getSFUStats()` returns:

```ts
{
  totalConnections: number
  publishers: number
  subscribers: number
  rooms: number
}
```

Exposed via the backend `/api/stats` endpoint.
