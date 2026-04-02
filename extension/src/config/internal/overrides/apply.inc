/*
 * =========================================================================
 * FILENAME:   src/config/internal/overrides/apply.inc
 * PROJECT:    king
 *
 * PURPOSE:
 * Applies collected runtime override buckets onto a composed `king_cfg_t`
 * snapshot. This file maps each normalized override bucket onto its owning
 * config family and drives the bounded `*_apply_userland_config_to()`
 * helpers before marking the snapshot as having explicit userland overrides.
 * =========================================================================
 */

#define KING_CONFIG_APPLY_OVERRIDE_BUCKET(bucket_member, cfg_member, apply_fn) \
    do { \
        if (state->bucket_member.initialized && \
            apply_fn(&cfg->cfg_member, &state->bucket_member.values) != SUCCESS) { \
            return FAILURE; \
        } \
    } while (0)

static int king_config_apply_override_state_to_cfg(
    king_cfg_t *cfg,
    king_config_override_state_t *state)
{
    KING_CONFIG_APPLY_OVERRIDE_BUCKET(tls, tls, kg_config_tls_and_crypto_apply_userland_config_to);
    KING_CONFIG_APPLY_OVERRIDE_BUCKET(quic, quic, kg_config_quic_transport_apply_userland_config_to);
    KING_CONFIG_APPLY_OVERRIDE_BUCKET(http2, http2, kg_config_http2_apply_userland_config_to);
    KING_CONFIG_APPLY_OVERRIDE_BUCKET(tcp, tcp, kg_config_tcp_transport_apply_userland_config_to);
    KING_CONFIG_APPLY_OVERRIDE_BUCKET(autoscale, autoscale, kg_config_cloud_autoscale_apply_userland_config_to);
    KING_CONFIG_APPLY_OVERRIDE_BUCKET(mcp, mcp, kg_config_mcp_and_orchestrator_apply_userland_config_to);
    KING_CONFIG_APPLY_OVERRIDE_BUCKET(geometry, semantic_geometry, kg_config_semantic_geometry_apply_userland_config_to);
    KING_CONFIG_APPLY_OVERRIDE_BUCKET(smartcontract, smart_contract, kg_config_smart_contracts_apply_userland_config_to);
    KING_CONFIG_APPLY_OVERRIDE_BUCKET(ssh, ssh, kg_config_ssh_over_quic_apply_userland_config_to);
    KING_CONFIG_APPLY_OVERRIDE_BUCKET(storage, storage, kg_config_native_object_store_apply_userland_config_to);
    KING_CONFIG_APPLY_OVERRIDE_BUCKET(cdn, cdn, kg_config_native_cdn_apply_userland_config_to);
    KING_CONFIG_APPLY_OVERRIDE_BUCKET(dns, dns, kg_config_smart_dns_apply_userland_config_to);
    KING_CONFIG_APPLY_OVERRIDE_BUCKET(otel, observability, kg_config_open_telemetry_apply_userland_config_to);

    return SUCCESS;
}

#undef KING_CONFIG_APPLY_OVERRIDE_BUCKET

static int king_config_apply_userland_overrides(king_cfg_t *cfg, zval *zopts)
{
    king_config_override_state_t state = {0};

    if (king_config_collect_userland_overrides(&state, zopts) != SUCCESS) {
        king_config_cleanup_override_state(&state);
        return FAILURE;
    }

    if (king_config_apply_override_state_to_cfg(cfg, &state) != SUCCESS) {
        king_config_cleanup_override_state(&state);
        return FAILURE;
    }

    king_config_cleanup_override_state(&state);
    cfg->userland_overrides_applied = 1;
    cfg->override_count = zend_hash_num_elements(Z_ARRVAL_P(zopts));

    return SUCCESS;
}
