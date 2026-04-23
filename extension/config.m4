dnl =========================================================================
dnl config.m4 -- King v1 build
dnl
dnl PURPOSE:
dnl This config.m4 drives the active King extension build with the current
dnl runtime source list compiled into one shared module.
dnl Mandatory inputs come from the PHP build toolchain plus the in-tree
dnl headers the build already probes for, while external LSQUIC/BoringSSL paths
dnl can be layered in for custom transport package layouts.
dnl
dnl USE:
dnl   cd extension
dnl   phpize
dnl   ./configure --enable-king
dnl   make
dnl
dnl This runtime is the integration integrity check. All real subsystem
dnl implementations are added here as they are transferred from src_bak/.
dnl
dnl LSQUIC/BoringSSL linking paths are optional overrides. The bundled runtime
dnl remains the default deployment path for PIE/install flows.
dnl =========================================================================

PHP_ARG_ENABLE([king],
    [whether to enable King support],
    [AS_HELP_STRING([--enable-king], [Enable King extension])],
    [yes])

PHP_ARG_WITH([king-lsquic],
    [optional LSQUIC include/library root for extended HTTP/3 builds],
    [AS_HELP_STRING([--with-king-lsquic[=DIR]], [Optional LSQUIC root. Use yes for pkg-config/default paths, or set KING_LSQUIC_CFLAGS/KING_LSQUIC_LIBS.])],
    [no],
    [no])

PHP_ARG_WITH([king-boringssl],
    [optional BoringSSL include/library root for extended HTTP/3 builds],
    [AS_HELP_STRING([--with-king-boringssl[=DIR]], [Optional BoringSSL root. Use yes for default paths, or set KING_BORINGSSL_CFLAGS/KING_BORINGSSL_LIBS.])],
    [no],
    [no])

if test "$PHP_KING" != "no"; then
    AC_PATH_PROG([PKG_CONFIG], [pkg-config], [no])

    KING_LSQUIC_CONFIGURED="no"
    KING_LSQUIC_INCLUDE_DIR="${KING_LSQUIC_INCLUDE_DIR:-}"
    KING_LSQUIC_LIBRARY_DIR="${KING_LSQUIC_LIBRARY_DIR:-}"
    KING_LSQUIC_ROOT="${KING_LSQUIC_ROOT:-}"
    KING_LSQUIC_CFLAGS="${KING_LSQUIC_CFLAGS:-}"
    KING_LSQUIC_LIBS="${KING_LSQUIC_LIBS:-}"

    if test "$PHP_KING_LSQUIC" != "no" || test -n "$KING_LSQUIC_ROOT$KING_LSQUIC_INCLUDE_DIR$KING_LSQUIC_LIBRARY_DIR$KING_LSQUIC_CFLAGS$KING_LSQUIC_LIBS"; then
        AC_MSG_CHECKING([for optional LSQUIC build paths])

        if test -n "$KING_LSQUIC_CFLAGS$KING_LSQUIC_LIBS"; then
            if test -z "$KING_LSQUIC_CFLAGS" || test -z "$KING_LSQUIC_LIBS"; then
                AC_MSG_ERROR([KING_LSQUIC_CFLAGS and KING_LSQUIC_LIBS must be provided together.])
            fi
            CFLAGS="$CFLAGS $KING_LSQUIC_CFLAGS"
            KING_SHARED_LIBADD="$KING_SHARED_LIBADD $KING_LSQUIC_LIBS"
            KING_LSQUIC_CONFIGURED="yes"
        fi

        if test "$KING_LSQUIC_CONFIGURED" = "no"; then
            if test "$PHP_KING_LSQUIC" != "yes" && test "$PHP_KING_LSQUIC" != "no"; then
                KING_LSQUIC_ROOT="$PHP_KING_LSQUIC"
            fi

            if test -n "$KING_LSQUIC_ROOT" && test -z "$KING_LSQUIC_INCLUDE_DIR"; then
                for candidate in "$KING_LSQUIC_ROOT/include" "$KING_LSQUIC_ROOT"; do
                    if test -f "$candidate/lsquic.h"; then
                        KING_LSQUIC_INCLUDE_DIR="$candidate"
                        break
                    fi
                done
            fi

            if test -n "$KING_LSQUIC_ROOT" && test -z "$KING_LSQUIC_LIBRARY_DIR"; then
                for candidate in "$KING_LSQUIC_ROOT/lib" "$KING_LSQUIC_ROOT/lib64" "$KING_LSQUIC_ROOT/build" "$KING_LSQUIC_ROOT/build/src/liblsquic" "$KING_LSQUIC_ROOT"; do
                    if test -f "$candidate/liblsquic.a" || test -f "$candidate/liblsquic.so" || test -f "$candidate/liblsquic.dylib"; then
                        KING_LSQUIC_LIBRARY_DIR="$candidate"
                        break
                    fi
                done
            fi

            if test -n "$KING_LSQUIC_INCLUDE_DIR" || test -n "$KING_LSQUIC_LIBRARY_DIR"; then
                if test -z "$KING_LSQUIC_INCLUDE_DIR"; then
                    AC_MSG_ERROR([KING_LSQUIC_LIBRARY_DIR was provided but no LSQUIC include directory was found.])
                fi
                if test -z "$KING_LSQUIC_LIBRARY_DIR"; then
                    AC_MSG_ERROR([KING_LSQUIC_INCLUDE_DIR was provided but no LSQUIC library directory was found.])
                fi
                if test ! -f "$KING_LSQUIC_INCLUDE_DIR/lsquic.h"; then
                    AC_MSG_ERROR([Could not find lsquic.h under KING_LSQUIC_INCLUDE_DIR=$KING_LSQUIC_INCLUDE_DIR.])
                fi
                PHP_ADD_INCLUDE([$KING_LSQUIC_INCLUDE_DIR])
                PHP_ADD_LIBRARY_WITH_PATH([lsquic], [$KING_LSQUIC_LIBRARY_DIR], [KING_SHARED_LIBADD])
                KING_LSQUIC_CONFIGURED="yes"
            fi
        fi

        if test "$KING_LSQUIC_CONFIGURED" = "no" && test "$PKG_CONFIG" != "no"; then
            for pc_name in lsquic liblsquic; do
                if $PKG_CONFIG --exists "$pc_name"; then
                    KING_LSQUIC_PC_CFLAGS=`$PKG_CONFIG --cflags "$pc_name"`
                    KING_LSQUIC_PC_LIBS=`$PKG_CONFIG --libs "$pc_name"`
                    CFLAGS="$CFLAGS $KING_LSQUIC_PC_CFLAGS"
                    KING_SHARED_LIBADD="$KING_SHARED_LIBADD $KING_LSQUIC_PC_LIBS"
                    KING_LSQUIC_CONFIGURED="yes"
                    break
                fi
            done
        fi

        if test "$KING_LSQUIC_CONFIGURED" = "no"; then
            AC_CHECK_HEADER([lsquic.h], [
                AC_CHECK_LIB([lsquic], [lsquic_global_init], [
                    PHP_ADD_LIBRARY([lsquic], [1], [KING_SHARED_LIBADD])
                    KING_LSQUIC_CONFIGURED="yes"
                ], [
                    AC_MSG_ERROR([--with-king-lsquic=yes was provided, but liblsquic was not found in the default library paths.])
                ])
            ], [
                AC_MSG_ERROR([--with-king-lsquic was requested, but lsquic.h was not found.])
            ])
        fi

        AC_DEFINE([HAVE_KING_LSQUIC], [1], [Whether optional LSQUIC build paths were configured])
        AC_DEFINE([KING_HTTP3_BACKEND_LSQUIC], [1], [Whether the HTTP/3 build is configured for LSQUIC])
        AC_MSG_RESULT([enabled])
    else
        AC_MSG_NOTICE([Building king without --with-king-lsquic; HTTP/3 runtime loader work is configured in later migration steps.])
    fi

    KING_BORINGSSL_CFLAGS="${KING_BORINGSSL_CFLAGS:-}"
    KING_BORINGSSL_LIBS="${KING_BORINGSSL_LIBS:-}"
    KING_BORINGSSL_ROOT="${KING_BORINGSSL_ROOT:-}"
    KING_BORINGSSL_INCLUDE_DIR="${KING_BORINGSSL_INCLUDE_DIR:-}"
    KING_BORINGSSL_SSL_LIBRARY_DIR="${KING_BORINGSSL_SSL_LIBRARY_DIR:-}"
    KING_BORINGSSL_CRYPTO_LIBRARY_DIR="${KING_BORINGSSL_CRYPTO_LIBRARY_DIR:-}"
    KING_TLS_LIBS_CONFIGURED="no"

    if test "$PHP_KING_BORINGSSL" != "no" || test -n "$KING_BORINGSSL_ROOT$KING_BORINGSSL_INCLUDE_DIR$KING_BORINGSSL_SSL_LIBRARY_DIR$KING_BORINGSSL_CRYPTO_LIBRARY_DIR$KING_BORINGSSL_CFLAGS$KING_BORINGSSL_LIBS"; then
        AC_MSG_CHECKING([for optional BoringSSL build paths])

        if test -n "$KING_BORINGSSL_CFLAGS$KING_BORINGSSL_LIBS"; then
            if test -z "$KING_BORINGSSL_CFLAGS" || test -z "$KING_BORINGSSL_LIBS"; then
                AC_MSG_ERROR([KING_BORINGSSL_CFLAGS and KING_BORINGSSL_LIBS must be provided together.])
            fi
            CFLAGS="$CFLAGS $KING_BORINGSSL_CFLAGS"
            KING_SHARED_LIBADD="$KING_SHARED_LIBADD $KING_BORINGSSL_LIBS"
            KING_TLS_LIBS_CONFIGURED="yes"
        else
            if test "$PHP_KING_BORINGSSL" != "yes" && test "$PHP_KING_BORINGSSL" != "no"; then
                KING_BORINGSSL_ROOT="$PHP_KING_BORINGSSL"
            fi

            if test -n "$KING_BORINGSSL_ROOT" && test -z "$KING_BORINGSSL_INCLUDE_DIR"; then
                for candidate in "$KING_BORINGSSL_ROOT/include" "$KING_BORINGSSL_ROOT"; do
                    if test -f "$candidate/openssl/ssl.h"; then
                        KING_BORINGSSL_INCLUDE_DIR="$candidate"
                        break
                    fi
                done
            fi

            if test -n "$KING_BORINGSSL_ROOT" && test -z "$KING_BORINGSSL_SSL_LIBRARY_DIR"; then
                for candidate in "$KING_BORINGSSL_ROOT/lib" "$KING_BORINGSSL_ROOT/lib64" "$KING_BORINGSSL_ROOT/build/ssl" "$KING_BORINGSSL_ROOT/build" "$KING_BORINGSSL_ROOT"; do
                    if test -f "$candidate/libssl.a" || test -f "$candidate/libssl.so" || test -f "$candidate/libssl.dylib"; then
                        KING_BORINGSSL_SSL_LIBRARY_DIR="$candidate"
                        break
                    fi
                done
            fi

            if test -n "$KING_BORINGSSL_ROOT" && test -z "$KING_BORINGSSL_CRYPTO_LIBRARY_DIR"; then
                for candidate in "$KING_BORINGSSL_ROOT/lib" "$KING_BORINGSSL_ROOT/lib64" "$KING_BORINGSSL_ROOT/build/crypto" "$KING_BORINGSSL_ROOT/build" "$KING_BORINGSSL_ROOT"; do
                    if test -f "$candidate/libcrypto.a" || test -f "$candidate/libcrypto.so" || test -f "$candidate/libcrypto.dylib"; then
                        KING_BORINGSSL_CRYPTO_LIBRARY_DIR="$candidate"
                        break
                    fi
                done
            fi

            if test -n "$KING_BORINGSSL_INCLUDE_DIR"; then
                if test ! -f "$KING_BORINGSSL_INCLUDE_DIR/openssl/ssl.h"; then
                    AC_MSG_ERROR([Could not find openssl/ssl.h under KING_BORINGSSL_INCLUDE_DIR=$KING_BORINGSSL_INCLUDE_DIR.])
                fi
                PHP_ADD_INCLUDE([$KING_BORINGSSL_INCLUDE_DIR])
            fi

            if test -n "$KING_BORINGSSL_SSL_LIBRARY_DIR" || test -n "$KING_BORINGSSL_CRYPTO_LIBRARY_DIR"; then
                if test -z "$KING_BORINGSSL_SSL_LIBRARY_DIR" || test -z "$KING_BORINGSSL_CRYPTO_LIBRARY_DIR"; then
                    AC_MSG_ERROR([Both KING_BORINGSSL_SSL_LIBRARY_DIR and KING_BORINGSSL_CRYPTO_LIBRARY_DIR are required for BoringSSL library paths.])
                fi
                PHP_ADD_LIBRARY_WITH_PATH([ssl], [$KING_BORINGSSL_SSL_LIBRARY_DIR], [KING_SHARED_LIBADD])
                PHP_ADD_LIBRARY_WITH_PATH([crypto], [$KING_BORINGSSL_CRYPTO_LIBRARY_DIR], [KING_SHARED_LIBADD])
                KING_TLS_LIBS_CONFIGURED="yes"
            else
                AC_CHECK_HEADER([openssl/ssl.h], [], [
                    AC_MSG_ERROR([--with-king-boringssl=yes was provided, but openssl/ssl.h was not found.])
                ])
                AC_CHECK_LIB([ssl], [SSL_CTX_new], [
                    PHP_ADD_LIBRARY([ssl], [1], [KING_SHARED_LIBADD])
                    PHP_ADD_LIBRARY([crypto], [1], [KING_SHARED_LIBADD])
                    KING_TLS_LIBS_CONFIGURED="yes"
                ], [
                    AC_MSG_ERROR([--with-king-boringssl=yes was provided, but libssl/libcrypto were not found in default library paths.])
                ], [-lcrypto])
            fi
        fi

        AC_DEFINE([HAVE_KING_BORINGSSL], [1], [Whether optional BoringSSL build paths were configured])
        AC_MSG_RESULT([enabled])
    fi

    if test "$KING_TLS_LIBS_CONFIGURED" = "no"; then
        AC_CHECK_HEADER([openssl/ssl.h], [], [
            AC_MSG_ERROR([King runtime requires OpenSSL/BoringSSL headers (openssl/ssl.h). Install OpenSSL dev headers or configure KING_BORINGSSL_* overrides.])
        ])
        AC_CHECK_LIB([ssl], [SSL_CTX_new], [
            PHP_ADD_LIBRARY([ssl], [1], [KING_SHARED_LIBADD])
            PHP_ADD_LIBRARY([crypto], [1], [KING_SHARED_LIBADD])
            KING_TLS_LIBS_CONFIGURED="yes"
        ], [
            AC_MSG_ERROR([King runtime requires libssl/libcrypto. Install OpenSSL dev libraries or configure KING_BORINGSSL_* overrides.])
        ], [-lcrypto])
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
    dnl Source files for the current runtime.
    dnl
    dnl Add active subsystem sources here. The legacy public stub compilation
    dnl unit is retired; placeholder exports should not be reintroduced
    dnl silently.
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
        src/autoscaling/autoscaling.c \
        src/autoscaling/provisioning.c \
        src/integration/system_integration.c \
        src/iibin/iibin_registry.c \
        src/iibin/iibin_schema.c \
        src/iibin/iibin_schema_compiler.c \
        src/iibin/iibin_encoding.c \
        src/iibin/iibin_decoding.c \
        src/gossip_mesh/gossip_mesh.c \
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
        src/telemetry/telemetry.c \
        src/telemetry/metrics.c \
        src/server/tls.c \
        src/server/websocket.c \
        src/media/rtp.c \
        src/core/version.c       \
        src/core/health.c        \
        src/core/introspection.c \
    "

    PHP_NEW_EXTENSION([king], [$KING_SRC], [$ext_shared])
    PHP_ADD_MAKEFILE_FRAGMENT
    PHP_SUBST([KING_SHARED_LIBADD])

    dnl Signal to php_king.h that we are in runtime mode.
    dnl This disables includes for component headers that don't exist yet.
    PHP_ADD_EXTENSION_DEP(king, standard)
    CFLAGS="$CFLAGS -DKING_RUNTIME_BUILD"

    dnl Make headers in extension root and include/ available
    PHP_ADD_INCLUDE([$ext_srcdir])
    PHP_ADD_INCLUDE([$ext_srcdir/include])
    AC_CHECK_FILE([$ext_srcdir/../libcurl/include/curl/curl.h], [
        PHP_ADD_INCLUDE([$ext_srcdir/../libcurl/include])
    ], [
        AC_MSG_WARN([curl headers are not available under ../libcurl/include; this build will fall back to installed libcurl headers.])
    ])
    PHP_ADD_LIBRARY([dl], [1], [KING_SHARED_LIBADD])

fi
