<?php

declare(strict_types=1);

function videochat_call_access_calendar_link_is_invalidated(PDO $pdo, array $accessLink): bool
{
    if (!videochat_tenant_table_has_column($pdo, 'appointment_bookings', 'access_id')) {
        return false;
    }

    $accessId = trim((string) ($accessLink['id'] ?? ''));
    if ($accessId === '') {
        return false;
    }

    $query = $pdo->prepare(
        <<<'SQL'
SELECT call_id, status
FROM appointment_bookings
WHERE access_id = :access_id
ORDER BY created_at DESC, id DESC
LIMIT 1
SQL
    );
    $query->execute([':access_id' => $accessId]);
    $booking = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($booking)) {
        return false;
    }

    $bookingStatus = strtolower(trim((string) ($booking['status'] ?? '')));
    if ($bookingStatus !== 'booked') {
        return true;
    }

    $bookingCallId = trim((string) ($booking['call_id'] ?? ''));
    $linkCallId = trim((string) ($accessLink['call_id'] ?? ''));
    return $bookingCallId !== '' && $linkCallId !== '' && !hash_equals($bookingCallId, $linkCallId);
}
