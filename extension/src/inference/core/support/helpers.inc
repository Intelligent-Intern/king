static zval *king_inference_array_find(zval *array, const char *key)
{
    if (array == NULL || Z_TYPE_P(array) != IS_ARRAY) {
        return NULL;
    }

    return zend_hash_str_find(Z_ARRVAL_P(array), key, strlen(key));
}

static bool king_inference_bool_from_array(zval *array, const char *key, bool fallback)
{
    zval *value = king_inference_array_find(array, key);

    if (value == NULL) {
        return fallback;
    }

    return zend_is_true(value);
}

static zend_long king_inference_long_from_array(zval *array, const char *key, zend_long fallback)
{
    zval *value = king_inference_array_find(array, key);

    return value != NULL && Z_TYPE_P(value) == IS_LONG ? Z_LVAL_P(value) : fallback;
}

static double king_inference_double_from_array(zval *array, const char *key, double fallback)
{
    zval *value = king_inference_array_find(array, key);

    if (value == NULL) {
        return fallback;
    }
    if (Z_TYPE_P(value) == IS_DOUBLE) {
        return Z_DVAL_P(value);
    }
    if (Z_TYPE_P(value) == IS_LONG) {
        return (double) Z_LVAL_P(value);
    }

    return fallback;
}
