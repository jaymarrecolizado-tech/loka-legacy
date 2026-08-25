<?php
/**
 * LOKA - Availability (trip planning guide)
 */

$pageTitle = 'Availability';

$year = (int) get('year', date('Y'));
$month = (int) get('month', date('n'));
if ($month < 1) {
    $month = 12;
    $year--;
} elseif ($month > 12) {
    $month = 1;
    $year++;
}

$dateParam = get('date');
$startDateParam = get('start_date');
$endDateParam = get('end_date');
if (!$dateParam && !$startDateParam) {
    // Default selection: today if in view month, else 1st of month
    $today = date('Y-m-d');
    $dateParam = ((int) date('Y') === $year && (int) date('n') === $month)
        ? $today
        : sprintf('%04d-%02d-01', $year, $month);
}

$window = availabilityParseWindow(
    $dateParam ?: null,
    $startDateParam ?: ($dateParam ?: null),
    $endDateParam ?: null
);

// Keep month grid aligned to selection start when year/month omitted oddly
if (!get('year') && !get('month')) {
    $year = (int) date('Y', strtotime($window['start_day']));
    $month = (int) date('n', strtotime($window['start_day']));
}

$firstDay = mktime(0, 0, 0, $month, 1, $year);
$daysInMonth = (int) date('t', $firstDay);
$startingDay = (int) date('N', $firstDay);
$monthName = date('F', $firstDay);

$prevMonth = $month - 1;
$prevYear = $year;
if ($prevMonth < 1) {
    $prevMonth = 12;
    $prevYear--;
}
$nextMonth = $month + 1;
$nextYear = $year;
if ($nextMonth > 12) {
    $nextMonth = 1;
    $nextYear++;
}

$vehicleTypeFilter = get('vehicle_type', '');
$vehicleTypes = db()->fetchAll(
    "SELECT id, name FROM vehicle_types WHERE deleted_at IS NULL ORDER BY name ASC"
);

$monthTrips = availabilityTripsForMonth($year, $month);
$busyDays = availabilityBusyDaysMap($monthTrips, $year, $month);
$fleetVehicles = availabilityFleetVehicles();
$totalVehicles = count($fleetVehicles);

$typeId = ($vehicleTypeFilter !== '' && ctype_digit((string) $vehicleTypeFilter))
    ? (int) $vehicleTypeFilter
    : null;

$windowTrips = availabilityTripsInWindow($window['start'], $window['end']);
$freeVehicles = availabilityFreeVehicles($window['start'], $window['end'], $typeId);
$freeDrivers = availabilityFreeDrivers($window['start'], $window['end']);

$showRequester = isApprover() || isMotorpool() || isAdmin() || isChiefAdminFinance();

$createRequestUrl = rtrim(APP_URL, '/') . '/?' . http_build_query([
    'page' => 'requests',
    'action' => 'create',
    'start_date' => $window['start_day'],
    'end_date' => $window['end_day'],
]);

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4 avail-page">
    <div class="mb-4">
        <h4 class="mb-1"><i class="bi bi-calendar3 me-2"></i>Availability</h4>
        <p class="text-muted mb-0">Pick a day or range to see trips and what’s free</p>
    </div>

    <div class="avail-layout">
        <aside class="avail-sidebar">
            <?php require __DIR__ . '/partials/mini_calendar.php'; ?>
        </aside>
        <main class="avail-main">
            <?php require __DIR__ . '/partials/day_panel.php'; ?>
        </main>
    </div>

    <details class="card avail-month-table mt-4" open>
        <summary class="avail-month-summary">
            <span><i class="bi bi-list-ul me-2"></i>All scheduled trips this month</span>
            <span class="text-sm text-muted"><?= count($monthTrips) ?> trip(s)</span>
        </summary>
        <div class="p-0">
            <?php if (empty($monthTrips)): ?>
                <div class="text-center py-5 text-muted">No scheduled trips this month</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Date Range</th>
                                <?php if ($showRequester): ?><th>Requester</th><?php endif; ?>
                                <th>Destination</th>
                                <th>Vehicle</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($monthTrips as $req): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= date('M j', strtotime($req->start_datetime)) ?></div>
                                        <small class="text-muted">
                                            <?= date('g:i A', strtotime($req->start_datetime)) ?> –
                                            <?= date('M j, g:i A', strtotime($req->end_datetime)) ?>
                                        </small>
                                    </td>
                                    <?php if ($showRequester): ?>
                                        <td><?= e($req->requester_name) ?></td>
                                    <?php endif; ?>
                                    <td>
                                        <a href="<?= APP_URL ?>/?page=requests&action=view&id=<?= (int) $req->id ?>">
                                            <?= e($req->destination) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <?php if ($req->plate_number): ?>
                                            <span class="badge bg-primary"><?= e($req->plate_number) ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($req->status === 'approved'): ?>
                                            <span class="badge bg-success">Approved</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">Pending Motorpool</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </details>
</div>

<script>
(function () {
    const grid = document.getElementById('availCalGrid');
    const rangeToggle = document.getElementById('availRangeMode');
    if (!grid) return;

    const params = new URLSearchParams(window.location.search);
    let rangeMode = rangeToggle ? rangeToggle.checked : false;

    function buildUrl(start, end) {
        const url = new URL(window.location.href);
        url.searchParams.set('page', 'schedule');
        url.searchParams.set('action', 'calendar');
        url.searchParams.set('year', '<?= (int) $year ?>');
        url.searchParams.set('month', '<?= (int) $month ?>');
        url.searchParams.set('date', start);
        if (end && end !== start) {
            url.searchParams.set('end_date', end);
        } else {
            url.searchParams.delete('end_date');
        }
        url.searchParams.delete('start_date');
        <?php if ($vehicleTypeFilter): ?>
        url.searchParams.set('vehicle_type', '<?= e((string) $vehicleTypeFilter) ?>');
        <?php endif; ?>
        return url.toString();
    }

    if (rangeToggle) {
        rangeToggle.addEventListener('change', function () {
            rangeMode = this.checked;
            grid.dataset.rangeMode = rangeMode ? '1' : '0';
            if (!rangeMode) {
                const start = params.get('date') || params.get('start_date');
                if (start) window.location.href = buildUrl(start, null);
            }
        });
    }

    grid.addEventListener('click', function (e) {
        const day = e.target.closest('a.avail-cal-day[data-date]');
        if (!day) return;

        const clicked = day.getAttribute('data-date');
        const currentStart = params.get('date') || params.get('start_date') || '';
        const currentEnd = params.get('end_date') || '';
        const useRange = rangeMode || e.shiftKey;

        if (!useRange) return;

        e.preventDefault();

        // Complete a range when we already have a single-day selection
        const canExtend = currentStart && !currentEnd && clicked !== currentStart;
        if (canExtend && (rangeMode || e.shiftKey)) {
            let start = currentStart;
            let end = clicked;
            if (end < start) {
                const tmp = start;
                start = end;
                end = tmp;
            }
            window.location.href = buildUrl(start, end);
            return;
        }

        // Start (or restart) selection
        window.location.href = buildUrl(clicked, null);
    });

    document.querySelectorAll('.avail-tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
            const name = this.getAttribute('data-tab');
            document.querySelectorAll('.avail-tab').forEach(function (t) { t.classList.remove('is-active'); });
            document.querySelectorAll('.avail-section').forEach(function (s) { s.classList.remove('is-active'); });
            this.classList.add('is-active');
            const panel = document.querySelector('.avail-section[data-panel="' + name + '"]');
            if (panel) panel.classList.add('is-active');
        });
    });
})();
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
