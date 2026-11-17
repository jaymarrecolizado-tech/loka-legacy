<?php
/**
 * Sidebar / dashboard badge counts with per-user "seen" acknowledgements.
 * Badge clears after viewing the page; returns when new pending IDs appear.
 */

function badgeCountPendingApprovals(): int
{
    return badgeUnseenCount('approvals', badgePendingIdsApprovals());
}

function badgeCountPendingGasVouchers(): int
{
    return badgeUnseenCount('gas_vouchers', badgePendingIdsGasVouchers());
}

function badgeCountSubmittedTripTickets(): int
{
    return badgeUnseenCount('trip_tickets', badgePendingIdsTripTickets());
}

function badgeCountPendingMaintenance(): int
{
    return badgeUnseenCount('maintenance', badgePendingIdsMaintenance());
}

function badgeCountGuardOps(): int
{
    return badgeUnseenCount('guard', badgePendingIdsGuard());
}

/**
 * Unbound OB Pass Slips waiting at the gate: departure stamp or arrival time.
 * Bound official-vehicle slips are excluded — they stamp from the vehicle row
 * (Plan #24) and are covered by the Guard Dashboard badge.
 */
function badgeCountObGuardStamps(): int
{
    return badgeUnseenCount('ob_guard_stamps', badgePendingIdsObGuard());
}

function badgeCountRequestsNeedingRevision(): int
{
    return badgeUnseenCount('requests_revision', badgePendingIdsRequestsRevision());
}

function badgeCountVehiclesAttention(): int
{
    return badgeUnseenCount('vehicles', badgePendingIdsVehiclesAttention());
}

function badgeCountSecurityLockouts(): int
{
    return badgeUnseenCount('security_lockouts', badgePendingIdsSecurityLockouts());
}

/** @return list<int> */
function badgePendingIdsApprovals(): array
{
    try {
        if (isAdmin()) {
            return badgeFetchIds(
                "SELECT id FROM requests
                 WHERE status IN ('pending','pending_motorpool','revision') AND deleted_at IS NULL"
            );
        }
        if (isMotorpool()) {
            return badgeFetchIds(
                "SELECT id FROM requests
                 WHERE (status = 'pending_motorpool' OR status = 'revision')
                   AND motorpool_head_id = ? AND deleted_at IS NULL",
                [userId()]
            );
        }
        if (isApprover()) {
            $deptId = currentUser()->department_id ?? null;
            if (!$deptId) {
                return [];
            }
            return badgeFetchIds(
                "SELECT id FROM requests
                 WHERE status = 'pending' AND department_id = ? AND deleted_at IS NULL",
                [$deptId]
            );
        }
    } catch (Throwable $e) {
        error_log('badgePendingIdsApprovals: ' . $e->getMessage());
    }
    return [];
}

/** @return list<int> */
function badgePendingIdsGasVouchers(): array
{
    try {
        $ids = [];
        if (isChiefAdminFinance() || isAdmin()) {
            // CAF stays on the final step only (Plan #31)
            $ids = badgeFetchIds(
                "SELECT id FROM gas_vouchers WHERE status = 'pending_approval' AND deleted_at IS NULL"
            );
        } elseif (isMotorpool() || isApprover()) {
            $ids = badgeFetchIds(
                "SELECT id FROM gas_vouchers
                 WHERE status IN ('pending_review','pending_approval') AND deleted_at IS NULL"
            );
        }
        // Budget Officers (flag pool) see the pending_budget step regardless of role
        if (isBudgetOfficer()) {
            $budgetIds = badgeFetchIds(
                "SELECT id FROM gas_vouchers WHERE status = 'pending_budget' AND deleted_at IS NULL"
            );
            $ids = array_values(array_unique(array_merge($ids, $budgetIds)));
        }
        return $ids;
    } catch (Throwable $e) {
        error_log('badgePendingIdsGasVouchers: ' . $e->getMessage());
    }
    return [];
}

/** @return list<int> */
function badgePendingIdsTripTickets(): array
{
    try {
        if (isApprover() || isMotorpool() || isAdmin()) {
            return badgeFetchIds(
                "SELECT id FROM trip_tickets WHERE status = 'submitted' AND deleted_at IS NULL"
            );
        }
    } catch (Throwable $e) {
        error_log('badgePendingIdsTripTickets: ' . $e->getMessage());
    }
    return [];
}

/** @return list<int> */
function badgePendingIdsMaintenance(): array
{
    try {
        if (isApprover() || isMotorpool() || isAdmin()) {
            return badgeFetchIds(
                "SELECT id FROM maintenance_requests
                 WHERE status IN (?, ?) AND deleted_at IS NULL",
                [MAINTENANCE_STATUS_PENDING, MAINTENANCE_STATUS_SCHEDULED]
            );
        }
    } catch (Throwable $e) {
        error_log('badgePendingIdsMaintenance: ' . $e->getMessage());
    }
    return [];
}

/** @return list<int> */
function badgePendingIdsGuard(): array
{
    try {
        if (!canAccessGuardDashboard()) {
            return [];
        }
        return badgeFetchIds(
            "SELECT id FROM requests
             WHERE status = 'approved' AND deleted_at IS NULL
               AND (
                 actual_dispatch_datetime IS NULL
                 OR (actual_dispatch_datetime IS NOT NULL AND actual_arrival_datetime IS NULL)
               )"
        );
    } catch (Throwable $e) {
        error_log('badgePendingIdsGuard: ' . $e->getMessage());
    }
    return [];
}

/**
 * Unbound OB slips waiting for a guard stamp (Plan #27): departure stamp on
 * approved slips, or the arrival time on slips already out. Mirrors the two
 * queries in pages/guard/partials/ob_section.php. Bound official-vehicle
 * slips are excluded (they stamp from the vehicle row).
 *
 * @return list<int>
 */
function badgePendingIdsObGuard(): array
{
    try {
        if (!canAccessGuardDashboard()) {
            return [];
        }
        return badgeFetchIds(
            "SELECT o.id FROM ob_requests o
             WHERE o.deleted_at IS NULL
               AND (
                 (o.ob_departure_datetime IS NULL
                   AND o.status IN ('approved','coa_received','completed'))
                 OR (o.ob_departure_datetime IS NOT NULL
                   AND o.ob_arrival_datetime IS NULL
                   AND o.status IN ('departed','coa_received','completed'))
               )
               AND NOT EXISTS (
                 SELECT 1 FROM requests r
                 WHERE r.ob_request_id = o.id
                   AND r.deleted_at IS NULL
                   AND r.status <> 'cancelled'
               )"
        );
    } catch (Throwable $e) {
        error_log('badgePendingIdsObGuard: ' . $e->getMessage());
    }
    return [];
}

function badgeCountObApprovals(): int
{
    return badgeUnseenCount('ob_approvals', badgePendingIdsObApprovals());
}

/**
 * OB slips awaiting MY approval (Plan #37): the named Immediate Supervisor
 * counts pending_supervisor slips addressed to them; Motorpool/Admin add all
 * pending_motorpool slips (any motorpool head may approve per process.php,
 * so no head-ID filter). Self-gating — a user who supervises nobody counts 0.
 *
 * @return list<int>
 */
function badgePendingIdsObApprovals(): array
{
    try {
        if (!isLoggedIn()) {
            return [];
        }
        $ids = badgeFetchIds(
            "SELECT id FROM ob_requests
             WHERE deleted_at IS NULL
               AND status = 'pending_supervisor'
               AND supervisor_user_id = ?",
            [userId()]
        );
        if (isMotorpool() || isAdmin()) {
            $mpIds = badgeFetchIds(
                "SELECT id FROM ob_requests
                 WHERE deleted_at IS NULL AND status = 'pending_motorpool'"
            );
            $ids = array_values(array_unique(array_merge($ids, $mpIds)));
        }
        return $ids;
    } catch (Throwable $e) {
        error_log('badgePendingIdsObApprovals: ' . $e->getMessage());
    }
    return [];
}

/** @return list<int> */
function badgePendingIdsRequestsRevision(): array
{
    try {
        if (isGuard() || isAdmin() || isMotorpool()) {
            return [];
        }
        return badgeFetchIds(
            "SELECT id FROM requests
             WHERE user_id = ? AND status = 'revision' AND deleted_at IS NULL",
            [userId()]
        );
    } catch (Throwable $e) {
        error_log('badgePendingIdsRequestsRevision: ' . $e->getMessage());
    }
    return [];
}

/** @return list<int> */
function badgePendingIdsVehiclesAttention(): array
{
    try {
        if (!(isMotorpool() || isAdmin() || isApprover())) {
            return [];
        }
        return badgeFetchIds(
            "SELECT id FROM vehicles
             WHERE deleted_at IS NULL AND status IN (?, ?)",
            [VEHICLE_MAINTENANCE, VEHICLE_OUT_OF_SERVICE]
        );
    } catch (Throwable $e) {
        error_log('badgePendingIdsVehiclesAttention: ' . $e->getMessage());
    }
    return [];
}

/** @return list<int> */
function badgePendingIdsSecurityLockouts(): array
{
    try {
        if (!isAllFather()) {
            return [];
        }
        return badgeFetchIds(
            "SELECT id FROM users
             WHERE deleted_at IS NULL
               AND (
                 (locked_until IS NOT NULL AND locked_until > NOW())
                 OR failed_login_attempts >= ?
               )",
            [RATE_LIMIT_LOGIN_ATTEMPTS]
        );
    } catch (Throwable $e) {
        error_log('badgePendingIdsSecurityLockouts: ' . $e->getMessage());
    }
    return [];
}

/**
 * @param list<int> $pendingIds
 */
function badgeUnseenCount(string $key, array $pendingIds): int
{
    if ($pendingIds === []) {
        return 0;
    }
    $seen = badgeGetSeenIds($key);
    if ($seen === []) {
        return count($pendingIds);
    }
    $unseen = array_diff($pendingIds, $seen);
    return count($unseen);
}

/**
 * @return list<int>
 */
function badgeGetSeenIds(string $key): array
{
    if (!isLoggedIn()) {
        return [];
    }
    try {
        $row = db()->fetch(
            "SELECT seen_ids_json FROM user_badge_acks WHERE user_id = ? AND badge_key = ?",
            [userId(), $key]
        );
        if (!$row || empty($row->seen_ids_json)) {
            return [];
        }
        $ids = json_decode($row->seen_ids_json, true);
        if (!is_array($ids)) {
            return [];
        }
        return array_values(array_map('intval', $ids));
    } catch (Throwable $e) {
        // Table may not exist yet
        return [];
    }
}

/**
 * @param list<int> $ids
 */
function badgeMarkSeen(string $key, array $ids): void
{
    if (!isLoggedIn()) {
        return;
    }
    $ids = array_values(array_unique(array_map('intval', $ids)));
    sort($ids);
    $json = json_encode($ids);
    $now = date(DATETIME_FORMAT);
    try {
        $existing = db()->fetch(
            "SELECT id FROM user_badge_acks WHERE user_id = ? AND badge_key = ?",
            [userId(), $key]
        );
        if ($existing) {
            db()->update('user_badge_acks', [
                'seen_ids_json' => $json,
                'updated_at' => $now,
            ], 'id = ?', [$existing->id]);
        } else {
            db()->insert('user_badge_acks', [
                'user_id' => userId(),
                'badge_key' => $key,
                'seen_ids_json' => $json,
                'updated_at' => $now,
                'created_at' => $now,
            ]);
        }
    } catch (Throwable $e) {
        error_log('badgeMarkSeen: ' . $e->getMessage());
    }
}

/**
 * Mark badges as seen for the current page visit.
 * Called from public_html/index.php after auth (Plan #27).
 */
function badgeMarkSeenForCurrentPage(string $page, string $action = 'index'): void
{
    if (!isLoggedIn()) {
        return;
    }

    $map = [
        'approvals' => ['approvals', badgePendingIdsApprovals()],
        'gas-vouchers' => ['gas_vouchers', badgePendingIdsGasVouchers()],
        'my-trip-tickets' => ['trip_tickets', badgePendingIdsTripTickets()],
        'trip-tickets' => ['trip_tickets', badgePendingIdsTripTickets()],
        'maintenance' => $action === 'schedule' ? null : ['maintenance', badgePendingIdsMaintenance()],
        'guard' => ['guard', badgePendingIdsGuard()],
        'requests' => ['requests_revision', badgePendingIdsRequestsRevision()],
        'vehicles' => ['vehicles', badgePendingIdsVehiclesAttention()],
        'security' => ['security_lockouts', badgePendingIdsSecurityLockouts()],
    ];

    // OB Pass Slips clear BOTH sidebar badges (guard stamps + approvals, Plan #37)
    if ($page === 'ob-requests') {
        badgeMarkSeen('ob_guard_stamps', badgePendingIdsObGuard());
        badgeMarkSeen('ob_approvals', badgePendingIdsObApprovals());
        return;
    }

    if (!isset($map[$page]) || $map[$page] === null) {
        return;
    }

    [$key, $ids] = $map[$page];
    badgeMarkSeen($key, $ids);
}

function sidebarBadgeHtml(int $count, bool $urgent = false): string
{
    if ($count <= 0) {
        return '';
    }
    $cls = $urgent ? 'badge bg-danger ms-auto' : 'badge bg-warning ms-auto';
    $label = $count > 99 ? '99+' : (string) $count;
    return '<span class="' . $cls . '">' . e($label) . '</span>';
}

/**
 * @return list<int>
 */
function badgeFetchIds(string $sql, array $params = []): array
{
    $rows = db()->fetchAll($sql, $params);
    $ids = [];
    foreach ($rows as $row) {
        $ids[] = (int) $row->id;
    }
    return $ids;
}
