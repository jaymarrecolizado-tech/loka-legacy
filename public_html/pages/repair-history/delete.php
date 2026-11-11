<?php
/**
 * LOKA - Soft-delete a repair-history entry (Plan #38, experimental)
 * POST: ?page=repair-history&action=delete
 */

require_once INCLUDES_PATH . '/repair_history.php';
requireRole(ROLE_APPROVER);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectWith('/?page=repair-history', 'warning', 'Invalid request.');
}
requireCsrf();

if (!repairHistoryEnabled()) {
    redirectWith('/?page=repair-history', 'warning', 'Repair History is disabled.');
}
if (!canManageRepairHistory()) {
    redirectWith('/?page=dashboard', 'danger', 'You do not have permission to delete repair history.');
}

$entryId = postInt('id');
$vehicleId = postInt('vehicle_id');
$entry = $entryId
    ? db()->fetch("SELECT * FROM vehicle_repair_entries WHERE id = ? AND deleted_at IS NULL", [$entryId])
    : null;
if (!$entry) {
    redirectWith('/?page=repair-history', 'danger', 'Repair history entry not found.');
}

repairHistorySoftDelete($entryId);
auditLog('repair_history_entry_deleted', 'vehicle_repair_entry', $entryId, (array) $entry, null);

$target = $vehicleId ?: (int) $entry->vehicle_id;
redirectWith(
    '/?page=repair-history&action=view&vehicle_id=' . $target,
    'success',
    'Repair history entry removed.'
);