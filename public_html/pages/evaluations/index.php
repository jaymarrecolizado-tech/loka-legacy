<?php
/**
 * LOKA - Evaluations Dashboard (response rates per trip, per-driver averages)
 * Access: approver+ or tagged driver (requireReportsAccess). Self-scoped drivers see own stats only.
 */

requireReportsAccess();

$pageTitle = 'Driver Evaluations';
$isSelfScoped = isSelfScopedDriverReporter();
$driverId = null;
if ($isSelfScoped) {
    $driverId = currentDriverId();
}

// Filters
$from = get('from', '');
$to = get('to', '');
$fromSql = $from ? date('Y-m-d 00:00:00', strtotime($from)) : null;
$toSql = $to ? date('Y-m-d 23:59:59', strtotime($to)) : null;

// Response rate per trip
$whereTrip = "r.status = 'completed'";
$paramsTrip = [];
if ($fromSql) { $whereTrip .= " AND r.start_datetime >= ?"; $paramsTrip[] = $fromSql; }
if ($toSql) { $whereTrip .= " AND r.start_datetime <= ?"; $paramsTrip[] = $toSql; }
if ($isSelfScoped && $driverId) { $whereTrip .= " AND r.driver_id = ?"; $paramsTrip[] = $driverId; }

$trips = db()->fetchAll(
    "SELECT r.id, r.destination, r.start_datetime, r.driver_id,
            u.name AS driver_name, v.plate_number,
            (SELECT COUNT(*) FROM driver_evaluations de WHERE de.request_id = r.id) AS total_invites,
            (SELECT COUNT(*) FROM driver_evaluations de WHERE de.request_id = r.id AND de.submitted_at IS NOT NULL) AS submitted_cnt,
            (SELECT AVG(de.overall) FROM driver_evaluations de WHERE de.request_id = r.id AND de.submitted_at IS NOT NULL) AS avg_overall
     FROM requests r
     LEFT JOIN drivers d ON r.driver_id = d.id AND d.deleted_at IS NULL
     LEFT JOIN users u ON d.user_id = u.id
     LEFT JOIN vehicles v ON r.vehicle_id = v.id AND v.deleted_at IS NULL
     WHERE {$whereTrip} AND r.deleted_at IS NULL
     ORDER BY r.start_datetime DESC LIMIT 100",
    $paramsTrip
);

// Per-driver averages (anonymous)
$whereDriver = "de.submitted_at IS NOT NULL";
$paramsDriver = [];
if ($fromSql) { $whereDriver .= " AND r.start_datetime >= ?"; $paramsDriver[] = $fromSql; }
if ($toSql) { $whereDriver .= " AND r.start_datetime <= ?"; $paramsDriver[] = $toSql; }
if ($isSelfScoped && $driverId) { $whereDriver .= " AND de.driver_id = ?"; $paramsDriver[] = $driverId; }

$perDriver = db()->fetchAll(
    "SELECT de.driver_id, u.name AS driver_name,
            COUNT(*) AS eval_count,
            AVG(de.overall) AS avg_overall,
            AVG(de.rating_cleanliness) AS avg_cleanliness,
            AVG(de.rating_behavior) AS avg_behavior,
            AVG(de.rating_appearance) AS avg_appearance,
            AVG(de.rating_safety) AS avg_safety
     FROM driver_evaluations de
     JOIN requests r ON de.request_id = r.id AND r.deleted_at IS NULL
     JOIN drivers d ON de.driver_id = d.id AND d.deleted_at IS NULL
     JOIN users u ON d.user_id = u.id
     WHERE {$whereDriver}
     GROUP BY de.driver_id
     ORDER BY avg_overall DESC",
    $paramsDriver
);

// Anonymous remarks (never show evaluator identity)
$whereRemarks = "de.submitted_at IS NOT NULL AND de.remarks IS NOT NULL AND TRIM(de.remarks) <> ''";
$paramsRemarks = [];
if ($fromSql) { $whereRemarks .= " AND r.start_datetime >= ?"; $paramsRemarks[] = $fromSql; }
if ($toSql) { $whereRemarks .= " AND r.start_datetime <= ?"; $paramsRemarks[] = $toSql; }
if ($isSelfScoped && $driverId) { $whereRemarks .= " AND de.driver_id = ?"; $paramsRemarks[] = $driverId; }

$remarks = db()->fetchAll(
    "SELECT de.remarks, de.overall, de.created_at, de.submitted_at,
            r.id AS request_id, r.destination, r.start_datetime,
            u.name AS driver_name, v.plate_number
     FROM driver_evaluations de
     JOIN requests r ON de.request_id = r.id AND r.deleted_at IS NULL
     JOIN drivers d ON de.driver_id = d.id AND d.deleted_at IS NULL
     JOIN users u ON d.user_id = u.id
     LEFT JOIN vehicles v ON r.vehicle_id = v.id AND v.deleted_at IS NULL
     WHERE {$whereRemarks}
     ORDER BY de.submitted_at DESC LIMIT 50",
    $paramsRemarks
);

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h4 class="mb-1"><i class="bi bi-star-half me-2"></i>Driver Evaluations</h4>
            <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
                <li class="breadcrumb-item active">Evaluations</li>
            </ol></nav>
            <?php if ($isSelfScoped): ?><div class="alert alert-info py-2 px-3 mt-2 mb-0 small"><i class="bi bi-info-circle me-1"></i>You are viewing your own trip evaluations only.</div><?php endif; ?>
        </div>
        <a href="<?= APP_URL ?>/?page=reports&action=driver-rankings" class="btn btn-primary"><i class="bi bi-trophy me-1"></i>Driver Rankings</a>
    </div>

    <form method="GET" class="card mb-4">
        <input type="hidden" name="page" value="evaluations">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-3"><label class="form-label">From</label><input type="date" class="form-control" name="from" value="<?= e($from) ?>"></div>
                <div class="col-md-3"><label class="form-label">To</label><input type="date" class="form-control" name="to" value="<?= e($to) ?>"></div>
                <div class="col-md-3"><button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Filter</button> <a href="<?= APP_URL ?>/?page=evaluations" class="btn btn-outline-secondary">Clear</a></div>
            </div>
        </div>
    </form>

    <!-- Per-driver averages -->
    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0"><i class="bi bi-people me-2"></i>Per-Driver Averages (Anonymous)</h5></div>
        <div class="card-body p-0">
            <?php if (empty($perDriver)): ?>
                <div class="text-center text-muted py-4">No evaluations yet for this period.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light"><tr><th>Driver</th><th class="text-center">Evaluations</th><th class="text-center">Overall</th><th class="text-center">Cleanliness<br><small class="text-muted">Vehicle</small></th><th class="text-center">Behavior<br><small class="text-muted">Driver</small></th><th class="text-center">Appearance<br><small class="text-muted">Hygiene</small></th><th class="text-center">Safety<br><small class="text-muted">Driving</small></th></tr></thead>
                    <tbody>
                    <?php foreach ($perDriver as $row): ?>
                        <tr>
                            <td><strong><?= e($row->driver_name) ?></strong></td>
                            <td class="text-center"><?= (int) $row->eval_count ?></td>
                            <td class="text-center"><span class="badge bg-success"><?= $row->avg_overall !== null ? number_format((float)$row->avg_overall,2) : '—' ?></span></td>
                            <td class="text-center"><?= $row->avg_cleanliness !== null ? number_format((float)$row->avg_cleanliness,2) : '—' ?></td>
                            <td class="text-center"><?= $row->avg_behavior !== null ? number_format((float)$row->avg_behavior,2) : '—' ?></td>
                            <td class="text-center"><?= $row->avg_appearance !== null ? number_format((float)$row->avg_appearance,2) : '—' ?></td>
                            <td class="text-center"><?= $row->avg_safety !== null ? number_format((float)$row->avg_safety,2) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Response rate per trip -->
    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0"><i class="bi bi-clipboard-check me-2"></i>Response Rate per Trip</h5></div>
        <div class="card-body p-0">
            <?php if (empty($trips)): ?>
                <div class="text-center text-muted py-4">No completed trips in this period.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light"><tr><th>Request</th><th>Destination</th><th>Driver</th><th>Date</th><th class="text-center">Invites</th><th class="text-center">Submitted</th><th class="text-center">Rate</th><th class="text-center">Avg</th></tr></thead>
                    <tbody>
                    <?php foreach ($trips as $t): $rate = $t->total_invites ? round($t->submitted_cnt / $t->total_invites * 100) : 0; ?>
                        <tr>
                            <td><a href="<?= APP_URL ?>/?page=requests&action=view&id=<?= (int)$t->id ?>">#<?= (int)$t->id ?></a></td>
                            <td><?= e($t->destination) ?></td>
                            <td><?= e($t->driver_name ?: '—') ?><?= $t->plate_number ? '<br><small class="text-muted">'.e($t->plate_number).'</small>' : '' ?></td>
                            <td><?= e(formatDateTime($t->start_datetime)) ?></td>
                            <td class="text-center"><?= (int)$t->total_invites ?></td>
                            <td class="text-center"><?= (int)$t->submitted_cnt ?></td>
                            <td class="text-center"><span class="badge bg-<?= $rate>=70?'success':($rate>=40?'warning':'secondary') ?>"><?= $rate ?>%</span></td>
                            <td class="text-center"><?= $t->avg_overall !== null ? number_format((float)$t->avg_overall,2) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Anonymous remarks -->
    <div class="card">
        <div class="card-header"><h5 class="mb-0"><i class="bi bi-chat-quote me-2"></i>Anonymous Remarks</h5><small class="text-muted">Identity is never shown — remarks appear with trip/driver only (GRAB-like).</small></div>
        <div class="card-body">
            <?php if (empty($remarks)): ?>
                <div class="text-center text-muted py-3">No remarks yet.</div>
            <?php else: foreach ($remarks as $r): ?>
                <div class="border rounded p-3 mb-3 bg-light">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                        <div>
                            <strong><?= e($r->driver_name) ?></strong> <?= $r->plate_number ? '<span class="text-muted">— '.e($r->plate_number).'</span>' : '' ?>
                            <small class="text-muted d-block">Trip #<?= (int)$r->request_id ?> — <?= e($r->destination) ?> • <?= e(formatDateTime($r->start_datetime)) ?></small>
                        </div>
                        <span class="badge bg-primary"><?= $r->overall !== null ? number_format((float)$r->overall,2).' ★' : '' ?></span>
                    </div>
                    <div class="mt-2"><em>"<?= nl2br(e($r->remarks)) ?>"</em></div>
                    <small class="text-muted">— Anonymous passenger</small>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
