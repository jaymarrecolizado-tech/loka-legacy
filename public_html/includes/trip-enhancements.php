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
     * Whether a request may still receive / answer a pre-trip confirmation email.
     * False once dispatched (on trip), past start, completed, cancelled, etc.
     */
    function tripConfirmationStillEligible(object $request): bool
    {
        $status = (string) ($request->status ?? $request->request_status ?? '');
        if ($status !== STATUS_APPROVED) {
            return false;
        }
        if (!empty($request->actual_dispatch_datetime)) {
            return false;
        }
        $start = $request->start_datetime ?? null;
        if ($start === null || $start === '' || strtotime((string) $start) <= time()) {
            return false;
        }
        return true;
    }

    /**
     * Cancel unsent/pending confirmation rows for a request (e.g. after dispatch).
     */
    function cancelPendingTripConfirmations(int $requestId, string $reason = ''): int
    {
        $affected = (int) db()->update(
            'trip_confirmations',
            ['status' => 'cancelled', 'updated_at' => date(DATETIME_FORMAT)],
            "request_id = ? AND status = 'pending'",
            [$requestId]
        );
        if ($affected > 0) {
            error_log(
                'cancelPendingTripConfirmations(#' . $requestId . '): cancelled '
                . $affected . ($reason !== '' ? " — {$reason}" : '')
            );
        }
        return $affected;
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

            if (!$request || !tripConfirmationStillEligible($request)) {
                return null;
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
                    r.vehicle_id, r.driver_id, r.motorpool_head_id,
                    r.actual_dispatch_datetime
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
     * Create one evaluation invitation per real rider of a completed trip —
     * the REQUESTER plus every companion — skipping the assigned driver.
     * System users get an email (queued; sync-sent in immediate delivery
     * mode) and an in-app nag notification. Guests cannot be emailed (no
     * address on file). Guest tokens are emailed unlabeled to the requester
     * to forward (one link per guest) — never shown on staff trip pages.
     * Guest rows are only topped up to the current guest count, so re-running
     * on arrival + completion never inserts duplicates.
     *
     * @return int Number of evaluation rows created.
     */
    function createDriverEvaluations(int $requestId): int
    {
        $created = 0;
        try {
            $request = db()->fetch(
                "SELECT id, driver_id, user_id
                 FROM requests WHERE id = ? AND deleted_at IS NULL",
                [$requestId]
            );
            if (!$request || !$request->driver_id) {
                return 0;
            }

            // Driver's user account (never invited to rate their own trip)
            $driverUser = db()->fetch(
                "SELECT d.user_id FROM drivers d WHERE d.id = ? AND d.deleted_at IS NULL",
                [$request->driver_id]
            );
            $driverUserId = $driverUser ? (int) $driverUser->user_id : null;

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

            // Real riders = requester + user companions (deduped)
            $riderUserIds = [];
            if ($request->user_id !== null) {
                $riderUserIds[(int) $request->user_id] = true;
            }
            foreach (db()->fetchAll(
                "SELECT user_id FROM request_passengers
                 WHERE request_id = ? AND user_id IS NOT NULL",
                [$requestId]
            ) as $p) {
                $riderUserIds[(int) $p->user_id] = true;
            }
            unset($riderUserIds[$driverUserId]);

            // Guest passengers count (rows are topped up, never duplicated)
            $guestCount = (int) db()->fetchColumn(
                "SELECT COUNT(*) FROM request_passengers
                 WHERE request_id = ? AND user_id IS NULL",
                [$requestId]
            );

            $now = date(DATETIME_FORMAT);
            $guestTokens = [];

            foreach (array_keys($riderUserIds) as $uid) {
                if (isset($existingUserIds[$uid])) {
                    continue;
                }

                $rawToken = bin2hex(random_bytes(32));
                db()->insert('driver_evaluations', [
                    'request_id' => $requestId,
                    'driver_id' => $request->driver_id,
                    'evaluator_user_id' => $uid,
                    'token_hash' => hash('sha256', $rawToken),
                    'created_at' => $now
                ]);
                $created++;

                $user = db()->fetch(
                    "SELECT email, name FROM users WHERE id = ? AND deleted_at IS NULL",
                    [$uid]
                );
                if ($user && $user->email) {
                    queueDriverEvaluationEmail($requestId, $user->email, $user->name, $rawToken);
                }

                // In-app nag (own inbox only — the invite email already carries the link)
                try {
                    db()->insert('notifications', [
                        'user_id' => $uid,
                        'type' => 'system_notification',
                        'title' => 'Driver evaluation pending',
                        'message' => 'Trip #' . $requestId . ' is complete. Please rate your driver — your feedback is anonymous.',
                        'link' => '/?page=evaluations&action=rate&id=' . $requestId,
                        'is_read' => 0,
                        'created_at' => $now
                    ]);
                } catch (Throwable $e) {
                    error_log('createDriverEvaluations notify: ' . $e->getMessage());
                }
            }

            for ($i = $existingGuests; $i < $guestCount; $i++) {
                $rawToken = bin2hex(random_bytes(32));
                db()->insert('driver_evaluations', [
                    'request_id' => $requestId,
                    'driver_id' => $request->driver_id,
                    'evaluator_user_id' => null,
                    'guest_label' => 'Guest ' . ($i + 1),
                    'token_hash' => hash('sha256', $rawToken),
                    'created_at' => $now
                ]);
                $created++;
                $guestTokens[] = $rawToken;
            }

            if ($guestTokens !== []) {
                queueGuestEvaluationForwardEmail(
                    $requestId,
                    (int) $request->user_id,
                    $driverUserId,
                    $guestTokens
                );
            }
        } catch (Throwable $e) {
            error_log('createDriverEvaluations(#' . $requestId . '): ' . $e->getMessage());
        }
        return $created;
    }

    /**
     * Queue an evaluation-related HTML email, then sync-send when delivery
     * mode is "immediate". On failure the queued row stays pending for cron.
     */
    function queueEvalTripEmail(int $requestId, string $email, string $name, string $body, string $logLabel): void
    {
        try {
            $queue = new EmailQueue();
            $subject = EmailQueue::requestThreadSubject($requestId);
            $queueId = $queue->queue($email, $subject, $body, $name, null, 5, null, $requestId);

            $mode = function_exists('emailDeliveryMode') ? emailDeliveryMode() : 'immediate';
            if ($mode === 'immediate' && MAIL_ENABLED) {
                try {
                    $mailer = new Mailer();
                    if ($mailer->send($email, $subject, $body, $name, true, $requestId, $queueId)) {
                        $queue->markSent($queueId);
                    } else {
                        error_log($logLabel . ' sync send failed (queued as backup): ' . implode(', ', $mailer->getErrors()));
                    }
                } catch (Throwable $e) {
                    error_log($logLabel . ' sync exception (queued as backup): ' . $e->getMessage());
                }
            }
        } catch (Throwable $e) {
            error_log($logLabel . ': ' . $e->getMessage());
        }
    }

    /**
     * Queue an evaluation invite, then sync-send it when the delivery mode is
     * "immediate" (localhost / no-cron installs). On failure the queued row
     * stays pending for cron. Reminders intentionally stay queue-only.
     */
    function queueDriverEvaluationEmail(int $requestId, string $email, string $name, string $rawToken): void
    {
        queueEvalTripEmail(
            $requestId,
            $email,
            $name,
            buildDriverEvaluationEmailBody($requestId, $rawToken),
            'queueDriverEvaluationEmail'
        );
    }

    /**
     * Email unlabeled guest evaluation links to the requester so they can
     * forward one link per guest. Never sent to motorpool/staff pages, and
     * never labelled with guest names (reports stay anonymous).
     *
     * @param list<string> $rawTokens
     */
    function queueGuestEvaluationForwardEmail(int $requestId, int $requesterUserId, ?int $driverUserId, array $rawTokens): void
    {
        if ($rawTokens === [] || $requesterUserId <= 0) {
            return;
        }
        if ($driverUserId !== null && $requesterUserId === $driverUserId) {
            return; // requester was the driver — they were not a rider
        }

        $requester = db()->fetch(
            "SELECT email, name FROM users WHERE id = ? AND deleted_at IS NULL",
            [$requesterUserId]
        );
        if (!$requester || !$requester->email) {
            return;
        }

        $links = '';
        foreach (array_values($rawTokens) as $i => $token) {
            $n = $i + 1;
            $url = APP_URL . '/?page=evaluations&action=submit&token=' . urlencode($token);
            $links .= "<p>Guest link {$n}: <a href='{$url}'>{$url}</a></p>";
        }
        $days = driverEvaluationExpiryDays();
        $appName = APP_NAME;
        $body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                <div style='background: #0d6efd; color: white; padding: 20px; text-align: center;'>
                    <h1 style='margin: 0;'>Guest evaluation links</h1>
                </div>
                <div style='padding: 20px; background: #f8f9fa;'>
                    <p>Trip (Request #{$requestId}) included guest passenger(s) who cannot receive email from LOKA.</p>
                    <p>Please forward <strong>one link to each guest</strong>. Each link is single-use, anonymous, and expires in {$days} days. Do not post these links on a shared trip page.</p>
                    {$links}
                </div>
                <div style='background: #6c757d; color: white; padding: 15px; text-align: center; font-size: 12px;'>
                    <p style='margin: 0;'>This is an automated message from {$appName}</p>
                </div>
            </div>";

        queueEvalTripEmail(
            $requestId,
            $requester->email,
            (string) $requester->name,
            $body,
            'queueGuestEvaluationForwardEmail'
        );
    }

    /**
     * Pending (unsubmitted, unexpired) evaluation invites for a user —
     * powers the in-app nag banner. Drivers are never invited to rate their
     * own trips, so no extra driver check is needed.
     *
     * @return list<object>{request_id:int,destination:string,start_datetime:string}
     */
    function pendingDriverEvaluationsForUser(?int $userId): array
    {
        if (!$userId) {
            return [];
        }
        $days = driverEvaluationExpiryDays();
        try {
            return db()->fetchAll(
                "SELECT de.request_id, r.destination, r.start_datetime
                 FROM driver_evaluations de
                 JOIN requests r ON r.id = de.request_id AND r.deleted_at IS NULL
                 WHERE de.evaluator_user_id = ?
                   AND de.submitted_at IS NULL
                   AND r.status = ?
                   AND de.created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
                 ORDER BY de.created_at ASC
                 LIMIT 20",
                [$userId, STATUS_COMPLETED]
            );
        } catch (Throwable $e) {
            error_log('pendingDriverEvaluationsForUser: ' . $e->getMessage());
            return [];
        }
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
