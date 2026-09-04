<?php
/**
 * LOKA - Evaluations Dashboard (response rates per trip, per-driver averages)
 * Access: approver+ or tagged driver (requireReportsAccess). Self-scoped drivers see own stats only.
 * Anonymous: rater identity is never selected or displayed.
 */

require_once INCLUDES_PATH . '/eval_report.php';
requireEvalReportAccess();

$pageTitle = 'Driver Evaluations';
$f = evalReportParseFilters(false); // blank From/To = all time

$trips = evalReportTrips($f);
$perDriver = evalReportRankings($f, false); // no minimum — show everyone with ≥1 submit
$remarks = evalReportRemarks($f, 50);

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
            <?php if ($f['self_scoped']): ?><div class="alert alert-info py-2 px-3 mt-2 mb-0 small"><i class="bi bi-info-circle me-1"></i>You are viewing your own trip evaluations only.</div><?php endif; ?>
        </div>
        <div class="d-flex gap-3 align-items-center flex-wrap">
            <?= evalReportPdfExportHtml($f) ?>
            <a href="<?= APP_URL ?>/?page=reports&action=driver-rankings" class="btn btn-primary"><i class="bi bi-trophy me-1"></i>Driver Rankings</a>
        </div>
    </div>

    <?= evalReportFilterBarHtml(
        $f,
        'evaluations',
        'index',
        false,
        APP_URL . '/?page=evaluations'
    ) ?>

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
