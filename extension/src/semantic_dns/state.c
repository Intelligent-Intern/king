/*
 * =========================================================================
 * FILENAME:   src/semantic_dns/state.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Implements the native Semantic-DNS durable state persistence,
 * allowing the control plane to recover topology and runtime data
 * across reboots or process limits.
 * =========================================================================
 */

#include "semantic_dns_internal.h"
#include <errno.h>
#include <fcntl.h>
#include <limits.h>
#include <stdio.h>
#include <sys/stat.h>
#include <sys/types.h>
#include <unistd.h>
#include <string.h>

#define KING_SEMANTIC_DNS_STATE_DIR "/tmp/king_semantic_dns_state"
#define KING_SEMANTIC_DNS_STATE_FILE KING_SEMANTIC_DNS_STATE_DIR "/durable_state.bin"
#define KING_SEMANTIC_DNS_STATE_MAGIC 0x53444e53 /* 'SDNS' */
#define KING_SEMANTIC_DNS_STATE_VERSION 1
#define KING_SEMANTIC_DNS_STATE_MAX_MOTHER_NODES 1024U

static int king_semantic_dns_state_dir_is_secure(const struct stat *st)
{
    mode_t perms;

    if (st == NULL || !S_ISDIR(st->st_mode)) {
        return FAILURE;
    }

    if (st->st_uid != geteuid()) {
        return FAILURE;
    }

    perms = st->st_mode & 0777;
    if ((perms & 0077) != 0 || perms != 0700) {
        return FAILURE;
    }

    return SUCCESS;
}

static int king_semantic_dns_state_ensure_directory(void)
{
    struct stat st;

    if (php_check_open_basedir(KING_SEMANTIC_DNS_STATE_DIR) != 0) {
        return FAILURE;
    }

    if (mkdir(KING_SEMANTIC_DNS_STATE_DIR, 0700) != 0 && errno != EEXIST) {
        return FAILURE;
    }

    if (lstat(KING_SEMANTIC_DNS_STATE_DIR, &st) != 0) {
        return FAILURE;
    }

    return king_semantic_dns_state_dir_is_secure(&st);
}

int king_semantic_dns_state_save(void)
{
    FILE *fp = NULL;
    int fd;
    char tmp_template[PATH_MAX];
    uint32_t magic = KING_SEMANTIC_DNS_STATE_MAGIC;
    uint32_t version = KING_SEMANTIC_DNS_STATE_VERSION;

    if (!king_semantic_dns_runtime.initialized) {
        return FAILURE;
    }

    if (king_semantic_dns_state_ensure_directory() != SUCCESS) {
        return FAILURE;
    }

    if (php_check_open_basedir(KING_SEMANTIC_DNS_STATE_FILE) != 0) {
        return FAILURE;
    }

    if (snprintf(tmp_template, sizeof(tmp_template), "%s/.state.XXXXXX", KING_SEMANTIC_DNS_STATE_DIR) >= (int) sizeof(tmp_template)) {
        return FAILURE;
    }

    if (php_check_open_basedir(tmp_template) != 0) {
        return FAILURE;
    }

    fd = mkstemp(tmp_template);
    if (fd < 0) {
        return FAILURE;
    }

    if (fchmod(fd, 0600) != 0) {
        close(fd);
        unlink(tmp_template);
        return FAILURE;
    }

    fp = fdopen(fd, "wb");
    if (fp == NULL) {
        close(fd);
        unlink(tmp_template);
        return FAILURE;
    }

    if (fwrite(&magic, sizeof(magic), 1, fp) != 1 || fwrite(&version, sizeof(version), 1, fp) != 1) {
        fclose(fp);
        unlink(tmp_template);
        return FAILURE;
    }

    /* Write the native mother_nodes list */
    if (fwrite(&king_semantic_dns_runtime.config.mother_node_count, sizeof(uint32_t), 1, fp) != 1) {
        fclose(fp);
        unlink(tmp_template);
        return FAILURE;
    }
    if (
        king_semantic_dns_runtime.config.mother_node_count > 0
        && king_semantic_dns_runtime.config.mother_nodes != NULL
        && fwrite(
            king_semantic_dns_runtime.config.mother_nodes,
            sizeof(king_mother_node_t),
            king_semantic_dns_runtime.config.mother_node_count,
            fp
        ) != king_semantic_dns_runtime.config.mother_node_count
    ) {
        fclose(fp);
        unlink(tmp_template);
        return FAILURE;
    }

    /* Persist scalar runtime fields after the topology snapshot. */
    if (
        fwrite(&king_semantic_dns_runtime.last_discovered_node_count, sizeof(zend_long), 1, fp) != 1
        || fwrite(&king_semantic_dns_runtime.last_synced_node_count, sizeof(zend_long), 1, fp) != 1
    ) {
        fclose(fp);
        unlink(tmp_template);
        return FAILURE;
    }

    if (fclose(fp) != 0) {
        unlink(tmp_template);
        return FAILURE;
    }

    if (rename(tmp_template, KING_SEMANTIC_DNS_STATE_FILE) != 0) {
        unlink(tmp_template);
        return FAILURE;
    }

    return SUCCESS;
}

int king_semantic_dns_state_load(void)
{
    FILE *fp = NULL;
    int fd;
    struct stat state_stat;
    king_mother_node_t *loaded_mother_nodes = NULL;
    uint32_t loaded_mother_node_count = 0;
    zend_long loaded_last_discovered = 0;
    zend_long loaded_last_synced = 0;
    uint32_t magic, version, node_count;

    if (!king_semantic_dns_runtime.initialized) {
        return FAILURE;
    }

    if (king_semantic_dns_state_ensure_directory() != SUCCESS) {
        return FAILURE;
    }

    fd = open(
        KING_SEMANTIC_DNS_STATE_FILE,
        O_RDONLY
#ifdef O_NOFOLLOW
        | O_NOFOLLOW
#endif
    );
    if (fd < 0) {
        return FAILURE; /* expected on first run */
    }

    if (fstat(fd, &state_stat) != 0 || !S_ISREG(state_stat.st_mode)) {
        close(fd);
        return FAILURE;
    }

    fp = fdopen(fd, "rb");
    if (fp == NULL) {
        close(fd);
        return FAILURE;
    }

    if (fread(&magic, sizeof(magic), 1, fp) != 1 || magic != KING_SEMANTIC_DNS_STATE_MAGIC) {
        fclose(fp);
        return FAILURE; /* expected on first run */
    }

    if (fread(&version, sizeof(version), 1, fp) != 1 || version != KING_SEMANTIC_DNS_STATE_VERSION) {
        fclose(fp);
        return FAILURE;
    }

    /* Restore mother nodes topology */
    if (fread(&node_count, sizeof(uint32_t), 1, fp) == 1) {
        if (node_count > 0) {
            if (node_count > KING_SEMANTIC_DNS_STATE_MAX_MOTHER_NODES) {
                fclose(fp);
                return FAILURE;
            }

            loaded_mother_nodes = pecalloc(node_count, sizeof(king_mother_node_t), 1);
            if (loaded_mother_nodes == NULL) {
                fclose(fp);
                return FAILURE;
            }

            if (fread(loaded_mother_nodes, sizeof(king_mother_node_t), node_count, fp) != node_count) {
                pefree(loaded_mother_nodes, 1);
                fclose(fp);
                return FAILURE;
            }

            loaded_mother_node_count = node_count;
        }
    } else {
        fclose(fp);
        return FAILURE;
    }

    if (
        fread(&loaded_last_discovered, sizeof(zend_long), 1, fp) != 1
        || fread(&loaded_last_synced, sizeof(zend_long), 1, fp) != 1
    ) {
        if (loaded_mother_nodes != NULL) {
            pefree(loaded_mother_nodes, 1);
        }
        fclose(fp);
        return FAILURE;
    }

    fclose(fp);

    if (king_semantic_dns_runtime.config.mother_nodes != NULL) {
        pefree(king_semantic_dns_runtime.config.mother_nodes, 1);
    }

    king_semantic_dns_runtime.config.mother_nodes = loaded_mother_nodes;
    king_semantic_dns_runtime.config.mother_node_count = loaded_mother_node_count;
    king_semantic_dns_runtime.last_discovered_node_count = loaded_last_discovered;
    king_semantic_dns_runtime.last_synced_node_count = loaded_last_synced;

    return SUCCESS;
}
