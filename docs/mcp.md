# MCP

King has two MCP surfaces:

- `King\MCPServer` and `king_mcp_server_*` implement public MCP server
  handling over JSON-RPC, stdio, and Streamable HTTP.
- `King\MCP` and `king_mcp_request*` are King-internal peer calls for trusted
  runtime-to-runtime work, including configured IIBIN payload contracts.

The public server surface follows the MCP wire shape: JSON-RPC 2.0 messages,
newline-delimited stdio messages, and a single Streamable HTTP endpoint that
accepts POST requests and can return either `application/json` or
`text/event-stream`.

## Internal Layout

The native MCP runtime contract lives in `extension/include/mcp/mcp.h`.
Runtime code lives under `extension/src/mcp/mcp/`; PHP userland binding code
lives under `extension/src/mcp/php_binding/`. `extension/src/php_king/mcp.inc`
is only the extension bootstrap bridge.

## Public Server Definition

```php
<?php
use King\MCPServer;

$server = new MCPServer([
    'serverInfo' => [
        'name' => 'invoice-support',
        'version' => '1.0.0',
    ],
    'instructions' => 'Expose tenant-scoped invoice support tools only.',
    'streamable_http' => [
        'prefer_sse' => true,
        'allowed_origins' => ['https://support.example.com'],
    ],
    'tools' => [
        'invoice.status' => [
            'description' => 'Return the visible processing status for one invoice.',
            'inputSchema' => [
                'type' => 'object',
                'required' => ['tenant_id', 'invoice_id'],
                'properties' => [
                    'tenant_id' => ['type' => 'string'],
                    'invoice_id' => ['type' => 'string'],
                ],
            ],
            'handler' => static function (array $arguments, array $context): array {
                authorize_support_lookup($context['user'] ?? null, $arguments['tenant_id']);

                return [
                    'content' => [[
                        'type' => 'text',
                        'text' => lookup_invoice_status_text(
                            $arguments['tenant_id'],
                            $arguments['invoice_id']
                        ),
                    ]],
                ];
            },
        ],
    ],
]);
```

The tool handler receives the MCP `arguments` and a caller-provided context.
Return a complete MCP tool result when you need exact control over `content`,
`structuredContent`, or `isError`. Returning a scalar or arbitrary array is
also accepted; King wraps it into a text result and, for structured values,
adds `structuredContent`.

## stdio Server

```php
<?php
$server->runStdio([
    'max_line_bytes' => 1024 * 1024,
]);
```

The stdio transport reads one UTF-8 JSON-RPC message per line from stdin and
writes only JSON-RPC messages to stdout. Notifications such as
`notifications/initialized` do not produce a response.

## Streamable HTTP Server

```php
<?php
$server = new King\MCPServer($definition);

while (true) {
    king_http1_server_listen_once('127.0.0.1', 9090, null, static function (array $request) use ($server): array {
        return $server->handleHttp($request, [
            'user' => authenticate_mcp_request($request['headers'] ?? []),
        ]);
    });
}
```

The HTTP adapter expects King's normal request array with `method`, `headers`,
and `body`, and returns King's normal response array with `status`, `headers`,
and `body`. POST requests require an `Accept` header that includes both
`application/json` and `text/event-stream`. GET opens a short SSE stream
response; long-lived streaming belongs in the surrounding HTTP server loop and
session policy. Browser `Origin` headers are rejected unless the exact origin
is listed in `streamable_http.allowed_origins`.

## Direct JSON-RPC Dispatch

```php
<?php
$response = $server->handleJsonRpc(json_encode([
    'jsonrpc' => '2.0',
    'id' => 1,
    'method' => 'tools/list',
], JSON_THROW_ON_ERROR));

echo $response . PHP_EOL;
```

Procedural code can use the same dispatcher:

```php
<?php
$server = king_mcp_server_create($definition);
$response = king_mcp_server_handle_jsonrpc($server, [
    'jsonrpc' => '2.0',
    'id' => 'call-1',
    'method' => 'tools/call',
    'params' => [
        'name' => 'invoice.status',
        'arguments' => [
            'tenant_id' => 'tenant-42',
            'invoice_id' => 'INV-1001',
        ],
    ],
], ['user' => $supportUser]);
```

## Internal King Peer With IIBIN

Use `King\MCP` for trusted King runtime peers that speak King's internal
line-framed transport. This is separate from the public MCP server surface.

```php
<?php
use King\Config;
use King\MCP;

king_proto_define_schema('SupportToolRequest', [
    'tenant_id' => ['tag' => 1, 'type' => 'string', 'required' => true],
    'case_id' => ['tag' => 2, 'type' => 'string', 'required' => true],
    'question' => ['tag' => 3, 'type' => 'string', 'required' => true],
]);

king_proto_define_schema('SupportToolResponse', [
    'answer' => ['tag' => 1, 'type' => 'string', 'required' => true],
    'source_count' => ['tag' => 2, 'type' => 'uint32'],
]);

$mcp = new MCP('127.0.0.1', 9091, new Config([
    'mcp.iibin_routes' => [
        'support.faq/answer' => [
            'request_schema' => 'SupportToolRequest',
            'response_schema' => 'SupportToolResponse',
            'decode_as_object' => false,
        ],
    ],
]));

$answer = $mcp->requestIibin('support.faq', 'answer', [
    'tenant_id' => 'tenant-42',
    'case_id' => 'CASE-1001',
    'question' => 'Which invoice status can the customer see?',
]);
```

The IIBIN route contract is copied into the MCP connection state when the
connection is created. Later changes to the `King\Config` object do not change
the schemas used by that connection.
