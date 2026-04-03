# Server Runtime

This chapter explains how King receives traffic instead of only sending it.
Client-side chapters talk about opening sessions toward remote systems. This
chapter talks about the opposite direction: listeners, accepted requests,
upgrades, early hints, cancel hooks, admin listeners, TLS reloads, and the
server-owned session state that ties those pieces together.

The main idea is simple. King does not treat server behavior as a hidden global
callback model. A server path still has explicit runtime state. There is a
listener. There is one accepted request or dispatch cycle. There is a
normalized request shape. There is a `King\Session`-backed server context. There
is one response, one upgrade, or one explicit close decision.

That design matters because serious server work includes more than "read request
body, return body string". Real server paths need to send early hints, attach
telemetry, react to cancellation, reload certificates, switch a request into
WebSocket mode, and expose privileged control flows through an admin API. King
keeps those actions in one server model.

## Start With The Basic Server Shape

A server listener in King accepts traffic on a host and port, normalizes the
incoming request into a stable request array, creates a server-capable session
snapshot, calls the handler, and then completes the request with one of a small
number of outcomes.

The outcome may be an ordinary HTTP response. It may include Early Hints before
that final response. It may become a WebSocket upgrade. It may be cancelled. It
may be closed by the server side with an explicit code and reason. It may also
carry telemetry or admin behavior attached to the same session.

```mermaid
flowchart TD
    A[Listener starts] --> B[Accept or dispatch one request]
    B --> C[Normalize request array]
    C --> D[Create server-capable session snapshot]
    D --> E[Call handler]
    E --> F[Return HTTP response]
    E --> G[Send Early Hints]
    E --> H[Upgrade to WebSocket]
    E --> I[Register cancel hook]
    E --> J[Close server side explicitly]
```

This picture is the right way to read the rest of the chapter. The API is built
around these server actions because these are the real actions a long-lived
server needs to take.

## The Public Server Entry Points

King exposes several listener functions because there are several useful ways to
receive traffic.

`king_http1_server_listen()` runs one HTTP/1 server dispatch through the local
server runtime. `king_http1_server_listen_once()` accepts one real on-wire
HTTP/1 request on a bound TCP socket and handles that one request from start to
finish. `king_http2_server_listen()` runs the same server model for an HTTP/2
request shape in the local runtime, and `king_http2_server_listen_once()`
accepts one real on-wire h2c connection, handles one request stream, sends one
response, and closes the connection with a clean `GOAWAY`.
`king_http3_server_listen()` runs the same model for HTTP/3 request shapes, and
`king_http3_server_listen_once()` accepts one real UDP/QUIC/HTTP/3 client,
builds one normalized request from live HTTP/3 frames, writes one response,
sends `GOAWAY`, and closes the connection cleanly. `king_server_listen()` is
the generic listener entry point that chooses the appropriate listener mode
from configuration.

The practical difference is not only protocol version. It is also about how
directly the request reaches the listener and which transport shape surrounds
the handler.

## Why The Listener Model Matters

Many PHP readers are used to server frameworks where the handler is the whole
story. A request arrives, the framework has already normalized everything, and
the code only sees the request and a response builder.

King is more explicit because its server runtime is meant to coordinate
transport, upgrades, telemetry, lifecycle, and operations. A request is not
only a body and headers. It is also part of a live session that can later be
cancelled, upgraded, closed, measured, reconfigured, or inspected.

This is why the server APIs carry both the listener entry point and explicit
session-aware helpers instead of burying everything inside one giant callback
abstraction.

## The Normalized Request Array

A server handler in King receives a normalized request array instead of raw
socket bytes. This is an important design choice.

The array carries the data a handler naturally wants to reason about: method,
the full request target in `uri`, the normalized routing path in `path`,
headers, body, and protocol-specific metadata. It can also carry stream and
session fields that help the handler understand which live server context it is
operating on.

The point of normalization is not to hide the protocol. The point is to avoid
forcing every handler to rebuild the same request parsing logic. The runtime
keeps ownership of the low-level transport work so the application can work at
the level of actual request handling.

```mermaid
flowchart LR
    A[Raw protocol input] --> B[Listener runtime]
    B --> C[Normalized request array]
    C --> D[Application handler]
    D --> E[Response array or upgrade action]
```

This design makes handlers easier to read while still keeping transport-aware
operations available through the session APIs. In practice, `uri` keeps the
original request target such as `/chat?room=alpha`, while `path` gives the
stable routing slice such as `/chat`.

## HTTP/1, HTTP/2, And HTTP/3 Listeners

The three protocol-specific listener functions share the same basic mental
model. They accept host, port, config, and a handler. They prepare a
server-capable session snapshot and pass a normalized request array into the
handler.

`king_http1_server_listen()` is the ordinary HTTP/1 listener entry point when
the application wants one server dispatch cycle in the local runtime. It is a
good match for focused HTTP/1 request handling, response generation, and
server-side helper flows that do not need a custom accept loop.

`king_http2_server_listen()` gives the same server shape over an HTTP/2-style
request model in the local runtime. `king_http2_server_listen_once()` adds the
network-backed sibling: it binds a real TCP socket, accepts one h2c client,
parses the incoming request stream, materializes one server-side session over
that accepted socket, invokes the handler, writes the response headers and
body, sends `GOAWAY`, drains the last protocol traffic cleanly, and then closes
the listener. That matters when the application wants one coherent way to work
with pseudo-header style metadata, stream semantics, and a proven on-wire
accept path instead of only a local dispatch slice.

`king_http3_server_listen()` does the same for HTTP/3-style request handling in
the server runtime. `king_http3_server_listen_once()` adds the matching network
leaf: it binds a real UDP socket, accepts one QUIC connection, builds the
HTTP/3 request from real header and body frames, materializes one server-side
session over that accepted socket, invokes the handler, writes one HTTP/3
response, sends `GOAWAY`, drains the last protocol traffic, and closes the
connection. That makes the HTTP/3 listener path a real accept path rather than
only a local request-shape slice.

The important thing is that these are not three unrelated APIs. They are three
protocol faces of one server runtime model.

## The One-Shot HTTP/1 And HTTP/2 Listeners

`king_http1_server_listen_once()` deserves special attention because it is the
most direct server accept path in the public surface. It binds a real TCP
socket, accepts exactly one request, materializes one server-side session, runs
the handler, writes the response when the handler returns a normal HTTP result,
and then closes the listener and accepted session.

The on-wire HTTP/1 path keeps that accept flow bounded. The runtime applies the
active TCP timeout budget to the real `accept`, request-head read, request-body
read, and response-write phases so a stalled client cannot hold the worker in
an unbounded blocking socket read.

When that one-shot cycle finishes, the listener does not leave the accepted
session half-open. The runtime closes the server-owned session and releases the
bound port cleanly enough that the same listener can be started again on the
same port for the next bounded request cycle.

This shape is especially useful when the application wants a tightly scoped,
single-request listener flow. It is also the most direct entry point for
workflows that need to observe one real HTTP/1 request and, when appropriate,
upgrade it into a WebSocket channel.

`king_http2_server_listen_once()` does the same kind of bounded work for h2c.
The request still arrives as real protocol traffic rather than as a synthetic
array. The runtime reads the HTTP/2 client preface and settings exchange,
decodes the request headers and optional body for one stream, builds the same
kind of normalized request array, invokes the handler once, writes one HTTP/2
response, sends `GOAWAY`, and closes the accepted connection cleanly.

The same applies to restart behavior: after the one-shot request drains through
`GOAWAY` and socket close, the runtime releases the bound port so the next
listener instance can reuse it immediately instead of inheriting half-closed
transport state from the previous request.

`king_http3_server_listen_once()` is the QUIC and HTTP/3 sibling. It owns the
same bounded one-request shape, but the transport work is different: it accepts
an incoming QUIC connection, creates the HTTP/3 transport layer on top of that
connection, reads the request headers and body frames for one stream, hands the
normalized request into the handler, writes one HTTP/3 response, sends
`GOAWAY`, and closes the connection. This is the right one-shot leaf when the
application needs proof against a real QUIC and HTTP/3 client instead of only a
local HTTP/3 request model.

That close path is also part of the public contract. After the response and
`GOAWAY` are drained, the one-shot HTTP/3 listener releases its bound UDP port
so a fresh listener instance can restart on the same port under live traffic.

The value of a one-shot listener is not that it is "small". The value is that
the whole accept path is explicit and bounded.

## The Generic Listener Dispatcher

`king_server_listen()` exists so the application does not have to repeat
protocol-selection logic in userland. The function accepts the same host, port,
config, and handler shape as the protocol-specific listeners and chooses the
actual listener mode from the runtime configuration.

In practice, this means a service can describe the server policy in
configuration and keep its application handler stable. The code still knows that
it is a server handler, but it does not have to manually branch between HTTP/1,
HTTP/2, and HTTP/3 listener functions in every place that starts a listener.

This is an example of King using configuration to choose runtime mode while
still keeping the actual runtime actions explicit.

## What A Handler Returns

The usual server path is that the handler returns a normalized response array.
The listener runtime validates that response, writes the final response, and
then closes or completes the relevant server state for that request.

The more interesting part is that a handler does not only return final content.
It can also interact with the live session while building the final result. It
can send Early Hints, register a cancel callback, attach telemetry, reload TLS
material on the server session, or upgrade the request into a WebSocket
connection.

That cancel-hook surface is not only a local convenience slice. The active
one-shot HTTP/1, HTTP/2, and HTTP/3 listener leaves now prove that a real
client abort during live traffic invokes a registered server-side cancel
callback once for the real active stream instead of only mutating hidden local
state.

That is why the session-aware helper functions are part of the public surface.
A real server request often needs to do more than return a status code and body.

The response array is also normalized before it reaches the client. Repeated
header values returned as arrays become repeated response fields. HTTP/1 keeps
ownership of the final `Content-Length` and `Connection: close` lines for the
one-shot listener, while HTTP/2 and HTTP/3 drop handler-supplied
`content-length` and `connection` fields and emit stable lowercase header names
on wire.

## Early Hints

Early Hints are provisional HTTP hints associated with a response before the
final body is ready. They are useful when the server already knows which assets
or resources the client will probably need and wants to record that information
early.

King exposes this through `king_server_send_early_hints()`. The function takes
a server-capable session, a stream identifier, and a normalized set of hint
headers. The current runtime stores and tracks the hint batch on the session
state, and the on-wire HTTP/1 one-shot listener now emits that normalized
batch as a real `103 Early Hints` response before the final status line for the
same active stream. The broader on-wire HTTP/2 and HTTP/3 interim-response
story is still narrower than this staged-response model.

This matters because server behavior is often staged. The final response may not
be ready yet, but the server may already know enough to improve the next few
round trips.

```mermaid
sequenceDiagram
    participant Client
    participant Server

    Client->>Server: Request
    Server->>Client: Early Hints with likely-needed headers
    Note over Client,Server: Client can start preparation work
    Server->>Client: Final response
```

The concept is simple: make useful pre-response intent explicit before the full
answer is finished.

## Server-Side Cancellation

Long-running server work needs a way to react when the active request is no
longer wanted. That is what `king_server_on_cancel()` is for. The function
registers a cancel callback for one stream on a live server-capable session.

This matters most when the server has started expensive work that should stop if
the client disconnects or cancels the request. A render, a data aggregation, a
streaming transform, or a long-running tool invocation may all need a clean stop
path instead of blindly continuing after the client is gone.

The cancel hook keeps that server-side reaction explicit. The handler is not
left guessing whether the client still cares about the result.

## Server-Owned Session State

The server helpers all operate on a server-capable `King\Session` snapshot. This
is the same broad session idea used on the client side, but the meaning is now
server-owned rather than outbound-client-owned.

The session is what lets the runtime keep related state together. Early Hints
batches, TLS reload state, telemetry attachment, cancel handlers, WebSocket
upgrade ownership, peer certificate subject data, and explicit server-side close
state all belong to the same live server session rather than being scattered
through unrelated globals.

This is the heart of the server runtime design. One accepted request may feel
small, but the surrounding server state is still real and still worth modeling
explicitly.

## Server CORS And Header State

The current one-shot listener runtime now treats request headers as a real,
normalized transport input instead of a loose helper artifact. On the active
HTTP/1, HTTP/2, and HTTP/3 server paths, request header names are materialized
through stable lowercase keys, so server code sees the same header shape across
all three protocols.

That matters directly for CORS. The server-side CORS helper reads the incoming
`origin`, `access-control-request-method`, and
`access-control-request-headers` fields from those normalized headers, records
the evaluated origin/preflight decision on the request snapshot, and reflects
the allowed origin plus `Vary: Origin` on the response path when the configured
policy allows it.

This is still a bounded slice. King is not pretending to be a full browser
policy engine. What is explicit now is the contract that real clients can rely
on today: the same live request headers that reach the handler are the ones the
CORS helper evaluates, and the response headers the client sees are generated
from that same evaluated state instead of from a disconnected local shortcut.

## Upgrading To WebSocket

`king_server_upgrade_to_websocket()` is how an incoming server request changes
from ordinary HTTP handling into a long-lived bidirectional WebSocket channel.

The function takes the current server-capable session and a stream identifier
for the request that is being upgraded. If the request is valid for upgrade, the
runtime creates a server-side WebSocket resource and hands ownership of that
channel to the handler.

On the local HTTP/1, HTTP/2, and HTTP/3 listener slices, that resource is an
in-process bidirectional channel backed by the shared bounded WebSocket queue,
so frame send, receive, ping, status, and close calls remain live even without
an accepted wire socket. On the on-wire HTTP/1 one-shot listener, the same
upgrade path keeps the stronger contract: the returned resource is bound to the
accepted socket and exchanges real WebSocket frames with the peer.

That local HTTP/2 and HTTP/3 contract is still honest about transport shape.
The resource identity becomes `wss://.../stream/<id>` under the secure `h2` or
`h3` listener session, but the runtime is not fabricating a hidden wire
`101` or Extended CONNECT handshake where no accepted peer socket exists. The
repo now carries dedicated local honesty contracts for both secure listener
families, so that transport claim is explicit rather than inherited only from
the broader mixed helper coverage.

Those server-owned upgrade resources are also request-boundary scoped. If user
code retains the returned resource after the handler returns, the runtime
force-closes it and clears any queued local frames before the next request or
same-process worker work unit can reuse that server path.

This matters because a WebSocket channel is more than "another response body".
It is a change in protocol mode and in connection ownership. After upgrade,
the server is no longer only preparing one final HTTP response. It is managing a
live channel.

```mermaid
flowchart TD
    A[Incoming HTTP request] --> B[Handler validates upgrade]
    B --> C[king_server_upgrade_to_websocket]
    C --> D[Server-side WebSocket resource]
    D --> E[Long-lived channel owned by handler]
```

That shift from request mode to channel mode is one of the biggest differences
between ordinary HTTP handlers and realtime server work.

## Closing From The Server Side

Sometimes the server needs to terminate a session on purpose. This is not the
same thing as the client disappearing. The server may be enforcing policy,
shutting down cleanly, rejecting a capability mismatch, or draining a session as
part of lifecycle management.

`king_session_close_server_initiated()` exists for that case. It marks a live
server-capable session as closed by the server side and records the explicit
error code and optional reason.

This is important because clean lifecycle management needs more than raw socket
shutdown. The runtime should know why the server ended the session and surface
that decision as part of the session state.

## Peer Certificate Information

When the server is operating with TLS or mTLS, it may need to inspect the
identity presented by the remote peer. `king_session_get_peer_cert_subject()`
returns the normalized peer-certificate subject snapshot for a live
server-capable session when the requested capability matches the active session.

This matters for policy decisions, audit logs, and privileged admin flows. It is
one thing to know that the handshake succeeded. It is another to know exactly
which peer identity the server accepted.

The function exists so that certificate-based trust can be observed by the
application, not only buried inside the transport layer.

## Reloading TLS Material

Server certificates and keys change over time. They expire. They are rotated.
An operator may need to switch a listener to new key material without treating
that event as a full process mystery.

King exposes this path through `king_server_reload_tls_config()`. The function
takes a live server-capable session plus new certificate and key paths, validates
that the replacement material is readable, and applies a new server-side TLS
snapshot to the session. That snapshot path is now also proven during a live
on-wire HTTP/3 request, so the runtime contract is not only a local marker.
The current slice still does not hot-swap a native listener backend mid-accept.

This matters because certificate rotation is not an edge case. It is normal
operations. A platform that serves traffic for long periods has to make TLS
reload a first-class action instead of something left to side effects.

## Attaching Telemetry To A Server Session

`king_server_init_telemetry()` attaches telemetry configuration to a live
server-capable session. This is how the handler or startup path can tell the
runtime that this server session should record the relevant metrics, spans, or
logs according to the supplied telemetry configuration.

Once telemetry is attached, the normalized request array can also expose
`$request['telemetry']['incoming_trace_context']` when the accepted request
carried a valid `traceparent` header and an optional `tracestate` header. That
snapshot still gives the handler an explicit view of the inbound trace
identity, and it now also seeds the first request-root span opened during that
handler so the local server trace joins the caller's trace instead of silently
forking a new root. The inbound parent seed is discarded again before the next
accepted request starts. Once that request-root span is active, outgoing
HTTP/1, HTTP/2, and HTTP/3 client requests issued from the same handler now
carry that live trace context automatically unless the handler pins an
explicit `traceparent` or `tracestate` boundary in the request headers.

This matters because good server behavior is not only about serving traffic. The
system also needs to observe itself while serving traffic. Early Hints counters,
cancel behavior, TLS reload counts, admin activity, request timing, and upgrade
activity all become more useful when they are tied back to a coherent telemetry
story.

## The Admin API Listener

`king_admin_api_listen()` is the entry point for a privileged operational
listener bound to a target server. This is where a platform can place
introspection, management, and protected control actions.

The point of an admin listener is not that it is "another port". The point is
that operational control is different from ordinary public request traffic. It
may require explicit enablement, stronger authentication, and separate request
policy. In King, the admin listener stays tied to the same server session model
instead of pretending that operations traffic lives in a completely different
universe.

This is one reason the server chapter belongs close to the operations chapter.
Serving traffic and operating the server are not two unrelated concerns.

## A Full Listener Example

The following example shows a small HTTP/1 listener that attaches telemetry,
sends Early Hints, and returns a normal response.

```php
<?php

king_http1_server_listen('127.0.0.1', 8080, [
    'tls.verify_peer' => false,
], function (array $request) {
    $session = $request['session'];
    $streamId = $request['stream_id'];

    king_server_init_telemetry($session, [
        'otel.enabled' => true,
        'otel.service_name' => 'handbook-demo',
    ]);

    king_server_send_early_hints($session, $streamId, [
        ['link', '</assets/app.css>; rel=preload; as=style'],
    ]);

    king_server_on_cancel($session, $streamId, function () {
        error_log('client cancelled request');
    });

    return [
        'status' => 200,
        'headers' => [
            'content-type' => 'text/plain',
        ],
        'body' => "hello from King\n",
    ];
});
```

This example is small, but it shows the important shape. The handler receives a
normalized request. It reaches back into the live server session when it needs
server-aware operations. It then returns a final response.

## A WebSocket Upgrade Example

The next example shows the protocol-mode change from HTTP request handling to a
WebSocket channel.

```php
<?php

king_http1_server_listen_once('127.0.0.1', 9001, null, function (array $request) {
    $session = $request['session'];
    $streamId = $request['stream_id'];

    if (($request['path'] ?? '/') !== '/realtime') {
        return [
            'status' => 404,
            'headers' => ['content-type' => 'text/plain'],
            'body' => "not found\n",
        ];
    }

    $websocket = king_server_upgrade_to_websocket($session, $streamId);
    if ($websocket === false) {
        return [
            'status' => 400,
            'headers' => ['content-type' => 'text/plain'],
            'body' => "upgrade failed\n",
        ];
    }

    return [
        'status' => 101,
        'headers' => [],
        'body' => '',
    ];
});
```

The exact response handling can vary by application design, but the basic
picture stays the same: a request enters as HTTP and, when accepted, becomes a
long-lived channel owned by the handler. On the current on-wire HTTP/1 one-shot
leaf, `king_server_upgrade_to_websocket()` writes the `101` handshake itself,
so the returned `101` response array remains part of the normalized handler
contract rather than a second wire write. On the local listener slices, there
is no accepted peer socket, so the returned resource stays an in-process
channel instead of pretending to be a hidden wire connection.

## How To Think About Protocol Choice

The server runtime lets the application be explicit about protocol while still
sharing one handler model.

Choose `king_http1_server_listen()` or `king_http1_server_listen_once()` when
you want HTTP/1 request handling or a tightly scoped one-shot accept path.
Choose `king_http2_server_listen()` when the surrounding traffic shape is better
described as HTTP/2 and a local dispatch slice is enough. Choose
`king_http2_server_listen_once()` when the accept path itself must be proven
against a real h2c client. Choose `king_http3_server_listen()` when the server
path should sit inside the QUIC and HTTP/3 side of the runtime but the local
dispatch slice is enough. Choose `king_http3_server_listen_once()` when the
accept path itself must be proven against a real QUIC and HTTP/3 client. Choose
`king_server_listen()` when configuration should decide the protocol mode.

The important point is that protocol choice is not the same as abandoning one
common server model. King keeps one consistent server model across those
listeners.

## Operational Questions For Server Readers

A server chapter is incomplete if it only explains how to write one response.
Operators and framework authors need answers to larger questions.

How does the server expose a cancel path for long-running work? How does it send
information before the final response is ready? How does it rotate certificate
material without treating that as out-of-band magic? How does it inspect peer
identity for mTLS-protected admin traffic? How does it attach telemetry to live
request handling? How does it cleanly terminate a session from the server side?

Those are the questions this public surface is designed to answer.

## Common Mistakes

The first common mistake is treating server-side helpers as if they were
optional decorations around a normal callback. They are part of the runtime
contract. If a handler needs cancel awareness, upgrade ownership, or telemetry
attachment, those actions should stay explicit.

The second mistake is treating a WebSocket upgrade like an ordinary HTTP body
decision. It is a protocol-mode change and should be thought about that way.

The third mistake is treating TLS reload and admin-listener behavior as separate
from the server runtime. In King they are part of the same server lifecycle and
should be documented, observed, and operated as such.

## Where To Go Next

If you want to understand the realtime side after an upgrade, read
[WebSocket](./websocket.md). If you want the transport story beneath HTTP/3 and
secure sessions, read [QUIC and TLS](./quic-and-tls.md). If you want the
operational view of shipping and running listener configurations, read
[Operations and Release](./operations-and-release.md). If you want the exact
function list grouped by subsystem, keep this chapter open beside
[Procedural API Reference](./procedural-api.md).
