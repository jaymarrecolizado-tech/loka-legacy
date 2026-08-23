<?php
/**
 * LOKA - Vehicle Availability Calendar
 * Shows approved requests to help users plan their trips
 */

$pageTitle = 'Vehicle Availability';

// Get current month/year from URL or default to current
$year = (int) get('year', date('Y'));
$month = (int) get('month', date('n'));

// Validate and adjust month/year
if ($month < 1) {
    $month = 12;
    $year--;
} elseif ($month > 12) {
    $month = 1;
    $year++;
}

$firstDay = mktime(0, 0, 0, $month, 1, $year);
$daysInMonth = date('t', $firstDay);
$startingDay = date('N', $firstDay); // 1=Mon, 7=Sun
$monthName = date('F', $firstDay);

// Get all vehicles
$vehicles = db()->fetchAll(
    "SELECT id, plate_number, make, model FROM vehicles WHERE status != 'retired' AND deleted_at IS NULL ORDER BY plate_number"
);

// Get approved requests for this month (and overlapping from previous/next)
$monthStart = date('Y-m-01', $firstDay);
$monthEnd = date('Y-m-t', $firstDay);

$approvedRequests = db()->fetchAll(
    "SELECT r.id, r.start_datetime, r.end_datetime, r.destination, r.purpose,
            r.vehicle_id, u.name as requester_name, v.plate_number
     FROM requests r
     JOIN users u ON r.user_id = u.id
     LEFT JOIN vehicles v ON r.vehicle_id = v.id AND v.deleted_at IS NULL
     WHERE r.status IN ('approved', 'pending_motorpool')
     AND r.deleted_at IS NULL
     AND (
         (r.start_datetime BETWEEN ? AND ?)
         OR (r.end_datetime BETWEEN ? AND ?)
         OR (r.start_datetime <= ? AND r.end_datetime >= ?)
     )
     ORDER BY r.start_datetime",
    [$monthStart, $monthEnd . ' 23:59:59', $monthStart, $monthEnd . ' 23:59:59', $monthStart, $monthEnd]
);

// Build a map of busy days
$busyDays = [];
foreach ($approvedRequests as $req) {
    $start = new DateTime($req->start_datetime);
    $end = new DateTime($req->end_datetime);
    
    $period = new DatePeriod($start, new DateInterval('P1D'), $end->modify('+1 day'));
    
    foreach ($period as $date) {
        $day = (int) $date->format('j');
        $dateMonth = (int) $date->format('n');
        $dateYear = (int) $date->format('Y');
        
        if ($dateMonth == $month && $dateYear == $year) {
            if (!isset($busyDays[$day])) {
                $busyDays[$day] = [];
            }
            $busyDays[$day][] = $req;
        }
    }
}

// Calculate vehicle availability per day
$totalVehicles = count($vehicles);

// Navigation URLs
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

require_once INCLUDES_PATH . '/header.php';
?>

<style>
.calendar-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 1px;
    background: #dee2e6;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    overflow: hidden;
}
.calendar-header {
    background: #0d6efd;
    color: white;
    padding: 12px 8px;
    text-align: center;
    font-weight: 600;
    font-size: 0.85rem;
}
.calendar-day {
    background: white;
    min-height: 100px;
    padding: 8px;
    position: relative;
    transition: all 0.2s;
}
.calendar-day:hover {
    background: #f8f9fa;
}
.calendar-day.other-month {
    background: #f8f9fa;
    color: #adb5bd;
}
.calendar-day.today {
    background: #e7f1ff;
}
.calendar-day.today .day-number {
    background: #0d6efd;
    color: white;
}
.day-number {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    font-weight: 600;
    font-size: 0.9rem;
    margin-bottom: 4px;
}
.day-events {
    font-size: 0.75rem;
}
.event-pill {
    display: block;
    padding: 2px 6px;
    margin-bottom: 2px;
    border-radius: 4px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    cursor: pointer;
    text-decoration: none;
}
.event-pill.approved {
    background: #d1e7dd;
    color: #0f5132;
}
.event-pill.pending {
    background: #fff3cd;
    color: #664d03;
}
.event-pill:hover {
    opacity: 0.8;
}
.availability-badge {
    position: absolute;
    top: 8px;
    right: 8px;
    font-size: 0.65rem;
    padding: 2px 6px;
}
.legend-item {
    display: inline-flex;
    align-items: center;
    margin-right: 15px;
    font-size: 0.85rem;
}
.legend-dot {
    width: 12px;
    height: 12px;
    border-radius: 3px;
    margin-right: 6px;
}
.calendar-day.fully-booked {
    background: #fff5f5;
}
.calendar-day.partially-booked {
    background: #fffbeb;
}
</style>

<div class="container-fluid py-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-calendar3 me-2"></i>Vehicle Availability Calendar</h4>
            <p class="text-muted mb-0">View scheduled trips to plan your requests</p>
        </div>
        <a href="<?= APP_URL ?>/?page=requests&action=create" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>New Request
        </a>
    </div>
    
    <!-- Calendar Navigation -->
    <div class="card mb-4">
        <div class="card-body py-3">
            <div class="d-flex justify-content-between align-items-center">
                <a href="<?= APP_URL ?>/?page=schedule&action=calendar&year=<?= $prevYear ?>&month=<?= $prevMonth ?>" 
                   class="btn btn-outline-primary">
                    <i class="bi bi-chevron-left me-1"></i>Previous
                </a>
                
                <div class="text-center">
                    <h3 class="mb-0"><?= $monthName ?> <?= $year ?></h3>
                    <small class="text-muted"><?= $totalVehicles ?> vehicles in fleet</small>
                </div>
                
                <a href="<?= APP_URL ?>/?page=schedule&action=calendar&year=<?= $nextYear ?>&month=<?= $nextMonth ?>" 
                   class="btn btn-outline-primary">
                    Next<i class="bi bi-chevron-right ms-1"></i>
                </a>
            </div>
            
            <!-- Quick Jump -->
            <div class="text-center mt-3">
                <a href="<?= APP_URL ?>/?page=schedule&action=calendar" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-calendar-event me-1"></i>Today
                </a>
            </div>
        </div>
    </div>
    
    <!-- Legend -->
    <div class="mb-3 p-3 bg-light rounded">
        <div class="legend-item">
            <span class="legend-dot bg-success"></span>
            <span>Available</span>
        </div>
        <div class="legend-item">
            <span class="legend-dot" style="background: #fff3cd;"></span>
            <span>Partially Booked</span>
        </div>
        <div class="legend-item">
            <span class="legend-dot" style="background: #f8d7da;"></span>
            <span>Fully Booked</span>
        </div>
        <div class="legend-item">
            <span class="legend-dot" style="background: #d1e7dd;"></span>
            <span>Approved Trip</span>
        </div>
        <div class="legend-item">
            <span class="legend-dot" style="background: #fff3cd;"></span>
            <span>Pending Motorpool</span>
        </div>
    </div>
    
    <!-- Calendar Grid -->
    <div class="calendar-grid">
        <!-- Headers -->
        <div class="calendar-header">Mon</div>
        <div class="calendar-header">Tue</div>
        <div class="calendar-header">Wed</div>
        <div class="calendar-header">Thu</div>
        <div class="calendar-header">Fri</div>
        <div class="calendar-header">Sat</div>
        <div class="calendar-header">Sun</div>
        
        <?php
        // Previous month days
        $prevMonthDays = $startingDay - 1;
        $prevMonthLastDay = date('t', mktime(0, 0, 0, $month - 1, 1, $year));
        
        for ($i = $prevMonthDays; $i > 0; $i--):
            $day = $prevMonthLastDay - $i + 1;
        ?>
        <div class="calendar-day other-month">
            <span class="day-number"><?= $day ?></span>
        </div>
        <?php endfor; ?>
        
        <?php
        // Current month days
        $today = date('Y-m-d');
        for ($day = 1; $day <= $daysInMonth; $day++):
            $currentDate = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $isToday = $currentDate === $today;
            $dayEvents = $busyDays[$day] ?? [];
            $bookedCount = count(array_unique(array_map(fn($e) => $e->vehicle_id, $dayEvents)));
            
            $dayClass = 'calendar-day';
            if ($isToday) $dayClass .= ' today';
            if ($bookedCount > 0 && $bookedCount < $totalVehicles) $dayClass .= ' partially-booked';
            if ($bookedCount >= $totalVehicles) $dayClass .= ' fully-booked';
        ?>
        <div class="<?= $dayClass ?>">
            <span class="day-number"><?= $day ?></span>
            
            <?php if ($totalVehicles > 0): ?>
            <span class="badge availability-badge <?= $bookedCount >= $totalVehicles ? 'bg-danger' : ($bookedCount > 0 ? 'bg-warning text-dark' : 'bg-success') ?>">
                <?= $totalVehicles - $bookedCount ?>/<?= $totalVehicles ?> free
            </span>
            <?php endif; ?>
            
            <div class="day-events">
                <?php 
                $shown = 0;
                foreach ($dayEvents as $event): 
                    if ($shown >= 3) {
                        echo '<small class="text-muted">+' . (count($dayEvents) - 3) . ' more</small>';
                        break;
                    }
                    $shown++;
                    $eventClass = $event->vehicle_id ? 'approved' : 'pending';
                ?>
                <a href="<?= APP_URL ?>/?page=requests&action=view&id=<?= $event->id ?>" 
                   class="event-pill <?= $eventClass ?>" 
                   title="<?= e($event->requester_name) ?>: <?= e($event->destination) ?>">
                    <?= e($event->plate_number ?: 'TBA') ?> - <?= e(substr($event->destination, 0, 15)) ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endfor; ?>
        
        <?php
        // Next month days
        $totalCells = $prevMonthDays + $daysInMonth;
        $nextMonthDays = (7 - ($totalCells % 7)) % 7;
        
        for ($day = 1; $day <= $nextMonthDays; $day++):
        ?>
        <div class="calendar-day other-month">
            <span class="day-number"><?= $day ?></span>
        </div>
        <?php endfor; ?>
    </div>
    
    <!-- Upcoming Trips List -->
    <div class="card mt-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>Scheduled Trips This Month</h5>
        </div>
        <div class="card-body p-0">
            <?php if (empty($approvedRequests)): ?>
            <div class="text-center py-4 text-muted">
                <i class="bi bi-calendar-check fs-1 d-block mb-2"></i>
                No scheduled trips this month
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Date Range</th>
                            <th>Requester</th>
                            <th>Destination</th>
                            <th>Vehicle</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($approvedRequests as $req): ?>
                        <tr>
                            <td>
                                <div class="fw-medium"><?= date('M j', strtotime($req->start_datetime)) ?></div>
                                <small class="text-muted">
                                    <?= date('g:i A', strtotime($req->start_datetime)) ?> - 
                                    <?= date('M j, g:i A', strtotime($req->end_datetime)) ?>
                                </small>
                            </td>
                            <td><?= e($req->requester_name) ?></td>
                            <td><?= e($req->destination) ?></td>
                            <td>
                                <?php if ($req->plate_number): ?>
                                <span class="badge bg-primary"><?= e($req->plate_number) ?></span>
                                <?php else: ?>
                                <span class="badge bg-secondary">Pending Assignment</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($req->vehicle_id): ?>
                                <span class="badge bg-success">Approved</span>
                                <?php else: ?>
                                <span class="badge bg-warning text-dark">Pending Motorpool</span>
                                <?php endif; ?>
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

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
