# Glossary

This glossary explains the recurring terms used across the King documentation in
simple language. It is meant for readers who do not want to guess what a word
means from context.

## Attribute

An attribute is one named fact attached to another piece of data. A span
attribute might say which service was called, and a log attribute might say
which object ID was involved. Attributes give context without changing the main
meaning of the record.

## Batch

A batch is a group of records that are processed or sent together. Telemetry
often uses batches so the runtime can export several metrics, spans, or logs in
one step instead of making one network request per item.

## Backpressure

Backpressure is what happens when a system slows or limits incoming work so it
does not accept more than it can handle safely. A queue limit is one simple form
of backpressure.

## Artifact

An artifact is something a system has produced and wants to keep or deliver
later. A release bundle, a model file, a generated report, or a compiled output
can all be artifacts.

## Blob

A blob is a body of bytes. It may be text, an image, a video fragment, or any
other binary content. The word is often used when the system cares about the
whole payload more than about fields inside it.

## Callback URL

A callback URL is the address another system sends a user or a result back to
after a previous step finishes. OAuth login flows use callback URLs to return a
browser from the identity provider to the application.

## Cancel Token

A cancel token is a small shared object that carries one instruction: this work
should stop. It lets one part of a program tell another part of a program to
stop a request, stream, MCP call, or other long-running operation.

## Cache Invalidation

Invalidation means telling a cache that an older copy is no longer safe to
serve. This is how a system removes stale data after an object changes.

## Cardinality

Cardinality is the number of distinct values a field can take. In telemetry,
high-cardinality labels can create too many unique metric series and make the
system harder to store or query efficiently.

## Checkpoint

A checkpoint is a saved state that lets work continue later from a known point.
In AI systems this is often a saved model state from training.

## Chunking

Chunking means splitting a large object into smaller pieces. This can make it
easier to move, copy, recover, or resume.

## Collector

A collector is the service that receives telemetry from applications and then
forwards, transforms, stores, or exports it elsewhere. An OTLP collector is a
collector that speaks the OpenTelemetry transport formats.

## Connection

A connection is one live communication relationship between two endpoints. It is
the broader thing that can carry many messages, requests, or streams over time.

## Connection ID

A connection ID is a transport identifier used by QUIC so that a connection can
be recognized even if the network path changes. It helps the transport keep its
identity separate from one specific IP and port combination.

## Congestion Control

Congestion control is the logic that decides how fast a sender should put data
onto the network. Its job is to avoid sending so aggressively that the network
starts dropping too much traffic.

## DirectStorage

DirectStorage means keeping data in a form and location that makes it quick to
read by the next workload that needs it, instead of leaving it only in a slow
or cold storage tier.

## Deployment

A deployment is the real running environment for the software: the machine,
container, credentials, network policy, filesystem paths, and process settings
that make the application live outside the source tree.

## Edge

An edge is a delivery node closer to users than the origin system. A CDN uses
edge nodes to reduce delay and lower the load on the origin.

## ETag

An ETag is an identifier for one concrete version of an object. It is often
used to tell whether cached data is still current.

## Fine-Tuning

Fine-tuning means taking an existing model and training it further on a more
specific dataset so it becomes better at a narrower task.

## Flow Control

Flow control is the logic that limits how much data one side may send before
the other side is ready to accept more. Its job is to protect memory and keep a
fast sender from overwhelming a slower receiver.

## Identity Provider

An identity provider is the system that proves who a user is. In OAuth and
OpenID Connect deployments this is often Google, Microsoft Entra, GitHub, or a
company-owned login service.

## Frame

A frame is one protocol unit inside a larger connection. In WebSocket, a frame
can carry text, binary data, or control information such as ping, pong, or
close.

## Handshake

A handshake is the setup exchange that happens before two sides start normal
application traffic. It is where they agree on security, identity, and basic
connection rules.

## Hotset

A hotset is the part of the data that the system expects to need soon. Hot data
is worth keeping in a faster place.

## Ingress

Ingress is the point where traffic enters a system. An ingress node accepts
outside traffic and passes work to internal nodes or services.

## Label

A label is a small key-value tag attached to a metric. Labels let one metric
name describe several dimensions, such as region, service, or queue name.

## Log Record

A log record is one structured event entry. It usually includes a level, a
message, a timestamp, and optional context such as attributes or trace IDs.

## Metric

A metric is a numeric measurement collected over time. Request rate, active
connections, CPU usage, and queue depth are all examples of metrics.

## OAuth

OAuth is a standard way to let one system ask another system to authenticate a
user or grant scoped access without sharing the user's password directly.

## Payload

A payload is the actual data being sent, received, stored, or processed. In
different contexts it may be an HTTP body, a binary message, an object body, or
the bytes inside a transfer.

## Object

An object is one stored unit of data together with the facts and rules that
describe it. It is more than only a file path and more than only raw bytes.

## Object Store

An object store is a storage system built around object identity, payloads,
metadata, and lifecycle rules instead of only folder paths.

## Origin

The origin is the main source of truth for data. A cache or edge node goes back
to the origin when it does not already have a valid local copy.

## OTLP

OTLP means OpenTelemetry Protocol. It is the transport format family commonly
used to move metrics, traces, and logs from an application runtime to a
collector.

## Presence

Presence is the simple fact that a user is currently connected, available, or
active in a room, channel, or workspace.

## Realtime

Realtime describes a system that stays connected and reacts continuously instead
of only working through short, isolated request-and-response cycles.

## Request

A request is one unit of work sent to another system or subsystem asking it to
do something and return a result.

## Room

A room is one shared realtime space in which a fixed group of users can
exchange chat, presence, media, or control messages.

## Response

A response is the reply to a request. It usually includes status information,
headers or metadata, and often a body.

## Package

A package is a bundle meant to be moved or installed as one unit. It usually
has a stable layout, a version, and integrity expectations.

## Propagation

Propagation means carrying context from one service boundary to another. In
distributed tracing, this usually means copying trace identifiers into headers
so downstream work can stay part of the same trace.

## Rehydration

Rehydration means rebuilding active working state from stored state. A system
rehydrates when it restarts and reconstructs what it needs from durable data.

## Replica

A replica is another stored copy of an object. Replicas are used for safety,
availability, performance, or recovery.

## Retry

Retry means trying the same operation again after it fails. A retry queue keeps
failed work around so the runtime can attempt delivery again later.

## Retention

Retention is the rule that says how long an object should be kept before it is
expired, archived, or destroyed.

## Session

A session is one long-lived runtime context that owns connection state and the
work that happens inside that state over time.

## Session Ticket

A session ticket is resumable security state that lets a later connection reuse
part of the work already completed during an earlier handshake.

## Shard

A shard is one part of a larger dataset or object set. Sharding spreads large
data or large workloads across multiple units.

## Signaling

Signaling is the exchange of control messages that helps two or more peers
agree how to start or change a realtime session. In chat and video systems this
often includes room joins, offers, answers, ICE candidates, mute state, and
presence updates.

## TLS

TLS is the security protocol that protects traffic in transit and helps a
client verify that it is talking to the right peer. It is also how a client can
present its own certificate when mutual authentication is required.

## Span

A span is one timed unit of work inside a trace. It usually has a name, a start
time, an end time, and attributes that describe what the work was about.

## Streaming

Streaming means data is read or written over time instead of being treated as
one already-finished body. A streamed response, for example, may deliver many
chunks before the application reaches the end.

## STUN

STUN is a helper protocol used in realtime media systems to help a client learn
how it appears to the outside network.

## Stream

A stream is one ordered lane of data or protocol work inside a larger
connection or session. A connection may carry many streams at once.

## Telemetry

Telemetry is the runtime information a system records about itself, such as
metrics, spans, and logs, so operators and control loops can understand what is
happening.

## TURN

TURN is a relay protocol used when two realtime peers cannot reach each other
directly and need a server to forward media between them.

## Trace

A trace is the larger operation made up of one or more spans. A single request
moving through several services may produce one trace with many spans.

## Trace Context

Trace context is the identifying information that lets one piece of work stay
connected to the rest of the trace. It usually includes trace IDs, span IDs,
and related propagation metadata.

## WebRTC

WebRTC is a realtime communication stack used for browser audio, video, and
data channels. It handles media session setup, peer negotiation, and transport
behavior for live calls.

## Tiering

Tiering means placing data across faster and slower storage classes such as
memory, local fast disks, distributed stores, and colder archive layers.

## TTL

TTL means "time to live". It is how long an object or cached copy remains valid
before it should expire or be checked again.
