/*
 * =========================================================================
 * FILENAME:   include/config/smart_dns/index.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header file declares the public C-API for the Smart DNS
 * configuration module.
 *
 * ARCHITECTURE:
 * It provides the function prototypes for the module's lifecycle, which
 * are called by the master dispatcher to orchestrate loading of settings.
 * =========================================================================
 */

#ifndef KING_CONFIG_SMART_DNS_INDEX_H
#define KING_CONFIG_SMART_DNS_INDEX_H

/**
 * @brief Initializes the Smart DNS configuration module.
 */
void kg_config_smart_dns_init(void);

/**
 * @brief Shuts down the Smart DNS configuration module.
 */
void kg_config_smart_dns_shutdown(void);

#endif /* KING_CONFIG_SMART_DNS_INDEX_H */
