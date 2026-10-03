<?php
/**
 * LOKA - Repair ticket (maintenance_requests) reminders (Plan #38, decision 8)
 *
 * The care calendar already had a 7d / 1d / due / daily-overdue ladder; repair
 * tickets with a `scheduled_date` notified nobody. This mirrors the care ladder
 * exactly, including the Plan #38 tier split:
 *
 *   7d, 1d      -> Motorpool Head + Approver  ('normal')
 *   due day     -> + Admin, All Father, CAF   ('escalate', stronger copy)
 *   overdue     -> same escalated audience, DAILY, no 7-day cap
 *
 * Runs standalone (CLI) or via the HTTP cron (?page=cron&action=maintenance&key=...).
 * This is independent of the experimental Repair History feature switch — the
 * reminders are about the repair ticket, not the history.
 */

if (php_sapi_name() === 'cli') {
    require_once __DIR__ . '/../config/bootstrap.php';
}

/** Ops audience for a reminder tier (assigned-care drivers do not own repairs). */
function notifyMaintenanceReminders(
    array $userIds,
    int $maintenanceId,
    string $type,
    string $title,
    string $message,
    string $link
): void {
    foreach ($userIds as $uid) {
        try {
            notify((int) $uid, $type, $title, $message, $link);
        } catch (Throwable $e) {
            error_log('notifyMaintenanceReminders: ' . $e->getMessage());
        }
    }
}

/** Active user ids holding the given roles. */
function maintenanceReminderUserIds(string $tier): array
{
    $roles = $tier === 'escalate'
        ? [ROLE_MOTORPOOL, ROLE_APPROVER, ROLE_ADMIN, ROLE_ALL_FATHER, ROLE_CHIEF_ADMIN_FINANCE, ROLE_OIC_CHIEF_ADMIN_FINANCE]
        : [ROLE_MOTORPOOL, ROLE_APPROVER];

    $ph = implode(',', array_fill(0, count($roles), '?'));
    return array_map(
        static fn($r) => (int) $r->id,
        db()->fetchAll(
            "SELECT id FROM users
             WHERE role IN ({$ph}) AND status = 'active' AND deleted_at IS NULL",
            $roles
        )
    );
}

/**
 * @return array{sent:int,skipped:int}
 */
function processMaintenanceReminders(): array
{
    $sent = 0;
    $skipped = 0;
    $today = date('Y-m-d');

    $rows = db()->fetchAll(
        "SELECT mr.*, v.plate_number, v.make, v.model
         FROM maintenance_requests mr
         JOIN vehicles v ON v.id = mr.vehicle_id AND v.deleted_at IS NULL
         WHERE mr.deleted_at IS NULL
           AND mr.status IN (?, ?, ?)
           AND mr.scheduled_date IS NOT NULL",
        [MAINTENANCE_STATUS_PENDING, MAINTENANCE_STATUS_SCHEDULED, MAINTENANCE_STATUS_IN_PROGRESS]
    );

    foreach ($rows as $row) {
        $due = $row->scheduled_date;
        $days = (int) floor((strtotime($due) - strtotime($today)) / 86400);
        $link = '/?page=maintenance&action=view&id=' . (int) $row->id;
        $base = "Repair #{$row->id} for {$row->plate_number}: {$row->title} (due " . formatDate($due) . ")";

        $kind = null;
        $tier = 'normal';
        $title = 'Repair Reminder';
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
            $tier = 'escalate';
            $title = 'Repair Due Today';
            $message = "Due today and awaiting completion: {$base}. Please attend to it today.";
            $update['reminded_due_at'] = date(DATETIME_FORMAT);
        } elseif ($days < 0) {
            $overdueOn = $row->reminded_overdue_on ?? null;
            if ($overdueOn !== $today) {
                $kind = 'overdue';
                $tier = 'escalate';
                $title = 'OVERDUE Repair';
                $message = "Overdue by " . abs($days) . " day(s): {$base}. This repair is now past due "
                    . "and requires immediate action.";
                $update['reminded_overdue_on'] = $today;
            }
        }

        if ($kind === null || $message === null) {
            $skipped++;
            continue;
        }

        notifyMaintenanceReminders(
            maintenanceReminderUserIds($tier),
            (int) $row->id,
            'maintenance_reminder',
            $title,
            $message,
            $link
        );
        $update['updated_at'] = date(DATETIME_FORMAT);
        db()->update('maintenance_requests', $update, 'id = ?', [$row->id]);
        $sent++;
    }

    return ['sent' => $sent, 'skipped' => $skipped];
}

if (php_sapi_name() === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $r = processMaintenanceReminders();
    echo date('c') . " MAINTENANCE reminders sent={$r['sent']} skipped={$r['skipped']}\n";
}