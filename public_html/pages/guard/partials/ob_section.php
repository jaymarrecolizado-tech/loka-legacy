<?php
/**
 * LOKA - Guard partial: OB Pass Slip time stamps (Plans #22 + #36)
 * Included by pages/guard/index.php and the OB index stamp board. Posts to
 * ?page=ob-requests&action=process (guard role and slip state are
 * re-validated server-side). Plan #36: Depart / Arrive buttons only — the
 * logged-in guard identity + timestamp are the record; no canvases.
 */

$obAwaitingDeparture = db()->fetchAll(
    "SELECT o.id, o.pass_slip_no, o.ob_date, o.purpose, o.plate_number, u.name AS employee_name
     FROM ob_requests o
     JOIN users u ON o.user_id = u.id
     WHERE o.deleted_at IS NULL
       AND o.ob_departure_datetime IS NULL
       AND o.status IN ('approved', 'coa_received', 'completed')
       AND NOT EXISTS (
           SELECT 1 FROM requests r
           WHERE r.ob_request_id = o.id AND r.deleted_at IS NULL AND r.status <> 'cancelled'
       )
     ORDER BY o.ob_date ASC, o.id ASC
     LIMIT 30"
);

$obAwaitingArrival = db()->fetchAll(
    "SELECT o.id, o.pass_slip_no, o.ob_date, o.purpose, o.plate_number, u.name AS employee_name,
            o.ob_departure_datetime
     FROM ob_requests o
     JOIN users u ON o.user_id = u.id
     WHERE o.deleted_at IS NULL
       AND o.ob_departure_datetime IS NOT NULL
       AND o.ob_arrival_datetime IS NULL
       AND o.status IN ('departed', 'coa_received', 'completed')
       AND NOT EXISTS (
           SELECT 1 FROM requests r
           WHERE r.ob_request_id = o.id AND r.deleted_at IS NULL AND r.status <> 'cancelled'
       )
     ORDER BY o.ob_date ASC, o.id ASC
     LIMIT 30"
);

$obCount = count($obAwaitingDeparture) + count($obAwaitingArrival);
$obPrinted = obPrintedLinesForIds(array_merge(
    array_map(static fn($s) => (int) $s->id, $obAwaitingDeparture),
    array_map(static fn($s) => (int) $s->id, $obAwaitingArrival)
));
?>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="bi bi-file-earmark-text me-2"></i>OB Pass Slips — Official Business Times</h6>
        <span class="badge bg-<?= $obCount > 0 ? 'warning text-dark' : 'secondary' ?>"><?= $obCount ?> waiting</span>
    </div>
    <div class="card-body">
        <?php if ($obCount === 0): ?>
            <p class="text-muted small mb-0">No OB Pass Slips waiting for guard time stamps.</p>
        <?php else: ?>
            <?php foreach ($obAwaitingDeparture as $s): ?>
                <div class="border rounded p-2 mb-2 d-flex justify-content-between flex-wrap gap-2">
                    <div class="small align-self-center">
                        <strong><?= e($s->pass_slip_no) ?></strong> — <?= e($obPrinted[(int) $s->id] ?? $s->employee_name) ?>
                        <span class="text-muted">· <?= e(date('M j', strtotime($s->ob_date))) ?> · <?= e(mb_strimwidth((string) $s->purpose, 0, 60, '…')) ?></span>
                    </div>
                    <form method="POST" action="<?= APP_URL ?>/?page=ob-requests&action=process" class="d-inline"
                          onsubmit="return confirm('Record departure for <?= e($s->pass_slip_no) ?> now? Your guard identity and the time are recorded.');">
                        <?= csrfField() ?>
                        <input type="hidden" name="ob_id" value="<?= (int) $s->id ?>">
                        <button type="submit" name="action" value="guard_departure" class="btn btn-sm btn-primary">
                            <i class="bi bi-box-arrow-up-right me-1"></i>Depart
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>
            <?php foreach ($obAwaitingArrival as $s): ?>
                <div class="border rounded p-2 mb-2 d-flex justify-content-between flex-wrap gap-2">
                    <div class="small align-self-center">
                        <strong><?= e($s->pass_slip_no) ?></strong> — <?= e($obPrinted[(int) $s->id] ?? $s->employee_name) ?>
                        <span class="text-muted">· departed <?= $s->ob_departure_datetime ? e(date('M j g:i A', strtotime($s->ob_departure_datetime))) : '' ?></span>
                    </div>
                    <form method="POST" action="<?= APP_URL ?>/?page=ob-requests&action=process" class="d-inline"
                          onsubmit="return confirm('Record arrival time for <?= e($s->pass_slip_no) ?> now?');">
                        <?= csrfField() ?>
                        <input type="hidden" name="ob_id" value="<?= (int) $s->id ?>">
                        <button type="submit" name="action" value="guard_arrival" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-box-arrow-in-down me-1"></i>Arrive
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>
            <p class="text-muted small mb-0 mt-2">
                <i class="bi bi-info-circle me-1"></i>Depart and Arrive record your guard identity and the time (no signature needed).
                Slips bound to a fleet trip are stamped from the trip instead.
            </p>
        <?php endif; ?>
    </div>
</div>
