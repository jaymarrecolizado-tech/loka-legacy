<?php
/**
 * LOKA - Ops GPS panel (Plan #41, experimental)
 *
 * Included by the Live Trip Board. Visible to Motorpool Head, Admin and All
 * Father ONLY (Plan #41 decision 4) — never to the requester or the approver.
 *
 * The trail is drawn on a bare SVG grid rather than map tiles, so vehicle
 * coordinates are never sent to a third-party tile provider.
 *
 * Expects: $tableRows (live-board rows). Optional: $gpsSingleRequestId.
 */

if (!function_exists('gpsTrackingEnabled')) {
    require_once INCLUDES_PATH . '/gps_tracking.php';
}

if (!gpsTrackingEnabled() || !canViewGpsTracking()) {
    return;
}

$panelRows = [];
$ids = [];
foreach ($tableRows as $row) {
    $panelRows[] = $row;
    $ids[] = (int) $row->id;
}
if (!empty($gpsSingleRequestId)) {
    $ids[] = (int) $gpsSingleRequestId;
}

$lastSeen = gpsLastSeen($ids);
if ($lastSeen === []) {
    return;   // nothing recorded yet — do not clutter the board
}
?>
<div class="card table-card mb-4">
    <div class="card-header d-flex flex-wrap align-items-center gap-2">
        <h5 class="mb-0 me-auto"><i class="bi bi-geo-alt me-2"></i>Live positions
            <span class="badge bg-secondary ms-1"><?= count($lastSeen) ?></span>
        </h5>
        <small class="text-muted">
            <i class="bi bi-eye-slash me-1"></i>Motorpool / Admin / All Father only ·
            points are kept <?= GPS_RETENTION_DAYS ?> days
        </small>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 no-datatable">
                <thead class="table-light">
                    <tr>
                        <th>Request</th>
                        <th>Plate</th>
                        <th>Trail</th>
                        <th>Exact position</th>
                        <th>Accuracy</th>
                        <th>Last seen</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($panelRows as $row): ?>
                    <?php
                    $id = (int) $row->id;
                    if (!isset($lastSeen[$id])) {
                        continue;
                    }
                    $fix = $lastSeen[$id];
                    $age = gpsLastSeenAge($fix);
                    $stale = $age !== null && $age > 300;   // > 5 min old
                    ?>
                    <tr>
                        <td>
                            <a href="<?= APP_URL ?>/?page=requests&action=view&id=<?= $id ?>">#<?= $id ?></a>
                        </td>
                        <td><?= $row->plate_number ? e($row->plate_number) : '<span class="text-muted">—</span>' ?></td>
                        <td style="width:340px;">
                            <?= gpsTrailSvg(gpsTrail($id, 300)) ?: '<span class="text-muted small">No trail yet</span>' ?>
                        </td>
                        <td class="font-monospace small text-nowrap">
                            <?= number_format((float) $fix->lat, 5) ?>, <?= number_format((float) $fix->lng, 5) ?>
                        </td>
                        <td class="small"><?= $fix->accuracy_m !== null ? '±' . round((float) $fix->accuracy_m) . ' m' : '—' ?></td>
                        <td class="small text-nowrap">
                            <?php if ($stale): ?>
                                <span class="badge bg-warning text-dark">
                                    <?= $age >= 3600 ? intdiv($age, 3600) . 'h ago' : intdiv($age, 60) . 'm ago' ?>
                                </span>
                            <?php else: ?>
                                <span class="badge bg-success"><?= max(1, (int) round($age / 60)) ?>m ago</span>
                            <?php endif; ?>
                            <small class="d-block text-muted"><?= e(formatDateTime($fix->received_at)) ?></small>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-white small text-muted">
            If the driver closes the page or the phone locks, the trail stops growing and
            the position above is the last one received. Trail axes are not to scale — use the
            exact coordinates for real navigation.
        </div>
    </div>
</div>