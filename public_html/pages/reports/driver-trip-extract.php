<?php
/**
 * LOKA - Driver Trip Extract (Plan #21)
 *
 * Date-range extract of EVERY assigned driver/trip (approved + completed),
 * including drivers with no ratings yet (scores —). Short driver summary on
 * top (trip count, submitted evals, fair rank score), then one row per trip
 * with tickable columns. CSV/PDF exports match the screen (GET flags).
 * Anonymous: comments shown as quotes only — never rater identity.
 */

require_once INCLUDES_PATH . '/eval_report.php';
requireEvalReportAccess();

$pageTitle = 'Driver Trip Extract';
$f = evalReportParseFilters(true); // defaults to current month
$cols = evalTripExtractColumns();

$rankData = evalReportRankings($f);
$trips = evalTripExtractTrips($f);
$remarksByReq = evalTripExtractRemarks($f);
$summary = evalTripExtractSummary($trips, $rankData);

// Per-driver rank info for the summary + repeated score column
$rankByDriver = [];
foreach (array_merge($rankData['ranked'], $rankData['unranked']) as $r) {
    $rankByDriver[(int) $r->driver_id] = $r;
}

$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
$fmt = static fn($v): string => $v !== null ? number_format((float) $v, 2) : '—';
$fmtE = static function (?float $v): string {
    return $v !== null ? number_format($v, 2) : '—';
};

// Visible trip columns (in display order)
$tripColumns = [];
if ($cols['col_date']) $tripColumns['date'] = 'Date';
if ($cols['col_trip']) $tripColumns['trip'] = 'Trip #';
if ($cols['col_driver']) $tripColumns['driver'] = 'Driver';
if ($cols['col_plate']) $tripColumns['plate'] = 'Plate';
if ($cols['col_dest']) $tripColumns['dest'] = 'Destination';
if ($cols['col_invites']) $tripColumns['invites'] = 'Invites/Sub.';
if ($cols['col_overall']) $tripColumns['overall'] = 'Overall';
if ($cols['col_cats']) $tripColumns['cats'] = 'C · B · A · S';
if ($cols['col_score']) $tripColumns['score'] = 'Rank score';
if ($cols['col_remarks']) $tripColumns['remarks'] = 'Comments (anonymous)';

// Checkbox markup; default-ON flags carry a hidden 0 so unchecking persists
$cb = static function (string $name, bool $checked, bool $defaultOn, string $label) use ($h): string {
    $hidden = $defaultOn ? '<input type="hidden" name="' . $name . '" value="0">' : '';
    return '<label class="form-check form-check-inline small mb-1">' . $hidden
        . '<input class="form-check-input mt-1" type="checkbox" name="' . $name . '" value="1"'
        . ($checked ? ' checked' : '') . '> ' . $h($label) . '</label>';
};

// Hidden inputs that carry the current column flags into export forms
$flagHidden = static function () use ($cols): string {
    $out = '';
    foreach ($cols as $k => $on) {
        $out .= '<input type="hidden" name="' . $k . '" value="' . ($on ? '1' : '0') . '">';
    }
    return $out;
};

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h4 class="mb-1"><i class="bi bi-table me-2"></i>Driver Trip Extract</h4>
            <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>/?page=reports">Reports</a></li>
                <li class="breadcrumb-item active">Driver Trip Extract</li>
            </ol></nav>
            <small class="text-muted"><i class="bi bi-shield-lock me-1"></i>Every assigned driver in the period — including trips with no ratings yet (scores —). Approved + completed trips only.</small>
        </div>
        <div class="d-flex gap-3 align-items-center flex-wrap">
            <form method="get" action="<?= $h(rtrim(APP_URL, '/')) ?>/" class="d-inline">
                <input type="hidden" name="page" value="reports">
                <input type="hidden" name="action" value="export-driver-trip-extract-csv">
                <input type="hidden" name="from" value="<?= $h($f['from']) ?>">
                <input type="hidden" name="to" value="<?= $h($f['to']) ?>">
                <?php if ($f['driver_id'] > 0): ?><input type="hidden" name="driver_id" value="<?= (int) $f['driver_id'] ?>"><?php endif; ?>
                <?php if ($f['vehicle_id'] > 0): ?><input type="hidden" name="vehicle_id" value="<?= (int) $f['vehicle_id'] ?>"><?php endif; ?>
                <?php if ($f['request_id'] > 0): ?><input type="hidden" name="request_id" value="<?= (int) $f['request_id'] ?>"><?php endif; ?>
                <?= $flagHidden() ?>
                <button type="submit" class="btn btn-outline-success"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Export CSV</button>
            </form>
            <form method="get" action="<?= $h(rtrim(APP_URL, '/')) ?>/" class="d-inline">
                <input type="hidden" name="page" value="reports">
                <input type="hidden" name="action" value="export-driver-trip-extract-pdf">
                <input type="hidden" name="from" value="<?= $h($f['from']) ?>">
                <input type="hidden" name="to" value="<?= $h($f['to']) ?>">
                <?php if ($f['driver_id'] > 0): ?><input type="hidden" name="driver_id" value="<?= (int) $f['driver_id'] ?>"><?php endif; ?>
                <?php if ($f['vehicle_id'] > 0): ?><input type="hidden" name="vehicle_id" value="<?= (int) $f['vehicle_id'] ?>"><?php endif; ?>
                <?php if ($f['request_id'] > 0): ?><input type="hidden" name="request_id" value="<?= (int) $f['request_id'] ?>"><?php endif; ?>
                <?= $flagHidden() ?>
                <button type="submit" class="btn btn-outline-danger"><i class="bi bi-file-earmark-pdf me-1"></i>Export PDF</button>
            </form>
            <a href="<?= APP_URL ?>/?page=reports&action=driver-rankings" class="btn btn-outline-primary"><i class="bi bi-trophy me-1"></i>Rankings</a>
        </div>
    </div>

    <form method="GET" class="card mb-4">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <input type="hidden" name="page" value="reports">
                <input type="hidden" name="action" value="driver-trip-extract">
                <div class="col-6 col-md-2"><label class="form-label">From</label><input type="date" class="form-control" name="from" value="<?= $h($f['from']) ?>"></div>
                <div class="col-6 col-md-2"><label class="form-label">To</label><input type="date" class="form-control" name="to" value="<?= $h($f['to']) ?>"></div>
                <?php if (!$f['self_scoped']): ?>
                <div class="col-6 col-md-2"><label class="form-label">Driver</label><select name="driver_id" class="form-select"><option value="">All drivers</option>
                    <?php foreach (evalReportFilterOptions()['drivers'] as $d): ?>
                    <option value="<?= (int) $d->id ?>"<?= $f['driver_id'] === (int) $d->id ? ' selected' : '' ?>><?= $h($d->name) ?></option>
                    <?php endforeach; ?>
                </select></div>
                <div class="col-6 col-md-2"><label class="form-label">Vehicle</label><select name="vehicle_id" class="form-select"><option value="">All vehicles</option>
                    <?php foreach (evalReportFilterOptions()['vehicles'] as $v): ?>
                    <option value="<?= (int) $v->id ?>"<?= $f['vehicle_id'] === (int) $v->id ? ' selected' : '' ?>><?= $h($v->plate_number) ?></option>
                    <?php endforeach; ?>
                </select></div>
                <?php else: ?>
                <div class="col-6 col-md-2"><label class="form-label">Driver</label><input type="text" class="form-control" value="Own trips only" disabled></div>
                <?php endif; ?>
                <div class="col-6 col-md-2"><label class="form-label">Trip No.</label><input type="number" class="form-control" name="request_id" value="<?= $f['request_id'] > 0 ? (int) $f['request_id'] : '' ?>" min="1" placeholder="e.g. 555"></div>
                <div class="col-6 col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-fill"><i class="bi bi-funnel me-1"></i>Apply</button>
                    <a href="<?= APP_URL ?>/?page=reports&action=driver-trip-extract" class="btn btn-outline-secondary flex-fill">Clear</a>
                </div>
            </div>
            <hr class="my-3">
            <div>
                <strong class="small me-2"><i class="bi bi-check2-square me-1"></i>Columns:</strong>
                <span class="small text-muted me-2">Summary:</span>
                <?= $cb('sum_score', $cols['sum_score'], true, 'Rank score') ?>
                <?= $cb('sum_raw', $cols['sum_raw'], true, 'Raw avg') ?>
                <span class="small text-muted ms-2 me-2">Trips:</span>
                <?= $cb('col_date', $cols['col_date'], true, 'Date') ?>
                <?= $cb('col_trip', $cols['col_trip'], true, 'Trip #') ?>
                <?= $cb('col_driver', $cols['col_driver'], true, 'Driver') ?>
                <?= $cb('col_dest', $cols['col_dest'], true, 'Destination') ?>
                <?= $cb('col_overall', $cols['col_overall'], true, 'Overall') ?>
                <?= $cb('col_cats', $cols['col_cats'], true, '4-category breakdown') ?>
                <?= $cb('col_remarks', $cols['col_remarks'], true, 'Comments') ?>
                <?= $cb('col_plate', $cols['col_plate'], false, 'Plate') ?>
                <?= $cb('col_invites', $cols['col_invites'], false, 'Invite counts') ?>
                <?= $cb('col_score', $cols['col_score'], false, 'Rank score per row') ?>
            </div>
        </div>
    </form>

    <!-- Driver summary -->
    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0"><i class="bi bi-person-badge me-2"></i>Driver Summary (<?= count($summary) ?> drivers with assigned trips)</h5></div>
        <div class="card-body p-0">
            <?php if (empty($summary)): ?>
            <div class="text-center text-muted py-4">No assigned trips in this period.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light"><tr>
                        <th>Driver</th>
                        <th class="text-center">Trips</th>
                        <th class="text-center">Evaluations</th>
                        <?php if ($cols['sum_score']): ?><th class="text-center">Rank score</th><?php endif; ?>
                        <?php if ($cols['sum_raw']): ?><th class="text-center">Raw avg</th><?php endif; ?>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($summary as $s): ?>
                        <tr>
                            <td><strong><?= $h($s->driver_name) ?></strong></td>
                            <td class="text-center"><?= (int) $s->trip_count ?></td>
                            <td class="text-center"><?= (int) $s->eval_count ?></td>
                            <?php if ($cols['sum_score']): ?><td class="text-center"><?= $s->rank_score !== null ? '<span class="badge bg-primary">' . $fmt($s->rank_score) . '</span>' : '—' ?></td><?php endif; ?>
                            <?php if ($cols['sum_raw']): ?><td class="text-center text-muted"><?= $fmt($s->avg_overall) ?></td><?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Trip rows -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-list-task me-2"></i>Trips (<?= count($trips) ?>)</h5>
            <small class="text-muted">One row per assigned trip · oldest first</small>
        </div>
        <div class="card-body p-0">
            <?php if (empty($trips)): ?>
            <div class="text-center text-muted py-4">No assigned trips in this period.</div>
            <?php elseif (empty($tripColumns)): ?>
            <div class="text-center text-muted py-4">All trip columns are unticked — tick at least one column above and Apply.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0 align-middle">
                    <thead class="table-light"><tr>
                        <?php foreach ($tripColumns as $label): ?><th><?= $h($label) ?></th><?php endforeach; ?>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($trips as $t):
                        $did = (int) $t->driver_id;
                        $rankRow = $rankByDriver[$did] ?? null;
                        $tripRemarks = $remarksByReq[(int) $t->id] ?? [];
                    ?>
                        <tr>
                            <?php if (isset($tripColumns['date'])): ?><td class="text-nowrap"><?= $h(date('M j, Y', strtotime($t->start_datetime))) ?><small class="text-muted d-block"><?= $h(date('g:i A', strtotime($t->start_datetime))) ?></small></td><?php endif; ?>
                            <?php if (isset($tripColumns['trip'])): ?><td><a href="<?= APP_URL ?>/?page=requests&action=view&id=<?= (int) $t->id ?>">#<?= (int) $t->id ?></a><small class="text-muted d-block"><?= $h(ucfirst($t->status)) ?></small></td><?php endif; ?>
                            <?php if (isset($tripColumns['driver'])): ?><td><?= $h($t->driver_name ?: '—') ?></td><?php endif; ?>
                            <?php if (isset($tripColumns['plate'])): ?><td class="text-nowrap"><?= $h($t->plate_number ?: '—') ?></td><?php endif; ?>
                            <?php if (isset($tripColumns['dest'])): ?><td><?= $h($t->destination ?: '—') ?></td><?php endif; ?>
                            <?php if (isset($tripColumns['invites'])): ?><td class="text-center text-nowrap"><?= (int) $t->submitted_cnt ?> / <?= (int) $t->total_invites ?></td><?php endif; ?>
                            <?php if (isset($tripColumns['overall'])): ?><td class="text-center"><?= $t->avg_overall !== null ? '<span class="badge bg-success">' . $fmt($t->avg_overall) . '</span>' : '—' ?></td><?php endif; ?>
                            <?php if (isset($tripColumns['cats'])): ?>
                            <td class="text-nowrap small text-muted"><?= $t->avg_overall !== null
                                ? 'C ' . $fmtE($t->avg_cleanliness !== null ? (float) $t->avg_cleanliness : null)
                                . ' · B ' . $fmtE($t->avg_behavior !== null ? (float) $t->avg_behavior : null)
                                . ' · A ' . $fmtE($t->avg_appearance !== null ? (float) $t->avg_appearance : null)
                                . ' · S ' . $fmtE($t->avg_safety !== null ? (float) $t->avg_safety : null)
                                : '—' ?></td>
                            <?php endif; ?>
                            <?php if (isset($tripColumns['score'])): ?><td class="text-center"><?= $rankRow !== null ? $fmt($rankRow->rank_score) : '—' ?></td><?php endif; ?>
                            <?php if (isset($tripColumns['remarks'])): ?>
                            <td style="max-width:280px;">
                                <?php if (empty($tripRemarks)): ?>
                                <span class="text-muted">—</span>
                                <?php else: foreach ($tripRemarks as $qr): ?>
                                <div class="small mb-1"><em>"<?= $h($qr) ?>"</em> <span class="text-muted">— Anonymous passenger</span></div>
                                <?php endforeach; endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($rankData['fleet_mean'] !== null): ?>
    <p class="small text-muted"><i class="bi bi-calculator me-1"></i><?= $h(evalReportScoreFootnote($rankData, $f)) ?></p>
    <?php endif; ?>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
