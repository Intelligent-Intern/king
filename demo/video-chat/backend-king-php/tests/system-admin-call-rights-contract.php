<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../http/module_calls_access.php';

function videochat_system_admin_call_rights_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[system-admin-call-rights-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_system_admin_call_rights_create_user(PDO $pdo, string $email, string $displayName, int $roleId, ?string $password): int
{
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', '24h', 'dark', :updated_at)
SQL
    );
    $passwordHash = null;
    if ($password !== null) {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        videochat_system_admin_call_rights_assert(is_string($passwordHash) && $passwordHash !== '', 'password hash should be generated');
    }
    $insert->execute([
        ':email' => strtolower(trim($email)),
        ':display_name' => $displayName,
        ':password_hash' => $passwordHash,
        ':role_id' => $roleId,
        ':updated_at' => gmdate('c'),
    ]);

    $userId = (int) $pdo->lastInsertId();
    videochat_system_admin_call_rights_assert($userId > 0, 'created user id should be positive');
    return $userId;
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[system-admin-call-rights-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-system-admin-call-rights-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $defaultTenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    $adminRoleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'admin' LIMIT 1")->fetchColumn();
    $userRoleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'user' LIMIT 1")->fetchColumn();
    $systemAdminId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();
    $regularUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('user@intelligent-intern.com') LIMIT 1")->fetchColumn();

    videochat_system_admin_call_rights_assert($defaultTenantId > 0, 'default tenant should exist');
    videochat_system_admin_call_rights_assert($adminRoleId > 0 && $userRoleId > 0, 'admin and user roles should exist');
    videochat_system_admin_call_rights_assert($systemAdminId > 0, 'seeded system admin should exist');
    videochat_system_admin_call_rights_assert($regularUserId > 0, 'seeded regular user should exist');

    $pdo->prepare(
        <<<'SQL'
INSERT INTO tenants(public_id, slug, label, status, created_at, updated_at)
VALUES(:public_id, :slug, :label, 'active', :created_at, :updated_at)
SQL
    )->execute([
        ':public_id' => '10000000-0000-4000-8000-000000000005',
        ':slug' => 'system-admin-foreign-org',
        ':label' => 'System Admin Foreign Organization',
        ':created_at' => gmdate('c'),
        ':updated_at' => gmdate('c'),
    ]);
    $foreignTenantId = (int) $pdo->lastInsertId();
    videochat_system_admin_call_rights_assert($foreignTenantId > 0, 'foreign tenant should be inserted');

    $foreignOwnerId = videochat_system_admin_call_rights_create_user(
        $pdo,
        'system-admin-call-owner@example.test',
        'Foreign Call Owner',
        $userRoleId,
        'owner-pass'
    );
    $foreignParticipantId = videochat_system_admin_call_rights_create_user(
        $pdo,
        'system-admin-call-participant@example.test',
        'Foreign Call Participant',
        $userRoleId,
        'participant-pass'
    );
    $foreignSecondParticipantId = videochat_system_admin_call_rights_create_user(
        $pdo,
        'system-admin-call-second-participant@example.test',
        'Foreign Call Second Participant',
        $userRoleId,
        'participant-pass'
    );
    $foreignOrgAdminId = videochat_system_admin_call_rights_create_user(
        $pdo,
        'system-admin-foreign-org-admin@example.test',
        'Foreign Organization Admin',
        $userRoleId,
        'admin-pass'
    );
    $tenantlessOwnerId = videochat_system_admin_call_rights_create_user(
        $pdo,
        'system-admin-tenantless-owner@example.test',
        'Tenantless Call Owner',
        $userRoleId,
        'tenantless-owner-pass'
    );
    videochat_tenant_attach_user($pdo, $foreignOwnerId, $foreignTenantId, 'owner');
    videochat_tenant_attach_user($pdo, $foreignParticipantId, $foreignTenantId, 'member');
    videochat_tenant_attach_user($pdo, $foreignSecondParticipantId, $foreignTenantId, 'member');
    videochat_tenant_attach_user($pdo, $foreignOrgAdminId, $foreignTenantId, 'member');

    $pdo->prepare(
        <<<'SQL'
INSERT INTO organizations(tenant_id, public_id, name, status, created_at, updated_at)
VALUES(:tenant_id, :public_id, :name, 'active', :created_at, :updated_at)
SQL
    )->execute([
        ':tenant_id' => $foreignTenantId,
        ':public_id' => 'org-system-admin-foreign',
        ':name' => 'System Admin Foreign Org Membership',
        ':created_at' => gmdate('c'),
        ':updated_at' => gmdate('c'),
    ]);
    $foreignOrganizationId = (int) $pdo->lastInsertId();
    videochat_system_admin_call_rights_assert($foreignOrganizationId > 0, 'foreign organization should be inserted');

    $foreignOrganizationMembership = $pdo->prepare(
        <<<'SQL'
INSERT INTO organization_memberships(tenant_id, organization_id, user_id, membership_role, status, created_at, updated_at)
VALUES(:tenant_id, :organization_id, :user_id, :membership_role, 'active', :created_at, :updated_at)
SQL
    );
    foreach (
        [
            [$foreignOwnerId, 'member'],
            [$foreignParticipantId, 'member'],
            [$foreignSecondParticipantId, 'member'],
            [$foreignOrgAdminId, 'admin'],
        ] as [$userId, $membershipRole]
    ) {
        $foreignOrganizationMembership->execute([
            ':tenant_id' => $foreignTenantId,
            ':organization_id' => $foreignOrganizationId,
            ':user_id' => $userId,
            ':membership_role' => $membershipRole,
            ':created_at' => gmdate('c'),
            ':updated_at' => gmdate('c'),
        ]);
    }

    $adminForeignMembership = $pdo->prepare(
        'SELECT COUNT(*) FROM tenant_memberships WHERE tenant_id = :tenant_id AND user_id = :user_id AND status = \'active\''
    );
    $adminForeignMembership->execute([
        ':tenant_id' => $foreignTenantId,
        ':user_id' => $systemAdminId,
    ]);
    videochat_system_admin_call_rights_assert((int) $adminForeignMembership->fetchColumn() === 0, 'system admin should not need foreign tenant membership');

    $created = videochat_create_call($pdo, $foreignOwnerId, [
        'title' => 'Foreign Organization Admin Slice',
        'starts_at' => gmdate('c', time() - 600),
        'ends_at' => gmdate('c', time() + 3600),
        'internal_participant_user_ids' => [$foreignParticipantId],
        'external_participants' => [],
    ], $foreignTenantId);
    videochat_system_admin_call_rights_assert((bool) ($created['ok'] ?? false), 'foreign organization call should be created');
    $callId = (string) (($created['call'] ?? [])['id'] ?? '');
    videochat_system_admin_call_rights_assert($callId !== '', 'foreign call id should be present');

    $adminParticipantCount = $pdo->prepare('SELECT COUNT(*) FROM call_participants WHERE call_id = :call_id AND user_id = :user_id');
    $adminParticipantCount->execute([
        ':call_id' => $callId,
        ':user_id' => $systemAdminId,
    ]);
    videochat_system_admin_call_rights_assert((int) $adminParticipantCount->fetchColumn() === 0, 'system admin should not need guest-list participant row');

    videochat_system_admin_call_rights_assert(
        videochat_user_has_system_admin_call_rights($pdo, $systemAdminId, 'admin'),
        'seeded admin should be recognized as trusted system admin'
    );
    $adminFetch = videochat_get_call_for_user($pdo, $callId, $systemAdminId, 'admin', $defaultTenantId);
    videochat_system_admin_call_rights_assert((bool) ($adminFetch['ok'] ?? false), 'system admin should fetch foreign-tenant call through default tenant context');
    videochat_system_admin_call_rights_assert(
        (int) (($adminFetch['call'] ?? [])['tenant_id'] ?? 0) === $foreignTenantId,
        'system admin fetch should return the foreign tenant call'
    );
    videochat_system_admin_call_rights_assert(
        (bool) (($adminFetch['call'] ?? [])['my_participation'] ?? true) === false,
        'system admin access should not depend on call participation'
    );

    $adminUpdate = videochat_update_call($pdo, $callId, $systemAdminId, 'admin', [
        'title' => 'Foreign Organization Admin Slice Updated',
    ], $defaultTenantId);
    videochat_system_admin_call_rights_assert((bool) ($adminUpdate['ok'] ?? false), 'system admin should update foreign-tenant call through default tenant context');
    videochat_system_admin_call_rights_assert(
        (string) (($adminUpdate['call'] ?? [])['title'] ?? '') === 'Foreign Organization Admin Slice Updated',
        'system admin update should return updated title'
    );

    $adminRoleUpdate = videochat_update_call_participant_role(
        $pdo,
        $callId,
        $foreignParticipantId,
        'moderator',
        $systemAdminId,
        'admin',
        $defaultTenantId
    );
    videochat_system_admin_call_rights_assert((bool) ($adminRoleUpdate['ok'] ?? false), 'system admin should manage foreign-tenant call participants');
    $participantRole = $pdo->prepare('SELECT call_role FROM call_participants WHERE call_id = :call_id AND user_id = :user_id LIMIT 1');
    $participantRole->execute([
        ':call_id' => $callId,
        ':user_id' => $foreignParticipantId,
    ]);
    videochat_system_admin_call_rights_assert((string) $participantRole->fetchColumn() === 'moderator', 'system admin participant role update should persist');

    $adminParticipantUpdate = videochat_update_call($pdo, $callId, $systemAdminId, 'admin', [
        'internal_participant_user_ids' => [$foreignParticipantId, $foreignSecondParticipantId],
    ], $defaultTenantId);
    videochat_system_admin_call_rights_assert((bool) ($adminParticipantUpdate['ok'] ?? false), 'system admin should update foreign-tenant participant list through default tenant context');
    videochat_system_admin_call_rights_assert(
        (int) ((($adminParticipantUpdate['call'] ?? [])['participants']['totals'] ?? [])['internal'] ?? 0) === 3,
        'system admin participant-list update should keep owner plus two foreign participants'
    );

    $adminOwnerTransfer = videochat_update_call_participant_role(
        $pdo,
        $callId,
        $foreignSecondParticipantId,
        'owner',
        $systemAdminId,
        'admin',
        $defaultTenantId
    );
    videochat_system_admin_call_rights_assert((bool) ($adminOwnerTransfer['ok'] ?? false), 'system admin should transfer owner on foreign-tenant call');
    $transferredOwnerUserId = (int) $pdo->query(
        'SELECT owner_user_id FROM calls WHERE id = ' . $pdo->quote($callId) . ' LIMIT 1'
    )->fetchColumn();
    videochat_system_admin_call_rights_assert($transferredOwnerUserId === $foreignSecondParticipantId, 'system admin owner transfer should persist');
    $adminAfterOwnerTransfer = videochat_get_call_for_user($pdo, $callId, $systemAdminId, 'admin', $defaultTenantId);
    videochat_system_admin_call_rights_assert((bool) ($adminAfterOwnerTransfer['ok'] ?? false), 'system admin rights should remain after owner transfer');

    $tenantlessCreated = videochat_create_call($pdo, $tenantlessOwnerId, [
        'title' => 'Tenantless System Admin Edge Call',
        'starts_at' => gmdate('c', time() - 300),
        'ends_at' => gmdate('c', time() + 3600),
        'internal_participant_user_ids' => [],
        'external_participants' => [],
    ], null);
    videochat_system_admin_call_rights_assert((bool) ($tenantlessCreated['ok'] ?? false), 'tenantless active call should be created when product data contains such calls');
    $tenantlessCallId = (string) (($tenantlessCreated['call'] ?? [])['id'] ?? '');
    videochat_system_admin_call_rights_assert($tenantlessCallId !== '', 'tenantless call id should be present');
    videochat_system_admin_call_rights_assert(
        array_key_exists('tenant_id', (array) ($tenantlessCreated['call'] ?? [])) && $tenantlessCreated['call']['tenant_id'] === null,
        'tenantless call should keep null tenant_id'
    );

    $adminTenantlessParticipantCount = $pdo->prepare('SELECT COUNT(*) FROM call_participants WHERE call_id = :call_id AND user_id = :user_id');
    $adminTenantlessParticipantCount->execute([
        ':call_id' => $tenantlessCallId,
        ':user_id' => $systemAdminId,
    ]);
    videochat_system_admin_call_rights_assert((int) $adminTenantlessParticipantCount->fetchColumn() === 0, 'system admin should not need tenantless call participant row');

    $adminTenantlessDecision = videochat_decide_call_access_for_user($pdo, $tenantlessCallId, $systemAdminId, 'admin', $defaultTenantId);
    videochat_system_admin_call_rights_assert((bool) ($adminTenantlessDecision['allowed'] ?? false), 'system admin should direct join tenantless active call');
    videochat_system_admin_call_rights_assert((string) ($adminTenantlessDecision['source'] ?? '') === 'system_admin', 'tenantless system admin decision source mismatch');
    videochat_system_admin_call_rights_assert(array_key_exists('tenant_id', $adminTenantlessDecision) && $adminTenantlessDecision['tenant_id'] === null, 'tenantless system admin decision must preserve null tenant_id');
    videochat_system_admin_call_rights_assert((bool) ($adminTenantlessDecision['can_administer'] ?? false), 'system admin should administer tenantless active call');
    videochat_system_admin_call_rights_assert((bool) ($adminTenantlessDecision['can_manage_owner'] ?? false), 'system admin should retain owner-management rights on tenantless call');

    $adminTenantlessFetch = videochat_get_call_for_user($pdo, $tenantlessCallId, $systemAdminId, 'admin', $defaultTenantId);
    videochat_system_admin_call_rights_assert((bool) ($adminTenantlessFetch['ok'] ?? false), 'system admin should fetch tenantless active call through default tenant context');
    videochat_system_admin_call_rights_assert(
        array_key_exists('tenant_id', (array) ($adminTenantlessFetch['call'] ?? [])) && $adminTenantlessFetch['call']['tenant_id'] === null,
        'system admin tenantless fetch should expose null tenant_id'
    );
    videochat_system_admin_call_rights_assert(
        (bool) (($adminTenantlessFetch['call'] ?? [])['my_participation'] ?? true) === false,
        'tenantless system admin access should not depend on call participation'
    );

    $orgAdminTenantlessDecision = videochat_decide_call_access_for_user($pdo, $tenantlessCallId, $foreignOrgAdminId, 'user', $foreignTenantId);
    videochat_system_admin_call_rights_assert(!(bool) ($orgAdminTenantlessDecision['allowed'] ?? true), 'organization admin must not inherit rights over tenantless calls');
    videochat_system_admin_call_rights_assert(
        in_array((string) ($orgAdminTenantlessDecision['reason'] ?? ''), ['forbidden', 'not_found'], true),
        'tenantless organization-admin denial reason mismatch'
    );

    $forgedRegularFetch = videochat_get_call_for_user($pdo, $callId, $regularUserId, 'admin');
    videochat_system_admin_call_rights_assert(!(bool) ($forgedRegularFetch['ok'] ?? true), 'regular user must not simulate system admin through role string');
    $forgedTenantlessFetch = videochat_get_call_for_user($pdo, $tenantlessCallId, $regularUserId, 'admin', $defaultTenantId);
    videochat_system_admin_call_rights_assert(!(bool) ($forgedTenantlessFetch['ok'] ?? true), 'regular user must not simulate system admin for tenantless call');
    videochat_system_admin_call_rights_assert(
        !videochat_user_has_system_admin_call_rights($pdo, $regularUserId, 'admin'),
        'regular user with forged role string should not have system-admin call rights'
    );

    $temporaryAdminId = videochat_system_admin_call_rights_create_user(
        $pdo,
        'guest+systemadmincallrights@videochat.local',
        'Temporary Admin-Shaped Guest',
        $adminRoleId,
        null
    );
    videochat_tenant_attach_user($pdo, $temporaryAdminId, $defaultTenantId, 'member');
    videochat_system_admin_call_rights_assert(
        !videochat_user_has_system_admin_call_rights($pdo, $temporaryAdminId, 'admin'),
        'temporary account must not receive system-admin call rights even with admin role data'
    );

    $temporaryFetch = videochat_get_call_for_user($pdo, $callId, $temporaryAdminId, 'admin');
    videochat_system_admin_call_rights_assert(!(bool) ($temporaryFetch['ok'] ?? true), 'temporary account must not join foreign call as system admin');
    videochat_system_admin_call_rights_assert(
        (string) ($temporaryFetch['reason'] ?? '') === 'forbidden',
        'temporary account foreign-call denial reason should be forbidden when tenant scope is absent'
    );
    $temporaryTenantlessDecision = videochat_decide_call_access_for_user($pdo, $tenantlessCallId, $temporaryAdminId, 'admin', $defaultTenantId);
    videochat_system_admin_call_rights_assert(!(bool) ($temporaryTenantlessDecision['allowed'] ?? true), 'temporary account must not join tenantless call as system admin');
    videochat_system_admin_call_rights_assert(
        in_array((string) ($temporaryTenantlessDecision['reason'] ?? ''), ['forbidden', 'not_found'], true),
        'temporary tenantless denial reason mismatch'
    );
    $temporaryRoleUpdate = videochat_update_call_participant_role(
        $pdo,
        $callId,
        $foreignParticipantId,
        'participant',
        $temporaryAdminId,
        'admin'
    );
    videochat_system_admin_call_rights_assert(!(bool) ($temporaryRoleUpdate['ok'] ?? true), 'temporary account must not manage foreign call as system admin');
    videochat_system_admin_call_rights_assert((string) ($temporaryRoleUpdate['reason'] ?? '') === 'forbidden', 'temporary account manage denial reason mismatch');

    $reviewAccessId = videochat_generate_call_access_uuid();
    $reviewAccessLink = [
        'id' => $reviewAccessId,
        'tenant_id' => $foreignTenantId,
        'call_id' => $callId,
        'participant_user_id' => $foreignParticipantId,
        'participant_email' => 'system-admin-call-participant@example.test',
    ];
    $reviewCall = [
        'id' => $callId,
        'tenant_id' => $foreignTenantId,
    ];
    $reviewLinkedUser = [
        'id' => $foreignParticipantId,
        'email' => 'system-admin-call-participant@example.test',
    ];
    $reviewRecord = videochat_call_access_record_duplicate_personalized_link_review(
        $pdo,
        $reviewAccessLink,
        $reviewCall,
        $reviewLinkedUser,
        $foreignSecondParticipantId,
        'system_admin_review_contract',
        ['session_id' => 'sess_system_admin_review_contract']
    );
    videochat_system_admin_call_rights_assert((bool) ($reviewRecord['ok'] ?? false), 'duplicate personalized-link review flag should record');
    videochat_system_admin_call_rights_assert((bool) ($reviewRecord['flag_created'] ?? false), 'review flag should be created for second account');
    $reviewFlag = is_array($reviewRecord['flag'] ?? null) ? $reviewRecord['flag'] : [];
    $reviewFlagPublicId = (string) ($reviewFlag['public_id'] ?? '');
    videochat_system_admin_call_rights_assert($reviewFlagPublicId !== '', 'review flag public id should be available');

    $systemAdminReviewList = videochat_call_access_list_review_flags_for_user($pdo, $systemAdminId, 'admin', [
        'status' => 'open',
        'tenant_id' => $foreignTenantId,
        'limit' => 5,
    ]);
    videochat_system_admin_call_rights_assert((bool) ($systemAdminReviewList['ok'] ?? false), 'system admin should list open review flags');
    videochat_system_admin_call_rights_assert((int) ($systemAdminReviewList['total'] ?? 0) === 1, 'system admin review list should return one open flag');
    $listedFlag = (array) (($systemAdminReviewList['flags'] ?? [])[0] ?? []);
    videochat_system_admin_call_rights_assert((string) ($listedFlag['public_id'] ?? '') === $reviewFlagPublicId, 'system admin review list public id mismatch');
    videochat_system_admin_call_rights_assert(!array_key_exists('access_fingerprint', $listedFlag), 'review list must not expose access fingerprint');
    videochat_system_admin_call_rights_assert((($listedFlag['payload'] ?? [])['raw_link_identifier_logged'] ?? true) === false, 'review payload must mark raw link id omitted');
    videochat_system_admin_call_rights_assert((($listedFlag['payload'] ?? [])['account_email_logged'] ?? true) === false, 'review payload must mark account email omitted');

    $regularReviewList = videochat_call_access_list_review_flags_for_user($pdo, $regularUserId, 'admin', ['status' => 'open']);
    videochat_system_admin_call_rights_assert(!(bool) ($regularReviewList['ok'] ?? true), 'regular user with forged role must not list review flags');
    videochat_system_admin_call_rights_assert((string) ($regularReviewList['reason'] ?? '') === 'forbidden', 'regular review list denial reason mismatch');
    $temporaryReviewList = videochat_call_access_list_review_flags_for_user($pdo, $temporaryAdminId, 'admin', ['status' => 'open']);
    videochat_system_admin_call_rights_assert(!(bool) ($temporaryReviewList['ok'] ?? true), 'temporary admin-shaped account must not list review flags');
    videochat_system_admin_call_rights_assert((string) ($temporaryReviewList['reason'] ?? '') === 'forbidden', 'temporary review list denial reason mismatch');

    $regularReviewHandle = videochat_call_access_handle_review_flag_for_user($pdo, $reviewFlagPublicId, $regularUserId, 'admin', 'resolved');
    videochat_system_admin_call_rights_assert(!(bool) ($regularReviewHandle['ok'] ?? true), 'regular user with forged role must not handle review flags');
    videochat_system_admin_call_rights_assert((string) ($regularReviewHandle['reason'] ?? '') === 'forbidden', 'regular review handle denial reason mismatch');

    $systemAdminReviewHandle = videochat_call_access_handle_review_flag_for_user($pdo, $reviewFlagPublicId, $systemAdminId, 'admin', 'resolved', [
        'note' => 'reviewed duplicate use',
    ]);
    videochat_system_admin_call_rights_assert((bool) ($systemAdminReviewHandle['ok'] ?? false), 'system admin should handle review flag');
    $handledFlag = (array) ($systemAdminReviewHandle['flag'] ?? []);
    videochat_system_admin_call_rights_assert((string) ($handledFlag['status'] ?? '') === 'resolved', 'handled review flag status mismatch');
    videochat_system_admin_call_rights_assert((int) ($handledFlag['handled_by_user_id'] ?? 0) === $systemAdminId, 'handled review flag actor mismatch');
    videochat_system_admin_call_rights_assert(!array_key_exists('access_fingerprint', $handledFlag), 'handled review flag must not expose access fingerprint');

    $auditReviewHandledCount = (int) $pdo->query("SELECT COUNT(*) FROM videochat_audit_events WHERE event_type = 'call_access_review_flag_handled'")->fetchColumn();
    videochat_system_admin_call_rights_assert($auditReviewHandledCount === 1, 'review flag handling should be audit logged once');
    $auditReviewPayload = (string) $pdo->query("SELECT payload_json FROM videochat_audit_events WHERE event_type = 'call_access_review_flag_handled' LIMIT 1")->fetchColumn();
    videochat_system_admin_call_rights_assert(!str_contains($auditReviewPayload, $reviewAccessId), 'review handling audit must not expose raw access id');
    videochat_system_admin_call_rights_assert(!str_contains($auditReviewPayload, 'system-admin-call-participant@example.test'), 'review handling audit must not expose account email');

    $jsonResponse = static fn (int $status, array $payload): array => [
        'status' => $status,
        'headers' => ['content-type' => 'application/json'],
        'body' => json_encode($payload, JSON_UNESCAPED_SLASHES),
    ];
    $errorResponse = static fn (int $status, string $code, string $message, array $details = []) => $jsonResponse($status, [
        'status' => 'error',
        'error' => ['code' => $code, 'message' => $message, 'details' => $details],
        'time' => gmdate('c'),
    ]);
    $decodeJsonBody = static function (array $request): array {
        $decoded = json_decode((string) ($request['body'] ?? ''), true);
        return [is_array($decoded) ? $decoded : null, is_array($decoded) ? null : 'invalid_json'];
    };
    $openDatabase = static fn (): PDO => $pdo;
    $systemAdminAuthContext = [
        'ok' => true,
        'session' => ['id' => 'sess_system_admin_review_route'],
        'user' => ['id' => $systemAdminId, 'role' => 'admin'],
    ];
    $regularForgedAuthContext = [
        'ok' => true,
        'session' => ['id' => 'sess_regular_review_route'],
        'user' => ['id' => $regularUserId, 'role' => 'admin'],
    ];

    $routeListResponse = videochat_handle_call_access_routes(
        '/api/call-access/review-flags',
        'GET',
        ['method' => 'GET', 'uri' => '/api/call-access/review-flags?status=resolved', 'headers' => []],
        $systemAdminAuthContext,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_system_admin_call_rights_assert(is_array($routeListResponse), 'review flag list route should return response');
    videochat_system_admin_call_rights_assert((int) ($routeListResponse['status'] ?? 0) === 200, 'system admin review list route should return 200');
    $routeListPayload = json_decode((string) ($routeListResponse['body'] ?? ''), true);
    videochat_system_admin_call_rights_assert((int) (($routeListPayload['result'] ?? [])['total'] ?? 0) === 1, 'system admin review list route should return resolved flag');
    videochat_system_admin_call_rights_assert(!str_contains((string) ($routeListResponse['body'] ?? ''), 'access_fingerprint'), 'review list route must not expose access fingerprint');

    $routeForbiddenResponse = videochat_handle_call_access_routes(
        '/api/call-access/review-flags',
        'GET',
        ['method' => 'GET', 'uri' => '/api/call-access/review-flags', 'headers' => []],
        $regularForgedAuthContext,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_system_admin_call_rights_assert(is_array($routeForbiddenResponse), 'forged review flag list route should return response');
    videochat_system_admin_call_rights_assert((int) ($routeForbiddenResponse['status'] ?? 0) === 403, 'regular forged admin review list route should be forbidden');

    $routeHandleResponse = videochat_handle_call_access_routes(
        '/api/call-access/review-flags/' . $reviewFlagPublicId,
        'PATCH',
        [
            'method' => 'PATCH',
            'uri' => '/api/call-access/review-flags/' . $reviewFlagPublicId,
            'headers' => [],
            'body' => json_encode(['status' => 'dismissed', 'note' => 'dismiss through route'], JSON_UNESCAPED_SLASHES),
        ],
        $systemAdminAuthContext,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_system_admin_call_rights_assert(is_array($routeHandleResponse), 'review flag handle route should return response');
    videochat_system_admin_call_rights_assert((int) ($routeHandleResponse['status'] ?? 0) === 200, 'system admin review handle route should return 200');
    $routeHandlePayload = json_decode((string) ($routeHandleResponse['body'] ?? ''), true);
    videochat_system_admin_call_rights_assert((string) (((($routeHandlePayload['result'] ?? [])['flag'] ?? [])['status'] ?? '')) === 'dismissed', 'review handle route should update status');
    videochat_system_admin_call_rights_assert(!str_contains((string) ($routeHandleResponse['body'] ?? ''), 'access_fingerprint'), 'review handle route must not expose access fingerprint');

    @unlink($databasePath);
    fwrite(STDOUT, "[system-admin-call-rights-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[system-admin-call-rights-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
