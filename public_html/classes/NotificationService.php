<?php
/**
 * LOKA - Notification Service
 *
 * Handles sending email notifications for various system events
 */

class NotificationService
{
    /**
     * Send notification for request approval
     */
    public static function requestApproved($requestId)
    {
        $request = db()->fetch(
            "SELECT r.*, u.name as requester_name, u.email as requester_email,
                    a.name as approver_name
             FROM requests r
             JOIN users u ON r.user_id = u.id
             JOIN approvals a ON a.request_id = r.id AND a.approval_type = 'department'
             WHERE r.id = ? AND r.deleted_at IS NULL
             ORDER BY a.created_at DESC LIMIT 1",
            [$requestId]
        );

        if (!$request || !$request->requester_email) {
            return false;
        }

        // Check if requester has notifications enabled
        if (!self::userHasNotificationEnabled($request->user_id, 'request_approved')) {
            return false;
        }

        $template = NotificationTemplate::requestApproved($request, (object)['name' => $request->approver_name]);
        $approver = (object)['name' => $request->approver_name];

        return self::sendEmail(
            $request->requester_email,
            $template['subject'],
            $template['body']
        );
    }

    /**
     * Send notification for request rejection
     */
    public static function requestRejected($requestId, $reason = '')
    {
        $request = db()->fetch(
            "SELECT r.*, u.name as requester_name, u.email as requester_email,
                    a.name as approver_name, a.comments
             FROM requests r
             JOIN users u ON r.user_id = u.id
             JOIN approvals a ON a.request_id = r.id AND a.approval_type = 'department'
             WHERE r.id = ? AND r.deleted_at IS NULL
             ORDER BY a.created_at DESC LIMIT 1",
            [$requestId]
        );

        if (!$request || !$request->requester_email) {
            return false;
        }

        if (!self::userHasNotificationEnabled($request->user_id, 'request_rejected')) {
            return false;
        }

        $template = NotificationTemplate::requestRejected($request, (object)['name' => $request->approver_name], $reason ?: $request->comments);

        return self::sendEmail(
            $request->requester_email,
            $template['subject'],
            $template['body']
        );
    }

    /**
     * Send notification for vehicle assignment
     */
    public static function vehicleAssigned($requestId)
    {
        $request = db()->fetch(
            "SELECT r.*, u.name as requester_name, u.email as requester_email,
                    v.plate_number, v.make, v.model,
                    d.name as driver_name, d.email as driver_email
             FROM requests r
             JOIN users u ON r.user_id = u.id
             LEFT JOIN vehicles v ON r.vehicle_id = v.id
             LEFT JOIN drivers drv ON r.driver_id = drv.id
             LEFT JOIN users d ON drv.user_id = d.id
             WHERE r.id = ? AND r.deleted_at IS NULL",
            [$requestId]
        );

        if (!$request) {
            return false;
        }

        $sent = 0;

        // Notify requester
        if ($request->requester_email && self::userHasNotificationEnabled($request->user_id, 'vehicle_assigned')) {
            $template = NotificationTemplate::vehicleAssigned($request, (object)[
                'plate_number' => $request->plate_number,
                'make' => $request->make,
                'model' => $request->model
            ], (object)['name' => $request->driver_name]);

            if (self::sendEmail($request->requester_email, $template['subject'], $template['body'])) {
                $sent++;
            }
        }

        // Notify driver
        if ($request->driver_email && self::userHasNotificationEnabled($request->driver_id, 'trip_assigned')) {
            $template = NotificationTemplate::driverAssigned($request, (object)['name' => $request->driver_name]);

            if (self::sendEmail($request->driver_email, $template['subject'], $template['body'])) {
                $sent++;
            }
        }

        return $sent > 0;
    }

    /**
     * Send notification for driver assignment
     */
    public static function driverAssigned($requestId)
    {
        $request = db()->fetch(
            "SELECT r.*, d.name as driver_name, d.email as driver_email
             FROM requests r
             LEFT JOIN drivers drv ON r.driver_id = drv.id
             LEFT JOIN users d ON drv.user_id = d.id
             WHERE r.id = ? AND r.deleted_at IS NULL",
            [$requestId]
        );

        if (!$request || !$request->driver_email) {
            return false;
        }

        if (!self::userHasNotificationEnabled($request->driver_id, 'trip_assigned')) {
            return false;
        }

        $template = NotificationTemplate::driverAssigned($request, $request);

        return self::sendEmail(
            $request->driver_email,
            $template['subject'],
            $template['body']
        );
    }

    /**
     * Send notification for request cancellation
     */
    public static function requestCancelled($requestId, $cancelledBy)
    {
        $request = db()->fetch(
            "SELECT r.*, u.name as requester_name, u.email as requester_email,
                    u2.name as canceller_name
             FROM requests r
             JOIN users u ON r.user_id = u.id
             LEFT JOIN users u2 ON u2.id = ?
             WHERE r.id = ? AND r.deleted_at IS NULL",
            [$cancelledBy, $requestId]
        );

        if (!$request) {
            return false;
        }

        // Notify approvers
        $approvers = db()->fetchAll(
            "SELECT DISTINCT u.email, u.name
             FROM approvals a
             JOIN users u ON a.approver_id = u.id
             WHERE a.request_id = ?",
            [$requestId]
        );

        $template = NotificationTemplate::requestCancelled($request, $request->canceller_name ?: 'System');
        $sent = 0;

        foreach ($approvers as $approver) {
            if (self::userHasNotificationEnabled($approver->id, 'request_cancelled')) {
                if (self::sendEmail($approver->email, $template['subject'], $template['body'])) {
                    $sent++;
                }
            }
        }

        // Notify requester if not cancelled by them
        if ($request->requester_email && $request->user_id != $cancelledBy) {
            if (self::userHasNotificationEnabled($request->user_id, 'request_cancelled')) {
                if (self::sendEmail($request->requester_email, $template['subject'], $template['body'])) {
                    $sent++;
                }
            }
        }

        return $sent > 0;
    }

    /**
     * Send notification for revision request
     */
    public static function revisionRequested($requestId, $approverId, $reason = '')
    {
        $request = db()->fetch(
            "SELECT r.*, u.name as requester_name, u.email as requester_email,
                    a.name as approver_name, a.comments
             FROM requests r
             JOIN users u ON r.user_id = u.id
             LEFT JOIN approvals a ON a.request_id = r.id AND a.approval_type = 'department' AND a.status = 'revision'
             WHERE r.id = ? AND r.deleted_at IS NULL",
            [$requestId]
        );

        if (!$request || !$request->requester_email) {
            return false;
        }

        if (!self::userHasNotificationEnabled($request->user_id, 'revision_requested')) {
            return false;
        }

        $approver = db()->fetch("SELECT name FROM users WHERE id = ?", [$approverId]);

        $template = NotificationTemplate::revisionRequested($request, $approver, $reason ?: ($request->comments ?? ''));

        return self::sendEmail(
            $request->requester_email,
            $template['subject'],
            $template['body']
        );
    }

    /**
     * Send trip reminder notification
     */
    public static function sendTripReminder($requestId)
    {
        $request = db()->fetch(
            "SELECT r.*, u.email, u.name, u.notification_preferences
             FROM requests r
             JOIN users u ON r.user_id = u.id
             WHERE r.id = ? AND r.deleted_at IS NULL",
            [$requestId]
        );

        if (!$request || !$request->email) {
            return false;
        }

        if (!self::userHasNotificationEnabled($request->user_id, 'trip_reminder')) {
            return false;
        }

        $template = NotificationTemplate::tripReminder($request);

        return self::sendEmail(
            $request->email,
            $template['subject'],
            $template['body']
        );
    }

    /**
     * Send daily digest to approvers
     */
    public static function sendDailyDigest()
    {
        // Get all approvers who have daily digest enabled
        $approvers = db()->fetchAll(
            "SELECT u.id, u.name, u.email, u.department_id
             FROM users u
             WHERE u.status = 'active'
             AND u.deleted_at IS NULL
             AND (u.role = 'admin' OR u.role = 'approver' OR u.role = 'motorpool_head')
             AND JSON_EXTRACT(u.notification_preferences, '$.daily_digest') = true"
        );

        $sent = 0;
        $today = date('Y-m-d');

        foreach ($approvers as $approver) {
            // Get pending requests based on role
            $pending = [];
            if ($approver->role === 'motorpool_head') {
                $pending = db()->fetchAll(
                    "SELECT r.id, r.purpose, r.start_datetime, u.name as requester_name
                     FROM requests r
                     JOIN users u ON r.user_id = u.id
                     WHERE r.status = 'pending_motorpool'
                     AND r.deleted_at IS NULL
                     ORDER BY r.created_at ASC
                     LIMIT 5",
                    []
                );
            } elseif ($approver->role === 'approver' || $approver->role === 'admin') {
                $pending = db()->fetchAll(
                    "SELECT r.id, r.purpose, r.start_datetime, u.name as requester_name
                     FROM requests r
                     JOIN users u ON r.user_id = u.id
                     WHERE (r.status = 'pending' OR r.status = 'revision')
                     AND (r.department_id = ? OR r.approver_id = ?)
                     AND r.deleted_at IS NULL
                     ORDER BY r.created_at ASC
                     LIMIT 5",
                    [$approver->department_id, $approver->id]
                );
            }

            if (count($pending) > 0) {
                $template = NotificationTemplate::dailyDigest($approver, count($pending), $pending);

                if (self::sendEmail($approver->email, $template['subject'], $template['body'])) {
                    $sent++;
                }
            }
        }

        return $sent;
    }

    /**
     * Check if user has a specific notification type enabled
     */
    private static function userHasNotificationEnabled($userId, $notificationType)
    {
        $user = db()->fetch("SELECT notification_preferences, email FROM users WHERE id = ?", [$userId]);

        if (!$user || !$user->email) {
            return false;
        }

        // If preferences are set, check specific type
        if ($user->notification_preferences) {
            $prefs = json_decode($user->notification_preferences, true);
            return isset($prefs[$notificationType]) && $prefs[$notificationType] === true;
        }

        // Default: all notifications enabled
        return true;
    }

    /**
     * Send email using configured mailer
     */
    private static function sendEmail($to, $subject, $body)
    {
        try {
            $mailer = new Mailer();
            return $mailer->send($to, $subject, $body);
        } catch (Exception $e) {
            error_log("Notification send failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get notification preferences HTML for user settings
     */
    public static function getPreferencesHtml($currentPreferences = null)
    {
        $prefs = $currentPreferences ? json_decode($currentPreferences, true) : [];

        $notifications = [
            'request_approved' => ['label' => 'Request Approved', 'description' => 'When your request is approved'],
            'request_rejected' => ['label' => 'Request Rejected', 'description' => 'When your request is rejected'],
            'vehicle_assigned' => ['label' => 'Vehicle Assigned', 'description' => 'When vehicle is assigned to your request'],
            'trip_assigned' => ['label' => 'Trip Assigned', 'description' => 'When you are assigned to a trip (drivers)'],
            'request_cancelled' => ['label' => 'Request Cancelled', 'description' => 'When a request is cancelled'],
            'revision_requested' => ['label' => 'Revision Requested', 'description' => 'When revision is needed'],
            'trip_reminder' => ['label' => 'Trip Reminder', 'description' => 'Reminders before your trip'],
            'daily_digest' => ['label' => 'Daily Digest', 'description' => 'Daily summary of pending approvals']
        ];

        $html = '<div class="card"><div class="card-body"><h5 class="card-title mb-3">Email Notifications</h5>';
        $html .= '<p class="text-muted small">Select which notifications you want to receive:</p>';
        $html .= '<div class="form-check form-switch mb-2">';
        $html .= '<input class="form-check-input" type="checkbox" id="enableAll" checked>';
        $html .= '<label class="form-check-label" for="enableAll"><strong>Enable All Notifications</strong></label>';
        $html .= '</div>';

        foreach ($notifications as $key => $notif) {
            $checked = isset($prefs[$key]) ? ($prefs[$key] ? 'checked' : '') : 'checked';
            $html .= '<div class="form-check mb-2">';
            $html .= '<input class="form-check-input notification-toggle" type="checkbox" id="notif_' . $key . '" name="notifications[' . $key . ']" ' . $checked . '>';
            $html .= '<label class="form-check-label" for="notif_' . $key . '">';
            $html .= '<strong>' . $notif['label'] . '</strong>';
            $html .= '<br><small class="text-muted">' . $notif['description'] . '</small>';
            $html .= '</label>';
            $html .= '</div>';
        }

        $html .= '</div></div>';

        return $html;
    }
}
