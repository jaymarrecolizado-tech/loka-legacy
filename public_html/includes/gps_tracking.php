<?php
/**
 * LOKA - Driver-phone GPS tracking helpers (Plan #41, experimental)
 *
 * Ships OFF (`gps_tracking_enabled`). Scope in v1 (locked decisions):
 *  - DICT fleet vehicle trips only (an assigned driver on an approved and
 *    dispatched request). Private-vehicle OB cars are NOT tracked.
 *  - The tracking window opens on guard Dispatch and closes on guard Arrival
 *    (or completion/cancellation). No driver Start/Stop, no pause.
 *  - Exact coordinates are visible to Motorpool Head, Admin and All Father only.
 *  - Foreground browser/PWA geolocation; the map shows the last-seen position when
 *    the driver closes the page.
 *
 * Consumers must require this file themselves.
 */

if (!defined('GPS_TRACKING_LOADED')) {

    define('GPS_TRACKING_LOADED', 1);

    /** Plan #41 decision 5: back off when idle or the fix is poor. */
    define('GPS_MIN_PING_INTERVAL_SECONDS', 45);
    define('GPS_POOR_ACCURACY_METERS', 200);
    define('GPS_RETENTION_DAYS', 30);

    function gpsTrackingEnabled(): bool
    {
        static $cached = null;
        if ($cached === null) {
            try {
                $row = db()->fetch("SELECT value FROM settings WHERE `key` = 'gps_tracking_enabled'");
                $cached = ($row && (string) $row->value === '1');
            } catch (Throwable $e) {
                error_log('gpsTrackingEnabled: ' . $e->getMessage());
                $cached = false;
            }
        }
        return $cached;
    }

    function gpsTrackingSetEnabled(bool $on): void
    {
        db()->query(
            "INSERT INTO settings (`key`, value, type, category, created_at, updated_at)
             VALUES ('gps_tracking_enabled', ?, 'boolean', 'experimental', NOW(), NOW())
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()",
            [$on ? '1' : '0']
        );
        auditLog('gps_tracking_toggled', 'settings', null, null, ['gps_tracking_enabled' => $on ? '1' : '0']);
    }

    /** Plan #41 decision 4: Motorpool Head + Admin + All Father only. */
    function canViewGpsTracking(): bool
    {
        return isMotorpool() || isAdmin();
    }

    /**
     * Is the tracking window open for this request?
     *
     * Open while the trip is `approved` and the guard has recorded a dispatch,
     * and still no arrival / completion / cancellation.
     */
    function gpsTrackingWindowOpen(object $request): bool
    {
        return $request->status === STATUS_APPROVED
            && !empty($request->actual_dispatch_datetime)
            && empty($request->actual_arrival_datetime);
    }

    /**
     * The request the signed-in driver may currently share location for.
     * Only the assigned driver of a live, dispatched fleet trip qualifies.
     */
    function gpsTrackingCurrentTripForDriver(?int $driverRecordId = null): ?object
    {
        $driverRecordId ??= currentDriverId();
        if (!$driverRecordId) {
            return null;
        }
        return db()->fetch(
            "SELECT r.*, v.plate_number, u.name AS driver_name
             FROM requests r
             JOIN drivers d ON d.id = r.driver_id
             JOIN users u ON u.id = d.user_id
             LEFT JOIN vehicles v ON v.id = r.vehicle_id
             WHERE r.driver_id = ? AND r.deleted_at IS NULL
               AND r.status = ?
               AND r.actual_dispatch_datetime IS NOT NULL
               AND r.actual_arrival_datetime IS NULL
               AND r.vehicle_id IS NOT NULL
             ORDER BY r.actual_dispatch_datetime DESC
             LIMIT 1",
            [$driverRecordId, STATUS_APPROVED]
        );
    }

    /**
     * Validate + record one ping.
     *
     * @return array{ok:bool, reason:string, id:?int}
     */
    function gpsRecordPing(int $requestId, int $driverUserId, array $fix): array
    {
        $lat = $fix['lat'] ?? null;
        $lng = $fix['lng'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return ['ok' => false, 'reason' => 'lat/lng are required', 'id' => null];
        }
        $lat = (float) $lat;
        $lng = (float) $lng;
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return ['ok' => false, 'reason' => 'coordinates out of range', 'id' => null];
        }

        $now = date(DATETIME_FORMAT);

        // Rate-limit per trip: one point every GPS_MIN_PING_INTERVAL_SECONDS.
        // Measured on recorded_at (the device clock of the fix) so a queued
        // backfill flushed after an offline gap is not dropped merely for
        // arriving back-to-back.
        $incomingTs = is_numeric($fix['recorded_at'] ?? null) ? (int) $fix['recorded_at'] : strtotime($now);
        $last = db()->fetch(
            "SELECT COALESCE(recorded_at, received_at) AS ts FROM trip_gps_points WHERE request_id = ? ORDER BY id DESC LIMIT 1",
            [$requestId]
        );
        if ($last) {
            $gap = $incomingTs - strtotime((string) $last->ts);
            if ($gap < GPS_MIN_PING_INTERVAL_SECONDS) {
                return ['ok' => false, 'reason' => 'too frequent', 'id' => null];
            }
        }

        $accuracy = isset($fix['accuracy']) && is_numeric($fix['accuracy']) ? round((float) $fix['accuracy'], 2) : null;
        // Plan #41 decision 5: drop fixes the device itself flags as poor.
        if ($accuracy !== null && $accuracy > GPS_POOR_ACCURACY_METERS) {
            return ['ok' => false, 'reason' => 'accuracy too poor', 'id' => null];
        }

        $id = db()->insert('trip_gps_points', [
            'request_id' => $requestId,
            'driver_user_id' => $driverUserId,
            'lat' => $lat,
            'lng' => $lng,
            'accuracy_m' => $accuracy,
            'speed_mps' => isset($fix['speed']) && is_numeric($fix['speed']) ? round((float) $fix['speed'], 2) : null,
            'heading_deg' => isset($fix['heading']) && is_numeric($fix['heading']) ? round((float) $fix['heading'], 2) : null,
            'recorded_at' => is_numeric($fix['recorded_at'] ?? null)
                ? date(DATETIME_FORMAT, (int) $fix['recorded_at'])
                : $now,
            'received_at' => $now,
            'created_at' => $now,
        ]);

        return ['ok' => true, 'reason' => '', 'id' => $id];
    }

    /**
     * Breadcrumb for a trip, oldest first.
     *
     * @return list<object>
     */
    function gpsTrail(int $requestId, int $limit = 500): array
    {
        // Take the newest $limit points, then present them OLDEST-first: the
        // SVG renderer treats the last element as the current (red) marker.
        $rows = db()->fetchAll(
            "SELECT lat, lng, accuracy_m, recorded_at, received_at
             FROM trip_gps_points
             WHERE request_id = ?
             ORDER BY id DESC
             LIMIT " . max(1, min(2000, $limit)),
            [$requestId]
        );
        return array_reverse($rows);
    }

    /**
     * Last known fix for each of the given requests.
     *
     * @param  list<int> $requestIds
     * @return array<int, object> keyed by request id
     */
    function gpsLastSeen(array $requestIds): array
    {
        $requestIds = array_values(array_unique(array_map('intval', $requestIds)));
        if ($requestIds === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($requestIds), '?'));
        $rows = db()->fetchAll(
            "SELECT p.request_id, p.lat, p.lng, p.accuracy_m, p.recorded_at, p.received_at
             FROM trip_gps_points p
             JOIN (
                 SELECT request_id, MAX(id) AS max_id
                 FROM trip_gps_points
                 WHERE request_id IN ({$ph})
                 GROUP BY request_id
             ) latest ON latest.max_id = p.id",
            $requestIds
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->request_id] = $r;
        }
        return $out;
    }

    /** How stale a last-seen fix is, in seconds. */
    function gpsLastSeenAge(object $fix): ?int
    {
        $ts = strtotime((string) ($fix->received_at ?: $fix->recorded_at));
        return $ts === false ? null : max(0, time() - $ts);
    }

    /**
     * Inline SVG of the trail. Deliberately tile-free: rendering the breadcrumb
     * on a bare grid keeps the coordinates on this server instead of leaking the
     * vehicle's area to a third-party tile provider.
     */
    function gpsTrailSvg(array $points, int $width = 320, int $height = 220): string
    {
        if (count($points) < 1) {
            return '';
        }
        $lats = array_map(static fn($p) => (float) $p->lat, $points);
        $lngs = array_map(static fn($p) => (float) $p->lng, $points);

        $minLat = min($lats);
        $maxLat = max($lats);
        $minLng = min($lngs);
        $maxLng = max($lngs);
        // Degenerate bounds (a stationary vehicle) would divide by zero.
        if ($maxLat - $minLat < 1e-6) {
            $minLat -= 0.001;
            $maxLat += 0.001;
        }
        if ($maxLng - $minLng < 1e-6) {
            $minLng -= 0.001;
            $maxLng += 0.001;
        }

        $pad = 12;
        $toX = static fn(float $lng): float => $pad + (($lng - $minLng) / ($maxLng - $minLng)) * ($width - 2 * $pad);
        $toY = static fn(float $lat): float => $height - $pad - (($lat - $minLat) / ($maxLat - $minLat)) * ($height - 2 * $pad);

        $d = '';
        foreach ($points as $i => $p) {
            $cmd = ($i === 0 ? 'M' : 'L') . round($toX((float) $p->lng), 1) . ' ' . round($toY((float) $p->lat), 1);
            $d .= $cmd . ' ';
        }
        $last = $points[count($points) - 1];
        $lx = round($toX((float) $last->lng), 1);
        $ly = round($toY((float) $last->lat), 1);

        $html = '<svg viewBox="0 0 ' . $width . ' ' . $height . '" width="100%" height="' . $height
            . '" role="img" aria-label="Breadcrumb trail of ' . count($points) . ' recorded positions">';
        $html .= '<rect x="0" y="0" width="' . $width . '" height="' . $height . '" fill="#f8f9fa" stroke="#dee2e6"/>';
        $html .= '<path d="' . trim($d) . '" fill="none" stroke="#0d6efd" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>';
        $html .= '<circle cx="' . $lx . '" cy="' . $ly . '" r="5" fill="#dc3545" stroke="#fff" stroke-width="2"/>';
        $html .= '<text x="6" y="' . ($height - 6) . '" font-size="9" fill="#6c757d">'
            . count($points) . ' point(s) · not to scale</text>';
        $html .= '</svg>';
        return $html;
    }

    /**
     * Purge points older than the retention window.
     *
     * @return array{purged:int}
     */
    function gpsPurgeOldPoints(int $retentionDays = GPS_RETENTION_DAYS): array
    {
        $days = max(1, min(365, $retentionDays));
        $stmt = db()->query(
            "DELETE FROM trip_gps_points WHERE received_at < DATE_SUB(NOW(), INTERVAL {$days} DAY)"
        );
        return ['purged' => $stmt->rowCount()];
    }
}