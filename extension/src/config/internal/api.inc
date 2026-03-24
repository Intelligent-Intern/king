/*
 * @brief Creates a new king_cfg_t from an optional PHP options array.
 *
 * In the runtime: snapshots the active module globals and applies the safe
 * explicit override surface that is already wired per resource.
 * In the full implementation: this will expand into the full 4-tier config
 * hierarchy.
 */
king_cfg_t *king_config_new_from_options(zval *zopts)
{
    king_cfg_t *cfg = ecalloc(1, sizeof(king_cfg_t));

    king_config_snapshot_globals(cfg);

    if (zopts && Z_TYPE_P(zopts) == IS_ARRAY && zend_hash_num_elements(Z_ARRVAL_P(zopts)) > 0) {
        if (!king_globals.is_userland_override_allowed) {
            king_config_free(cfg);
            zend_throw_exception_ex(
                king_ce_runtime_exception,
                0,
                "Configuration override is disabled by system policy."
            );
            return NULL;
        }

        if (king_config_apply_userland_overrides(cfg, zopts) != SUCCESS) {
            king_config_free(cfg);
            return NULL;
        }
    }

    return cfg;
}

/*
 * @brief Frees a king_cfg_t and all underlying resources.
 */
void king_config_free(king_cfg_t *cfg)
{
    if (!cfg) {
        return;
    }

    king_config_free_owned_overrides(cfg);

    /*
     * The runtime snapshot currently borrows the rest of its string members
     * from the module-global config surfaces and owns only explicit override
     * allocations plus the outer struct itself.
     */

    efree(cfg);
}

/*
 * @brief Marks the config as immutable (frozen).
 *
 * Called when the config is first used to open a QUIC connection.
 * Any modification attempt after freezing should throw ValidationException.
 */
void king_config_mark_frozen(king_cfg_t *cfg)
{
    if (cfg) {
        cfg->frozen = 1;
    }
}

/*
 * PHP_FUNCTION(king_new_config)
 *
 * PHP signature: king_new_config(?array $options = null): resource
 *
 * Runtime: creates a new composed king_cfg_t resource snapshot.
 */
PHP_FUNCTION(king_new_config)
{
    zval *zopts = NULL;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(zopts)
    ZEND_PARSE_PARAMETERS_END();

    king_cfg_t *cfg = king_config_new_from_options(zopts);

    if (!cfg) {
        RETURN_THROWS();
    }

    king_set_error("");
    RETURN_RES(zend_register_resource(cfg, le_king_cfg));
}
