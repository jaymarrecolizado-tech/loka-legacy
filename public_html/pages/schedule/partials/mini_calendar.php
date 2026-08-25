<?php
/**
 * Mini month calendar for Availability planning.
 *
 * Expects: $year, $month, $monthName, $daysInMonth, $startingDay,
 * $busyDays, $totalVehicles, $window, $prevYear, $prevMonth, $nextYear, $nextMonth,
 * $vehicleTypeFilter (optional string/int)
 */
$today = date('Y-m-d');
$selStart = $window['start_day'];
$selEnd = $window['end_day'];
?>
<div class="card avail-mini">
    <div class="avail-mini-nav">
        <a href="<?= e(availabilityBuildUrl([
            'year' => $prevYear,
            'month' => $prevMonth,
            'date' => $selStart,
            'end_date' => $window['is_range'] ? $selEnd : null,
            'vehicle_type' => $vehicleTypeFilter ?: null,
        ])) ?>" class="btn btn-outline-primary btn-sm" aria-label="Previous month">
            <i class="bi bi-chevron-left"></i>
        </a>
        <div class="text-center">
            <div class="avail-mini-title"><?= e($monthName) ?> <?= (int) $year ?></div>
            <div class="avail-mini-sub"><?= (int) $totalVehicles ?> fleet vehicles</div>
        </div>
        <a href="<?= e(availabilityBuildUrl([
            'year' => $nextYear,
            'month' => $nextMonth,
            'date' => $selStart,
            'end_date' => $window['is_range'] ? $selEnd : null,
            'vehicle_type' => $vehicleTypeFilter ?: null,
        ])) ?>" class="btn btn-outline-primary btn-sm" aria-label="Next month">
            <i class="bi bi-chevron-right"></i>
        </a>
    </div>

    <div class="avail-mini-tools">
        <a href="<?= e(availabilityBuildUrl(['vehicle_type' => $vehicleTypeFilter ?: null])) ?>" class="btn btn-secondary btn-sm">
            Today
        </a>
        <label class="avail-range-toggle">
            <input type="checkbox" id="availRangeMode" <?= $window['is_range'] ? 'checked' : '' ?>>
            <span>Select range</span>
        </label>
    </div>

    <div class="avail-cal-grid" id="availCalGrid" data-range-mode="<?= $window['is_range'] ? '1' : '0' ?>">
        <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $dow): ?>
            <div class="avail-cal-dow"><?= $dow ?></div>
        <?php endforeach; ?>

        <?php
        $prevMonthDays = $startingDay - 1;
        $prevMonthLastDay = (int) date('t', mktime(0, 0, 0, $month - 1, 1, $year));
        for ($i = $prevMonthDays; $i > 0; $i--):
            $day = $prevMonthLastDay - $i + 1;
        ?>
            <div class="avail-cal-day is-other" aria-hidden="true">
                <span class="avail-day-num"><?= $day ?></span>
            </div>
        <?php endfor; ?>

        <?php for ($day = 1; $day <= $daysInMonth; $day++):
            $currentDate = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $dayEvents = $busyDays[$day] ?? [];
            $bookedCount = availabilityBookedVehicleCount($dayEvents);
            $isToday = $currentDate === $today;
            $inSelection = $currentDate >= $selStart && $currentDate <= $selEnd;
            $isSelStart = $currentDate === $selStart;
            $isSelEnd = $currentDate === $selEnd;

            $occupancy = 'free';
            if ($totalVehicles > 0) {
                if ($bookedCount >= $totalVehicles) {
                    $occupancy = 'full';
                } elseif ($bookedCount > 0) {
                    $occupancy = 'partial';
                }
            }

            $href = availabilityBuildUrl([
                'year' => $year,
                'month' => $month,
                'date' => $currentDate,
                'vehicle_type' => $vehicleTypeFilter ?: null,
            ]);
            $freeLabel = $totalVehicles > 0
                ? ($totalVehicles - $bookedCount) . '/' . $totalVehicles
                : '—';
            $classes = 'avail-cal-day is-' . $occupancy;
            if ($isToday) {
                $classes .= ' is-today';
            }
            if ($inSelection) {
                $classes .= ' is-selected';
            }
            if ($isSelStart) {
                $classes .= ' is-sel-start';
            }
            if ($isSelEnd && $window['is_range']) {
                $classes .= ' is-sel-end';
            }
        ?>
            <a href="<?= e($href) ?>"
               class="<?= e($classes) ?>"
               data-date="<?= e($currentDate) ?>"
               title="<?= e($currentDate) ?> · <?= count($dayEvents) ?> trip(s) · <?= e($freeLabel) ?> free">
                <span class="avail-day-num"><?= $day ?></span>
                <span class="avail-day-free"><?= e($freeLabel) ?></span>
                <?php if (count($dayEvents) > 0): ?>
                    <span class="avail-day-dots" aria-hidden="true">
                        <?php for ($d = 0; $d < min(3, count($dayEvents)); $d++): ?>
                            <i></i>
                        <?php endfor; ?>
                    </span>
                <?php endif; ?>
            </a>
        <?php endfor; ?>

        <?php
        $totalCells = $prevMonthDays + $daysInMonth;
        $nextMonthDays = (7 - ($totalCells % 7)) % 7;
        for ($day = 1; $day <= $nextMonthDays; $day++):
        ?>
            <div class="avail-cal-day is-other" aria-hidden="true">
                <span class="avail-day-num"><?= $day ?></span>
            </div>
        <?php endfor; ?>
    </div>

    <div class="avail-legend">
        <span><i class="avail-dot free"></i> Available</span>
        <span><i class="avail-dot partial"></i> Partial</span>
        <span><i class="avail-dot full"></i> Full</span>
    </div>
    <p class="avail-hint">Tip: turn on <strong>Select range</strong>, then click start and end days. Or Shift+click a second day.</p>
</div>
