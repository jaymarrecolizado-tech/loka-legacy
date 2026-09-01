<?php
/**
 * LOKA - Driver Rankings Report (Anonymous evaluations, GRAB-like)
 * Computed average overall per driver (+ per-criterion averages, eval count)
 * sorted best → worst, with Chart.js bar and CSV export.
 * Identity of remarks providers never shown.
 */

requireReportsAccess();

$pageTitle = 'Driver Rankings';
$isSelfScoped = isSelfScopedDriverReporter();

$from = get('from', date('Y-m-01'));
$to = get('to', date('Y-m-t'));
$minEval = max(1, (int) get('min_eval', 2));

$fromSql = $from ? date('Y-m-d 00:00:00', strtotime($from)) : null;
$toSql = $to ? date('Y-m-d 23:59:59', strtotime($to)) : null;

$where = "de.submitted_at IS NOT NULL";
$params = [];
if ($fromSql) { $where .= " AND r.start_datetime >= ?"; $params[] = $fromSql; }
if ($toSql) { $where .= " AND r.start_datetime <= ?"; $params[] = $toSql; }
if ($isSelfScoped) {
    $driverId = currentDriverId();
    if ($driverId) {
        $where .= " AND de.driver_id = ?";
        $params[] = $driverId;
    }
}

$rankings = db()->fetchAll(
    "SELECT de.driver_id, u.name AS driver_name,
            COUNT(*) AS eval_count,
            AVG(de.overall) AS avg_overall,
            AVG(de.rating_punctuality) AS avg_punctuality,
            AVG(de.rating_safety) AS avg_safety,
            AVG(de.rating_courtesy) AS avg_courtesy,
            AVG(de.rating_driving) AS avg_driving,
            AVG(de.rating_vehicle) AS avg_vehicle
     FROM driver_evaluations de
     JOIN requests r ON de.request_id = r.id AND r.deleted_at IS NULL
     JOIN drivers d ON de.driver_id = d.id AND d.deleted_at IS NULL
     JOIN users u ON d.user_id = u.id
     WHERE {$where}
     GROUP BY de.driver_id
     HAVING eval_count >= ?
     ORDER BY avg_overall DESC, eval_count DESC",
    array_merge($params, [$minEval])
);

// For chart: use top 15
$chartDrivers = array_slice($rankings, 0, 15);

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h4 class="mb-1"><i class="bi bi-trophy me-2"></i>Driver Rankings</h4>
            <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>/?page=reports">Reports</a></li>
                <li class="breadcrumb-item active">Driver Rankings</li>
            </ol></nav>
            <small class="text-muted"><i class="bi bi-shield-lock me-1"></i>Anonymous — passenger identities never shown. Rankings computed from submitted evaluations only.</small>
        </div>
        <div class="d-flex gap-2">
            <?php if (!empty($rankings)): ?>
                <a href="<?= APP_URL ?>/?page=reports&action=export-driver-rankings-csv&from=<?= e($from) ?>&to=<?= e($to) ?>&min_eval=<?= (int) $minEval ?>" class="btn btn-outline-success"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Export CSV</a>
            <?php endif; ?>
            <a href="<?= APP_URL ?>/?page=evaluations" class="btn btn-outline-primary"><i class="bi bi-star me-1"></i>Evaluations</a>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <input type="hidden" name="page" value="reports">
                <input type="hidden" name="action" value="driver-rankings">
                <div class="col-md-3"><label class="form-label">From</label><input type="date" class="form-control" name="from" value="<?= e($from) ?>"></div>
                <div class="col-md-3"><label class="form-label">To</label><input type="date" class="form-control" name="to" value="<?= e($to) ?>"></div>
                <div class="col-md-2"><label class="form-label">Min Evaluations</label><input type="number" class="form-control" name="min_eval" value="<?= (int) $minEval ?>" min="1" max="100"></div>
                <div class="col-md-2"><button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel me-1"></i>Apply</button></div>
                <div class="col-md-2"><a href="<?= APP_URL ?>/?page=reports&action=driver-rankings" class="btn btn-outline-secondary w-100">Reset</a></div>
            </form>
        </div>
    </div>

    <?php if (empty($rankings)): ?>
        <div class="card"><div class="card-body text-center py-5 text-muted"><i class="bi bi-inbox fs-1"></i><p class="mt-2 mb-0">No driver has reached the minimum evaluation threshold for this period.<br>Try lowering the minimum or expanding the date range.</p></div></div>
    <?php else: ?>
        <?php if (!empty($chartDrivers)): ?>
        <div class="card mb-4">
            <div class="card-header"><h6 class="mb-0"><i class="bi bi-bar-chart me-2"></i>Average Overall Rating (Top <?= count($chartDrivers) ?>)</h6></div>
            <div class="card-body"><canvas id="rankingChart" height="100"></canvas></div>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-list-ol me-2"></i>Rankings (<?= count($rankings) ?> drivers)</h5>
                <small class="text-muted">Sorted best → worst by average overall</small>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light"><tr><th>#</th><th>Driver</th><th class="text-center">Evals</th><th class="text-center">Overall</th><th class="text-center">Punct.</th><th class="text-center">Safety</th><th class="text-center">Courtesy</th><th class="text-center">Driving</th><th class="text-center">Vehicle</th></tr></thead>
                        <tbody>
                        <?php foreach ($rankings as $idx => $row): $rank = $idx+1; ?>
                            <tr class="<?= $rank<=3 ? 'table-warning' : '' ?>">
                                <td>
                                    <?php if ($rank===1): ?><span class="badge bg-warning text-dark"><i class="bi bi-trophy-fill me-1"></i>1</span>
                                    <?php elseif ($rank===2): ?><span class="badge bg-secondary">2</span>
                                    <?php elseif ($rank===3): ?><span class="badge" style="background:#cd7f32;color:white;">3</span>
                                    <?php else: ?><span class="badge bg-light text-dark"><?= $rank ?></span><?php endif; ?>
                                </td>
                                <td><strong><?= e($row->driver_name) ?></strong></td>
                                <td class="text-center"><?= (int) $row->eval_count ?></td>
                                <td class="text-center"><span class="badge bg-success fs-6"><?= number_format((float)$row->avg_overall,2) ?></span></td>
                                <td class="text-center"><?= number_format((float)$row->avg_punctuality,2) ?></td>
                                <td class="text-center"><?= number_format((float)$row->avg_safety,2) ?></td>
                                <td class="text-center"><?= number_format((float)$row->avg_courtesy,2) ?></td>
                                <td class="text-center"><?= number_format((float)$row->avg_driving,2) ?></td>
                                <td class="text-center"><?= number_format((float)$row->avg_vehicle,2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php if (!empty($chartDrivers)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function(){
    const labels = <?= json_encode(array_map(fn($r)=>$r->driver_name, $chartDrivers)) ?>;
    const data = <?= json_encode(array_map(fn($r)=> round((float)$r->avg_overall,2), $chartDrivers)) ?>;
    new Chart(document.getElementById('rankingChart'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{ label: 'Avg Overall (1-5)', data: data, backgroundColor: 'rgba(25,135,84,0.7)', borderColor: '#198754', borderWidth: 1 }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            scales: { x: { beginAtZero: true, max: 5, ticks: { stepSize: 0.5 } } },
            plugins: { legend: { display: false } }
        }
    });
});
</script>
<?php endif; ?>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
