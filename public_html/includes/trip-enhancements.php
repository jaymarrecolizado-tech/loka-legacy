<?php
/**
 * LOKA - Trip Enhancements Helpers
 *
 * Shared logic for:
 *  - Travel Order / Official Business Slip enforcement (admin/all_father toggle)
 *  - Pre-trip confirmation emails ("GRAB-style" Proceed / Don't Proceed)
 *  - Overdue-trip alerts to the Motorpool Head
 *  - Anonymous post-trip driver evaluations
 *
 * Requires: config bootstrap (Database, functions.php) to be loaded first.
 */

if (!defined('TRIP_ENHANCEMENTS_LOADED')) {

    /**
     * Read a single settings value with a fallback default.
     */
    function tripSetting(string $key, string $default = ''): string
    {
        try {
            $row = db()->fetch("SELECT value FROM settings WHERE `key` = ?", [$key]);
            return ($row && $row->value !== null && $row->value !== '') ? (string) $row->value : $default;
        } catch (Throwable $e) {
            error_log('tripSetting(' . $key . '): ' . $e->getMessage());
            return $default;
        }
    }

    // =====================================================================
    // FEATURE: Travel Order / OB Slip enforcement
    // =====================================================================

    /**
     * Process an uploaded Travel Order / OB Slip file (from $_FILES['travel_order_file']).
     *
     * @param int $requestId Request ID (folder scoping)
     * @return array{path:?string, original_name:?string, error:string}
     */
    function handleTravelOrderFileUpload(int $requestId): array
    {
        $result = ['path' => null, 'original_name' => null, 'error' => ''];

        if (empty($_FILES['travel_order_file']['name'])) {
            return $result; // no file uploaded - caller decides whether it is required
        }

        try {
            $handler = FileUpload::createTravelOrderHandler($requestId);
            $path = $handler->upload($_FILES['travel_order_file'], 'to_');

            if ($path === false) {
                $result['error'] = implode('; ', $handler->getErrors());
            } elseif ($path !== '') {
                $result['path'] = $path;
                $result['original_name'] = basename((string) $_FILES['travel_order_file']['name']);
            } else {
                $result['error'] = 'No file was uploaded.';
            }
        } catch (Throwable $e) {
            $result['error'] = $e->getMessage();
            error_log('handleTravelOrderFileUpload: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * Whether the Travel Order / Official Business Slip upload is mandatory
     * during request submission (toggled by admin / all_father in Settings).
     */
    function requireTravelOrderUpload(): bool
    {
        static $cached = null;
        if ($cached === null) {
            $cached = tripSetting('require_travel_order_upload', '0') === '1';
        }
        return $cached;
    }

    // =====================================================================
    // FEATURE: Pre-trip confirmation emails
    // =====================================================================

    function tripConfirmationEnabled(): bool
    {
        return tripSetting('trip_confirmation_enabled', '1') === '1';
    }

    function tripConfirmationLeadHours(): int
    {
        return max(1, (int) tripSetting('trip_confirmation_lead_hours', '24'));
    }

    function tripConfirmationSameDayLeadMinutes(): int
    {
        return max(5, (int) tripSetting('trip_confirmation_same_day_lead_minutes', '60'));
    }

    function tripConfirmationWindowMinutes(): int
    {
        return max(15, (int) tripSetting('trip_confirmation_window_minutes', '60'));
    }

    function tripOverdueRenotifyHours(): int
    {
        return max(1, (int) tripSetting('trip_overdue_renotify_hours', '24'));
    }

    function driverEvaluationReminderHours(): int
    {
        return max(1, (int) tripSetting('driver_evaluation_reminder_hours', '48'));
    }

    function driverEvaluationExpiryDays(): int
    {
        return max(1, (int) tripSetting('driver_evaluation_expiry_days', '30'));
    }

    /**
     * Create a trip confirmation row for an approved request (if applicable).
     *
     * Scheduling rule:
     *  - Trip on a later day than the request was created: email goes out
     *    `trip_confirmation_lead_hours` (default 24h) before start.
     *  - Trip on the SAME calendar day the request was created: email goes out
     *    `trip_confirmation_same_day_lead_minutes` before start.
     *
     * The response deadline is `trip_confirmation_window_minutes` before start.
     * No response = the trip PROCEEDS (default behaviour).
     *
     * @return int|null Confirmation row id, or null when not applicable.
     */
    function createTripConfirmation(int $requestId): ?int
    {
        try {
            if (!tripConfirmationEnabled()) {
                return null;
            }

            $request = db()->fetch(
                "SELECT id, user_id, start_datetime, end_datetime, status, created_at, actual_dispatch_datetime
                 FROM requests WHERE id = ? AND deleted_at IS NULL",
                [$requestId]
            );

            if (!$request || $request->status !== STATUS_APPROVED) {
                return null;
            }
            if (!empty($request->actual_dispatch_datetime)) {
                return null; // already dispatched — nothing to confirm
            }
            if (strtotime($request->end_datetime) <= time()) {
                return null; // trip window already over
            }

            // Never duplicate an active confirmation
            $active = db()->fetch(
                "SELECT id FROM trip_confirmations
                 WHERE request_id = ? AND status IN ('pending','confirmed') LIMIT 1",
                [$requestId]
            );
            if ($active) {
                return (int) $active->id;
            }

            // Cancel stale pending rows (e.g. schedule changed by override)
            db()->update(
                'trip_confirmations',
                ['status' => 'cancelled', 'updated_at' => date(DATETIME_FORMAT)],
                "request_id = ? AND status = 'pending'",
                [$requestId]
            );

            $tz = new DateTimeZone('Asia/Manila');
            $startDt = new DateTime($request->start_datetime, $tz);
            $nowDt = new DateTime('now', $tz);
            $createdDt = new DateTime($request->created_at, $tz);

            // Same-day rule: trip start date == request creation date
            if ($startDt->format('Y-m-d') === $createdDt->format('Y-m-d')) {
                $leadMinutes = tripConfirmationSameDayLeadMinutes();
            } else {
                $leadMinutes = tripConfirmationLeadHours() * 60;
            }

            $sendAt = (clone $startDt)->modify("-{$leadMinutes} minutes");
            if ($sendAt < $nowDt) {
                $sendAt = $nowDt; // trip is very soon — send immediately
            }

            $windowMinutes = tripConfirmationWindowMinutes();
            $deadline = (clone $startDt)->modify("-{$windowMinutes} minutes");
            if ($deadline <= $nowDt) {
                // Response window already passed — trip proceeds by default,
                // no confirmation email is meaningful anymore.
                return null;
            }

            $rawToken = bin2hex(random_bytes(32));
            $cycle = (int) (db()->fetch(
                "SELECT COALESCE(MAX(cycle), 0) AS c FROM trip_confirmations WHERE request_id = ?",
                [$requestId]
            )->c ?? 0) + 1;

            return db()->insert('trip_confirmations', [
                'request_id' => $requestId,
                'token_hash' => hash('sha256', $rawToken),
                'cycle' => $cycle,
                'status' => 'pending',
                'scheduled_send_at' => $sendAt->format(DATETIME_FORMAT),
                'deadline_at' => $deadline->format(DATETIME_FORMAT),
                'created_at' => date(DATETIME_FORMAT)
            ]);
        } catch (Throwable $e) {
            error_log('createTripConfirmation(#' . $requestId . '): ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Resolve a raw confirmation token (from email link) to its row + request.
     */
    function findTripConfirmationByToken(string $rawToken): ?object
    {
        if ($rawToken === '') {
            return null;
        }
        return db()->fetch(
            "SELECT tc.*, r.user_id AS requester_id, r.status AS request_status,
                    r.start_datetime, r.end_datetime, r.destination, r.purpose,
                    r.vehicle_id, r.driver_id, r.motorpool_head_id
             FROM trip_confirmations tc
             JOIN requests r ON r.id = tc.request_id AND r.deleted_at IS NULL
             WHERE tc.token_hash = ?",
            [hash('sha256', $rawToken)]
        );
    }

    /**
     * Notify the responsible motorpool head(s) of a request.
     * Falls back to all active motorpool users when none is assigned.
     */
    function notifyMotorpoolHeads(int $requestId, string $type, string $title, string $message, string $link): void
    {
        $recipientIds = [];

        $request = db()->fetch(
            "SELECT motorpool_head_id FROM requests WHERE id = ? AND deleted_at IS NULL",
            [$requestId]
        );
        if ($request && $request->motorpool_head_id) {
            $recipientIds[] = (int) $request->motorpool_head_id;
        } else {
            $heads = db()->fetchAll(
                "SELECT id FROM users
                 WHERE role = ? AND status = 'active' AND deleted_at IS NULL",
                [ROLE_MOTORPOOL]
            );
            foreach ($heads as $h) {
                $recipientIds[] = (int) $h->id;
            }
        }

        foreach (array_unique($recipientIds) as $uid) {
            try {
                notify($uid, $type, $title, $message, $link, $requestId);
            } catch (Throwable $e) {
                error_log('notifyMotorpoolHeads: ' . $e->getMessage());
            }
        }
    }

    // =====================================================================
    // FEATURE: Anonymous post-trip driver evaluations
    // =====================================================================

    /**
     * Create one evaluation invitation per passenger of a completed trip
     * (system users AND guests, driver excluded). Guests cannot be emailed
     * (no address on file) — their tokens are still created so a shareable
     * link can be handed to them; user passengers get an email queued.
     *
     * @return int Number of evaluation rows created.
     */
    function createDriverEvaluations(int $requestId): int
    {
        $created = 0;
        try {
            $request = db()->fetch(
                "SELECT id, driver_id, user_id, start_datetime
                 FROM requests WHERE id = ? AND deleted_at IS NULL",
                [$requestId]
            );
            if (!$request || !$request->driver_id) {
                return 0;
            }

            // Driver's user account (to exclude them from passenger evaluations)
            $driverUser = db()->fetch(
                "SELECT d.user_id FROM drivers d WHERE d.id = ? AND d.deleted_at IS NULL",
                [$request->driver_id]
            );

            // Existing rows (idempotency)
            $existingUserIds = [];
            $existingGuests = 0;
            foreach (db()->fetchAll(
                "SELECT evaluator_user_id, guest_label FROM driver_evaluations WHERE request_id = ?",
                [$requestId]
            ) as $row) {
                if ($row->evaluator_user_id === null) {
                    $existingGuests++;
                } else {
                    $existingUserIds[(int) $row->evaluator_user_id] = true;
                }
            }

            $passengers = db()->fetchAll(
                "SELECT rp.user_id, rp.guest_name
                 FROM request_passengers rp
                 WHERE rp.request_id = ?",
                [$requestId]
            );

            $guestOrdinal = $existingGuests;
            $rawTokensByEmail = [];

            foreach ($passengers as $p) {
                if ($p->user_id !== null) {
                    $uid = (int) $p->user_id;

                    // Exclude the driver (if listed as passenger)
                    if ($driverUser && $uid === (int) $driverUser->user_id) {
                        continue;
                    }
                    if (isset($existingUserIds[$uid])) {
                        continue;
                    }

                    $rawToken = bin2hex(random_bytes(32));
                    db()->insert('driver_evaluations', [
                        'request_id' => $requestId,
                        'driver_id' => $request->driver_id,
                        'evaluator_user_id' => $uid,
                        'token_hash' => hash('sha256', $rawToken),
                        'created_at' => date(DATETIME_FORMAT)
                    ]);
                    $created++;

                    $user = db()->fetch(
                        "SELECT email, name FROM users WHERE id = ? AND deleted_at IS NULL",
                        [$uid]
                    );
                    if ($user && $user->email) {
                        $rawTokensByEmail[$user->email] = ['token' => $rawToken, 'name' => $user->name];
                    }
                } else {
                    // Guest passenger — generic ordinal label, never the real name
                    $guestOrdinal++;
                    $rawToken = bin2hex(random_bytes(32));
                    db()->insert('driver_evaluations', [
                        'request_id' => $requestId,
                        'driver_id' => $request->driver_id,
                        'evaluator_user_id' => null,
                        'guest_label' => 'Guest ' . $guestOrdinal,
                        'token_hash' => hash('sha256', $rawToken),
                        'created_at' => date(DATETIME_FORMAT)
                    ]);
                    $created++;
                }
            }

            // Queue evaluation emails for user passengers (after row creation)
            foreach ($rawTokensByEmail as $email => $info) {
                try {
                    $queue = new EmailQueue();
                    $queue->queue(
                        $email,
                        'How was your trip? Rate your driver',
                        buildDriverEvaluationEmailBody($requestId, $info['token']),
                        $info['name'],
                        null,   // template
                        5,      // priority
                        null,   // scheduledAt
                        $requestId
                    );
                } catch (Throwable $e) {
                    error_log('createDriverEvaluations email: ' . $e->getMessage());
                }
            }
        } catch (Throwable $e) {
            error_log('createDriverEvaluations(#' . $requestId . '): ' . $e->getMessage());
        }
        return $created;
    }

    /**
     * Resolve a raw evaluation token to its row + trip + driver context.
     */
    function findDriverEvaluationByToken(string $rawToken): ?object
    {
        if ($rawToken === '') {
            return null;
        }
        return db()->fetch(
            "SELECT de.*, r.start_datetime AS trip_start, r.end_datetime AS trip_end,
                    r.destination, r.purpose,
                    v.plate_number, v.make AS vehicle_make, v.model AS vehicle_model,
                    du.name AS driver_name
             FROM driver_evaluations de
             JOIN requests r ON r.id = de.request_id AND r.deleted_at IS NULL
             LEFT JOIN vehicles v ON r.vehicle_id = v.id AND v.deleted_at IS NULL
             LEFT JOIN drivers d ON de.driver_id = d.id AND d.deleted_at IS NULL
             LEFT JOIN users du ON d.user_id = du.id
             WHERE de.token_hash = ?",
            [hash('sha256', $rawToken)]
        );
    }

    /**
     * HTML body for the driver evaluation invitation email.
     */
    function buildDriverEvaluationEmailBody(int $requestId, string $rawToken): string
    {
        $link = APP_URL . '/?page=evaluations&action=submit&token=' . urlencode($rawToken);
        $appName = APP_NAME;

        return "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                <div style='background: #0d6efd; color: white; padding: 20px; text-align: center;'>
                    <h1 style='margin: 0;'>&#11088; Rate Your Driver</h1>
                </div>
                <div style='padding: 20px; background: #f8f9fa;'>
                    <p>Your trip (Request #{$requestId}) has been completed.</p>
                    <p>Please take a moment to evaluate the service rendered by your driver.
                    Your feedback is <strong>completely anonymous</strong> &mdash; the driver and the
                    motorpool will never see who submitted which rating or comment.</p>
                    <p><a href='{$link}'
                        style='background: #0d6efd; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>
                        Evaluate This Trip</a></p>
                    <p style='font-size: 12px; color: #6c757d;'>This link is single-use and expires in "
                    . driverEvaluationExpiryDays() . " days.</p>
                </div>
                <div style='background: #6c757d; color: white; padding: 15px; text-align: center; font-size: 12px;'>
                    <p style='margin: 0;'>This is an automated message from {$appName}</p>
                </div>
            </div>";
    }

    /**
     * HTML body for the pre-trip confirmation email (Proceed / Don't Proceed).
     */
    function buildTripConfirmationEmailBody(object $request, string $rawToken): string
    {
        $proceedUrl = APP_URL . '/?page=requests&action=confirm&token=' . urlencode($rawToken) . '&choice=proceed';
        $declineUrl = APP_URL . '/?page=requests&action=confirm&token=' . urlencode($rawToken) . '&choice=decline';
        $appName = APP_NAME;
        $reqId = (int) $request->id;
        $requesterName = htmlspecialchars($request->requester_name ?? '', ENT_QUOTES);
        $destination = htmlspecialchars($request->destination ?? '', ENT_QUOTES);
        $start = formatDateTime($request->start_datetime ?? '');
        $end = formatDateTime($request->end_datetime ?? '');

        return "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                <div style='background: #ffc107; color: #212529; padding: 20px; text-align: center;'>
                    <h1 style='margin: 0;'>&#128663; Will you proceed with your trip?</h1>
                </div>
                <div style='padding: 20px; background: #f8f9fa;'>
                    <p>Dear {$requesterName},</p>
                    <p>Your approved vehicle request <strong>#{$reqId}</strong> is scheduled as follows:</p>
                    <div style='background: white; padding: 15px; border-radius: 8px; margin: 15px 0;'>
                        <p style='margin: 0 0 8px 0;'><strong>Destination:</strong> {$destination}</p>
                        <p style='margin: 0 0 8px 0;'><strong>Departure:</strong> {$start}</p>
                        <p style='margin: 0;'><strong>Expected Return:</strong> {$end}</p>
                    </div>
                    <p>Please confirm whether this trip will push through. If we do not receive your
                    response before the deadline, the trip will <strong>proceed as scheduled</strong>.</p>
                    <p>
                        <a href='{$proceedUrl}'
                           style='background: #198754; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; margin-right: 8px;'>
                           &#9989; Proceed</a>
                        <a href='{$declineUrl}'
                           style='background: #dc3545; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>
                           &#10060; Don't Proceed</a>
                    </p>
                </div>
                <div style='background: #6c757d; color: white; padding: 15px; text-align: center; font-size: 12px;'>
                    <p style='margin: 0;'>This is an automated message from {$appName}</p>
                </div>
            </div>";
    }
}
