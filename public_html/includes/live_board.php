<?php
/**
 * Live Trip Board (Plan #17) — read-only queries over requests + fleet.
 * Guard dispatch/arrival remains the source of truth.
 */

function liveBoardOperationalWhere(): string
{
    return "r.status = 'approved'
        AND r.deleted_at IS NULL
        AND r.actual_arrival_datetime IS NULL
        AND (
            r.actual_dispatch_datetime IS NOT NULL
            OR (r.actual_dispatch_datetime IS NULL AND r.end_datetime >= CURDATE())
        )";
}

/**
 * Today's operational set: approved, not arrived; dispatched OR not-yet-dispatched with end today/future.
 *
 * @return list<object>
 */
function liveBoardFetchRows(): array
{
    return db()->fetchAll(
        "SELECT r.id, r.destination, r.passenger_count,
                r.start_datetime, r.end_datetime,
                r.actual_dispatch_datetime, r.actual_arrival_datetime,
                v.plate_number,
                driver_user.name as driver_name
         FROM requests r
         LEFT JOIN vehicles v ON r.vehicle_id = v.id AND v.deleted_at IS NULL
         LEFT JOIN drivers dr ON r.driver_id = dr.id AND dr.deleted_at IS NULL
         LEFT JOIN users driver_user ON dr.user_id = driver_user.id
         WHERE " . liveBoardOperationalWhere() . "
         ORDER BY
            CASE
                WHEN r.actual_dispatch_datetime IS NOT NULL AND r.end_datetime < NOW() THEN 0
                WHEN r.actual_dispatch_datetime IS NOT NULL AND r.end_datetime >= NOW()
                     AND r.end_datetime <= DATE_ADD(NOW(), INTERVAL 1 HOUR) THEN 1
                WHEN r.actual_dispatch_datetime IS NOT NULL THEN 2
                ELSE 3
            END ASC,
            r.end_datetime ASC"
    );
}

function liveBoardClassify(object $row, int $now): string
{
    $dispatched = !empty($row->actual_dispatch_datetime);
    $end = !empty($row->end_datetime) ? (int) strtotime($row->end_datetime) : 0;
    if (!$dispatched) {
        return 'not_dispatched';
    }
    if ($end > 0 && $end < $now) {
        return 'overdue';
    }
    if ($end > 0 && $end <= $now + 3600) {
        return 'due_soon';
    }
    return 'on_trip';
}

function liveBoardDurationLabel(int $seconds): string
{
    $seconds = abs($seconds);
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    if ($hours > 0) {
        return $hours . 'h ' . $minutes . 'm';
    }
    return $minutes > 0 ? $minutes . 'm' : '<1m';
}

function liveBoardTiming(object $row, int $now): string
{
    $end = !empty($row->end_datetime) ? (int) strtotime($row->end_datetime) : 0;
    if ($end <= 0) {
        return '—';
    }
    $diff = $end - $now;
    if ($diff < 0) {
        return 'Late by ' . liveBoardDurationLabel($diff);
    }
    return liveBoardDurationLabel($diff) . ' left';
}

function liveBoardAvailableVehicleCount(): int
{
    return (int) db()->fetchColumn(
        "SELECT COUNT(*) FROM vehicles v
         WHERE v.status = 'available' AND v.deleted_at IS NULL
         AND NOT EXISTS (
             SELECT 1 FROM requests r
             WHERE r.vehicle_id = v.id
             AND r.status = 'approved'
             AND r.actual_dispatch_datetime IS NOT NULL
             AND r.actual_arrival_datetime IS NULL
             AND r.deleted_at IS NULL
         )"
    );
}

function liveBoardMatchesSearch(object $row, string $q): bool
{
    $q = strtolower($q);
    $hay = strtolower(trim(implode(' ', [
        (string) $row->id,
        (string) ($row->plate_number ?? ''),
        (string) ($row->driver_name ?? ''),
    ])));
    return $q === '' || str_contains($hay, $q);
}

/**
 * @return array{label: string, class: string}
 */
function liveBoardChip(string $status): array
{
    switch ($status) {
        case 'overdue':
            return ['label' => 'Overdue', 'class' => 'bg-danger'];
        case 'due_soon':
            return ['label' => 'Due soon', 'class' => 'bg-warning text-dark'];
        case 'on_trip':
            return ['label' => 'On trip', 'class' => 'bg-info text-dark'];
        default:
            return ['label' => 'Approved not dispatched', 'class' => 'bg-secondary'];
    }
}
