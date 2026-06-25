/*
 * include/awaitable/registration.h - Awaitable MINIT registration hooks
 */

#ifndef KING_AWAITABLE_REGISTRATION_H
#define KING_AWAITABLE_REGISTRATION_H

#include <php.h>

void king_awaitable_register_classes(void);
void king_awaitable_init_object_handlers(void);

#endif /* KING_AWAITABLE_REGISTRATION_H */
