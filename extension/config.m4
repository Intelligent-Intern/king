dnl =========================================================================
dnl config.m4 -- Skeleton build (no quiche, no libcurl)
dnl
dnl PURPOSE:
dnl This config.m4 builds a minimal, compilable king extension.
dnl It has zero external dependencies beyond the PHP build system itself.
dnl
dnl USE:
dnl   cd extension
dnl   phpize
dnl   ./configure --enable-king
dnl   make
dnl
dnl This skeleton is the integration integrity check. All real subsystem
dnl implementations are added here as they are transferred from src_bak/.
dnl
dnl When the full build is ready, this file will be replaced with the
dnl production config.m4 that links quiche, libcurl, and OpenSSL.
dnl =========================================================================

PHP_ARG_ENABLE([king],
    [whether to enable King support],
    [AS_HELP_STRING([--enable-king], [Enable King extension (skeleton build)])])

PHP_ARG_WITH([king-quiche],
    [optional quiche include/library root for future non-skeleton builds],
    [AS_HELP_STRING([--with-king-quiche[=DIR]], [Prepare optional quiche include/library paths while keeping the active build on the skeleton transport runtime])],
    [no],
    [no])

if test "$PHP_KING" != "no"; then

    if test "$PHP_KING_QUICHE" != "no"; then
        AC_MSG_CHECKING([for optional quiche build paths])

        KING_QUICHE_INCLUDE_DIR=""
        KING_QUICHE_LIBRARY_DIR=""

        if test "$PHP_KING_QUICHE" = "yes"; then
            AC_CHECK_HEADER([quiche.h], [
                AC_CHECK_LIB([quiche], [quiche_config_new], [], [
                    AC_MSG_ERROR([--with-king-quiche=yes was provided, but libquiche was not found in the default library paths.])
                ])
            ], [
                AC_MSG_ERROR([--with-king-quiche=yes was provided, but quiche.h was not found in the default include paths.])
            ])
            PHP_ADD_LIBRARY([quiche], [1], [KING_SHARED_LIBADD])
        else
            for candidate in "$PHP_KING_QUICHE/include" "$PHP_KING_QUICHE"; do
                if test -f "$candidate/quiche.h"; then
                    KING_QUICHE_INCLUDE_DIR="$candidate"
                    break
                fi
            done

            for candidate in "$PHP_KING_QUICHE/lib" "$PHP_KING_QUICHE/target/release" "$PHP_KING_QUICHE"; do
                if test -f "$candidate/libquiche.a" || test -f "$candidate/libquiche.so"; then
                    KING_QUICHE_LIBRARY_DIR="$candidate"
                    break
                fi
            done

            if test -z "$KING_QUICHE_INCLUDE_DIR"; then
                AC_MSG_ERROR([Could not find quiche.h under --with-king-quiche=$PHP_KING_QUICHE.])
            fi

            if test -z "$KING_QUICHE_LIBRARY_DIR"; then
                AC_MSG_ERROR([Could not find libquiche.a or libquiche.so under --with-king-quiche=$PHP_KING_QUICHE.])
            fi

            PHP_ADD_INCLUDE([$KING_QUICHE_INCLUDE_DIR])
            PHP_ADD_LIBRARY_WITH_PATH([quiche], [$KING_QUICHE_LIBRARY_DIR], [KING_SHARED_LIBADD])
        fi

        AC_DEFINE([HAVE_KING_QUICHE], [1], [Whether optional quiche build paths were configured])
        AC_MSG_RESULT([enabled])
    else
        AC_MSG_NOTICE([Building king without optional quiche path; active transport runtime stays on the skeleton UDP substrate.])
    fi

    dnl ---------------------------------------------------------------------------
    dnl PHP version check: require PHP 8.1+
    dnl ---------------------------------------------------------------------------
    if test -z "$PHP_VERSION"; then
        AC_MSG_CHECKING([for PHP version])
        PHP_VERSION=`$PHP_CONFIG --version 2>/dev/null`
        AC_MSG_RESULT([$PHP_VERSION])
    fi

    PHP_VERSION_ID=`$PHP_CONFIG --vernum 2>/dev/null`
    if test -n "$PHP_VERSION_ID"; then
        if test "$PHP_VERSION_ID" -lt "80100"; then
            AC_MSG_ERROR([King requires PHP 8.1 or newer. Found: $PHP_VERSION])
        fi
    fi

    dnl ---------------------------------------------------------------------------
    dnl Source files for the skeleton build.
    dnl
    dnl To add a real subsystem implementation:
    dnl   1. Remove the functions from src/stubs/all_stubs.c
    dnl   2. Add the new .c files here
    dnl ---------------------------------------------------------------------------

    KING_SRC=" \
        src/php_king.c        \
        src/king_globals.c    \
        src/king_init.c       \
        src/config/config.c   \
        src/config/app_http3_websockets_webtransport/base_layer.c \
        src/config/app_http3_websockets_webtransport/config.c \
        src/config/app_http3_websockets_webtransport/default.c \
        src/config/app_http3_websockets_webtransport/index.c \
        src/config/app_http3_websockets_webtransport/ini.c \
        src/config/bare_metal_tuning/base_layer.c \
        src/config/bare_metal_tuning/config.c \
        src/config/bare_metal_tuning/default.c \
        src/config/bare_metal_tuning/index.c \
        src/config/bare_metal_tuning/ini.c \
        src/config/cloud_autoscale/base_layer.c \
        src/config/cloud_autoscale/config.c \
        src/config/cloud_autoscale/default.c \
        src/config/cloud_autoscale/index.c \
        src/config/cloud_autoscale/ini.c \
        src/config/cluster_and_process/base_layer.c \
        src/config/cluster_and_process/config.c \
        src/config/cluster_and_process/default.c \
        src/config/cluster_and_process/index.c \
        src/config/cluster_and_process/ini.c \
        src/config/dynamic_admin_api/base_layer.c \
        src/config/dynamic_admin_api/config.c \
        src/config/dynamic_admin_api/default.c \
        src/config/dynamic_admin_api/index.c \
        src/config/dynamic_admin_api/ini.c \
        src/config/high_perf_compute_and_ai/base_layer.c \
        src/config/high_perf_compute_and_ai/config.c \
        src/config/high_perf_compute_and_ai/default.c \
        src/config/high_perf_compute_and_ai/index.c \
        src/config/high_perf_compute_and_ai/ini.c \
        src/config/http2/base_layer.c \
        src/config/http2/config.c \
        src/config/http2/default.c \
        src/config/http2/index.c \
        src/config/http2/ini.c \
        src/config/iibin/base_layer.c \
        src/config/iibin/config.c \
        src/config/iibin/default.c \
        src/config/iibin/index.c \
        src/config/iibin/ini.c \
        src/config/mcp_and_orchestrator/base_layer.c \
        src/config/mcp_and_orchestrator/config.c \
        src/config/mcp_and_orchestrator/default.c \
        src/config/mcp_and_orchestrator/index.c \
        src/config/mcp_and_orchestrator/ini.c \
        src/config/native_cdn/base_layer.c \
        src/config/native_cdn/config.c \
        src/config/native_cdn/default.c \
        src/config/native_cdn/index.c \
        src/config/native_cdn/ini.c \
        src/config/native_object_store/base_layer.c \
        src/config/native_object_store/config.c \
        src/config/native_object_store/default.c \
        src/config/native_object_store/index.c \
        src/config/native_object_store/ini.c \
        src/config/open_telemetry/base_layer.c \
        src/config/open_telemetry/config.c \
        src/config/open_telemetry/default.c \
        src/config/open_telemetry/index.c \
        src/config/open_telemetry/ini.c \
        src/config/quic_transport/base_layer.c \
        src/config/quic_transport/config.c \
        src/config/quic_transport/default.c \
        src/config/quic_transport/index.c \
        src/config/quic_transport/ini.c \
        src/config/router_and_loadbalancer/base_layer.c \
        src/config/router_and_loadbalancer/config.c \
        src/config/router_and_loadbalancer/default.c \
        src/config/router_and_loadbalancer/index.c \
        src/config/router_and_loadbalancer/ini.c \
        src/config/security_and_traffic/base_layer.c \
        src/config/security_and_traffic/config.c \
        src/config/security_and_traffic/default.c \
        src/config/security_and_traffic/index.c \
        src/config/security_and_traffic/ini.c \
        src/config/semantic_geometry/base_layer.c \
        src/config/semantic_geometry/config.c \
        src/config/semantic_geometry/default.c \
        src/config/semantic_geometry/index.c \
        src/config/semantic_geometry/ini.c \
        src/config/smart_contracts/base_layer.c \
        src/config/smart_contracts/config.c \
        src/config/smart_contracts/default.c \
        src/config/smart_contracts/index.c \
        src/config/smart_contracts/ini.c \
        src/config/smart_dns/base_layer.c \
        src/config/smart_dns/config.c \
        src/config/smart_dns/default.c \
        src/config/smart_dns/index.c \
        src/config/smart_dns/ini.c \
        src/config/ssh_over_quic/base_layer.c \
        src/config/ssh_over_quic/config.c \
        src/config/ssh_over_quic/default.c \
        src/config/ssh_over_quic/index.c \
        src/config/ssh_over_quic/ini.c \
        src/config/state_management/base_layer.c \
        src/config/state_management/config.c \
        src/config/state_management/default.c \
        src/config/state_management/index.c \
        src/config/state_management/ini.c \
        src/config/tcp_transport/base_layer.c \
        src/config/tcp_transport/config.c \
        src/config/tcp_transport/default.c \
        src/config/tcp_transport/index.c \
        src/config/tcp_transport/ini.c \
        src/config/tls_and_crypto/base_layer.c \
        src/config/tls_and_crypto/config.c \
        src/config/tls_and_crypto/default.c \
        src/config/tls_and_crypto/index.c \
        src/config/tls_and_crypto/ini.c \
        src/validation/config_param/validate_bool.c \
        src/validation/config_param/validate_colon_separated_string_from_allowlist.c \
        src/validation/config_param/validate_comma_separated_numeric_string.c \
        src/validation/config_param/validate_comma_separated_string_from_allowlist.c \
        src/validation/config_param/validate_cors_origin_string.c \
        src/validation/config_param/validate_cpu_affinity_map_string.c \
        src/validation/config_param/validate_double_range.c \
        src/validation/config_param/validate_erasure_coding_shards_string.c \
        src/validation/config_param/validate_generic_string.c \
        src/validation/config_param/validate_host_string.c \
        src/validation/config_param/validate_long_range.c \
        src/validation/config_param/validate_niceness_value.c \
        src/validation/config_param/validate_non_negative_long.c \
        src/validation/config_param/validate_positive_long.c \
        src/validation/config_param/validate_readable_file_path.c \
        src/validation/config_param/validate_scale_up_policy_string.c \
        src/validation/config_param/validate_scheduler_policy.c \
        src/validation/config_param/validate_string.c \
        src/validation/config_param/validate_string_from_allowlist.c \
        src/client/session.c \
        src/client/cancel.c \
        src/client/tls.c \
        src/client/index.c \
        src/client/early_hints.c \
        src/client/websocket.c \
        src/client/http1.c \
        src/client/http2.c \
        src/client/http3.c \
        src/iibin/iibin.c \
        src/iibin/iibin_api.c \
        src/iibin/iibin_registry.c \
        src/iibin/iibin_schema.c \
        src/iibin/iibin_schema_compiler.c \
        src/iibin/iibin_encoding.c \
        src/iibin/iibin_decoding.c \
        src/semantic_dns/semantic_dns.c \
        src/semantic_dns/mother_node_discovery.c \
        src/semantic_dns/routing.c \
        src/semantic_dns/state.c \
        src/object_store/object_store.c \
        src/mcp/mcp.c \
        src/pipeline_orchestrator/tool_registry.c \
        src/pipeline_orchestrator/orchestrator.c \
        src/server/admin_api.c \
        src/server/cancel.c \
        src/server/cors.c \
        src/server/early_hints.c \
        src/server/http1.c \
        src/server/http2.c \
        src/server/http3.c \
        src/server/index.c \
        src/server/open_telemetry.c \
        src/server/session.c \
        src/server/tls.c \
        src/server/websocket.c \
        src/core/version.c       \
        src/core/health.c        \
        src/core/introspection.c \
        src/stubs/all_stubs.c \
    "

    PHP_NEW_EXTENSION([king], [$KING_SRC], [$ext_shared])
    PHP_SUBST([KING_SHARED_LIBADD])

    dnl Signal to php_king.h that we are in skeleton mode.
    dnl This disables includes for component headers that don't exist yet.
    PHP_ADD_EXTENSION_DEP(king, standard)
    CFLAGS="$CFLAGS -DKING_SKELETON_BUILD"

    dnl Make headers in extension root and include/ available
    PHP_ADD_INCLUDE([$ext_srcdir])
    PHP_ADD_INCLUDE([$ext_srcdir/../libcurl/include])
    PHP_ADD_INCLUDE([$ext_srcdir/../quiche/quiche/include])
    PHP_ADD_LIBRARY([dl], [1], [KING_SHARED_LIBADD])

fi
