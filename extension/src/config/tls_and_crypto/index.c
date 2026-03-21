#include "include/config/tls_and_crypto/index.h"
#include "include/config/tls_and_crypto/default.h"
#include "include/config/tls_and_crypto/ini.h"

void kg_config_tls_and_crypto_init(void)
{
    kg_config_tls_and_crypto_defaults_load();
    kg_config_tls_and_crypto_ini_register();
}

void kg_config_tls_and_crypto_shutdown(void)
{
    kg_config_tls_and_crypto_ini_unregister();
}
