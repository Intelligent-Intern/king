<?php

declare(strict_types=1);

require_once __DIR__ . '/../../support/tenant_context.php';

function videochat_tenant_portability_create_job_id(): string
{
    return 'tenant_job_' . bin2hex(random_bytes(12));
}

function videochat_tenant_export_bundle(PDO $pdo, int $tenantId, int $actorUserId, array $options = []): array
{
    if ($tenantId <= 0 || $actorUserId <= 0) {
        return ['ok' => false, 'reason' => 'invalid_context', 'errors' => ['tenant' => 'required']];
    }

    $scopeType = in_array((string) ($options['scope_type'] ?? 'organization'), ['user', 'organization'], true)
        ? (string) $options['scope_type']
        : 'organization';
    $jobId = videochat_tenant_portability_create_job_id();
    $now = gmdate('c');
    $tables = videochat_tenant_owned_table_names();
    $manifest = [
        'schema_version' => 'tenant-export.v1',
        'tenant_id' => $tenantId,
        'scope_type' => $scopeType,
        'tables' => [],
        'generated_at' => $now,
    ];

    foreach ($tables as $table) {
        if (!videochat_tenant_table_has_column($pdo, $table, 'tenant_id')) {
            continue;
        }
        $count = $pdo->prepare('SELECT COUNT(*) FROM ' . preg_replace('/[^A-Za-z0-9_]/', '', $table) . ' WHERE tenant_id = :tenant_id');
        $count->execute([':tenant_id' => $tenantId]);
        $manifest['tables'][$table] = ['row_count' => (int) $count->fetchColumn()];
    }

    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO tenant_export_jobs(id, tenant_id, actor_user_id, scope_type, schema_version, status, result_json, created_at, updated_at, completed_at)
VALUES(:id, :tenant_id, :actor_user_id, :scope_type, :schema_version, 'completed', :result_json, :created_at, :updated_at, :completed_at)
SQL
    );
    $insert->execute([
        ':id' => $jobId,
        ':tenant_id' => $tenantId,
        ':actor_user_id' => $actorUserId,
        ':scope_type' => $scopeType,
        ':schema_version' => 'tenant-export.v1',
        ':result_json' => json_encode($manifest, JSON_UNESCAPED_SLASHES),
        ':created_at' => $now,
        ':updated_at' => $now,
        ':completed_at' => $now,
    ]);

    return ['ok' => true, 'reason' => 'export_ready', 'job_id' => $jobId, 'bundle' => $manifest];
}

function videochat_tenant_import_dry_run(PDO $pdo, int $tenantId, int $actorUserId, array $bundle): array
{
    $errors = [];
    if ($tenantId <= 0 || $actorUserId <= 0) {
        $errors['tenant'] = 'required';
    }
    if ((string) ($bundle['schema_version'] ?? '') !== 'tenant-export.v1') {
        $errors['schema_version'] = 'unsupported_schema_version';
    }
    if (!is_array($bundle['tables'] ?? null)) {
        $errors['tables'] = 'required_manifest_tables';
    }

    $jobId = videochat_tenant_portability_create_job_id();
    $now = gmdate('c');
    $result = [
        'dry_run' => true,
        'accepted' => $errors === [],
        'errors' => $errors,
        'table_count' => is_array($bundle['tables'] ?? null) ? count($bundle['tables']) : 0,
    ];
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO tenant_import_jobs(id, tenant_id, actor_user_id, scope_type, schema_version, dry_run, status, result_json, failure_reason, created_at, updated_at, completed_at)
VALUES(:id, :tenant_id, :actor_user_id, 'organization', :schema_version, 1, :status, :result_json, :failure_reason, :created_at, :updated_at, :completed_at)
SQL
    );
    $insert->execute([
        ':id' => $jobId,
        ':tenant_id' => $tenantId,
        ':actor_user_id' => $actorUserId,
        ':schema_version' => (string) ($bundle['schema_version'] ?? ''),
        ':status' => $errors === [] ? 'completed' : 'failed',
        ':result_json' => json_encode($result, JSON_UNESCAPED_SLASHES),
        ':failure_reason' => $errors === [] ? '' : 'validation_failed',
        ':created_at' => $now,
        ':updated_at' => $now,
        ':completed_at' => $now,
    ]);

    return ['ok' => $errors === [], 'reason' => $errors === [] ? 'dry_run_passed' : 'validation_failed', 'job_id' => $jobId, 'result' => $result, 'errors' => $errors];
}
