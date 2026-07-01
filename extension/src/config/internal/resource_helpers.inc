/*
 * Config resource and object resolver shared by runtime leaves that accept
 * either a King\Config object or a native King\Config resource.
 */

king_cfg_t *king_fetch_config(zval *zcfg)
{
    if (Z_TYPE_P(zcfg) == IS_OBJECT
        && king_ce_config != NULL
        && instanceof_function(Z_OBJCE_P(zcfg), king_ce_config)) {
        king_config_object *intern = php_king_config_obj_from_zend(Z_OBJ_P(zcfg));

        if (Z_ISUNDEF(intern->resource) || Z_TYPE(intern->resource) != IS_RESOURCE) {
            return NULL;
        }

        zcfg = &intern->resource;
    }

    if (Z_TYPE_P(zcfg) != IS_RESOURCE) {
        return NULL;
    }

    return (king_cfg_t *) zend_fetch_resource(Z_RES_P(zcfg), "King\\Config", le_king_cfg);
}
