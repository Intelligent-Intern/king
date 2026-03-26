#!/usr/bin/env bash

set -euo pipefail

usage() {
    cat <<'EOF'
Usage: ./scripts/smoke-profile.sh <release|debug|asan|ubsan>

Runs a focused smoke test against the staged profile artifact under:
  extension/build/profiles/<profile>/
EOF
}

if [[ "${1:-}" == "--help" || "${1:-}" == "-h" ]]; then
    usage
    exit 0
fi

PROFILE="${1:-release}"

case "${PROFILE}" in
    release|debug|asan|ubsan)
        ;;
    *)
        echo "Unknown profile: ${PROFILE}" >&2
        usage >&2
        exit 1
        ;;
esac

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
EXT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
PROFILE_DIR="${EXT_DIR}/build/profiles/${PROFILE}"
PHP_BIN="${PHP_BIN:-php}"
EXT_SO="${PROFILE_DIR}/king.so"
QUICHE_LIB="${PROFILE_DIR}/libquiche.so"
QUICHE_SERVER="${PROFILE_DIR}/quiche-server"

resolve_sanitizer_runtime() {
    local runtime_name="$1"
    local staged_runtime="${PROFILE_DIR}/${runtime_name}"
    local runtime_path=""

    if [[ -f "${staged_runtime}" ]]; then
        printf '%s\n' "${staged_runtime}"
        return 0
    fi

    runtime_path="$(clang -print-file-name="${runtime_name}")"
    if [[ -n "${runtime_path}" && "${runtime_path}" != "${runtime_name}" && -f "${runtime_path}" ]]; then
        printf '%s\n' "${runtime_path}"
        return 0
    fi

    return 1
}

if [[ ! -f "${EXT_SO}" ]]; then
    echo "Missing staged extension for profile '${PROFILE}': ${EXT_SO}" >&2
    echo "Run ./scripts/build-profile.sh ${PROFILE} first." >&2
    exit 1
fi

if [[ ! -f "${QUICHE_LIB}" ]]; then
    echo "Missing staged libquiche for profile '${PROFILE}': ${QUICHE_LIB}" >&2
    exit 1
fi

if [[ ! -x "${QUICHE_SERVER}" ]]; then
    echo "Missing staged quiche-server for profile '${PROFILE}': ${QUICHE_SERVER}" >&2
    exit 1
fi

export KING_QUICHE_LIBRARY="${QUICHE_LIB}"
export KING_QUICHE_SERVER="${QUICHE_SERVER}"
export LD_LIBRARY_PATH="${PROFILE_DIR}${LD_LIBRARY_PATH:+:${LD_LIBRARY_PATH}}"

case "${PROFILE}" in
    asan)
        asan_runtime="$(resolve_sanitizer_runtime libclang_rt.asan-x86_64.so)"
        export USE_ZEND_ALLOC=0
        export ASAN_OPTIONS="${ASAN_OPTIONS:-detect_leaks=0:abort_on_error=1:symbolize=1}"
        export LD_PRELOAD="${asan_runtime}${LD_PRELOAD:+ ${LD_PRELOAD}}"
        ;;
    ubsan)
        ubsan_runtime="$(resolve_sanitizer_runtime libclang_rt.ubsan_standalone-x86_64.so)"
        export USE_ZEND_ALLOC=0
        export UBSAN_OPTIONS="${UBSAN_OPTIONS:-print_stacktrace=1:halt_on_error=1}"
        export LD_PRELOAD="${ubsan_runtime}${LD_PRELOAD:+ ${LD_PRELOAD}}"
        ;;
esac

"${PHP_BIN}" \
    -d "extension=${EXT_SO}" \
    -d "king.security_allow_config_override=1" \
    -r '
        $schema = "ProfileSmoke_" . getmypid();

        if (!function_exists("king_connect")) {
            fwrite(STDERR, "King extension functions are unavailable.\n");
            exit(1);
        }

        if (!king_proto_define_schema($schema, [
            "name" => ["tag" => 3, "type" => "string"],
            "id" => ["tag" => 1, "type" => "int32", "required" => true],
            "enabled" => ["tag" => 2, "type" => "bool"],
        ])) {
            fwrite(STDERR, "Failed to define proto smoke schema.\n");
            exit(1);
        }

        $encoded = king_proto_encode($schema, [
            "name" => "profile-smoke",
            "id" => 7,
            "enabled" => true,
        ]);
        $decoded = king_proto_decode($schema, $encoded);
        if (!is_array($decoded) || ($decoded["id"] ?? null) !== 7) {
            fwrite(STDERR, "Proto smoke roundtrip failed.\n");
            exit(1);
        }

        $root = sys_get_temp_dir() . "/king_profile_smoke_" . getmypid();
        if (!is_dir($root) && !mkdir($root, 0777, true) && !is_dir($root)) {
            fwrite(STDERR, "Failed to create object-store smoke directory.\n");
            exit(1);
        }

        try {
            king_object_store_init([
                "storage_root_path" => $root,
                "max_storage_size_bytes" => 1048576,
                "cdn_config" => [
                    "enabled" => true,
                    "default_ttl_seconds" => 60,
                ],
            ]);
            king_object_store_put("profile-smoke", "payload");
            if (king_object_store_get("profile-smoke") !== "payload") {
                fwrite(STDERR, "Object-store smoke roundtrip failed.\n");
                exit(1);
            }
            if (!king_cdn_cache_object("profile-smoke") || !king_object_store_delete("profile-smoke")) {
                fwrite(STDERR, "Object-store smoke cache/delete failed.\n");
                exit(1);
            }
        } finally {
            foreach (scandir($root) ?: [] as $entry) {
                if ($entry === "." || $entry === "..") {
                    continue;
                }
                @unlink($root . DIRECTORY_SEPARATOR . $entry);
            }
            @rmdir($root);
        }

        if (!king_semantic_dns_init([
            "enabled" => true,
            "bind_address" => "127.0.0.1",
            "dns_port" => 8053,
            "default_record_ttl_sec" => 120,
            "service_discovery_max_ips_per_response" => 5,
            "semantic_mode_enable" => true,
            "mothernode_uri" => "mother://profile-smoke",
            "routing_policies" => ["mode" => "local"],
        ])) {
            fwrite(STDERR, "Semantic-DNS init smoke failed.\n");
            exit(1);
        }
        if (!king_semantic_dns_start_server()) {
            fwrite(STDERR, "Semantic-DNS start smoke failed.\n");
            exit(1);
        }
        if (!king_semantic_dns_register_service([
            "service_id" => "profile-smoke",
            "service_name" => "profile-smoke",
            "service_type" => "pipeline_orchestrator",
            "status" => "healthy",
            "hostname" => "profile.internal",
            "port" => 8443,
        ])) {
            fwrite(STDERR, "Semantic-DNS register smoke failed.\n");
            exit(1);
        }

        $discovery = king_semantic_dns_discover_service("pipeline_orchestrator");
        $route = king_semantic_dns_get_optimal_route("profile-smoke");
        $topology = king_semantic_dns_get_service_topology();
        if (($discovery["service_count"] ?? 0) < 1
            || ($route["service_id"] ?? null) !== "profile-smoke"
            || ($topology["statistics"]["healthy_services"] ?? 0) < 1) {
            fwrite(STDERR, "Semantic-DNS smoke verification failed.\n");
            exit(1);
        }

        $session = king_connect("127.0.0.1", 443, [
            "sni" => "profile.example",
            "alpn" => ["h3"],
        ]);
        if (!is_resource($session)) {
            fwrite(STDERR, "Session smoke failed to create a resource.\n");
            exit(1);
        }

        $stats = king_get_stats($session);
        if (!is_array($stats) || ($stats["state"] ?? null) !== "open") {
            fwrite(STDERR, "Session smoke failed to read stats.\n");
            exit(1);
        }

        if (!king_poll($session, 0) || !king_close($session)) {
            fwrite(STDERR, "Session smoke poll/close failed.\n");
            exit(1);
        }

        echo "profile smoke ok\n";
    '
