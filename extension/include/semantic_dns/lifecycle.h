/*
 * include/semantic_dns/lifecycle.h - Semantic-DNS module bootstrap hooks
 */

#ifndef KING_SEMANTIC_DNS_LIFECYCLE_H
#define KING_SEMANTIC_DNS_LIFECYCLE_H

int king_semantic_dns_registry_minit(void);
void king_semantic_dns_registry_mshutdown(void);

#endif /* KING_SEMANTIC_DNS_LIFECYCLE_H */
