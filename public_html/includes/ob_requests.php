<?php
/**
 * LOKA - Official Business Pass Slip helpers (Plan #22)
 *
 * OB Pass Slips are a first-class application parallel to vehicle requests:
 * own table, routing and timeline; optional 1:1 bind to a vehicle request.
 * Never stored in `requests` (except the nullable unique ob_request_id bind).
 */

if (!defined('OB_REQUESTS_LOADED')) {

    define('OB_REQUESTS_LOADED', 1);

    /** status => [label, bootstrap color] */
    define('OB_STATUSES', [
        'pending_supervisor' => ['label' => 'Pending Supervisor', 'color' => 'warning'],
        'pending_motorpool'  => ['label' => 'Pending Motorpool', 'color' => 'info'],
        'approved'           => ['label' => 'Approved', 'color' => 'success'],
        'departed'           => ['label' => 'Departed', 'color' => 'primary'],
        'coa_received'       => ['label' => 'CoA Received', 'color' => 'secondary'],
        'completed'          => ['label' => 'Completed', 'color' => 'dark'],
        'rejected'           => ['label' => 'Rejected', 'color' => 'danger'],
        'revision'           => ['label' => 'For Revision', 'color' => 'warning'],
        'cancelled'          => ['label' => 'Cancelled', 'color' => 'secondary'],
    ]);

    define('OB_SIGNATURE_OWNERS', ['employee', 'supervisor', 'motorpool', 'guard_departure', 'coa']);

    function obStatusLabel(string $status): string
    {
        return OB_STATUSES[$status]['label'] ?? ucfirst($status);
    }

    function obStatusColor(string $status): string
    {
        return OB_STATUSES[$status]['color'] ?? 'secondary';
    }

    function obSetting(string $key, string $default = ''): string
    {
        return tripSetting($key, $default);
    }

    /** All Father toggle: allow binding an approved OB to a vehicle request AFTER it was submitted. */
    function obAttachAfterSubmitAllowed(): bool
    {
        return obSetting('allow_ob_attach_after_submit', '0') === '1';
    }

    function obCoaTokenDays(): int
    {
        return max(1, (int) obSetting('ob_coa_token_days', '7'));
    }

    /**
     * Users tagged as Immediate Supervisors (admin checkbox users.is_ob_approver).
     *
     * @return list<object>{id:int,name:string}
     */
    function obGetSupervisors(): array
    {
        return db()->fetchAll(
            "SELECT id, name FROM users
             WHERE is_ob_approver = 1 AND status = 'active' AND deleted_at IS NULL
             ORDER BY name ASC"
        );
    }

    /**
     * Fleet plates for the optional Pass Slip dropdown (not deleted).
     *
     * @return list<object>{plate_number:string,make:?string,model:?string}
     */
    function obListVehicles(): array
    {
        return db()->fetchAll(
            "SELECT plate_number, make, model FROM vehicles
             WHERE deleted_at IS NULL
             ORDER BY plate_number ASC"
        );
    }

    /**
     * Active vehicle bookings (approved or pending motorpool). Used to color the
     * Pass Slip plate dropdown green/red for the chosen Official Business date.
     *
     * @return list<object>{plate_number:string,start_datetime:string,end_datetime:string,destination:?string,requester_name:string}
     */
    function obActiveVehicleTrips(): array
    {
        return db()->fetchAll(
            "SELECT v.plate_number, r.start_datetime, r.end_datetime, r.destination,
                    u.name AS requester_name
             FROM requests r
             JOIN vehicles v ON v.id = r.vehicle_id AND v.deleted_at IS NULL
             JOIN users u ON u.id = r.user_id
             WHERE r.deleted_at IS NULL
               AND r.vehicle_id IS NOT NULL
               AND r.status IN ('approved', 'pending_motorpool')
             ORDER BY r.start_datetime ASC"
        );
    }

    /**
     * Load an OB slip with the human names the views/PDF need.
     */
    function obFind(int $id): ?object
    {
        return db()->fetch(
            "SELECT o.*,
                    u.name AS employee_name, u.email AS employee_email,
                    d.name AS department_name,
                    s.name AS supervisor_name,
                    m.name AS motorpool_name,
                    dg.name AS departure_guard_name,
                    ag.name AS arrival_guard_name
             FROM ob_requests o
             JOIN users u ON o.user_id = u.id
             LEFT JOIN departments d ON o.department_id = d.id
             LEFT JOIN users s ON o.supervisor_user_id = s.id
             LEFT JOIN users m ON o.motorpool_head_id = m.id
             LEFT JOIN users dg ON o.departure_guard_id = dg.id
             LEFT JOIN users ag ON o.arrival_guard_id = ag.id
             WHERE o.id = ? AND o.deleted_at IS NULL",
            [$id]
        );
    }

    /**
     * Vehicle request bound to this OB (1:1), if any.
     */
    function obBoundVehicleRequest(int $obId): ?object
    {
        return db()->fetch(
            "SELECT id, status, destination, start_datetime, end_datetime
             FROM requests WHERE ob_request_id = ? AND deleted_at IS NULL
             ORDER BY id DESC LIMIT 1",
            [$obId]
        );
    }

    /**
     * OB slips the given user may bind to a vehicle request: own,
     * approved-or-later, not cancelled/rejected/revision, not already bound.
     * When $startDatetime is provided, only slips whose ob_date matches the
     * trip date are returned.
     *
     * @return list<object>
     */
    function obBindableForRequest(int $userId, ?string $startDatetime = null): array
    {
        $sql = "SELECT o.id, o.pass_slip_no, o.ob_date, o.purpose, o.status
             FROM ob_requests o
             WHERE o.user_id = ?
               AND o.deleted_at IS NULL
               AND o.status IN ('approved','departed','coa_received','completed')
               AND NOT EXISTS (
                   SELECT 1 FROM requests r WHERE r.ob_request_id = o.id AND r.deleted_at IS NULL
               )";
        $params = [$userId];
        if ($startDatetime !== null) {
            $sql .= ' AND o.ob_date = DATE(?)';
            $params[] = $startDatetime;
        }
        $sql .= ' ORDER BY o.ob_date DESC, o.id DESC LIMIT 50';
        return db()->fetchAll($sql, $params);
    }

    /**
     * Validate a bind; returns an error message or null when OK.
     */
    function obValidateBind(int $obId, int $userId, string $startDatetime, int $excludeRequestId = 0): ?string
    {
        $ob = db()->fetch(
            "SELECT id, user_id, ob_date, status, deleted_at FROM ob_requests WHERE id = ?",
            [$obId]
        );
        if (!$ob || $ob->deleted_at !== null) {
            return 'Selected OB Pass Slip was not found.';
        }
        if ((int) $ob->user_id !== $userId) {
            return 'You can only attach your own OB Pass Slip.';
        }
        if (!in_array($ob->status, ['approved', 'departed', 'coa_received', 'completed'], true)) {
            return 'The OB Pass Slip must be approved before it can be attached (status: ' . obStatusLabel($ob->status) . ').';
        }
        if (date('Y-m-d', strtotime($ob->ob_date)) !== date('Y-m-d', strtotime($startDatetime))) {
            return 'The OB Pass Slip date (' . date('M j, Y', strtotime($ob->ob_date)) . ') must overlap the trip date.';
        }
        $bound = db()->fetch(
            "SELECT id FROM requests WHERE ob_request_id = ? AND deleted_at IS NULL AND id != ?",
            [$obId, $excludeRequestId]
        );
        if ($bound) {
            return 'That OB Pass Slip is already attached to another vehicle request (1 OB = 1 vehicle request).';
        }
        return null;
    }

    /**
     * Save a canvas signature (PNG data URL) for an OB slip.
     * $who must be one of OB_SIGNATURE_OWNERS. Returns the stored relative path.
     */
    function obSaveSignature(int $obId, string $who, string $dataUrl): ?string
    {
        if (!in_array($who, OB_SIGNATURE_OWNERS, true)) {
            return null;
        }
        if (!preg_match('#^data:image/png;base64,#', $dataUrl)) {
            return null;
        }
        $binary = base64_decode(substr($dataUrl, strlen('data:image/png;base64,')), true);
        if ($binary === false || strlen($binary) < 500) {
            return null; // blank/garbage canvas
        }

        $dir = BASE_PATH . '/uploads/ob_signatures/' . $obId;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            error_log("obSaveSignature: cannot create {$dir}");
            return null;
        }

        $path = 'uploads/ob_signatures/' . $obId . '/' . $who . '.png';
        if (file_put_contents(BASE_PATH . '/' . $path, $binary) === false) {
            error_log("obSaveSignature: cannot write {$path}");
            return null;
        }
        return $path;
    }

    /** Signature image path for a slip + owner column name, or null. */
    function obSignaturePath(object $ob, string $who): ?string
    {
        $col = $who . '_signature_path';
        $p = $ob->{$col} ?? null;
        return ($p !== null && $p !== '' && is_file(BASE_PATH . '/' . $p)) ? (string) $p : null;
    }

    /**
     * Create a one-time public CoA token; returns the raw token (hash stored).
     */
    function obCreateCoaToken(int $obId): string
    {
        $raw = bin2hex(random_bytes(32));
        db()->update('ob_requests', [
            'coa_token_hash' => hash('sha256', $raw),
            'coa_token_expires_at' => date(DATETIME_FORMAT, time() + obCoaTokenDays() * 86400),
            'updated_at' => date(DATETIME_FORMAT),
        ], 'id = ?', [$obId]);
        return $raw;
    }

    function obFindCoaByToken(string $raw): ?object
    {
        if ($raw === '') {
            return null;
        }
        return db()->fetch(
            "SELECT o.*, u.name AS employee_name
             FROM ob_requests o
             JOIN users u ON o.user_id = u.id
             WHERE o.coa_token_hash = ?
               AND o.deleted_at IS NULL
               AND o.coa_token_expires_at IS NOT NULL
               AND o.coa_token_expires_at >= NOW()",
            [hash('sha256', $raw)]
        );
    }

    function obClearCoaToken(int $obId): void
    {
        db()->update('ob_requests', [
            'coa_token_hash' => null,
            'coa_token_expires_at' => null,
            'updated_at' => date(DATETIME_FORMAT),
        ], 'id = ?', [$obId]);
    }

    /** Persist one timeline row in ob_approvals. */
    function obLog(int $obId, string $type, string $action, ?int $actorId = null, ?string $comments = null): void
    {
        db()->insert('ob_approvals', [
            'ob_request_id' => $obId,
            'approver_user_id' => $actorId,
            'approval_type' => $type,
            'action' => $action,
            'comments' => $comments,
            'created_at' => date(DATETIME_FORMAT),
        ]);
    }

    /**
     * OB notification — same notify() channel, OB event keys, and never tagged
     * with a vehicle request id unless the slip is actually bound.
     */
    function obNotify(int $userId, string $type, string $title, string $message, ?string $link, ?int $requestId = null): void
    {
        notify($userId, $type, $title, $message, $link, $requestId);
    }

    /**
     * Next Pass Slip No.: YYMMDD-NNN (e.g. 260915-001).
     * 26=year, 09=month, 15=day of the OB date; NNN is the monthly series
     * and resets to 001 on the next calendar month. Uses MAX(serial) so
     * cancelled slips do not reuse a number. Unique-key retry in create.php.
     */
    function obGeneratePassSlipNo(?string $obDate = null): string
    {
        $ts = $obDate ? strtotime($obDate) : time();
        if ($ts === false) {
            $ts = time();
        }
        $ymd = date('ymd', $ts);
        $ym = date('ym', $ts);
        $seq = (int) db()->fetchColumn(
            "SELECT MAX(CAST(SUBSTRING_INDEX(pass_slip_no, '-', -1) AS UNSIGNED))
             FROM ob_requests
             WHERE pass_slip_no LIKE ?",
            [$ym . '%']
        );
        return $ymd . '-' . str_pad((string) ($seq + 1), 3, '0', STR_PAD_LEFT);
    }

    require_once __DIR__ . '/ob_esign.php';
    require_once __DIR__ . '/ob_guard_bind.php';
    require_once __DIR__ . '/ob_participants.php';
}
