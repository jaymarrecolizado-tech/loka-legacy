<?php
/**
 * Vehicle care calendar helpers (assignments, access, notify).
 * Additive — does not change repair maintenance_requests behavior.
 *
 * NOTE (port): In the upstream app this file is required from
 * includes/functions.php. Since functions.php is shared and must not be
 * edited here, each consumer (pages, cron) must require this file itself:
 *   require_once INCLUDES_PATH . '/vehicle_care.php';
 */

/**
 * Care Assignments: Administrator, Motorpool Head, and All Father only.
 * (Not Approver, CAF, or drivers.)
 */
function canManageCareAssignments(): bool
{
    if (isRealAllFather()) {
        return true;
    }
    $role = userRole();
    return $role === ROLE_ADMIN || $role === ROLE_MOTORPOOL;
}

function canApproveCareSchedules(): bool
{
    return canManageCareAssignments();
}

function canAccessMaintenanceSchedule(): bool
{
    if (isAdmin() || isMotorpool() || isApprover() || isChiefAdminFinance() || isRealAllFather()) {
        return true;
    }
    return currentDriverId() !== null && driverHasCareAssignment(currentDriverId());
}

function canProposeCareSchedule(): bool
{
    if (canApproveCareSchedules()) {
        return true;
    }
    return currentDriverId() !== null && driverHasCareAssignment(currentDriverId());
}

function driverHasCareAssignment(?int $driverId = null): bool
{
    $driverId = $driverId ?? currentDriverId();
    if (!$driverId) {
        return false;
    }
    return (int) db()->fetchColumn(
        "SELECT COUNT(*) FROM vehicle_care_assignments
         WHERE driver_id = ? AND deleted_at IS NULL",
        [$driverId]
    ) > 0;
}

/**
 * @return list<int>
 */
function careVehicleIdsForDriver(?int $driverId = null): array
{
    $driverId = $driverId ?? currentDriverId();
    if (!$driverId) {
        return [];
    }
    $rows = db()->fetchAll(
        "SELECT vehicle_id FROM vehicle_care_assignments
         WHERE driver_id = ? AND deleted_at IS NULL",
        [$driverId]
    );
    return array_map(static fn($r) => (int) $r->vehicle_id, $rows);
}

function canViewCareVehicle(int $vehicleId): bool
{
    if (isAdmin() || isMotorpool() || isChiefAdminFinance() || isRealAllFather()) {
        return true;
    }
    if (isApprover()) {
        $deptId = (int) (currentUser()->department_id ?? 0);
        if ($deptId <= 0) {
            return true; // no dept on user → see all (safe default for ops)
        }
        $hit = db()->fetch(
            "SELECT vca.id
             FROM vehicle_care_assignments vca
             JOIN drivers d ON d.id = vca.driver_id AND d.deleted_at IS NULL
             JOIN users u ON u.id = d.user_id AND u.deleted_at IS NULL
             WHERE vca.vehicle_id = ? AND vca.deleted_at IS NULL AND u.department_id = ?
             LIMIT 1",
            [$vehicleId, $deptId]
        );
        return (bool) $hit;
    }
    $ids = careVehicleIdsForDriver();
    return in_array($vehicleId, $ids, true);
}

/**
 * SQL fragment + params to scope vehicles for the current viewer.
 *
 * @return array{0:string,1:list<mixed>}
 */
function careVehicleVisibilitySql(string $vehicleColumn = 'vcs.vehicle_id'): array
{
    if (isAdmin() || isMotorpool() || isChiefAdminFinance() || isRealAllFather()) {
        return ['1=1', []];
    }
    if (isApprover()) {
        $deptId = (int) (currentUser()->department_id ?? 0);
        if ($deptId <= 0) {
            return ['1=1', []];
        }
        return [
            "EXISTS (
                SELECT 1 FROM vehicle_care_assignments vca
                JOIN drivers d ON d.id = vca.driver_id AND d.deleted_at IS NULL
                JOIN users u ON u.id = d.user_id AND u.deleted_at IS NULL
                WHERE vca.vehicle_id = {$vehicleColumn}
                  AND vca.deleted_at IS NULL
                  AND u.department_id = ?
            )",
            [$deptId],
        ];
    }
    $ids = careVehicleIdsForDriver();
    if ($ids === []) {
        return ['0=1', []];
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    return ["{$vehicleColumn} IN ({$ph})", $ids];
}

/**
 * Notify locked audience: assigned drivers + MH + Approvers + Admin + AF + CAF.
 */
function notifyCareStakeholders(
    int $vehicleId,
    string $type,
    string $title,
    string $message,
    ?string $link = null,
    ?int $excludeUserId = null
): void {
    $userIds = [];

    $drivers = db()->fetchAll(
        "SELECT d.user_id
         FROM vehicle_care_assignments vca
         JOIN drivers d ON d.id = vca.driver_id AND d.deleted_at IS NULL
         JOIN users u ON u.id = d.user_id AND u.deleted_at IS NULL AND u.status = 'active'
         WHERE vca.vehicle_id = ? AND vca.deleted_at IS NULL AND d.user_id IS NOT NULL",
        [$vehicleId]
    );
    foreach ($drivers as $d) {
        $userIds[(int) $d->user_id] = true;
    }

    $roles = [ROLE_MOTORPOOL, ROLE_APPROVER, ROLE_ADMIN, ROLE_ALL_FATHER, ROLE_CHIEF_ADMIN_FINANCE, ROLE_OIC_CHIEF_ADMIN_FINANCE];
    $ph = implode(',', array_fill(0, count($roles), '?'));
    $ops = db()->fetchAll(
        "SELECT id FROM users
         WHERE role IN ({$ph}) AND status = 'active' AND deleted_at IS NULL",
        $roles
    );
    foreach ($ops as $u) {
        $userIds[(int) $u->id] = true;
    }

    if ($excludeUserId) {
        unset($userIds[$excludeUserId]);
    }

    foreach (array_keys($userIds) as $uid) {
        try {
            notify((int) $uid, $type, $title, $message, $link, $vehicleId);
        } catch (Throwable $e) {
            error_log('notifyCareStakeholders: ' . $e->getMessage());
        }
    }
}

/**
 * Calendar events for care schedules in a date range (visibility applied).
 *
 * @return list<object>
 */
function careCalendarEvents(string $monthStart, string $monthEnd, ?int $vehicleId = null): array
{
    [$visSql, $visParams] = careVehicleVisibilitySql('vcs.vehicle_id');
    $sql = "SELECT vcs.*, v.plate_number
            FROM vehicle_care_schedules vcs
            JOIN vehicles v ON v.id = vcs.vehicle_id AND v.deleted_at IS NULL
            WHERE vcs.deleted_at IS NULL
              AND vcs.due_date BETWEEN ? AND ?
              AND vcs.status IN (?, ?)
              AND {$visSql}";
    $params = array_merge([$monthStart, $monthEnd, CARE_STATUS_PENDING, CARE_STATUS_SCHEDULED], $visParams);
    if ($vehicleId) {
        $sql .= ' AND vcs.vehicle_id = ?';
        $params[] = $vehicleId;
    }
    $sql .= ' ORDER BY vcs.due_date ASC';
    $rows = db()->fetchAll($sql, $params);
    foreach ($rows as $row) {
        $row->_source = 'care';
        $row->scheduled_date = $row->due_date;
        $row->title = '[Care] ' . $row->title;
    }
    return $rows;
}

function careDefaultIntervals(string $careType): array
{
    $info = CARE_TYPES[$careType] ?? CARE_TYPES[CARE_TYPE_OTHER];
    return [
        'interval_days' => $info['interval_days'],
        'interval_km' => $info['interval_km'],
    ];
}
