# Getting Started

This chapter is the easiest place to enter the King extension.

King is a PHP extension for programs that need more control than a small
request helper or a short-lived web script can offer. It is built for systems
that keep connections open, move large [payloads](./glossary.md#payload),
stream responses, talk to multiple protocols, store durable state, export
[telemetry](./glossary.md#telemetry), and make operational decisions from
inside PHP.

That description is broad on purpose. King is not one narrowly focused client
library. It is a native runtime that lets PHP code work with transport,
streaming, storage, control-plane traffic, orchestration, and operations
through one extension.

## Fastest First Success

If you want the shortest path from zero to "King is loaded on this machine",
start with a local repository build:

```bash
git clone --recurse-submodules https://github.com/Intelligent-Intern/king.git
cd king
./infra/scripts/build-profile.sh release
php -d extension="$(pwd)/extension/modules/king.so" -r 'echo king_version(), PHP_EOL;'
```

If that prints a version string, King is alive in your local PHP runtime.

This is the easiest path today because it does not depend on package metadata,
system-wide extension registration, or external package publication. It builds
the extension and the matching QUIC runtime artifacts from the repository you
already have in front of you.

## What PIE Is

If you keep seeing "PIE" and wondering what that means, here is the short
version:

- PIE means `PHP Installer for Extensions`
- it is the successor to PECL
- Composer installs PHP packages into a project
- PIE installs PHP extensions into a PHP runtime

If you already know the older PHP world, this is the translation:

- PECL is the old name most extension-install tutorials still mention
- PIE is the newer installer direction you should map that knowledge onto
- so if an older guide says "install the extension with PECL", the modern
  King-shaped question is "what is the PIE install path?"

That matters because King is not a normal Composer library. It is an extension.
So the long-term packaging direction is PIE, not "drop some PHP files into
vendor and hope the runtime changes itself".

The King-specific PIE details live in [PIE Install](./pie-install.md). For a
first local success, the repository build path above is still the shortest
route.

If that sounds large, the good news is that the basic idea is still simple.
Your PHP code decides what should happen. King owns the runtime machinery that
makes it happen and reports back what happened.

## What You Can Build With King

People usually arrive at King because they need one or more of these things.
They may need an HTTP client with real timeout and streaming control. They may
need long-lived WebSocket connections. They may need HTTP/2 or HTTP/3. They may
need binary payloads described by schemas. They may need a durable object
store, a CDN-aware cache path, an MCP runtime, a pipeline orchestrator, or an
autoscaling control loop that reacts to telemetry.

What matters is that these are not separate libraries glued together in user
code. They live inside one extension and share one runtime model. The same
configuration object can shape transport policy. The same lifecycle layer can
report subsystem truth. The same telemetry layer can observe client, server,
storage, and orchestration behavior. The same release workflow can test them
together.

## The Main Runtime Objects

Most King programs revolve around a small number of names. It is worth learning
these early because they reappear throughout the rest of the handbook.

`King\Config` is a validated snapshot of runtime policy. You use it when you
want to say how requests, streams, telemetry, transport, DNS, or other
subsystems should behave.

`King\Session` represents one live transport context. In plain language, this
is the object that owns a [session](./glossary.md#session) or listener state.

`King\Stream` represents one unit of work inside a session. For HTTP, this is
where request data and response data meet the runtime.

`King\Response` represents a received [response](./glossary.md#response) in
structured form. It gives you
status, headers, and body access in one object.

`King\CancelToken` is an explicit stop signal that belongs to the caller. If an
operation must be interruptible by your own program logic, a cancel token is
the usual tool.

King exposes these ideas in two programming styles:

- a direct procedural API such as `king_send_request()` or
  `king_client_websocket_connect()`
- an object-oriented API such as `King\Config`, `King\Client\Http3Client`, or
  `King\WebSocket\Connection`

Neither style is the "fake" surface. They are two real ways to drive the same
runtime. The practical choice is about how much explicit control you want in a
small script versus how much structure you want in a larger application.

You do not need to memorize all of this before writing your first program. The
important part is to understand what kind of system King wants you to build.
It makes state explicit. Configuration is explicit. Sessions are explicit.
Streams are explicit. Cancellation is explicit. That is one reason the
extension scales from small request flows to long-lived runtime behavior
without changing its basic vocabulary.

## A First Small Request

The shortest useful example is an outbound [request](./glossary.md#request)
through the procedural API.

```php
<?php

$response = king_send_request('https://example.com');

if ($response === false) {
    throw new RuntimeException(king_get_last_error());
}

echo $response['status'], PHP_EOL;
echo $response['body'], PHP_EOL;
```

This example already shows the basic pattern. Your PHP code asks for work. The
extension performs the protocol work. The result comes back as structured data.
If the request fails, you can inspect the runtime error directly instead of
guessing from a partial body or an exception from an unrelated helper layer.

## The Same Idea With A Config Object

As soon as you want explicit control, create a configuration snapshot.

```php
<?php

$config = new King\Config([
    'tls.verify_peer' => true,
    'quic.ping_interval_ms' => 15000,
    'otel.service_name' => 'my_app',
]);

$response = king_send_request('https://example.com', [
    'config' => $config,
]);
```

This is often the point where King starts to feel different from a typical
helper library. The runtime policy is no longer hidden in global state or
spread across unrelated option arrays. It lives in one explicit object that can
be reused, inspected, and passed through different client or subsystem paths.

## The Same Idea With The OO Surface

The procedural API is direct and compact. The object-oriented API is better
when you want long-lived clients or a more structured application design.

```php
<?php

$client = new King\Client\Http3Client(
    new King\Config([
        'tls.verify_peer' => true,
        'quic.ping_interval_ms' => 15000,
    ])
);

$response = $client->request('GET', 'https://example.com');

echo $response->getStatusCode(), PHP_EOL;
echo $response->getBody(), PHP_EOL;
```

The two styles sit on the same native runtime. The choice is about how you want
to structure your program, not about two different implementations. A small
script may prefer the procedural API. A larger application may prefer long-lived
objects. The contract stays the same underneath.

For the first local WebSocket roundtrip below, the guide intentionally uses the
procedural API. That is the most complete beginner path today because the
current receive loop and the on-wire server-side upgrade leaf are both exposed
there most directly.

## What You Should Know Early

New readers often ask whether King is a framework. It is not. It is a native
runtime that PHP code can use directly.

They also ask whether they must use the object-oriented API. They do not. The
procedural surface and the OO surface are parallel views of the same runtime.

They ask whether every program needs a `King\Config` object. It does not. Many
calls can use inline configuration arrays or system defaults. The reason to use
`King\Config` is clarity, reuse, and consistent policy across several calls.

They also ask when they should care about
[cancel tokens](./glossary.md#cancel-token). The answer is simple: use them
whenever your own code may need to stop a request, an MCP exchange, a pipeline
run, or another long operation before it finishes on its own.

Another common question is whether they need to understand every subsystem
before using the extension. They do not. The handbook is structured so you can
start with the piece you need. The value of the early chapters is that they
teach the common language shared by the later ones.

## First Local WebSocket Roundtrip

The fastest honest "King is really doing something live" demo is a local
WebSocket roundtrip over the current on-wire HTTP/1 one-shot upgrade path.

Create `server.php`:

```php
<?php

king_http1_server_listen_once('127.0.0.1', 9501, null, static function (array $request): array {
    if (($request['path'] ?? '/') !== '/chat') {
        return [
            'status' => 404,
            'headers' => ['content-type' => 'text/plain'],
            'body' => "not found\n",
        ];
    }

    $websocket = king_server_upgrade_to_websocket($request['session'], $request['stream_id']);
    if (!is_resource($websocket)) {
        return [
            'status' => 400,
            'headers' => ['content-type' => 'text/plain'],
            'body' => "upgrade failed\n",
        ];
    }

    $incoming = king_client_websocket_receive($websocket, 1000);

    king_websocket_send($websocket, json_encode([
        'ok' => true,
        'received' => $incoming,
        'path' => $request['uri'],
    ], JSON_UNESCAPED_SLASHES));

    king_client_websocket_close($websocket, 1000, 'done');

    return [
        'status' => 101,
        'headers' => [],
        'body' => '',
    ];
});
```

Create `client.php`:

```php
<?php

$websocket = king_client_websocket_connect('ws://127.0.0.1:9501/chat');
king_client_websocket_send($websocket, 'hello-from-php');
echo king_client_websocket_receive($websocket, 1000), PHP_EOL;
king_client_websocket_close($websocket, 1000, 'bye');
```

Open terminal 1 and start the server:

```bash
php -d extension="$(pwd)/extension/modules/king.so" server.php
```

Open terminal 2 and run the client:

```bash
php -d extension="$(pwd)/extension/modules/king.so" client.php
```

You should see JSON come back from the server, roughly like this:

```json
{"ok":true,"received":"hello-from-php","path":"/chat"}
```

That is already a real proof point:

- the server bound a real TCP listener
- the client performed a real WebSocket handshake
- the server upgraded the HTTP request into a live channel
- one side sent a frame
- the other side read it and answered

The listener used here is intentionally one-shot. That makes it the smallest
real on-wire WebSocket path in King today and keeps the first successful demo
easy to reason about.

## What To Build Right After That

Once the local WebSocket roundtrip works, the next step that feels most "King"
is usually one of these:

- add `King\Config` so transport and runtime policy stop living in ad hoc arrays
- switch from a pure chat frame to an object-store backed notification flow
- encode your message payloads with IIBIN instead of JSON
- start attaching telemetry so you can see the live channel behavior

## Where To Go Next

The next useful chapter is [Glossary](./glossary.md) if you want the common
terms explained. After that, read [Platform Model](./platform-model.md) to see
how the main parts of the extension fit together. If configuration naming and
policy are still unclear, continue directly into
[Configuration Handbook](./configuration-handbook.md).

If you already know that you care most about transport, go next to
[HTTP Clients and Streams](./http-clients-and-streams.md) and
[QUIC and TLS](./quic-and-tls.md).

If you already know that you care most about data and storage, go next to
[Object Store and CDN](./object-store-and-cdn.md), [MCP](./mcp.md), and
[IIBIN](./iibin.md).
