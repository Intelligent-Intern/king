/* PHP integers cover only the signed half of protobuf's uint64 domain.
 * Canonical decimal strings preserve the remaining values without floats. */
static bool king_proto_runtime_uint64_from_zval(zval *value, uint64_t *out)
{
    if (Z_TYPE_P(value) == IS_LONG) {
        if (Z_LVAL_P(value) < 0) return false;
        *out = (uint64_t) Z_LVAL_P(value);
        return true;
    }
    if (Z_TYPE_P(value) != IS_STRING || Z_STRLEN_P(value) == 0
        || (Z_STRLEN_P(value) > 1 && Z_STRVAL_P(value)[0] == '0')) return false;
    uint64_t result = 0;
    for (size_t i = 0; i < Z_STRLEN_P(value); i++) {
        unsigned char c = (unsigned char) Z_STRVAL_P(value)[i];
        if (c < '0' || c > '9' || result > (UINT64_MAX - (c - '0')) / 10) return false;
        result = result * 10 + (c - '0');
    }
    *out = result;
    return true;
}

static void king_proto_runtime_uint64_to_zval(uint64_t value, zval *out)
{
    if (value <= (uint64_t) ZEND_LONG_MAX) {
        ZVAL_LONG(out, (zend_long) value);
    } else {
        ZVAL_STR(out, strpprintf(0, "%" PRIu64, value));
    }
}
