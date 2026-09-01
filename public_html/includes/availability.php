<?php
/**
 * Availability / planning helpers for the schedule calendar.
 */

/**
 * Normalize selection window from request (day or range).
 *
 * @return array{start: string, end: string, start_day: string, end_day: string, is_range: bool}
 */
function availabilityParseWindow(?string $date = null, ?string $startDate = null, ?string $endDate = null): array
{
    $today = date('Y-m-d');
    $startDay = $startDate ?: ($date ?: $today);
    $endDay = $endDate ?: $startDay;

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDay)) {
        $startDay = $today;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDay)) {
        $endDay = $startDay;
    }
    if ($endDay < $startDay) {
        [$startDay, $endDay] = [$endDay, $startDay];
    }

    return [
        'start' => $startDay . ' 00:00:00',
        'end' => $endDay . ' 23:59:59',
        'start_day' => $startDay,
        'end_day' => $endDay,
        'is_range' => $startDay !== $endDay,
    ];
}

/**
 * Trips overlapping a month (for calendar grid).
 *
 * @return list<object>
 */
function availabilityTripsForMonth(int $year, int $month): array
{
    $monthStart = sprintf('%04d-%02d-01', $year, $month);
    $monthEnd = date('Y-m-t', strtotime($monthStart));

    return db()->fetchAll(
        "SELECT r.id, r.start_datetime, r.end_datetime, r.destination, r.purpose,
                r.vehicle_id, r.driver_id, r.requested_driver_id, r.status,
                r.actual_arrival_datetime, r.end_datetime AS trip_end,
                u.name AS requester_name, v.plate_number
         FROM requests r
         JOIN users u ON r.user_id = u.id
         LEFT JOIN vehicles v ON r.vehicle_id = v.id AND v.deleted_at IS NULL
         WHERE r.status IN ('approved', 'pending_motorpool')
         AND r.deleted_at IS NULL
         AND r.start_datetime < ?
         AND r.end_datetime > ?
         ORDER BY r.start_datetime ASC",
        [$monthEnd . ' 23:59:59', $monthStart . ' 00:00:00']
    );
}

/**
 * Trips overlapping a selected window.
 *
 * @return list<object>
 */
function availabilityTripsInWindow(string $windowStart, string $windowEnd): array
{
    return db()->fetchAll(
        "SELECT r.id, r.start_datetime, r.end_datetime, r.destination, r.purpose,
                r.vehicle_id, r.driver_id, r.status,
                u.name AS requester_name, v.plate_number,
                du.name AS driver_name
         FROM requests r
         JOIN users u ON r.user_id = u.id
         LEFT JOIN vehicles v ON r.vehicle_id = v.id AND v.deleted_at IS NULL
         LEFT JOIN drivers d ON r.driver_id = d.id AND d.deleted_at IS NULL
         LEFT JOIN users du ON d.user_id = du.id
         WHERE r.status IN ('approved', 'pending_motorpool')
         AND r.deleted_at IS NULL
         AND r.start_datetime < ?
         AND r.end_datetime > ?
         ORDER BY r.start_datetime ASC",
        [$windowEnd, $windowStart]
    );
}

/**
 * Map day-of-month => trips for calendar cells.
 *
 * @param list<object> $trips
 * @return array<int, list<object>>
 */
function availabilityBusyDaysMap(array $trips, int $year, int $month): array
{
    $busy = [];
    foreach ($trips as $req) {
        try {
            $start = new DateTime($req->start_datetime);
            $end = new DateTime($req->end_datetime);
            $end->modify('+1 second');
            $period = new DatePeriod($start, new DateInterval('P1D'), $end);
            foreach ($period as $date) {
                if ((int) $date->format('n') === $month && (int) $date->format('Y') === $year) {
                    $day = (int) $date->format('j');
                    $busy[$day][] = $req;
                }
            }
        } catch (Throwable $e) {
            continue;
        }
    }
    return $busy;
}

/**
 * Fleet vehicles considered for occupancy (not maintenance / out of service).
 *
 * @return list<object>
 */
function availabilityFleetVehicles(): array
{
    return db()->fetchAll(
        "SELECT v.id, v.plate_number, v.make, v.model, v.status, vt.name AS type_name
         FROM vehicles v
         LEFT JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
         WHERE v.deleted_at IS NULL
         AND v.status IN (?, ?)
         ORDER BY v.plate_number ASC",
        [VEHICLE_AVAILABLE, VEHICLE_IN_USE]
    );
}

/**
 * Free vehicles in window (no overlapping booking).
 *
 * @return list<object>
 */
function availabilityFreeVehicles(string $windowStart, string $windowEnd, ?int $vehicleTypeId = null): array
{
    $sql = "SELECT v.id, v.plate_number, v.make, v.model, v.status, vt.name AS type_name
            FROM vehicles v
            LEFT JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
            WHERE v.deleted_at IS NULL
            AND v.status IN (?, ?)
            AND NOT EXISTS (
                SELECT 1 FROM requests r
                WHERE r.vehicle_id = v.id
                AND r.status IN ('approved', 'pending_motorpool')
                AND r.deleted_at IS NULL
                AND r.start_datetime < ?
                AND r.end_datetime > ?
            )";
    $params = [VEHICLE_AVAILABLE, VEHICLE_IN_USE, $windowEnd, $windowStart];

    if ($vehicleTypeId) {
        $sql .= " AND v.vehicle_type_id = ?";
        $params[] = $vehicleTypeId;
    }

    $sql .= " ORDER BY v.plate_number ASC";
    return db()->fetchAll($sql, $params);
}

/**
 * Free drivers in window (active user, not on leave/unavailable, no overlap).
 *
 * @return list<object>
 */
function availabilityFreeDrivers(string $windowStart, string $windowEnd): array
{
    return db()->fetchAll(
        "SELECT d.id, d.status, d.license_number, u.name AS driver_name, u.phone AS driver_phone
         FROM drivers d
         JOIN users u ON d.user_id = u.id
         WHERE d.deleted_at IS NULL
         AND u.deleted_at IS NULL
         AND u.status = 'active'
         AND d.status NOT IN (?, ?)
         AND NOT EXISTS (
             SELECT 1 FROM requests r
             WHERE r.deleted_at IS NULL
             AND r.status IN ('approved', 'pending_motorpool')
             AND r.start_datetime < ?
             AND r.end_datetime > ?
             AND (r.driver_id = d.id OR r.requested_driver_id = d.id)
         )
         ORDER BY u.name ASC",
        [DRIVER_ON_LEAVE, DRIVER_UNAVAILABLE, $windowEnd, $windowStart]
    );
}

/**
 * Distinct vehicle IDs booked among day events.
 *
 * @param list<object> $dayEvents
 */
function availabilityBookedVehicleCount(array $dayEvents): int
{
    $ids = [];
    foreach ($dayEvents as $e) {
        if (!empty($e->vehicle_id)) {
            $ids[(int) $e->vehicle_id] = true;
        }
    }
    return count($ids);
}

function availabilityBuildUrl(array $params): string
{
    $base = [
        'page' => 'schedule',
        'action' => 'calendar',
    ];
    $q = array_filter(array_merge($base, $params), static fn($v) => $v !== null && $v !== '');
    return rtrim(APP_URL, '/') . '/?' . http_build_query($q);
}
