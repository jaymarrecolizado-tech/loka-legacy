<?php
/**
 * LOKA - Printable Motor Vehicle Repair History sheet (Plan #38, experimental)
 * Route: ?page=repair-history&action=print&vehicle_id=N
 *
 * Browser print (landscape A4) laid out to mirror the DICT workbook:
 *   header: Agency / Province / Type / Brand-Model / Engine No. / Plate Number
 *   rows:   Date | Nature of Repair | Description | Unit | Quantity | Price
 *   footer: Motorpool certification + signatory lines.
 */

require_once INCLUDES_PATH . '/repair_history.php';
requireRole(ROLE_APPROVER);

if (!repairHistoryEnabled()) {
    redirectWith('/?page=repair-history', 'warning', 'Repair History is disabled. An All Father must enable it first.');
}
if (!canViewRepairHistory()) {
    redirectWith('/?page=dashboard', 'danger', 'You do not have permission to print repair history.');
}

$vehicleId = getInt('vehicle_id');
$vehicle = $vehicleId
    ? db()->fetch(
        "SELECT v.*, vt.name AS type_name
         FROM vehicles v
         LEFT JOIN vehicle_types vt ON vt.id = v.vehicle_type_id
         WHERE v.id = ? AND v.deleted_at IS NULL",
        [$vehicleId]
    )
    : null;
if (!$vehicle) {
    redirectWith('/?page=repair-history', 'danger', 'Vehicle not found.');
}

$history = repairHistoryForVehicle((int) $vehicle->id);

$agency = tripSetting('repair_agency_name', 'Department of Information and Communications Technology');
$province = tripSetting('repair_province_name', 'Cagayan Provincial Office');

$grandTotal = 0.0;
foreach ($history as $row) {
    $grandTotal += (float) $row['entry']->total_amount;
}

$pageTitle = 'Repair History — ' . $vehicle->plate_number;
require_once INCLUDES_PATH . '/header.php';
?>

<style>
@media print {
    .no-print { display: none !important; }
    body { background: #fff; }
    .main-content, .wrapper { padding: 0 !important; margin: 0 !important; }
    @page { size: A4 landscape; margin: 10mm; }
    .rh-sheet { width: 100%; font-size: 9.5px; }
    .rh-sheet thead { display: table-header-group; }
    .rh-sheet tr { page-break-inside: avoid; }
    .rh-cert { page-break-inside: avoid; }
}
.rh-sheet { font-size: 10px; }
.rh-sheet td, .rh-sheet th { padding: 2px 4px; vertical-align: top; }
</style>

<div class="container-fluid px-4 py-4">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3 no-print">
        <a class="btn btn-outline-secondary" href="<?= APP_URL ?>/?page=repair-history&action=view&vehicle_id=<?= (int) $vehicle->id ?>">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
        <div class="ms-auto d-flex gap-2">
            <button type="button" class="btn btn-primary" onclick="window.print()">
                <i class="bi bi-printer me-1"></i>Print / Save PDF
            </button>
        </div>
    </div>

    <div class="border rounded p-3 rh-sheet">
        <h5 class="text-center mb-1">MOTOR VEHICLE REPAIR HISTORY</h5>
        <p class="text-center small text-muted mb-3">
            <?= e($agency) ?> — <?= e($province) ?>
        </p>

        <table class="table table-bordered table-sm mb-3">
            <tbody>
                <tr>
                    <th class="w-auto" style="width:12%">Agency</th>
                    <td colspan="3"><?= e($agency) ?></td>
                    <th style="width:12%">Type</th>
                    <td><?= e($vehicle->type_name ?: trim(($vehicle->make ?? '') . ' ' . ($vehicle->model ?? ''))) ?></td>
                </tr>
                <tr>
                    <th>Province</th>
                    <td colspan="3"><?= e($province) ?></td>
                    <th>Brand / Model</th>
                    <td><?= e(trim(($vehicle->make ?? '') . ' ' . ($vehicle->model ?? ''))) ?></td>
                </tr>
                <tr>
                    <th>Engine No.</th>
                    <td colspan="3" class="font-monospace"><?= e($vehicle->engine_number ?: '') ?></td>
                    <th>Plate Number</th>
                    <td class="fw-semibold"><?= e($vehicle->plate_number) ?></td>
                </tr>
            </tbody>
        </table>

        <table class="table table-bordered table-sm rh-sheet mb-0">
            <thead>
                <tr>
                    <th rowspan="2" style="width:9%">Date</th>
                    <th rowspan="2" style="width:27%">Nature of Repair</th>
                    <th colspan="4" class="text-center">Purchased Items</th>
                </tr>
                <tr>
                    <th style="width:30%">Description</th>
                    <th style="width:9%">Unit</th>
                    <th style="width:9%" class="text-end">Quantity</th>
                    <th style="width:16%" class="text-end">Price</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($history === []): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No repairs recorded for this vehicle.</td></tr>
                <?php endif; ?>
                <?php foreach ($history as $row): $entry = $row['entry']; ?>
                    <?php if ($row['items'] === []): ?>
                        <tr>
                            <td><?= formatDate($entry->repair_date) ?></td>
                            <td><?= e($entry->nature_of_repair) ?></td>
                            <td colspan="4" class="text-muted">—</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($row['items'] as $idx => $item): ?>
                        <tr>
                            <?php if ($idx === 0): ?>
                                <td rowspan="<?= max(1, count($row['items'])) ?>"><?= formatDate($entry->repair_date) ?></td>
                                <td rowspan="<?= max(1, count($row['items'])) ?>"><?= e($entry->nature_of_repair) ?></td>
                            <?php endif; ?>
                            <td><?= e($item->description) ?></td>
                            <td><?= e($item->unit ?: '') ?></td>
                            <td class="text-end"><?= e(rtrim(rtrim(number_format((float) $item->quantity, 2, '.', ''), '0'), '.')) ?></td>
                            <td class="text-end"><?= $item->amount !== null ? number_format((float) $item->amount, 2) : '' ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="fw-semibold">
                    <td colspan="5" class="text-end">GRAND TOTAL</td>
                    <td class="text-end"><?= number_format($grandTotal, 2) ?></td>
                </tr>
            </tfoot>
        </table>

        <div class="rh-cert mt-4">
            <p class="mb-2">
                I hereby certify that the above listed were the repairs actually undertaken as of
                <strong><?= formatDate(date('Y-m-d')) ?></strong>, that is based on the submitted
                &ldquo;Vehicle Monthly Repair Log&rdquo; and on available records in this office.
            </p>
            <div class="row g-4 mt-4">
                <div class="col-6">
                    <div class="border-top pt-1 small text-muted">Prepared by / Date</div>
                </div>
                <div class="col-6 text-end">
                    <div class="border-top pt-1 small text-muted">
                        <strong>Motorpool Head</strong> — Certification over printed name &amp; signature
                    </div>
                </div>
            </div>
            <p class="text-center small text-muted mt-4 mb-0">
                Printed by <?= e(currentUser()->name ?? '—') ?> on <?= formatDateTime(date(DATETIME_FORMAT)) ?>
            </p>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>