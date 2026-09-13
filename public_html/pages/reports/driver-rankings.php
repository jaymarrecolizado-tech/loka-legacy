<?php
/**
 * LOKA - Driver Rankings Report (Anonymous evaluations, GRAB-like)
 *
 * Fair ranking: Bayesian rank score shrunk toward the fleet mean
 * (score = (v/(v+m))·R + (m/(v+m))·C), ranked by score with more evals as
 * tie-break. Drivers below the Min evaluations threshold are listed
 * separately ("Not ranked"). Grouped top-10 chart (overall + 4 categories),
 * expandable per-evaluation breakdown per driver. Rater identity is never
 * shown.
 */

require_once INCLUDES_PATH . '/eval_report.php';
requireEvalReportAccess();

$pageTitle = 'Driver Rankings';
$f = evalReportParseFilters(true); // defaults to current month

$data = evalReportRankings($f);
$evalRows = evalReportDriverEvalRows($f);

// Grouped chart: top 10 ranked drivers, overall + 4 categories
$chartDrivers = array_slice($data['ranked'], 0, 10);

$csvUrl = APP_URL . evalReportQueryString($f, ['action' => 'export-driver-rankings-csv']);

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
            <small class="text-muted"><i class="bi bi-shield-lock me-1"></i>Anonymous — passenger identities never shown. Ranked by fair score, not raw average.</small>
        </div>
        <div class="d-flex gap-3 align-items-center flex-wrap">
            <?= evalReportPdfExportHtml($f) ?>
            <?php if (!empty($data['ranked'])): ?>
                <a href="<?= e($csvUrl) ?>" class="btn btn-outline-success"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Export CSV</a>
            <?php endif; ?>
            <a href="<?= APP_URL ?>/?page=evaluations" class="btn btn-outline-primary"><i class="bi bi-star me-1"></i>Evaluations</a>
        </div>
    </div>

    <?= evalReportFilterBarHtml(
        $f,
        'reports',
        'driver-rankings',
        true,
        APP_URL . '/?page=reports&action=driver-rankings'
    ) ?>

    <?php if (!empty($chartDrivers)): ?>
    <div class="card mb-4">
        <div class="card-header"><h6 class="mb-0"><i class="bi bi-bar-chart me-2"></i>Top <?= count($chartDrivers) ?> — Overall + 4 Categories</h6></div>
        <div class="card-body"><canvas id="rankingChart" height="110"></canvas></div>
    </div>
    <?php endif; ?>

    <?= evalReportRankTableHtml($data, $evalRows, $f) ?>

    <div class="d-flex gap-3 flex-wrap mb-4">
        <a href="<?= APP_URL ?>/?page=reports&action=driver-trip-extract&from=<?= e($f['from']) ?>&to=<?= e($f['to']) ?>" class="btn btn-outline-secondary">
            <i class="bi bi-table me-1"></i>Driver Trip Extract
        </a>
    </div>
</div>

<?php if (!empty($chartDrivers)): ?>
<?php
$chartData = array_map(static fn($r): array => [
    'name' => $r->driver_name,
    'avg_overall' => $r->avg_overall !== null ? round((float) $r->avg_overall, 2) : null,
    'avg_cleanliness' => $r->avg_cleanliness !== null ? round((float) $r->avg_cleanliness, 2) : null,
    'avg_behavior' => $r->avg_behavior !== null ? round((float) $r->avg_behavior, 2) : null,
    'avg_appearance' => $r->avg_appearance !== null ? round((float) $r->avg_appearance, 2) : null,
    'avg_safety' => $r->avg_safety !== null ? round((float) $r->avg_safety, 2) : null,
], $chartDrivers);
?>
<script>
document.addEventListener('DOMContentLoaded', function(){
    const rows = <?= json_encode($chartData) ?>;
    const labels = rows.map(r => r.name);
    const ds = (label, key, color) => ({
        label: label,
        data: rows.map(r => r[key]),
        backgroundColor: color,
        borderWidth: 1
    });
    new Chart(document.getElementById('rankingChart'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                ds('Overall', 'avg_overall', 'rgba(13,110,253,0.85)'),
                ds('Cleanliness', 'avg_cleanliness', 'rgba(25,135,84,0.75)'),
                ds('Behavior', 'avg_behavior', 'rgba(255,193,7,0.75)'),
                ds('Appearance', 'avg_appearance', 'rgba(220,53,69,0.65)'),
                ds('Safety', 'avg_safety', 'rgba(108,117,125,0.75)')
            ]
        },
        options: {
            responsive: true,
            scales: { y: { beginAtZero: true, max: 5, ticks: { stepSize: 0.5 } } },
            plugins: { legend: { position: 'top' } }
        }
    });
});
</script>
<?php endif; ?>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
