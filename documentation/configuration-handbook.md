# Configuration Handbook

This chapter explains how configuration works in King before you look at the
full key-by-key reference.

The most important idea is that King has two configuration layers. One layer is
meant for application code. The other is meant for operators and deployment.
Once that split is clear, the rest of the configuration model becomes much
easier to understand.

## The Two Configuration Layers

The first layer is runtime configuration. This is the configuration your PHP
code passes directly into the runtime. You usually reach it through
`king_new_config()`, `new King\Config([...])`, or other APIs that accept a
config array or a config object.

The second layer is system configuration. This is the configuration loaded from
`php.ini` through `king.*` directives. This layer is owned by the
[deployment](./glossary.md#deployment).

Both layers describe the same platform, but they solve different problems.

## Runtime Configuration

Runtime configuration is the right tool when one workflow needs an explicit
policy in code.

For example, one client may need stricter TLS checks. One service may need a
different telemetry service name. One orchestration step may need a specific
timeout. One MCP path may need a custom size limit. In these cases it makes
sense to keep the policy close to the code that depends on it.

Runtime keys use namespaced names such as `quic.*`, `tls.*`, `http2.*`,
`storage.*`, `cdn.*`, `dns.*`, `otel.*`, `mcp.*`, `orchestrator.*`, and other
subsystem families.

## System Configuration

System configuration is the right tool when a setting belongs to the whole
process or the whole deployment.

This includes things such as filesystem paths, provider credentials, operator
defaults, deployment-level listeners, process-wide security rules, or settings
that should be fixed before application code begins to run.

In practical terms, system configuration is where operators place the decisions
that application code should inherit rather than redefine.

## When To Use Which Layer

Use runtime configuration when the application itself needs to say how a
particular workflow should behave.

Use system configuration when the setting belongs to the machine, the container,
the deployment policy, or the operator.

If you are not sure, ask a simple question: does this choice belong to the code
path, or does it belong to the environment that runs the code? If it belongs to
the environment, it is usually a system setting.

## Why The Keys Are Namespaced

King is a large extension. Namespaces keep the settings readable.

When you see `quic.cc_algorithm`, you know the key belongs to QUIC transport.
When you see `tls.verify_peer`, you know it belongs to transport security. When
you see `otel.service_name`, you know it belongs to
[telemetry](./glossary.md#telemetry). When you see
`storage.default_replication_factor`, you know it belongs to the object store.

This matters because a long config file becomes manageable when the key already
tells you which subsystem you are touching.

## A Small Runtime Configuration Example

```php
<?php

$config = new King\Config([
    'tls.verify_peer' => true,
    'http2.max_concurrent_streams' => 100,
    'otel.service_name' => 'billing_api',
]);
```

This one object now carries transport, protocol, and telemetry policy together.

## Reading Configuration Back

Configuration is not only something you write once and forget.

```php
<?php

$config = new King\Config([
    'quic.cc_algorithm' => 'bbr',
    'otel.service_name' => 'my_service',
]);

echo $config->get('quic.cc_algorithm'), PHP_EOL;
print_r($config->toArray());
```

Reading the configuration back is useful for diagnostics, startup logs, admin
UIs, and sanity checks in larger systems.

## The Main Configuration Families

The transport families describe how connections behave on the wire. These
include QUIC, TLS, HTTP/2, TCP, and related network behavior.

The realtime and protocol families describe longer-lived or protocol-aware
traffic. These include WebSocket, WebTransport-related settings, MCP, and
server upgrade behavior.

The data families describe how payloads are stored, described, and delivered.
These include the object store, CDN, IIBIN, and state-oriented subsystems.

The control-plane families describe how the platform finds services and
coordinates work. These include autoscaling, router and load-balancer policy,
Semantic-DNS, and the pipeline orchestrator.

The observability families describe metrics, traces, logs, export behavior, and
queueing limits.

## A Good Way To Change Configuration Safely

The safest pattern is simple.

Begin with deployment defaults. Add only the runtime overrides your code truly
needs. Keep related keys together in one config snapshot. Prefer clear explicit
values over hidden behavior. Keep secrets, paths, and operator-owned values in
system configuration whenever possible.

This approach keeps code readable and deployments predictable.

## Three Small Examples

Here is a strict TLS client configuration:

```php
<?php

$config = new King\Config([
    'tls.verify_peer' => true,
    'tls.default_ca_file' => '/etc/ssl/certs/ca-certificates.crt',
]);
```

Here is an HTTP/2-oriented client with a larger concurrency limit:

```php
<?php

$config = new King\Config([
    'http2.max_concurrent_streams' => 256,
    'http2.enable_push' => true,
]);
```

Here is a telemetry-aware service configuration:

```php
<?php

$config = new King\Config([
    'otel.enable' => true,
    'otel.service_name' => 'orders_api',
    'otel.exporter_endpoint' => 'http://collector.internal:4317',
]);
```

## How Large The Surface Really Is

The configuration surface is large because the extension covers transport,
storage, control plane, telemetry, orchestration, and security. Most programs
do not need to touch everything. The point of the large surface is not to force
you to set every key. The point is to make important policy explicit when you
need it.

## Where The Full Lists Live

When you need the full key list, go next to the
[Runtime Configuration Reference](./runtime-configuration.md) and the
[System INI Reference](./system-ini-reference.md).

This chapter is meant to explain the shape of the model. Those chapters are the
exhaustive indexes.
