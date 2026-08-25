<?php
/**
 * Guard vehicle observation helpers (dispatch / arrival condition + photos).
 */

const OBSERVATION_FLAG_KEYS = [
    'exterior_damage',
    'interior_damage',
    'tire_issue',
    'lights_issue',
    'fuel_low',
    'unclean',
    'missing_items',
    'other',
];

/**
 * @return list<object>
 */
function observationGetForRequest(int $requestId): array
{
    return db()->fetchAll(
        "SELECT o.*, u.name AS guard_name
         FROM vehicle_observations o
         LEFT JOIN users u ON o.guard_id = u.id
         WHERE o.request_id = ?
         ORDER BY FIELD(o.phase, 'dispatch', 'arrival'), o.id ASC",
        [$requestId]
    );
}

/**
 * @return list<object>
 */
function observationPhotos(int $observationId): array
{
    return db()->fetchAll(
        "SELECT * FROM vehicle_observation_photos
         WHERE observation_id = ?
         ORDER BY sort_order ASC, id ASC",
        [$observationId]
    );
}

/**
 * Parse checklist flags from POST.
 *
 * @return array<string, bool>
 */
function observationParseFlagsFromPost(): array
{
    $raw = post('condition_flags', []);
    if (!is_array($raw)) {
        $raw = [];
    }
    $flags = [];
    foreach (OBSERVATION_FLAG_KEYS as $key) {
        $flags[$key] = !empty($raw[$key]);
    }
    return $flags;
}

/**
 * Save observation + compressed photos for a request phase.
 *
 * @return array{ok: bool, error?: string, observation_id?: int}
 */
function observationSaveForPhase(
    int $requestId,
    ?int $vehicleId,
    string $phase,
    int $guardId,
    string $overallCondition,
    array $flags,
    string $notes,
    ?int $mileageReading,
    array $filesField
): array {
    if (!in_array($phase, ['dispatch', 'arrival'], true)) {
        return ['ok' => false, 'error' => 'Invalid observation phase.'];
    }

    $allowedConditions = ['good', 'fair', 'poor', 'damaged'];
    if (!in_array($overallCondition, $allowedConditions, true)) {
        return ['ok' => false, 'error' => 'Please select overall vehicle condition.'];
    }

    $notes = mb_substr(trim($notes), 0, 1000);
    if ($overallCondition === 'damaged' && $notes === '') {
        return ['ok' => false, 'error' => 'Notes are required when condition is damaged.'];
    }

    $fileList = observationNormalizeFiles($filesField);
    if (count($fileList) < 1) {
        return ['ok' => false, 'error' => 'At least one vehicle photo is required.'];
    }
    if (count($fileList) > 6) {
        return ['ok' => false, 'error' => 'Maximum 6 photos allowed.'];
    }

    $existing = db()->fetch(
        "SELECT id FROM vehicle_observations WHERE request_id = ? AND phase = ?",
        [$requestId, $phase]
    );
    if ($existing) {
        return ['ok' => false, 'error' => 'An observation for this phase already exists.'];
    }

    $now = date(DATETIME_FORMAT);
    $fullMax = ($overallCondition === 'damaged') ? 1920 : 1600;

    try {
        db()->beginTransaction();

        $obsId = db()->insert('vehicle_observations', [
            'request_id' => $requestId,
            'vehicle_id' => $vehicleId,
            'phase' => $phase,
            'guard_id' => $guardId,
            'overall_condition' => $overallCondition,
            'flags_json' => json_encode($flags),
            'notes' => $notes !== '' ? $notes : null,
            'mileage_reading' => $mileageReading,
            'observed_at' => $now,
            'created_at' => $now,
        ]);

        $relDir = "uploads/vehicle_observations/{$requestId}/{$phase}";
        $sort = 0;
        $written = [];

        foreach ($fileList as $file) {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Photo upload failed.');
            }
            if (($file['size'] ?? 0) > 8 * 1024 * 1024) {
                throw new RuntimeException('A photo exceeds the 8MB upload limit.');
            }

            $mime = @mime_content_type($file['tmp_name']);
            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
                throw new RuntimeException('Only JPEG, PNG, GIF, or WebP photos are allowed.');
            }

            $optimized = optimizeObservationImage(
                $file['tmp_name'],
                $relDir,
                $phase,
                $fullMax,
                480,
                82
            );
            if (!$optimized) {
                throw new RuntimeException('Could not process one of the photos. Try a different image.');
            }

            $written[] = $optimized['full_path'];
            $written[] = $optimized['thumb_path'];

            db()->insert('vehicle_observation_photos', [
                'observation_id' => $obsId,
                'file_path' => $optimized['full_path'],
                'thumb_path' => $optimized['thumb_path'],
                'full_path' => $optimized['full_path'],
                'file_name' => basename($optimized['full_path']),
                'mime_type' => $optimized['mime_type'],
                'file_size' => $optimized['file_size'],
                'width' => $optimized['width'],
                'height' => $optimized['height'],
                'caption' => null,
                'sort_order' => $sort++,
                'created_at' => $now,
            ]);
        }

        if ($sort < 1) {
            throw new RuntimeException('At least one vehicle photo is required.');
        }

        db()->commit();

        return ['ok' => true, 'observation_id' => (int) $obsId];
    } catch (Throwable $e) {
        if (dbInTransaction()) {
            db()->rollback();
        }
        if (!empty($written)) {
            foreach ($written as $path) {
                FileUpload::delete($path);
            }
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * @return list<array{name:string,type:string,tmp_name:string,error:int,size:int}>
 */
function observationNormalizeFiles(array $filesField): array
{
    $out = [];
    if (!isset($filesField['name'])) {
        return $out;
    }
    if (is_array($filesField['name'])) {
        $n = count($filesField['name']);
        for ($i = 0; $i < $n; $i++) {
            if (($filesField['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = [
                'name' => $filesField['name'][$i],
                'type' => $filesField['type'][$i],
                'tmp_name' => $filesField['tmp_name'][$i],
                'error' => $filesField['error'][$i],
                'size' => $filesField['size'][$i],
            ];
        }
    } elseif (($filesField['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $out[] = $filesField;
    }
    return $out;
}

function observationNotifyDamage(object $request, string $condition, string $notes): void
{
    if ($condition !== 'damaged' && $condition !== 'poor') {
        return;
    }

    $recipients = [];
    if (!empty($request->motorpool_head_id)) {
        $recipients[] = (int) $request->motorpool_head_id;
    }
    $admins = db()->fetchAll(
        "SELECT id FROM users WHERE role IN (?, ?) AND status = 'active' AND deleted_at IS NULL",
        [ROLE_MOTORPOOL, ROLE_ADMIN]
    );
    foreach ($admins as $a) {
        $recipients[] = (int) $a->id;
    }
    $recipients = array_unique($recipients);

    $msg = "Vehicle condition on return for request #{$request->id} marked as {$condition}."
        . ($notes !== '' ? " Notes: {$notes}" : '');

    foreach ($recipients as $uid) {
        notify(
            $uid,
            'vehicle_arrived',
            'Vehicle condition alert',
            $msg,
            '/?page=requests&action=view&id=' . $request->id,
            (int) $request->id
        );
    }
}

function observationFileUrl(string $relativePath): string
{
    return rtrim(APP_URL, '/') . '/?page=file-view&file=' . rawurlencode($relativePath);
}
