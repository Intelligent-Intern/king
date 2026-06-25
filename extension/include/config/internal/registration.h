/*
 * include/config/internal/registration.h - Config object MINIT hooks
 */

#ifndef KING_CONFIG_INTERNAL_REGISTRATION_H
#define KING_CONFIG_INTERNAL_REGISTRATION_H

#include <php.h>
#include "class_methods.h"

void king_config_register_classes(void);
void king_config_init_object_handlers(void);

#endif /* KING_CONFIG_INTERNAL_REGISTRATION_H */
