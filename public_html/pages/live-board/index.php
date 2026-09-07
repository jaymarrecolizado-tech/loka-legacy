<?php
/**
 * LOKA - Live Trip Board (Plan #17)
 * Motorpool Head, Guard, Admin, All Father. Reads guard dispatch/arrival only.
 */

requireLiveBoardAccess();
require_once INCLUDES_PATH . '/live_board.php';

$pageTitle = 'Live Trip Board';
$filter = get('filter', 'today');
$allowedFilters = ['today', 'on_trip', 'overdue', 'due_soon'];
if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'today';
}
$search = trim((string) get('q', ''));
$now = time();

$rows = liveBoardFetchRows();
foreach ($rows as $row) {
    $row->board_status = liveBoardClassify($row, $now);
}

$kpis = [
    'on_trip' => 0,
    'overdue' => 0,
    'due_soon' => 0,
];
foreach ($rows as $row) {
    if ($row->board_status === 'overdue') {
        $kpis['overdue']++;
        $kpis['on_trip']++;
    } elseif ($row->board_status === 'due_soon') {
        $kpis['due_soon']++;
        $kpis['on_trip']++;
    } elseif ($row->board_status === 'on_trip') {
        $kpis['on_trip']++;
    }
}
$kpis['available'] = liveBoardAvailableVehicleCount();

$tableRows = [];
foreach ($rows as $row) {
    if ($filter === 'on_trip' && !in_array($row->board_status, ['on_trip', 'overdue', 'due_soon'], true)) {
        continue;
    }
    if ($filter === 'overdue' && $row->board_status !== 'overdue') {
        continue;
    }
    if ($filter === 'due_soon' && $row->board_status !== 'due_soon') {
        continue;
    }
    if (!liveBoardMatchesSearch($row, $search)) {
        continue;
    }
    $tableRows[] = $row;
}

$filterBase = APP_URL . '/?page=live-board';
$qSuffix = $search !== '' ? '&q=' . urlencode($search) : '';

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h4 class="mb-1"><i class="bi bi-display me-2"></i>Live Trip Board</h4>
            <p class="text-muted mb-0">Who is out, overdue, or due back — without opening the request list.</p>
        </div>
        <div class="text-end">
            <div class="fw-semibold" id="liveBoardClock"><?= e(date('D, M j, Y g:i:s A')) ?></div>
            <small class="text-muted">Manila · auto-refresh <span id="liveBoardCountdown">30</span>s</small>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <?php
        $kpiCards = [
            ['key' => 'on_trip', 'label' => 'On trip', 'value' => $kpis['on_trip'], 'icon' => 'bi-truck', 'tone' => 'info', 'filter' => 'on_trip'],
            ['key' => 'overdue', 'label' => 'Overdue', 'value' => $kpis['overdue'], 'icon' => 'bi-exclamation-triangle', 'tone' => 'danger', 'filter' => 'overdue'],
            ['key' => 'due_soon', 'label' => 'Due within 1 hour', 'value' => $kpis['due_soon'], 'icon' => 'bi-clock', 'tone' => 'warning', 'filter' => 'due_soon'],
            ['key' => 'available', 'label' => 'Available vehicles', 'value' => $kpis['available'], 'icon' => 'bi-car-front', 'tone' => 'success', 'filter' => null],
        ];
        foreach ($kpiCards as $card):
            $href = $card['filter'] ? $filterBase . '&filter=' . $card['filter'] . $qSuffix : null;
            $active = $card['filter'] && $filter === $card['filter'];
        ?>
        <div class="col-md-3">
            <?php if ($href): ?><a href="<?= e($href) ?>" class="text-decoration-none"><?php endif; ?>
            <div class="card shadow-sm h-100 <?= $active ? 'border border-2 border-' . $card['tone'] : 'border-0' ?>">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-<?= $card['tone'] ?> bg-opacity-10 rounded p-3">
                                <i class="bi <?= $card['icon'] ?> text-<?= $card['tone'] ?> fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1"><?= e($card['label']) ?></h6>
                            <h3 class="mb-0 text-body"><?= (int) $card['value'] ?></h3>
                        </div>
                    </div>
                </div>
            </div>
            <?php if ($href): ?></a><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="card table-card mb-3">
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <input type="hidden" name="page" value="live-board">
                <input type="hidden" name="filter" value="<?= e($filter) ?>">
                <div class="col-lg-7">
                    <div class="btn-group flex-wrap" role="group" aria-label="Board filters">
                        <?php
                        $pills = [
                            'today' => 'Today',
                            'on_trip' => 'On trip',
                            'overdue' => 'Overdue',
                            'due_soon' => 'Due within 1 hour',
                        ];
                        foreach ($pills as $key => $label):
                            $cls = $filter === $key ? 'btn-primary' : 'btn-outline-primary';
                        ?>
                        <a class="btn <?= $cls ?>" href="<?= e($filterBase . '&filter=' . $key . $qSuffix) ?>"><?= e($label) ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-lg-5">
                    <label class="form-label visually-hidden">Search</label>
                    <div class="input-group">
                        <input type="text" name="q" class="form-control" placeholder="Plate, driver, or Control No." value="<?= e($search) ?>">
                        <button type="submit" class="btn btn-outline-primary"><i class="bi bi-search"></i></button>
                        <?php if ($search !== ''): ?>
                        <a class="btn btn-outline-secondary" href="<?= e($filterBase . '&filter=' . $filter) ?>">Reset</a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card table-card">
        <div class="card-body p-0">
            <?php if (empty($tableRows)): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-check2-circle fs-3 d-block mb-2"></i>
                No trips on this board right now.
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Status</th>
                            <th>Control No.</th>
                            <th>Plate</th>
                            <th>Driver</th>
                            <th>Destination</th>
                            <th>Passengers</th>
                            <th>Dispatched</th>
                            <th>Expected return</th>
                            <th>Late by / time left</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($tableRows as $row):
                        $chip = liveBoardChip($row->board_status);
                        $rowClass = $row->board_status === 'overdue' ? 'table-danger' : '';
                    ?>
                        <tr class="<?= $rowClass ?>">
                            <td><span class="badge <?= e($chip['class']) ?>"><?= e($chip['label']) ?></span></td>
                            <td>
                                <a href="<?= APP_URL ?>/?page=requests&action=view&id=<?= (int) $row->id ?>">
                                    <?= (int) $row->id ?>
                                </a>
                            </td>
                            <td><?= $row->plate_number ? e($row->plate_number) : '<span class="text-muted">—</span>' ?></td>
                            <td><?= $row->driver_name ? e($row->driver_name) : '<span class="text-muted">Unassigned</span>' ?></td>
                            <td><?= truncate((string) ($row->destination ?? ''), 40) ?></td>
                            <td><?= (int) ($row->passenger_count ?? 0) ?></td>
                            <td><?= !empty($row->actual_dispatch_datetime) ? e(formatDateTime($row->actual_dispatch_datetime)) : '<span class="text-muted">Not yet</span>' ?></td>
                            <td><?= e(formatDateTime($row->end_datetime)) ?></td>
                            <td class="<?= $row->board_status === 'overdue' ? 'text-danger fw-semibold' : '' ?>">
                                <?= e(liveBoardTiming($row, $now)) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function () {
    var clockEl = document.getElementById('liveBoardClock');
    var countEl = document.getElementById('liveBoardCountdown');
    var left = 30;
    function tickClock() {
        try {
            clockEl.textContent = new Date().toLocaleString('en-PH', {
                timeZone: 'Asia/Manila',
                weekday: 'short', year: 'numeric', month: 'short', day: 'numeric',
                hour: 'numeric', minute: '2-digit', second: '2-digit', hour12: true
            });
        } catch (e) { /* keep server-rendered clock */ }
    }
    setInterval(tickClock, 1000);
    setInterval(function () {
        left -= 1;
        if (countEl) countEl.textContent = String(Math.max(left, 0));
        if (left <= 0) location.reload();
    }, 1000);
})();
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
