<?php
/**
 * LOKA - Shared maintenance service (Plan #40 groundwork)
 *
 * One implementation of the care / repair-ticket transitions, called by BOTH
 * the real screens (pages/maintenance/care-edit.php, pages/maintenance/edit.php)
 * and the AI assistant's executing tools (includes/ai_actions.php).
 *
 * Why this file exists: letting the assistant perform the same actions as the UI
 * means there must be exactly ONE place that decides the rules. If the tool kept
 * its own copy, the two would drift and the assistant would quietly become a
 * second, unaudited approval path.
 *
 * Every function returns:
 *   ['ok'=>bool, 'error'=>string, 'summary'=>string, 'before'=>array, 'after'=>array]
 * so a caller can preview the change (before/after) and then run it.
 */

if (!defined('MAINTENANCE_SERVICE_LOADED')) {

    define('MAINTENANCE_SERVICE_LOADED', 1);

    /** Load a care schedule row with its vehicle, or null. */
    function maintenanceServiceCareItem(int $id): ?object
    {
        return db()->fetch(
            "SELECT vcs.*, v.plate_number, v.make, v.model, v.mileage
             FROM vehicle_care_schedules vcs
             JOIN vehicles v ON v.id = vcs.vehicle_id
             WHERE vcs.id = ? AND vcs.deleted_at IS NULL",
            [$id]
        );
    }

    /** Load a repair ticket with its vehicle, or null. */
    function maintenanceServiceTicket(int $id): ?object
    {
        return db()->fetch(
            "SELECT mr.*, v.plate_number, v.make, v.model
             FROM maintenance_requests mr
             JOIN vehicles v ON v.id = mr.vehicle_id
             WHERE mr.id = ? AND mr.deleted_at IS NULL",
            [$id]
        );
    }

    /**
     * Preview/approve a care item (pending -> scheduled).
     * Read-only: returns the proposed change without writing.
     */
    function maintenanceServicePreviewApproveCare(object $item): array
    {
        if ($item->status !== CARE_STATUS_PENDING) {
            return ['ok' => false, 'error' => 'This care item is already "' . $item->status . '".', 'summary' => '', 'before' => [], 'after' => []];
        }
        return [
            'ok' => true,
            'error' => '',
            'summary' => sprintf(
                'Approve care "%s" for %s — status %s -> %s, due %s.',
                $item->title,
                $item->plate_number,
                $item->status,
                CARE_STATUS_SCHEDULED,
                formatDate($item->due_date)
            ),
            'before' => ['status' => $item->status, 'approved_by' => null],
            'after' => ['status' => CARE_STATUS_SCHEDULED, 'approved_by' => (int) userId()],
        ];
    }

    /**
     * Approve (schedule) a care item.
     *
     * @param  int   $actorId user id recorded as the approver
     * @return array{ok:bool,error:string,summary:string,before:array,after:array}
     */
    function maintenanceServiceApproveCare(object $item, int $actorId): array
    {
        $preview = maintenanceServicePreviewApproveCare($item);
        if (!$preview['ok']) {
            return $preview;
        }
        $now = date(DATETIME_FORMAT);
        db()->update('vehicle_care_schedules', [
            'status' => CARE_STATUS_SCHEDULED,
            'approved_by' => $actorId,
            'approved_at' => $now,
            'updated_at' => $now,
        ], 'id = ?', [(int) $item->id]);

        auditLog('care_schedule_approve', 'vehicle_care_schedule', (int) $item->id, null, [
            'from' => $item->status, 'to' => CARE_STATUS_SCHEDULED, 'via' => 'maintenance_service',
        ]);

        require_once INCLUDES_PATH . '/vehicle_care.php';
        notifyCareStakeholders(
            (int) $item->vehicle_id,
            'care_schedule_scheduled',
            'Care item approved',
            "{$item->title} for {$item->plate_number} is scheduled for " . formatDate($item->due_date) . '.',
            '/?page=maintenance&action=care-edit&id=' . (int) $item->id
        );

        $preview['after']['status'] = CARE_STATUS_SCHEDULED;
        return $preview;
    }

    /**
     * Preview completing a care item. No write.
     *
     * @param  array $costItems Plan #38 costing lines (empty = no history entry)
     */
    function maintenanceServicePreviewCompleteCare(object $item, ?int $completedMileage, array $costItems = []): array
    {
        if (!in_array($item->status, [CARE_STATUS_PENDING, CARE_STATUS_SCHEDULED], true)) {
            return ['ok' => false, 'error' => 'This care item is "' . $item->status . '" and cannot be completed.', 'summary' => '', 'before' => [], 'after' => []];
        }
        $recurring = false;
        $typeInfo = CARE_TYPES[$item->care_type] ?? null;
        if ($typeInfo && !empty($typeInfo['recurring']) && $item->interval_days) {
            $recurring = true;
        }
        $lines = [];
        foreach ($costItems as $c) {
            $lines[] = trim(($c['description'] ?? '') . ' (' . ($c['amount'] ?? 0) . ')');
        }

        $summary = sprintf(
            'Complete care "%s" for %s — status %s -> %s%s%s.',
            $item->title,
            $item->plate_number,
            $item->status,
            CARE_STATUS_COMPLETED,
            $completedMileage !== null ? ', odometer ' . number_format($completedMileage) : '',
            $recurring ? ', and schedule the next recurring item' : ''
        );
        if ($lines !== []) {
            $summary .= ' Repair History entries: ' . implode('; ', $lines) . '.';
        }

        return [
            'ok' => true,
            'error' => '',
            'summary' => $summary,
            'before' => ['status' => $item->status, 'completed_at' => null],
            'after' => ['status' => CARE_STATUS_COMPLETED, 'completed_at' => date(DATETIME_FORMAT), 'recurs' => $recurring],
        ];
    }

    /**
     * Complete a care item: mark done, roll the recurring interval forward,
     * notify stakeholders, and (Plan #38) write Repair History when costing was
     * supplied and the feature is on.
     *
     * @param  array $costItems normalised Plan #38 costing lines
     */
    function maintenanceServiceCompleteCare(object $item, ?int $completedMileage, array $costItems, int $actorId): array
    {
        $preview = maintenanceServicePreviewCompleteCare($item, $completedMileage, $costItems);
        if (!$preview['ok']) {
            return $preview;
        }

        $now = date(DATETIME_FORMAT);
        db()->update('vehicle_care_schedules', [
            'status' => CARE_STATUS_COMPLETED,
            'completed_at' => $now,
            'completed_by' => $actorId,
            'completed_mileage' => $completedMileage,
            'updated_at' => $now,
        ], 'id = ?', [(int) $item->id]);

        // Recurring: create the next scheduled item.
        $typeInfo = CARE_TYPES[$item->care_type] ?? null;
        if ($typeInfo && !empty($typeInfo['recurring']) && $item->interval_days) {
            $nextDue = date('Y-m-d', strtotime($item->due_date . ' +' . (int) $item->interval_days . ' days'));
            $nextId = db()->insert('vehicle_care_schedules', [
                'vehicle_id' => $item->vehicle_id,
                'care_type' => $item->care_type,
                'title' => $item->title,
                'notes' => $item->notes,
                'due_date' => $nextDue,
                'status' => CARE_STATUS_SCHEDULED,
                'proposed_by' => $actorId,
                'approved_by' => $actorId,
                'approved_at' => $now,
                'interval_days' => $item->interval_days,
                'interval_km' => $item->interval_km,
                'created_at' => $now,
            ]);
            auditLog('care_schedule_recur', 'vehicle_care_schedule', (int) $nextId, null, [
                'from' => (int) $item->id, 'via' => 'maintenance_service',
            ]);
            $preview['after']['next_care_id'] = (int) $nextId;
        }

        require_once INCLUDES_PATH . '/vehicle_care.php';
        notifyCareStakeholders(
            (int) $item->vehicle_id,
            'care_schedule_completed',
            'Care item completed',
            "{$item->title} for {$item->plate_number} was marked completed.",
            '/?page=maintenance&action=schedule'
        );

        // Plan #38: completed care WITH costing writes a repair-history entry.
        // No-op while the experimental flag is off, by design.
        require_once INCLUDES_PATH . '/repair_history.php';
        if (repairHistoryEnabled() && $costItems !== []) {
            $fresh = maintenanceServiceCareItem((int) $item->id);
            $entryId = repairHistoryUpsertFromCare($fresh, $costItems);
            if ($entryId) {
                auditLog('repair_history_auto_written', 'vehicle_repair_entry', $entryId, null, [
                    'source' => 'care', 'care_schedule_id' => (int) $item->id, 'via' => 'maintenance_service',
                ]);
                $preview['after']['repair_history_entry'] = (int) $entryId;
            }
        }

        auditLog('care_schedule_complete', 'vehicle_care_schedule', (int) $item->id,
            ['status' => $item->status], ['status' => CARE_STATUS_COMPLETED, 'via' => 'maintenance_service']);

        return $preview;
    }

    /**
     * Preview creating a PENDING care item (no write).
     */
    function maintenanceServicePreviewCreateCare(int $vehicleId, string $careType, string $title, string $dueDate): array
    {
        $vehicle = db()->fetch(
            "SELECT id, plate_number FROM vehicles WHERE id = ? AND deleted_at IS NULL",
            [$vehicleId]
        );
        if (!$vehicle) {
            return ['ok' => false, 'error' => 'That vehicle does not exist.', 'summary' => '', 'before' => [], 'after' => []];
        }
        if (!isset(CARE_TYPES[$careType])) {
            return ['ok' => false, 'error' => 'Unknown care type.', 'summary' => '', 'before' => [], 'after' => []];
        }
        if (trim($title) === '' || mb_strlen($title) > 255) {
            return ['ok' => false, 'error' => 'A title is required (max 255 characters).', 'summary' => '', 'before' => [], 'after' => []];
        }
        if (!strtotime($dueDate)) {
            return ['ok' => false, 'error' => 'The due date must be a real date.', 'summary' => '', 'before' => [], 'after' => []];
        }
        return [
            'ok' => true,
            'error' => '',
            'summary' => sprintf(
                'Create a PENDING care item "%s" (%s) for %s, due %s. It still needs approval.',
                trim($title), CARE_TYPES[$careType]['label'] ?? $careType, $vehicle->plate_number, formatDate($dueDate)
            ),
            'before' => ['care_items' => 'unchanged'],
            'after' => ['status' => CARE_STATUS_PENDING, 'title' => trim($title), 'due_date' => $dueDate],
        ];
    }

    /**
     * Create a PENDING care item. Pending on purpose: the assistant proposes, a
     * human still approves.
     */
    function maintenanceServiceCreateCare(int $vehicleId, string $careType, string $title, string $dueDate, ?int $actorId = null): array
    {
        $preview = maintenanceServicePreviewCreateCare($vehicleId, $careType, $title, $dueDate);
        if (!$preview['ok']) {
            return $preview;
        }
        $actorId ??= (int) userId();
        $id = db()->insert('vehicle_care_schedules', [
            'vehicle_id' => $vehicleId,
            'care_type' => $careType,
            'title' => trim($title),
            'due_date' => date('Y-m-d', strtotime($dueDate)),
            'status' => CARE_STATUS_PENDING,
            'proposed_by' => $actorId,
            'created_at' => date(DATETIME_FORMAT),
        ]);
        auditLog('care_schedule_create', 'vehicle_care_schedule', $id, null, [
            'vehicle_id' => $vehicleId, 'title' => trim($title), 'via' => 'maintenance_service',
        ]);
        $preview['after']['id'] = (int) $id;
        $preview['summary'] .= ' Created as care #' . $id . '.';
        return $preview;
    }

    /**
     * Preview completing a repair ticket. No write.
     */
    function maintenanceServicePreviewCompleteTicket(object $mr, ?int $odometer): array
    {
        if ($mr->status === MAINTENANCE_STATUS_COMPLETED) {
            return ['ok' => false, 'error' => 'This repair ticket is already completed.', 'summary' => '', 'before' => [], 'after' => []];
        }
        if ($odometer === null) {
            return ['ok' => false, 'error' => 'An odometer reading is required to complete a repair ticket.', 'summary' => '', 'before' => [], 'after' => []];
        }
        $vehicle = db()->fetch("SELECT mileage FROM vehicles WHERE id = ?", [(int) $mr->vehicle_id]);
        $newMileage = max((int) ($vehicle->mileage ?? 0), $odometer);
        return [
            'ok' => true,
            'error' => '',
            'summary' => sprintf(
                'Complete repair #%d "%s" for %s — status %s -> %s, odometer %s, vehicle mileage %s -> %s, vehicle released to available.',
                (int) $mr->id, $mr->title, $mr->plate_number, $mr->status, MAINTENANCE_STATUS_COMPLETED,
                number_format($odometer), number_format((int) ($vehicle->mileage ?? 0)), number_format($newMileage)
            ),
            'before' => ['status' => $mr->status, 'vehicle_mileage' => (int) ($vehicle->mileage ?? 0)],
            'after' => ['status' => MAINTENANCE_STATUS_COMPLETED, 'vehicle_mileage' => $newMileage],
        ];
    }

    /**
     * Complete a repair ticket: mark done, stamp odometer, release the vehicle,
     * and (Plan #38) write Repair History from the actual cost.
     */
    function maintenanceServiceCompleteTicket(object $mr, ?int $odometer, ?float $actualCost, int $actorId): array
    {
        $preview = maintenanceServicePreviewCompleteTicket($mr, $odometer);
        if (!$preview['ok']) {
            return $preview;
        }

        $now = date(DATETIME_FORMAT);
        $update = [
            'status' => MAINTENANCE_STATUS_COMPLETED,
            'completed_date' => $now,
            'completed_at' => $now,
            'mileage_at_completion' => $odometer,
            'odometer_reading' => $odometer,
            'updated_at' => $now,
        ];
        if ($actualCost !== null) {
            $update['actual_cost'] = $actualCost;
        }
        db()->update('maintenance_requests', $update, 'id = ?', [(int) $mr->id]);

        $vehicle = db()->fetch("SELECT mileage FROM vehicles WHERE id = ?", [(int) $mr->vehicle_id]);
        $newMileage = max((int) ($vehicle->mileage ?? 0), (int) $odometer);
        db()->update('vehicles', [
            'status' => VEHICLE_AVAILABLE,
            'mileage' => $newMileage,
            'last_maintenance_date' => date('Y-m-d'),
            'last_maintenance_odometer' => $odometer,
            'updated_at' => $now,
        ], 'id = ?', [(int) $mr->vehicle_id]);

        auditLog('maintenance_updated', 'maintenance_request', (int) $mr->id,
            ['status' => $mr->status], ['status' => MAINTENANCE_STATUS_COMPLETED, 'via' => 'maintenance_service']);

        // Plan #38: a completed repair writes Repair History (flag-dependent).
        require_once INCLUDES_PATH . '/repair_history.php';
        if (repairHistoryEnabled()) {
            $fresh = maintenanceServiceTicket((int) $mr->id);
            $entryId = repairHistoryUpsertFromMaintenance($fresh);
            if ($entryId) {
                auditLog('repair_history_auto_written', 'vehicle_repair_entry', $entryId, null, [
                    'source' => 'maintenance', 'maintenance_request_id' => (int) $mr->id, 'via' => 'maintenance_service',
                ]);
                $preview['after']['repair_history_entry'] = (int) $entryId;
            }
        }

        return $preview;
    }
}