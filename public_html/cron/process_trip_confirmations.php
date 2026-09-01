<?php
/**
 * LOKA - Trip confirmation + overdue-trip processor (CLI or include)
 *
 * Jobs handled (every 5 minutes recommended):
 *   1. SEND      - queue pre-trip confirmation emails whose send time arrived
 *   2. EXPIRE    - mark unanswered confirmations expired (default: trip proceeds)
 *   3. OVERDUE   - alert the motorpool head when an approved trip passes its
 *                  designated end datetime without a recorded arrival
 *   4. REMINDERS - re-send driver evaluation invitations (once) to passengers
 *                  who have not submitted after N hours
 *
 * CLI:   php cron/process_trip_confirmations.php
 * HTTP:  /?page=cron&action=trips&key=SECRET  (handled in pages/cron/index.php)
 *
 * crontab (installed by setup.sh):
 *   0-59/5 * * * * /usr/bin/php /path/to/public_html/cron/process_trip_confirmations.php >> /path/to/logs/cron.log 2>&1
 */

if (php_sapi_name() === 'cli') {
    require_once __DIR__ . '/../config/bootstrap.php';
}

/**
 * Send a pre-trip confirmation email for a pending confirmation row.
 * Expects $confirmation->raw_token to be set by the caller (raw tokens are
 * generated at SEND time since only their hash is stored).
 */
function sendTripConfirmationEmail(object $confirmation): bool
{
    $rawToken = $confirmation->raw_token ?? null;
    if (!$rawToken) {
        return false;
    }

    $request = db()->fetch(
        "SELECT r.*, u.name AS requester_name, u.email AS requester_email
         FROM requests r
         JOIN users u ON r.user_id = u.id AND u.deleted_at IS NULL
         WHERE r.id = ? AND r.deleted_at IS NULL",
        [$confirmation->request_id]
    );

    if (!$request || !$request->requester_email) {
        return false;
    }

    try {
        $queue = new EmailQueue();
        $queue->queue(
            $request->requester_email,
            "[Trip #{$confirmation->request_id}] Will you proceed with your trip?",
            buildTripConfirmationEmailBody($request, $rawToken),
            $request->requester_name,
            null,  // template
            3,     // higher priority - time sensitive
            null,  // scheduledAt
            (int) $confirmation->request_id
        );
        return true;
    } catch (Throwable $e) {
        error_log('sendTripConfirmationEmail: ' . $e->getMessage());
        return false;
    }
}

/**
 * Main processor.
 *
 * @return array{sent:int,expired:int,overdue:int,reminders:int}
 */
function processTripJobs(): array
{
    $sent = 0;
    $expired = 0;
    $overdue = 0;
    $reminders = 0;
    $now = date(DATETIME_FORMAT);

    // ------------------------------------------------------------------
    // 1. SEND - pending confirmations whose scheduled send time arrived
    // ------------------------------------------------------------------
    $dueRows = db()->fetchAll(
        "SELECT * FROM trip_confirmations
         WHERE status = 'pending' AND sent_at IS NULL AND scheduled_send_at <= ?",
        [$now]
    );

    foreach ($dueRows as $row) {
        // The raw token cannot be recovered from its hash; re-issue a token
        // and update the stored hash so the emailed link stays valid.
        $rawToken = bin2hex(random_bytes(32));
        db()->update(
            'trip_confirmations',
            ['token_hash' => hash('sha256', $rawToken), 'updated_at' => $now],
            'id = ?',
            [$row->id]
        );
        $row->raw_token = $rawToken;

        if (sendTripConfirmationEmail($row)) {
            db()->update('trip_confirmations', ['sent_at' => $now, 'updated_at' => $now], 'id = ?', [$row->id]);
            $sent++;
        } else {
            // Left unsent so the next run retries
            error_log("processTripJobs: could not queue confirmation #{$row->id} (missing requester email?)");
        }
    }

    // ------------------------------------------------------------------
    // 2. EXPIRE - no response before deadline: trip proceeds by default
    // ------------------------------------------------------------------
    $deadlineRows = db()->fetchAll(
        "SELECT * FROM trip_confirmations
         WHERE status = 'pending' AND deadline_at < ?",
        [$now]
    );

    foreach ($deadlineRows as $row) {
        $affected = db()->update(
            'trip_confirmations',
            ['status' => 'expired', 'responded_at' => $now, 'updated_at' => $now],
            'id = ? AND status = ?',
            [$row->id, 'pending']
        );

        if ($affected > 0) {
            $expired++;
            $link = '/?page=requests&action=view&id=' . $row->request_id;

            notifyMotorpoolHeads(
                (int) $row->request_id,
                'trip_confirmation_response',
                'No Confirmation - Trip Proceeding',
                "Request #{$row->request_id}: the requester did not respond to the pre-trip confirmation "
                . "before the deadline. Default action applied - the trip will proceed as scheduled.",
                $link
            );

            // Also inform the requester of the default outcome
            $request = db()->fetch(
                "SELECT user_id FROM requests WHERE id = ? AND deleted_at IS NULL",
                [$row->request_id]
            );
            if ($request) {
                try {
                    notify(
                        (int) $request->user_id,
                        'trip_confirmation_response',
                        'Trip Proceeding (No Response Recorded)',
                        "Request #{$row->request_id}: since no confirmation was received before the deadline, "
                        . "your trip will proceed as scheduled. If your plans changed, please cancel or request "
                        . "a reschedule from the request page.",
                        $link,
                        (int) $row->request_id
                    );
                } catch (Throwable $e) {
                    error_log('processTripJobs expire notify: ' . $e->getMessage());
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // 3. OVERDUE - approved trips past their designated end without arrival
    // ------------------------------------------------------------------
    $renotifyHours = tripOverdueRenotifyHours();
    $overdueRows = db()->fetchAll(
        "SELECT id, destination, end_datetime, motorpool_head_id
         FROM requests
         WHERE status = ?
           AND deleted_at IS NULL
           AND actual_arrival_datetime IS NULL
           AND end_datetime < ?
           AND (overdue_notified_at IS NULL
                OR overdue_notified_at < DATE_SUB(?, INTERVAL {$renotifyHours} HOUR))",
        [STATUS_APPROVED, $now, $now]
    );

    foreach ($overdueRows as $row) {
        db()->update('requests', ['overdue_notified_at' => $now, 'updated_at' => $now], 'id = ?', [$row->id]);
        $overdue++;

        $link = '/?page=requests&action=view&id=' . $row->id;
        notifyMotorpoolHeads(
            (int) $row->id,
            'trip_overdue_alert',
            'Trip Exceeded Designated End Time',
            "Request #{$row->id} ({$row->destination}) exceeded its designated end of trip "
            . "(" . formatDateTime($row->end_datetime) . ") and no arrival has been recorded yet. "
            . "Please follow up with the driver or the passengers.",
            $link
        );
    }

    // ------------------------------------------------------------------
    // 4. REMINDERS - evaluation invitations not yet submitted (once each)
    // ------------------------------------------------------------------
    $reminderHours = driverEvaluationReminderHours();
    $pendingEvals = db()->fetchAll(
        "SELECT de.id, de.request_id, de.evaluator_user_id
         FROM driver_evaluations de
         JOIN requests r ON r.id = de.request_id AND r.deleted_at IS NULL
         WHERE de.submitted_at IS NULL
           AND de.reminder_sent_at IS NULL
           AND de.created_at <= DATE_SUB(?, INTERVAL {$reminderHours} HOUR)
           AND de.evaluator_user_id IS NOT NULL
           AND r.status = ?",
        [$now, STATUS_COMPLETED]
    );

    foreach ($pendingEvals as $eval) {
        $user = db()->fetch(
            "SELECT email, name FROM users WHERE id = ? AND deleted_at IS NULL",
            [$eval->evaluator_user_id]
        );
        if (!$user || !$user->email) {
            // Nothing to remind - mark so we stop retrying
            db()->update('driver_evaluations', ['reminder_sent_at' => $now], 'id = ?', [$eval->id]);
            continue;
        }

        // Raw token is not recoverable; re-issue (same approach as confirmations)
        $rawToken = bin2hex(random_bytes(32));
        $affected = db()->update(
            'driver_evaluations',
            [
                'token_hash' => hash('sha256', $rawToken),
                'reminder_sent_at' => $now
            ],
            'id = ? AND submitted_at IS NULL',
            [$eval->id]
        );
        if ($affected === 0) {
            continue; // submitted between the SELECT and this UPDATE
        }

        try {
            $queue = new EmailQueue();
            $queue->queue(
                $user->email,
                'Reminder: Rate your driver for trip #' . $eval->request_id,
                buildDriverEvaluationEmailBody((int) $eval->request_id, $rawToken),
                $user->name,
                null,
                5,
                null,
                (int) $eval->request_id
            );
            $reminders++;
        } catch (Throwable $e) {
            error_log('processTripJobs reminder email: ' . $e->getMessage());
        }
    }

    return ['sent' => $sent, 'expired' => $expired, 'overdue' => $overdue, 'reminders' => $reminders];
}

// Direct CLI execution
if (php_sapi_name() === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    echo '[' . date('Y-m-d H:i:s') . '] process_trip_confirmations started' . PHP_EOL;
    try {
        $r = processTripJobs();
        echo '[' . date('Y-m-d H:i:s') . "] done: sent={$r['sent']} expired={$r['expired']} "
            . "overdue={$r['overdue']} reminders={$r['reminders']}" . PHP_EOL;
    } catch (Throwable $e) {
        echo '[' . date('Y-m-d H:i:s') . '] ERROR: ' . $e->getMessage() . PHP_EOL;
        error_log('process_trip_confirmations: ' . $e->getMessage());
        exit(1);
    }
}
