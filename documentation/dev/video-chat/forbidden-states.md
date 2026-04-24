# Video Chat Forbidden States

This file lists call/runtime constellations that must not occur in the active
video-chat stack. Each item defines the forbidden state and the required system
reaction.

These are runtime invariants, not optional UX preferences.

## Authorization And Membership

1. User is inside an active call room without a valid authorization path.
Reaction:
The user must be removed from the call immediately.
Allowed paths are:
- admin role
- owner/moderator role for the call
- explicit participant assignment for the call with an admission state that allows entry
- a valid access-link session bound to the same call and room
- a `free_for_all` call mode that explicitly permits direct entry

2. User remains in a call after losing the authorization path that admitted them.
Reaction:
The server must re-evaluate call access on presence refresh, room join, and
admission changes. If the user is no longer allowed, close the call session and
redirect away from the call.

3. User sees lobby controls or moderator actions without moderation rights.
Reaction:
Hide the controls in the UI and reject the command server-side.

4. User is marked as queued, pending, or admitted after closing the join modal,
disconnecting the websocket, or abandoning the flow.
Reaction:
Reset the admission state back to the last valid durable state and remove the
stale lobby presence.

5. User is listed as a connected participant without a live room presence
connection.
Reaction:
Drop the participant from the live roster and do not render them as in-call.

## Call And Route Lifecycle

6. User is routed into a call that no longer exists.
Reaction:
If the user is authenticated, redirect to the call list.
If the user is not authenticated, redirect to the configurable exit page.

7. User is routed into a room that does not belong to the resolved call.
Reaction:
Reject the join, clear the stale route context, and redirect to a valid call
surface.

8. User is admitted by the owner, but the client stays on the waiting/join view.
Reaction:
Promote the client into the resolved call route immediately. The client must
not fall back to the dashboard or remain in waiting-room state.

9. Waiting-room is treated as a real call room for persistence or media
operations.
Reaction:
Never persist room-bound call state against `waiting-room`. Waiting-room is a
virtual gate state only.

10. Exit page is shown to an authenticated user leaving or losing a call.
Reaction:
Authenticated users go back to the call list or workspace shell, not to the
guest exit page.

## Media And Realtime

11. User is shown as connected or active, but remote participants cannot see or
hear them.
Reaction:
Treat this as a runtime failure, not as a normal state. Emit diagnostics,
attempt controlled recovery, and fail visibly if recovery does not succeed.

12. A participant tile or user list entry is rendered, but the media path for
that participant never reaches visible video or audible audio.
Reaction:
Do not treat roster presence as proof of media success. Track publish,
subscribe, decode, and render separately and surface the failing stage.

13. Client publishes media or participant activity before admission is complete
or before the client is inside the real call room.
Reaction:
Do not publish. Gate the operation until the client is in the resolved call
room.

14. Signaling, websocket, or SFU session is bound to a different call or room
than the visible workspace route.
Reaction:
Invalidate the session, resync the room snapshot, and reconnect only against
the resolved call-room binding.

15. Remote media frames are flowing, but the UI still shows `Connecting...`
indefinitely.
Reaction:
This is a state machine bug. The client must transition from connection state
to render state once frames or decoded samples arrive.

16. Client reports successful media runtime path selection while no remote
decode/render path is actually working.
Reaction:
Capability selection is not enough. Mark the runtime unhealthy until publish
and remote render are proven.

17. Participant activity persistence writes against `waiting-room` or any room
that is not a real `rooms.id`.
Reaction:
Ignore or reroute the event before persistence. Never write invalid room-bound
activity rows.

## Data Consistency

18. `active_call_id`, `requested_call_id`, `room_id`, `pending_room_id`, and
the visible route disagree about where the user actually is.
Reaction:
The backend is authoritative. Reconcile the client to the backend snapshot and
drop stale local state.

19. A call participant record exists for a user, but the resolved room/call
binding points somewhere else.
Reaction:
Do not merge the states optimistically. Re-resolve the binding from backend
data and reject the stale client path.

20. A deleted call leaves behind live lobby entries, participant activity,
layout state, or media presence that still appears current.
Reaction:
Cascade-delete or prune the live state and redirect clients away from the dead
call.

## Current High-Severity Forbidden State

The currently observed production failure belongs to this class:

- remote participant is admitted into the same call
- websocket and/or SFU connection is up
- participant roster and mini-tile can appear
- remote image and audio are still not rendered

This state is forbidden. It means the system is treating transport presence as
if it were successful media delivery. The fix path must prove the whole chain:

1. caller is allowed into the call
2. caller is bound to the correct call and room
3. publisher produces media
4. SFU/signaling routes the media to the other participant
5. receiver decodes the media
6. UI renders the media and leaves `Connecting...`

Anything short of that is still broken.
