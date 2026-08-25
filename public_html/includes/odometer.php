<?php
/**
 * LOKA — Guard / trip odometer helpers
 */

/** Known plates with permanently broken / unreadable odometers (fallback if DB flag missing). */
define('ODOMETER_BROKEN_PLATES_DEFAULT', [
    'SDF424',
    'SBY225',
    'SJN940',
]);

function normalizePlateNumber(?string $plate): string
{
    return strtoupper((string) preg_replace('/\s+/', '', (string) $plate));
}

/** Parse optional posted mileage (empty → null; avoids postInt defaulting to 0). */
function guardPostedMileage(string $key): ?int
{
    $raw = post($key);
    if ($raw === null || $raw === '') {
        return null;
    }
    return (int) $raw;
}

function guardAppendNotes(?string $existing, ?string $extra): ?string
{
    $extra = trim((string) $extra);
    if ($extra === '') {
        return $existing;
    }
    $existing = trim((string) $existing);
    return $existing === '' ? $extra : ($existing . "\n" . $extra);
}

/**
 * Rebuild settings.odometer_broken_plates from vehicles.odometer_broken flags.
 */
function syncOdometerBrokenPlatesSetting(): void
{
    $rows = db()->fetchAll(
        "SELECT plate_number FROM vehicles
         WHERE deleted_at IS NULL AND COALESCE(odometer_broken, 0) = 1
         ORDER BY plate_number ASC"
    );
    $plates = [];
    foreach ($rows as $row) {
        $n = normalizePlateNumber($row->plate_number ?? '');
        if ($n !== '') {
            $plates[] = $n;
        }
    }
    $value = implode(',', array_values(array_unique($plates)));
    $now = date(DATETIME_FORMAT);
    $existing = db()->fetch("SELECT id FROM settings WHERE `key` = 'odometer_broken_plates' LIMIT 1");
    if ($existing) {
        db()->query(
            "UPDATE settings SET value = ?, updated_at = ? WHERE `key` = 'odometer_broken_plates'",
            [$value, $now]
        );
    } else {
        try {
            db()->insert('settings', [
                'key' => 'odometer_broken_plates',
                'value' => $value,
                'type' => 'string',
                'category' => 'fleet',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (Throwable $e) {
            db()->insert('settings', [
                'key' => 'odometer_broken_plates',
                'value' => $value,
                'type' => 'string',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}

/**
 * Whether this vehicle is known to have a broken odometer.
 * Prefers vehicles.odometer_broken when present; otherwise plate allowlist.
 */
function vehicleOdometerIsBroken(?object $vehicle = null, ?string $plate = null): bool
{
    if ($vehicle !== null && property_exists($vehicle, 'odometer_broken') && $vehicle->odometer_broken !== null) {
        return (int) $vehicle->odometer_broken === 1;
    }

    $normalized = normalizePlateNumber($plate ?? ($vehicle->plate_number ?? ''));
    if ($normalized === '') {
        return false;
    }

    $list = ODOMETER_BROKEN_PLATES_DEFAULT;
    try {
        $row = db()->fetch("SELECT value FROM settings WHERE `key` = 'odometer_broken_plates' LIMIT 1");
        if ($row && trim((string) $row->value) !== '') {
            $fromSettings = array_filter(array_map(
                static fn($p) => normalizePlateNumber($p),
                explode(',', (string) $row->value)
            ));
            if (!empty($fromSettings)) {
                $list = array_values($fromSettings);
            }
        }
    } catch (Throwable $e) {
        // use defaults
    }

    return in_array($normalized, $list, true);
}

/**
 * Validate guard odometer input for dispatch/arrival.
 *
 * @return array{ok:bool,error:?string,mileage:?int,broken:bool,note:?string}
 */
function guardResolveOdometerReading(
    string $phase,
    ?int $postedMileage,
    bool $brokenChecked,
    bool $vehicleBrokenKnown,
    ?int $baselineMileage = null
): array {
    $broken = $brokenChecked || $vehicleBrokenKnown;
    $label = $phase === 'dispatch' ? 'starting' : 'ending';

    if ($broken) {
        return [
            'ok' => true,
            'error' => null,
            'mileage' => null,
            'broken' => true,
            'note' => $phase === 'dispatch'
                ? '[Dispatch] Odometer broken/unreadable — starting reading skipped (driver reminded).'
                : '[Arrival] Odometer broken/unreadable — ending reading skipped (driver reminded).',
        ];
    }

    if ($postedMileage === null || $postedMileage < 0) {
        return [
            'ok' => false,
            'error' => "Please enter the {$label} odometer reading, or check “Odometer broken” to continue.",
            'mileage' => null,
            'broken' => false,
            'note' => null,
        ];
    }

    if ($phase === 'arrival' && $baselineMileage !== null && $postedMileage < $baselineMileage) {
        return [
            'ok' => false,
            'error' => "Ending mileage ({$postedMileage}) cannot be less than starting mileage ({$baselineMileage}).",
            'mileage' => null,
            'broken' => false,
            'note' => null,
        ];
    }

    if ($phase === 'dispatch' && $baselineMileage !== null && $postedMileage < $baselineMileage) {
        return [
            'ok' => false,
            'error' => "Starting mileage ({$postedMileage}) cannot be less than the vehicle's current mileage ({$baselineMileage}).",
            'mileage' => null,
            'broken' => false,
            'note' => null,
        ];
    }

    return [
        'ok' => true,
        'error' => null,
        'mileage' => $postedMileage,
        'broken' => false,
        'note' => null,
    ];
}
