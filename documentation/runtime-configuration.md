# Runtime Configuration Reference

This page documents the namespaced runtime override surface used by
`king_new_config()` and `King\Config`.

If you want the conceptual explanation first, read
[Configuration Handbook](./configuration-handbook.md). This page is the place to
come back to when you already know which subsystem you want to change and need
the exact key name and a plain explanation of what that key is for.

## How To Read This Page

Each namespace groups keys for one subsystem. The key names are written exactly
as they appear in runtime configuration arrays and in `King\Config`.

```php
<?php

$config = new King\Config([
    'tls.verify_peer' => true,
    'http2.max_concurrent_streams' => 100,
    'otel.service_name' => 'payments',
]);
```

The reference below answers two questions for every key. The first question is
what the key controls. The second question is when you would care about it.

## Namespaces At A Glance

The runtime configuration surface is organized into the following namespaces.

| Namespace | Use it for |
| --- | --- |
| `quic.*` | QUIC transport pacing, flow control, loss recovery, and datagrams |
| `tls.*` | certificate verification, cipher policy, ticket reuse, and related encryption policy |
| `http2.*` | HTTP/2 multiplexing, push capture, and frame/window limits |
| `tcp.*` | TCP socket and listener behavior |
| `autoscale.*` | autoscaling provider, lifecycle, and budget policy |
| `mcp.*` | MCP transport timeouts, retries, and caching |
| `orchestrator.*` | pipeline execution policy and backend selection |
| `geometry.*` | semantic geometry defaults and algorithm choices |
| `smartcontract.*` | ledger, wallet, and contract event policy |
| `ssh.*` | SSH-over-QUIC gateway behavior |
| `storage.*` | object-store policy |
| `cdn.*` | cache and origin behavior |
| `dns.*` | Smart DNS and Semantic-DNS behavior |
| `otel.*` | telemetry export policy |

## QUIC: `quic.*`

Use the `quic.*` namespace when you want to change how the QUIC transport
behaves at the packet and stream level. These settings shape congestion
control, pacing, probe timeouts, flow-control windows, and datagram queues.

Teams usually care about this namespace when they are tuning throughput, burst
behavior, stream concurrency, or the way the runtime behaves on unstable
networks.

| Key | Meaning |
| --- | --- |
| `quic.cc_algorithm` | Selects the congestion-control algorithm. |
| `quic.cc_initial_cwnd_packets` | Sets the initial congestion window in packets. |
| `quic.cc_min_cwnd_packets` | Sets the minimum congestion window floor. |
| `quic.cc_enable_hystart_plus_plus` | Enables or disables HyStart++ startup behavior. |
| `quic.pacing_enable` | Enables paced packet transmission. |
| `quic.pacing_max_burst_packets` | Limits how many packets may be sent in one pacing burst. |
| `quic.max_ack_delay_ms` | Sets the peer ACK delay budget. |
| `quic.ack_delay_exponent` | Sets the ACK delay exponent used for delay interpretation. |
| `quic.pto_timeout_ms_initial` | Sets the initial probe timeout. |
| `quic.pto_timeout_ms_max` | Sets the maximum probe timeout. |
| `quic.max_pto_probes` | Limits how many PTO probes are sent before loss escalation. |
| `quic.ping_interval_ms` | Sets the keepalive ping interval. |
| `quic.initial_max_data` | Sets the connection-level flow-control window. |
| `quic.initial_max_stream_data_bidi_local` | Sets the local bidirectional stream receive window. |
| `quic.initial_max_stream_data_bidi_remote` | Sets the remote bidirectional stream receive window. |
| `quic.initial_max_stream_data_uni` | Sets the unidirectional stream receive window. |
| `quic.initial_max_streams_bidi` | Limits bidirectional stream count. |
| `quic.initial_max_streams_uni` | Limits unidirectional stream count. |
| `quic.active_connection_id_limit` | Limits how many active connection IDs are kept. |
| `quic.stateless_retry_enable` | Enables or disables stateless retry behavior. |
| `quic.grease_enable` | Enables or disables QUIC grease behavior. |
| `quic.datagrams_enable` | Enables QUIC datagram support. |
| `quic.dgram_recv_queue_len` | Sets the datagram receive queue length. |
| `quic.dgram_send_queue_len` | Sets the datagram send queue length. |

## TLS: `tls.*`

Use the `tls.*` namespace when you need to express trust, identity, or
encryption policy. This namespace covers certificate verification, ticket reuse,
cipher suites, TLS version floors, and the storage or MCP encryption features
that build on top of that same trust model.

This is the namespace that operators usually touch when they need to harden
certificate verification, load a CA bundle, change ticket behavior, or wire in
certificate files for client authentication.

| Key | Meaning |
| --- | --- |
| `tls.verify_peer` | Turns peer certificate verification on or off. |
| `tls.enable_early_data` | Enables or disables TLS early data. |
| `tls.enable_ocsp_stapling` | Controls OCSP stapling policy. |
| `tls.enable_ech` | Controls Encrypted ClientHello policy. |
| `tls.require_ct_policy` | Controls certificate transparency enforcement. |
| `tls.disable_sni_validation` | Disables SNI validation when explicitly allowed. |
| `tls.verify_depth` | Sets certificate chain verification depth. |
| `tls.session_ticket_lifetime_sec` | Sets the session ticket lifetime. |
| `tls.server_0rtt_cache_size` | Sets the 0-RTT session cache size on the server side. |
| `tls.default_ca_file` | Points at the default CA bundle file. |
| `tls.default_cert_file` | Points at the default client certificate file. |
| `tls.default_key_file` | Points at the default client private key file. |
| `tls.ticket_key_file` | Points at the server ticket key file. |
| `tls.ciphers_tls13` | Sets the TLS 1.3 cipher-suite list. |
| `tls.ciphers_tls12` | Sets the TLS 1.2 cipher-suite list. |
| `tls.curves` | Sets the preferred curve list. |
| `tls.min_version_allowed` | Sets the runtime-wide TLS version floor. |
| `tls.tcp_tls_min_version_allowed` | Sets the TLS version floor for TCP/TLS paths. |
| `tls.storage_encryption_at_rest_enable` | Enables object-store encryption at rest. |
| `tls.storage_encryption_algorithm` | Selects the encryption algorithm for stored objects. |
| `tls.storage_encryption_key_path` | Points at the key material used for object-store encryption. |
| `tls.mcp_payload_encryption_enable` | Enables MCP payload encryption. |
| `tls.mcp_payload_encryption_psk_env_var` | Names the environment variable that holds the MCP pre-shared key. |

## HTTP/2: `http2.*`

Use the `http2.*` namespace when you need to tune HTTP/2 multiplexing. These
settings control stream windows, frame sizing, header limits, push behavior,
and the overall concurrency policy of an HTTP/2 session.

This is the namespace you reach for when one session is carrying too many
parallel streams, when push capture should be disabled, or when memory and flow
control need tighter boundaries.

| Key | Meaning |
| --- | --- |
| `http2.enable` | Enables or disables the HTTP/2 runtime. |
| `http2.initial_window_size` | Sets the per-stream flow-control window. |
| `http2.max_concurrent_streams` | Limits how many streams may be active at once. |
| `http2.max_header_list_size` | Limits accepted header bytes. |
| `http2.enable_push` | Enables or disables server push capture. |
| `http2.max_frame_size` | Sets the maximum HTTP/2 frame size. |

## TCP: `tcp.*`

Use the `tcp.*` namespace for socket-level behavior. These keys decide how TCP
connections are created, how listeners behave, and which low-level socket
options are preferred.

This is the namespace operators touch when they care about backlog depth,
keepalive, Nagle interaction, `SO_REUSEPORT`, or TCP-specific TLS policy.

| Key | Meaning |
| --- | --- |
| `tcp.enable` | Enables or disables the TCP transport. |
| `tcp.reuse_port_enable` | Controls `SO_REUSEPORT` behavior. |
| `tcp.nodelay_enable` | Controls `TCP_NODELAY` behavior. |
| `tcp.cork_enable` | Controls `TCP_CORK` behavior. |
| `tcp.keepalive_enable` | Enables or disables TCP keepalive. |
| `tcp.max_connections` | Sets the listener connection ceiling. |
| `tcp.connect_timeout_ms` | Sets the outbound TCP connect timeout. |
| `tcp.listen_backlog` | Sets the listener backlog depth. |
| `tcp.keepalive_time_sec` | Sets the idle time before keepalive begins. |
| `tcp.keepalive_interval_sec` | Sets the interval between keepalive probes. |
| `tcp.keepalive_probes` | Sets how many keepalive probes are attempted. |
| `tcp.tls_min_version_allowed` | Sets the TCP-specific TLS version floor. |
| `tcp.tls_ciphers_tls12` | Sets TCP-specific TLS 1.2 cipher suites. |

## Autoscaling: `autoscale.*`

Use the `autoscale.*` namespace when you need to define how the autoscaling
controller talks to a provider, what node lifecycle rules it uses, and what
budget or quota limits it must respect.

This is the place to set provider credentials, bootstrap policy, scale-up and
scale-down thresholds, region and instance type, and the paths or endpoints the
autoscaling controller needs to manage live nodes.

| Key | Meaning |
| --- | --- |
| `autoscale.provider` | Selects the autoscaling provider backend. |
| `autoscale.region` | Selects the target cloud region. |
| `autoscale.credentials_path` | Points at the provider credentials file. |
| `autoscale.api_endpoint` | Sets the provider API base URL. |
| `autoscale.state_path` | Points at the autoscaling durable state file. |
| `autoscale.server_name_prefix` | Sets the hostname prefix for managed nodes. |
| `autoscale.bootstrap_user_data` | Supplies provider bootstrap user-data. |
| `autoscale.firewall_ids` | Lists the firewall IDs attached to managed nodes. |
| `autoscale.placement_group_id` | Sets the provider placement group identifier. |
| `autoscale.prepared_release_url` | Points at the release artifact used for bootstrap. |
| `autoscale.join_endpoint` | Points at the cluster join endpoint. |
| `autoscale.hetzner_budget_path` | Points at the Hetzner budget probe file. |
| `autoscale.min_nodes` | Sets the minimum managed node count. |
| `autoscale.max_nodes` | Sets the maximum managed node count. |
| `autoscale.max_scale_step` | Limits how many nodes can be added or removed per decision. |
| `autoscale.scale_up_cpu_threshold_percent` | Sets the CPU threshold for scale-up. |
| `autoscale.scale_down_cpu_threshold_percent` | Sets the CPU threshold for scale-down. |
| `autoscale.scale_up_policy` | Selects the scale-up action shape. |
| `autoscale.spend_warning_threshold_percent` | Sets the spend warning threshold. |
| `autoscale.spend_hard_limit_percent` | Sets the spend hard limit. |
| `autoscale.quota_warning_threshold_percent` | Sets the quota warning threshold. |
| `autoscale.quota_hard_limit_percent` | Sets the quota hard limit. |
| `autoscale.cooldown_period_sec` | Sets the cooldown period between decisions. |
| `autoscale.idle_node_timeout_sec` | Sets the rollback timeout for pending or idle nodes. |
| `autoscale.instance_type` | Selects the provisioned instance flavor. |
| `autoscale.instance_image_id` | Selects the provisioned image ID. |
| `autoscale.network_config` | Sets provider-specific network attachment settings. |
| `autoscale.instance_tags` | Sets the provider tag set for managed nodes. |

## MCP: `mcp.*`

Use the `mcp.*` namespace for Model Context Protocol transport policy. These
keys define timeouts, size limits, retry defaults, and request caching.

This namespace matters when the control plane is using MCP heavily and needs
clear limits on request size, retry behavior, or cache lifetime.

| Key | Meaning |
| --- | --- |
| `mcp.default_request_timeout_ms` | Sets the default unary request timeout. |
| `mcp.max_message_size_bytes` | Limits inbound and outbound MCP message size. |
| `mcp.default_retry_policy_enable` | Enables or disables the default retry policy. |
| `mcp.default_retry_max_attempts` | Sets the retry attempt ceiling. |
| `mcp.default_retry_backoff_ms_initial` | Sets the initial retry backoff. |
| `mcp.enable_request_caching` | Enables or disables MCP request caching. |
| `mcp.request_cache_ttl_sec` | Sets the request-cache TTL. |

## Orchestrator: `orchestrator.*`

Use the `orchestrator.*` namespace when you need to define how pipeline runs are
timed, where they execute, and how controller and worker topology should work.

This namespace covers local execution, file-worker queueing, remote-peer
execution, and the concurrency and timeout policy that shapes those runs.

One important boundary belongs here: the persistent `orchestrator_state_path` is
system-owned and therefore not part of the normal runtime override surface. It
belongs in system INI, not here.

| Key | Meaning |
| --- | --- |
| `orchestrator.default_pipeline_timeout_ms` | Sets the default timeout budget for one run. |
| `orchestrator.max_recursion_depth` | Limits nested orchestrator recursion depth. |
| `orchestrator.loop_concurrency_default` | Sets the default local concurrency ceiling. |
| `orchestrator.enable_distributed_tracing` | Controls distributed tracing policy for runs. |
| `orchestrator.execution_backend` | Selects `local`, `file_worker`, or `remote_peer` execution. |
| `orchestrator.worker_queue_path` | Points at the file-worker queue directory. |
| `orchestrator.remote_host` | Sets the remote execution peer host. |
| `orchestrator.remote_port` | Sets the remote execution peer port. |

## Geometry: `geometry.*`

Use the `geometry.*` namespace for vector and geometric computation policy. This
namespace lets the runtime state its numeric precision, default vector size, and
algorithm preferences for hull, containment, and distance work.

This matters when a workload depends on embeddings, clustering, topology-aware
ranking, or other geometric reasoning.

| Key | Meaning |
| --- | --- |
| `geometry.default_vector_dimensions` | Sets the default vector dimensionality. |
| `geometry.calculation_precision` | Selects numeric precision such as `float32` or `float64`. |
| `geometry.convex_hull_algorithm` | Selects the convex-hull algorithm. |
| `geometry.point_in_polytope_algorithm` | Selects the point-in-polytope algorithm. |
| `geometry.hausdorff_distance_algorithm` | Selects the Hausdorff distance algorithm. |
| `geometry.spiral_search_step_size` | Sets the spiral-search step size. |
| `geometry.core_consolidation_threshold` | Sets the threshold for consolidating candidates into the core set. |

## Smart Contracts: `smartcontract.*`

Use the `smartcontract.*` namespace for ledger integration, wallet policy, and
contract event behavior. This is the namespace that tells the runtime which
ledger backend is active and how contract execution should be funded and signed.

It matters when applications rely on contract metadata, RPC connectivity, gas
defaults, local wallets, hardware wallets, or contract event listeners.

| Key | Meaning |
| --- | --- |
| `smartcontract.enable` | Enables or disables the smart-contract runtime family. |
| `smartcontract.registry_uri` | Points at the contract registry URI. |
| `smartcontract.dlt_provider` | Selects the ledger provider. |
| `smartcontract.dlt_rpc_endpoint` | Points at the ledger RPC endpoint. |
| `smartcontract.chain_id` | Sets the target chain ID. |
| `smartcontract.default_gas_limit` | Sets the default gas limit. |
| `smartcontract.default_gas_price_gwei` | Sets the default gas price in gwei. |
| `smartcontract.default_wallet_path` | Points at the default wallet path. |
| `smartcontract.default_wallet_password_env_var` | Names the environment variable that holds the wallet password. |
| `smartcontract.use_hardware_wallet` | Enables or disables hardware-wallet usage. |
| `smartcontract.hsm_pkcs11_library_path` | Points at the PKCS#11 library path for HSM access. |
| `smartcontract.abi_directory` | Points at the ABI search directory. |
| `smartcontract.event_listener_enable` | Enables or disables contract event listeners. |

## SSH Over QUIC: `ssh.*`

Use the `ssh.*` namespace for SSH gateway behavior. These keys control listener
binding, default target selection, authentication mode, target mapping mode,
idle policy, and the URIs of the control-plane helpers involved in access
decisions.

This namespace matters when SSH access is being treated as a governed gateway
instead of a raw unmanaged tunnel.

| Key | Meaning |
| --- | --- |
| `ssh.gateway_enable` | Enables or disables the SSH gateway. |
| `ssh.gateway_log_session_activity` | Enables or disables session activity logging. |
| `ssh.gateway_listen_host` | Sets the gateway bind host. |
| `ssh.gateway_default_target_host` | Sets the default upstream target host. |
| `ssh.gateway_mcp_auth_agent_uri` | Points at the auth agent URI. |
| `ssh.gateway_user_profile_agent_uri` | Points at the user profile agent URI. |
| `ssh.gateway_listen_port` | Sets the gateway listen port. |
| `ssh.gateway_default_target_port` | Sets the default upstream target port. |
| `ssh.gateway_target_connect_timeout_ms` | Sets the upstream SSH connect timeout. |
| `ssh.gateway_idle_timeout_sec` | Sets the idle session timeout. |
| `ssh.gateway_auth_mode` | Selects the authentication mode. |
| `ssh.gateway_target_mapping_mode` | Selects how the upstream target is chosen. |

## Object Store: `storage.*`

Use the `storage.*` namespace for object-store behavior such as redundancy,
metadata handling, node discovery, chunk sizing, versioning, and
DirectStorage-style placement policy.

This namespace matters when durable object lifecycle and storage topology are a
core part of the application.

| Key | Meaning |
| --- | --- |
| `storage.enable` | Enables or disables the object-store runtime. |
| `storage.s3_api_compat_enable` | Enables or disables S3-compatible API behavior. |
| `storage.versioning_enable` | Enables or disables object versioning. |
| `storage.allow_anonymous_access` | Controls anonymous object access policy. |
| `storage.default_redundancy_mode` | Selects the default redundancy strategy. |
| `storage.erasure_coding_shards` | Sets the erasure-coding shard layout. |
| `storage.default_replication_factor` | Sets the default replication factor. |
| `storage.default_chunk_size_mb` | Sets the default chunk size in megabytes. |
| `storage.metadata_agent_uri` | Points at the metadata agent endpoint. |
| `storage.node_discovery_mode` | Selects storage-node discovery policy. |
| `storage.node_static_list` | Provides the static storage-node list. |
| `storage.metadata_cache_enable` | Enables or disables metadata caching. |
| `storage.metadata_cache_ttl_sec` | Sets metadata cache TTL. |
| `storage.enable_directstorage` | Enables or disables DirectStorage integration. |

## CDN: `cdn.*`

Use the `cdn.*` namespace for cache shape and origin behavior. These keys define
cache mode, memory and disk limits, TTL policy, origin endpoints, stale-on-error
behavior, and response-header additions.

This namespace matters when the runtime acts as a real cache and delivery edge
instead of only as an origin fetcher.

| Key | Meaning |
| --- | --- |
| `cdn.enable` | Enables or disables the CDN runtime. |
| `cdn.cache_mode` | Selects the cache implementation mode. |
| `cdn.cache_memory_limit_mb` | Sets the memory ceiling for cache state. |
| `cdn.cache_disk_path` | Points at the disk path used by the cache. |
| `cdn.cache_default_ttl_sec` | Sets the default cache TTL. |
| `cdn.cache_max_object_size_mb` | Sets the largest cacheable object size. |
| `cdn.cache_respect_origin_headers` | Controls whether origin cache headers are honored. |
| `cdn.cache_vary_on_headers` | Sets the header set used for `Vary` behavior. |
| `cdn.origin_mcp_endpoint` | Points at the MCP origin endpoint. |
| `cdn.origin_http_endpoint` | Points at the HTTP origin endpoint. |
| `cdn.origin_request_timeout_ms` | Sets the origin request timeout. |
| `cdn.serve_stale_on_error` | Controls stale-on-error behavior. |
| `cdn.response_headers_to_add` | Adds headers to cache responses. |
| `cdn.allowed_http_methods` | Sets the cacheable request methods. |

## DNS: `dns.*`

Use the `dns.*` namespace for Smart DNS and Semantic-DNS behavior. These keys
control the listener, record TTLs, service discovery shape, semantic mode, and
mother-node coordination.

This namespace matters when the runtime is being used for service registration,
discovery, route selection, or DNS-backed coordination.

| Key | Meaning |
| --- | --- |
| `dns.server_enable` | Enables or disables the DNS listener. |
| `dns.server_enable_tcp` | Enables or disables the TCP DNS listener. |
| `dns.enable_dnssec_validation` | Controls DNSSEC validation policy. |
| `dns.semantic_mode_enable` | Enables or disables Semantic-DNS behavior. |
| `dns.server_port` | Sets the DNS listen port. |
| `dns.default_record_ttl_sec` | Sets the default record TTL. |
| `dns.service_discovery_max_ips_per_response` | Limits how many discovery IPs appear in one response. |
| `dns.edns_udp_payload_size` | Sets the EDNS UDP payload size. |
| `dns.mothernode_sync_interval_sec` | Sets the mother-node synchronization interval. |
| `dns.mode` | Selects the DNS operating mode. |
| `dns.server_bind_host` | Sets the DNS bind host. |
| `dns.static_zone_file_path` | Points at the static zone file path. |
| `dns.recursive_forwarders` | Lists recursive forwarders. |
| `dns.health_agent_mcp_endpoint` | Points at the health-agent MCP endpoint. |
| `dns.mothernode_uri` | Points at the mother-node bootstrap URI. |

## OpenTelemetry: `otel.*`

Use the `otel.*` namespace for telemetry export policy. These keys define which
collector should receive telemetry, which protocol to use, how large the queue
may grow, how traces are sampled, and how metrics and logs are batched.

This namespace matters whenever observability should be tuned at runtime without
rewriting application code.

| Key | Meaning |
| --- | --- |
| `otel.enable` | Enables or disables the telemetry runtime. |
| `otel.service_name` | Sets the service name attached to exported telemetry. |
| `otel.exporter_endpoint` | Points at the collector endpoint. |
| `otel.exporter_protocol` | Selects the collector protocol. |
| `otel.exporter_timeout_ms` | Sets the exporter timeout. |
| `otel.exporter_headers` | Adds static exporter headers. |
| `otel.batch_processor_max_queue_size` | Sets the telemetry retry queue size. |
| `otel.batch_processor_schedule_delay_ms` | Sets the batch processor schedule delay. |
| `otel.traces_sampler_type` | Selects the trace sampler policy. |
| `otel.traces_sampler_ratio` | Sets the probabilistic trace sampler ratio. |
| `otel.traces_max_attributes_per_span` | Limits how many attributes one span may carry. |
| `otel.metrics_enable` | Enables or disables metrics export. |
| `otel.metrics_export_interval_ms` | Sets the metrics export interval. |
| `otel.metrics_default_histogram_boundaries` | Sets default histogram bucket boundaries. |
| `otel.logs_enable` | Enables or disables logs export. |
| `otel.logs_exporter_batch_size` | Sets the log exporter batch size. |
