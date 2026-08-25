<?php
/**
 * All Father — Manage vehicles with broken / unreadable odometers
 */

requireSystemControl();

require_once INCLUDES_PATH . '/odometer.php';

$pageTitle = 'Broken Odometers';
$flash = null;
$q = trim((string) get('q', ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $op = post('op', '');

    try {
        if ($op === 'toggle') {
            $vehicleId = postInt('vehicle_id');
            $broken = post('odometer_broken') ? 1 : 0;
            $vehicle = db()->fetch(
                "SELECT id, plate_number, make, model, odometer_broken
                 FROM vehicles WHERE id = ? AND deleted_at IS NULL",
                [$vehicleId]
            );
            if (!$vehicle) {
                throw new InvalidArgumentException('Vehicle not found.');
            }

            db()->update('vehicles', [
                'odometer_broken' => $broken,
                'updated_at' => date(DATETIME_FORMAT),
            ], 'id = ?', [$vehicleId]);

            // Keep settings plate list in sync (fallback for helpers)
            syncOdometerBrokenPlatesSetting();

            auditLog(
                'vehicle_odometer_broken_updated',
                'vehicle',
                $vehicleId,
                ['odometer_broken' => (int) ($vehicle->odometer_broken ?? 0)],
                [
                    'odometer_broken' => $broken,
                    'plate_number' => $vehicle->plate_number,
                ]
            );

            $label = $broken ? 'marked as broken/unreadable' : 'cleared (odometer readable)';
            $flash = ['success', "{$vehicle->plate_number}: odometer {$label}."];
        } elseif ($op === 'mark_plates') {
            $raw = trim((string) post('plates', ''));
            if ($raw === '') {
                throw new InvalidArgumentException('Enter at least one plate number.');
            }
            $plates = array_values(array_unique(array_filter(array_map(
                static fn($p) => normalizePlateNumber($p),
                preg_split('/[\s,;]+/', $raw) ?: []
            ))));
            if (empty($plates)) {
                throw new InvalidArgumentException('No valid plate numbers found.');
            }

            $marked = 0;
            $missing = [];
            foreach ($plates as $norm) {
                $vehicle = db()->fetch(
                    "SELECT id, plate_number FROM vehicles
                     WHERE deleted_at IS NULL
                       AND REPLACE(UPPER(plate_number), ' ', '') = ?
                     LIMIT 1",
                    [$norm]
                );
                if (!$vehicle) {
                    $missing[] = $norm;
                    continue;
                }
                db()->update('vehicles', [
                    'odometer_broken' => 1,
                    'updated_at' => date(DATETIME_FORMAT),
                ], 'id = ?', [$vehicle->id]);
                $marked++;
            }

            syncOdometerBrokenPlatesSetting();
            auditLog('vehicle_odometer_broken_bulk', 'settings', null, null, [
                'plates' => $plates,
                'marked' => $marked,
                'missing' => $missing,
            ]);

            $msg = "Marked {$marked} vehicle(s) as broken odometer.";
            if (!empty($missing)) {
                $msg .= ' Not found: ' . implode(', ', $missing) . '.';
            }
            $flash = [$marked > 0 ? 'success' : 'warning', $msg];
        } else {
            throw new InvalidArgumentException('Unknown action.');
        }
    } catch (Throwable $e) {
        $flash = ['danger', $e->getMessage()];
    }
}

$params = [];
$where = 'v.deleted_at IS NULL';
if ($q !== '') {
    $where .= ' AND (v.plate_number LIKE ? OR v.make LIKE ? OR v.model LIKE ?)';
    $like = '%' . $q . '%';
    $params = [$like, $like, $like];
}

$countRow = db()->fetch("SELECT COUNT(*) as c FROM vehicles v WHERE {$where}", $params);
$pag = listPaginationState((int) ($countRow->c ?? 0));

$vehicles = db()->fetchAll(
    "SELECT v.id, v.plate_number, v.make, v.model, v.mileage, v.status,
            COALESCE(v.odometer_broken, 0) as odometer_broken,
            vt.name as type_name
     FROM vehicles v
     LEFT JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
     WHERE {$where}
     ORDER BY v.odometer_broken DESC, v.plate_number ASC
     LIMIT ? OFFSET ?",
    array_merge($params, [$pag['perPage'], $pag['offset']])
);

$brokenRow = db()->fetch(
    "SELECT COUNT(*) as c FROM vehicles WHERE deleted_at IS NULL AND COALESCE(odometer_broken, 0) = 1"
);
$brokenCount = (int) ($brokenRow->c ?? 0);

$baseParams = [
    'page' => 'security',
    'action' => 'odometer',
    'q' => $q,
    'per_page' => $pag['perPage'],
];

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid px-4 py-4">
    <div class="mb-2">
        <h4 class="mb-1">Broken Odometers</h4>
        <p class="text-muted small mb-0">
            Mark vehicles whose odometer is broken or unreadable. Guards can then skip the required reading on dispatch/arrival.
        </p>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash[0]) ?> mb-4"><?= e($flash[1]) ?></div>
    <?php endif; ?>

    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <div class="card">
                <div class="card-body py-3 text-center">
                    <div class="fs-4 fw-semibold text-warning"><?= $brokenCount ?></div>
                    <div class="small text-muted">Vehicles with broken odometer</div>
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    <h5 class="mb-2 small fw-semibold">Quick mark by plate</h5>
                    <form method="POST" class="d-flex flex-column flex-sm-row gap-2">
                        <?= csrfField() ?>
                        <input type="hidden" name="op" value="mark_plates">
                        <input type="text" name="plates" class="form-control form-control-sm"
                               placeholder="e.g. SDF 424, SBY 225, SJN 940"
                               required>
                        <button type="submit" class="btn btn-primary btn-sm text-nowrap">
                            Mark broken
                        </button>
                    </form>
                    <p class="form-text small mt-2 mb-0">
                        Separate multiple plates with commas or spaces.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <form method="GET" class="d-flex flex-wrap align-items-end gap-2 mb-4">
                <input type="hidden" name="page" value="security">
                <input type="hidden" name="action" value="odometer">
                <?= listSearchFieldHtml($q, 'Plate, make, model…') ?>
                <?= perPageFieldHtml($pag['perPage']) ?>
                <button type="submit" class="btn btn-secondary btn-sm">Search</button>
                <?php if ($q !== ''): ?>
                <a href="<?= APP_URL ?>/?page=security&action=odometer" class="btn btn-secondary btn-sm">Clear</a>
                <?php endif; ?>
            </form>

            <?php if (empty($vehicles)): ?>
                <div class="small text-muted py-6 text-center py-5">No vehicles found.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Plate</th>
                            <th>Vehicle</th>
                            <th>Mileage</th>
                            <th>Status</th>
                            <th class="text-center">Odometer broken</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($vehicles as $v): ?>
                        <tr class="<?= (int) $v->odometer_broken === 1 ? 'table-warning' : '' ?>">
                            <td class="fw-medium"><?= e($v->plate_number) ?></td>
                            <td class="small">
                                <?= e(trim(($v->make ?? '') . ' ' . ($v->model ?? ''))) ?>
                                <?php if (!empty($v->type_name)): ?>
                                    <span class="small text-muted d-block"><?= e($v->type_name) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="small"><?= number_format((int) $v->mileage) ?> km</td>
                            <td><?= vehicleStatusBadge($v->status) ?></td>
                            <td class="text-center">
                                <form method="POST" class="d-inline-flex align-items-center justify-content-center gap-2">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="op" value="toggle">
                                    <input type="hidden" name="vehicle_id" value="<?= (int) $v->id ?>">
                                    <input type="hidden" name="odometer_broken" value="0">
                                    <label style="cursor:pointer;" class="d-inline-flex align-items-center gap-2 mb-0">
                                        <input type="checkbox"
                                               name="odometer_broken"
                                               value="1"
                                               role="switch"
                                               class="form-check-input"
                                               <?= (int) $v->odometer_broken === 1 ? 'checked' : '' ?>
                                               onchange="this.form.submit()">
                                        <span class="small <?= (int) $v->odometer_broken === 1 ? 'text-warning fw-medium' : 'text-muted' ?>">
                                            <?= (int) $v->odometer_broken === 1 ? 'Broken' : 'OK' ?>
                                        </span>
                                    </label>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= listPaginationFooter($pag, $baseParams) ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
