<?php
/**
 * LOKA - Vehicle care schedule reminders (CLI or include)
 * Sends email+SMS via notify() at 7d, 1d, due day, and overdue (daily).
 *
 * Ported fallback: if includes/vehicle_care.php (notifyCareStakeholders) is not
 * installed yet, a local audience resolver (assigned drivers + ops roles) is used.
 */

if (php_sapi_name() === 'cli') {
    require_once __DIR__ . '/../config/bootstrap.php';
}

if (!function_exists('notifyCareStakeholders')) {
    /**
     * Fallback audience: assigned drivers + MH + Approvers + Admin + AF + CAF.
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

        try {
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
        } catch (Throwable $e) {
            error_log('notifyCareStakeholders (driver lookup): ' . $e->getMessage());
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
}

/**
 * @return array{sent:int,skipped:int}
 */
function processCareReminders(): array
{
    $sent = 0;
    $skipped = 0;
    $today = date('Y-m-d');

    $rows = db()->fetchAll(
        "SELECT vcs.*, v.plate_number
         FROM vehicle_care_schedules vcs
         JOIN vehicles v ON v.id = vcs.vehicle_id AND v.deleted_at IS NULL
         WHERE vcs.deleted_at IS NULL
           AND vcs.status = ?
           AND vcs.due_date IS NOT NULL",
        [CARE_STATUS_SCHEDULED]
    );

    foreach ($rows as $row) {
        $due = $row->due_date;
        $days = (int) floor((strtotime($due) - strtotime($today)) / 86400);
        $link = '/?page=maintenance&action=care-edit&id=' . (int) $row->id;
        $label = CARE_TYPES[$row->care_type]['label'] ?? $row->care_type;
        $base = "{$label} for {$row->plate_number}: {$row->title} (due " . formatDate($due) . ")";

        $kind = null;
        $title = 'Vehicle Care Reminder';
        $message = null;
        $update = [];

        if ($days === 7 && empty($row->reminded_7d_at)) {
            $kind = '7d';
            $message = "Reminder (7 days): {$base}";
            $update['reminded_7d_at'] = date(DATETIME_FORMAT);
        } elseif ($days === 1 && empty($row->reminded_1d_at)) {
            $kind = '1d';
            $message = "Reminder (tomorrow): {$base}";
            $update['reminded_1d_at'] = date(DATETIME_FORMAT);
        } elseif ($days === 0 && empty($row->reminded_due_at)) {
            $kind = 'due';
            $message = "Due today: {$base}";
            $update['reminded_due_at'] = date(DATETIME_FORMAT);
        } elseif ($days < 0) {
            $overdueOn = $row->reminded_overdue_on ?? null;
            if ($overdueOn !== $today && $days >= -7) {
                $kind = 'overdue';
                $message = "Overdue (" . abs($days) . " day(s)): {$base}";
                $update['reminded_overdue_on'] = $today;
            }
        }

        if ($kind === null || $message === null) {
            $skipped++;
            continue;
        }

        notifyCareStakeholders(
            (int) $row->vehicle_id,
            'care_schedule_reminder',
            $title,
            $message,
            $link
        );
        $update['updated_at'] = date(DATETIME_FORMAT);
        db()->update('vehicle_care_schedules', $update, 'id = ?', [$row->id]);
        $sent++;
    }

    return ['sent' => $sent, 'skipped' => $skipped];
}

if (php_sapi_name() === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $r = processCareReminders();
    echo date('c') . " CARE reminders sent={$r['sent']} skipped={$r['skipped']}\n";
}
