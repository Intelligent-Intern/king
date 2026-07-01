/*
 * include/xslt/registration.h - XSLT MINIT hooks
 */

#ifndef KING_XSLT_REGISTRATION_H
#define KING_XSLT_REGISTRATION_H

#include <php.h>
#include "class_methods.h"

void king_xslt_register_classes(void);
void king_xslt_init_object_handlers(void);

#endif /* KING_XSLT_REGISTRATION_H */
