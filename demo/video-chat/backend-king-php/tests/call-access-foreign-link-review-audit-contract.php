<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/calls/call_access_review.php';

function videochat_foreign_link_review_audit_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-access-foreign-link-review-audit-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_foreign_link_review_audit_assert_no_needles(string $text, array $needles, string $label): void
{
    $haystack = strtolower($text);
    foreach ($needles as $needle) {
        $value = strtolower(trim((string) $needle));
        if ($value === '') {
            continue;
        }
        videochat_foreign_link_review_audit_assert(!str_contains($haystack, $value), "{$label} leaked {$needle}");
    }
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-access-foreign-link-review-audit-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $linkTenantId = 7101;
    $callTenantId = 7202;
    $foreignUserId = 75;
    $linkedUserId = 82;
    $accessId = '11111111-1111-4111-8111-111111111111';
    $callId = '22222222-2222-4222-8222-222222222222';
    $sessionId = 'sess_foreign_link_review_should_not_persist';
    $secondSessionId = 'sess_foreign_link_review_second_should_not_persist';
    $hostName = 'Private Foreign Review Host';
    $token = 'tok_foreign_link_review_should_not_persist';
    $cookie = 'king_session=foreign-link-review-cookie';
    $sdp = "v=0\r\no=- foreign-link-review 1 1 IN IP4 127.0.0.1\r\ns=-\r\n";
    $iceCandidate = 'candidate:1 1 udp 2122260223 127.0.0.1 9 typ host';
    $foreignEmail = 'foreign-review-actor@example.test';
    $linkedEmail = 'linked-review-target@example.test';
    $hostEmail = 'private-review-host@example.test';
    $foreignName = 'Foreign Review Actor';
    $linkedName = 'Linked Review Target';
    $privateCallTitle = 'Private Foreign Review Call';

    $accessLink = [
        'id' => $accessId,
        'tenant_id' => $linkTenantId,
        'call_id' => 'stale-link-call-id',
        'participant_user_id' => $linkedUserId,
        'participant_email' => $linkedEmail,
        'link_kind' => 'personal',
    ];
    $call = [
        'id' => $callId,
        'tenant_id' => $callTenantId,
        'title' => $privateCallTitle,
        'status' => 'scheduled',
    ];
    $linkedUser = [
        'id' => $linkedUserId,
        'email' => $linkedEmail,
        'display_name' => $linkedName,
    ];
    $sensitiveNeedles = [
        $accessId,
        $sessionId,
        $secondSessionId,
        $hostName,
        $token,
        $cookie,
        $sdp,
        $iceCandidate,
        $foreignEmail,
        $linkedEmail,
        $hostEmail,
        $foreignName,
        $linkedName,
        $privateCallTitle,
        'stale-link-call-id',
    ];

    videochat_foreign_link_review_audit_assert(
        videochat_call_access_review_tenant_id($accessLink, $call) === $callTenantId,
        'review tenant resolver must prefer the call organization over a stale link tenant'
    );
    videochat_foreign_link_review_audit_assert(
        videochat_audit_call_access_tenant_id($accessLink, $call) === $callTenantId,
        'audit tenant resolver must prefer the call organization over a stale link tenant'
    );

    $firstReview = videochat_call_access_record_duplicate_personalized_link_review(
        $pdo,
        $accessLink,
        $call,
        $linkedUser,
        $foreignUserId,
        'foreign_link_review_audit',
        [
            'session_id' => $sessionId,
            'host_name' => $hostName,
            'token' => $token,
            'cookie' => $cookie,
            'sdp' => $sdp,
            'ice_candidate' => $iceCandidate,
            'foreign_email' => $foreignEmail,
            'host_email' => $hostEmail,
            'private_call_title' => $privateCallTitle,
        ]
    );
    videochat_foreign_link_review_audit_assert((bool) ($firstReview['ok'] ?? false), 'foreign duplicate review should record');
    videochat_foreign_link_review_audit_assert((bool) ($firstReview['flag_created'] ?? false), 'first foreign duplicate review should create a flag');

    $secondReview = videochat_call_access_record_duplicate_personalized_link_review(
        $pdo,
        $accessLink,
        $call,
        $linkedUser,
        $foreignUserId,
        'foreign_link_review_audit_repeat',
        [
            'session_id' => $secondSessionId,
            'host_name' => 'Second Private Host',
            'token' => 'second-token-should-not-persist',
        ]
    );
    videochat_foreign_link_review_audit_assert((bool) ($secondReview['ok'] ?? false), 'repeat foreign duplicate review should record');
    videochat_foreign_link_review_audit_assert(!(bool) ($secondReview['flag_created'] ?? true), 'repeat foreign duplicate review should reuse the existing review flag');

    $flags = $pdo->query('SELECT * FROM call_access_review_flags')->fetchAll();
    videochat_foreign_link_review_audit_assert(count($flags) === 1, 'review flags must deduplicate per foreign subject and access fingerprint');
    $flag = $flags[0];
    videochat_foreign_link_review_audit_assert((int) ($flag['tenant_id'] ?? 0) === $callTenantId, 'review flag tenant must follow the call organization');
    videochat_foreign_link_review_audit_assert((string) ($flag['call_id'] ?? '') === $callId, 'review flag call id must follow the call');
    videochat_foreign_link_review_audit_assert((int) ($flag['subject_user_id'] ?? 0) === $foreignUserId, 'review flag subject must be the foreign account');
    videochat_foreign_link_review_audit_assert((int) ($flag['target_user_id'] ?? 0) === $linkedUserId, 'review flag target must be the linked account');
    videochat_foreign_link_review_audit_assert((int) ($flag['first_seen_user_id'] ?? 0) === $linkedUserId, 'review flag should identify the affected linked account reference');
    videochat_foreign_link_review_audit_assert((string) ($flag['access_fingerprint'] ?? '') === videochat_audit_fingerprint($accessId), 'review flag must fingerprint the foreign link');
    videochat_foreign_link_review_audit_assert_no_needles((string) ($flag['payload_json'] ?? ''), $sensitiveNeedles, 'review flag payload');

    $comparison = videochat_audit_record_call_access_account_compared(
        $pdo,
        $accessLink,
        $call,
        $linkedUser,
        $foreignUserId,
        'strong_mismatch',
        [
            'session_id' => $sessionId,
            'stage' => 'foreign_review_audit_probe',
            'host_name' => $hostName,
            'token' => $token,
        ]
    );
    videochat_foreign_link_review_audit_assert((bool) ($comparison['ok'] ?? false), 'mismatched account comparison audit should record');

    $reviewEvents = $pdo->query(
        "SELECT tenant_id, actor_user_id, target_user_id, call_id, resource_id, resource_fingerprint, session_fingerprint, payload_json, created_at
         FROM videochat_audit_events
         WHERE event_type = 'call_access_duplicate_personalized_link_review'
         ORDER BY id ASC"
    )->fetchAll();
    videochat_foreign_link_review_audit_assert(count($reviewEvents) === 2, 'duplicate review attempts should create auditable attempts while reusing one flag');

    foreach ($reviewEvents as $index => $event) {
        $payload = json_decode((string) ($event['payload_json'] ?? '{}'), true);
        videochat_foreign_link_review_audit_assert(is_array($payload), 'review audit payload should decode');
        videochat_foreign_link_review_audit_assert((int) ($event['tenant_id'] ?? 0) === $callTenantId, 'review audit tenant must follow the call organization');
        videochat_foreign_link_review_audit_assert((int) ($event['actor_user_id'] ?? 0) === $foreignUserId, 'review audit actor must be the foreign account');
        videochat_foreign_link_review_audit_assert((int) ($event['target_user_id'] ?? 0) === $linkedUserId, 'review audit target must be the linked account');
        videochat_foreign_link_review_audit_assert((string) ($event['call_id'] ?? '') === $callId, 'review audit call id must follow the call');
        videochat_foreign_link_review_audit_assert((string) ($event['resource_id'] ?? '') === '', 'review audit must not persist raw access id');
        videochat_foreign_link_review_audit_assert((string) ($event['resource_fingerprint'] ?? '') === videochat_audit_fingerprint($accessId), 'review audit must fingerprint the foreign link');
        $expectedSession = $index === 0 ? $sessionId : $secondSessionId;
        videochat_foreign_link_review_audit_assert((string) ($event['session_fingerprint'] ?? '') === videochat_audit_fingerprint($expectedSession), 'review audit must fingerprint the session id');
        videochat_foreign_link_review_audit_assert(trim((string) ($event['created_at'] ?? '')) !== '', 'review audit should include a timestamp');
        videochat_foreign_link_review_audit_assert((string) ($payload['flag'] ?? '') === 'duplicate_personalized_link', 'review audit flag mismatch');
        videochat_foreign_link_review_audit_assert((string) ($payload['review_status'] ?? '') === 'manual_review_required', 'review audit should be reviewer-understandable');
        videochat_foreign_link_review_audit_assert((bool) ($payload['flag_created'] ?? true) === ($index === 0), 'review audit should expose whether the deduplicated flag was created');
        videochat_foreign_link_review_audit_assert((int) ($payload['first_seen_user_id'] ?? 0) === $linkedUserId, 'review audit should reference the affected linked account');
        videochat_foreign_link_review_audit_assert((bool) ($payload['raw_link_identifier_logged'] ?? true) === false, 'review audit must mark raw link omission');
        videochat_foreign_link_review_audit_assert((bool) ($payload['account_email_logged'] ?? true) === false, 'review audit must mark email omission');
        videochat_foreign_link_review_audit_assert((bool) ($payload['host_name_logged'] ?? true) === false, 'review audit must mark host-name omission');
    }

    $comparisonEvent = $pdo->query(
        "SELECT tenant_id, actor_user_id, target_user_id, call_id, resource_id, resource_fingerprint, session_fingerprint, payload_json
         FROM videochat_audit_events
         WHERE event_type = 'call_access_account_compared'
         ORDER BY id DESC
         LIMIT 1"
    )->fetch();
    videochat_foreign_link_review_audit_assert(is_array($comparisonEvent), 'mismatched account comparison audit event should exist');
    videochat_foreign_link_review_audit_assert((int) ($comparisonEvent['tenant_id'] ?? 0) === $callTenantId, 'mismatched account audit tenant must follow the call organization');
    videochat_foreign_link_review_audit_assert((string) ($comparisonEvent['call_id'] ?? '') === $callId, 'mismatched account audit call id must follow the call');
    videochat_foreign_link_review_audit_assert((string) ($comparisonEvent['resource_id'] ?? '') === '', 'mismatched account audit must not persist raw access id');
    videochat_foreign_link_review_audit_assert((string) ($comparisonEvent['resource_fingerprint'] ?? '') === videochat_audit_fingerprint($accessId), 'mismatched account audit must fingerprint the foreign link');
    videochat_foreign_link_review_audit_assert((string) ($comparisonEvent['session_fingerprint'] ?? '') === videochat_audit_fingerprint($sessionId), 'mismatched account audit must fingerprint the session id');
    $comparisonPayload = json_decode((string) ($comparisonEvent['payload_json'] ?? '{}'), true);
    videochat_foreign_link_review_audit_assert(is_array($comparisonPayload), 'mismatched account audit payload should decode');
    videochat_foreign_link_review_audit_assert((bool) ($comparisonPayload['foreign_account_data_logged'] ?? true) === false, 'mismatched account audit must mark foreign data omission');
    videochat_foreign_link_review_audit_assert((bool) ($comparisonPayload['raw_link_identifier_logged'] ?? true) === false, 'mismatched account audit must mark raw link omission');
    videochat_foreign_link_review_audit_assert((bool) ($comparisonPayload['raw_credential_identifier_logged'] ?? true) === false, 'mismatched account audit must mark credential omission');

    $encodedAuditRows = json_encode(
        $pdo->query('SELECT * FROM videochat_audit_events ORDER BY id ASC')->fetchAll(),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    videochat_foreign_link_review_audit_assert(is_string($encodedAuditRows), 'audit rows should encode');
    videochat_foreign_link_review_audit_assert_no_needles($encodedAuditRows, $sensitiveNeedles, 'review and mismatch audit events');

    fwrite(STDOUT, "[call-access-foreign-link-review-audit-contract] PASS\n");
} catch (Throwable $error) {
    fwrite(STDERR, '[call-access-foreign-link-review-audit-contract] ERROR: ' . $error->getMessage() . "\n");
    fwrite(STDERR, $error->getTraceAsString() . "\n");
    exit(1);
}
