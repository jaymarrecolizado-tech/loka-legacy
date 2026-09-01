<?php
/**
 * LOKA - Printable Trip Request Form
 * Generates a document with approval status (no signature lines)
 */

$requestId = (int) get('id');

// Get request with all related data
$request = db()->fetch(
    "SELECT r.*, 
            u.name as requester_name, u.email as requester_email, u.phone as requester_phone,
            d.name as department_name,
            v.plate_number, v.make, v.model as vehicle_model,
            vt.name as vehicle_type,
            (SELECT name FROM users WHERE id = dr.user_id) as driver_name,
            (SELECT phone FROM users WHERE id = dr.user_id) as driver_phone
     FROM requests r
     JOIN users u ON r.user_id = u.id
     JOIN departments d ON r.department_id = d.id
     LEFT JOIN vehicles v ON r.vehicle_id = v.id AND v.deleted_at IS NULL
     LEFT JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
     LEFT JOIN drivers dr ON r.driver_id = dr.id AND dr.deleted_at IS NULL
     WHERE r.id = ? AND r.deleted_at IS NULL",
    [$requestId]
);

if (!$request) {
    redirectWith('/?page=requests', 'danger', 'Request not found.');
}

// Check access - requester, approvers, motorpool, guards, admin, or assigned/requested driver
$isDriverOnTrip = isAssignedOrRequestedDriver($request);
if ($request->user_id !== userId() && !isApprover() && !isGuard() && !isMotorpool() && !isAdmin() && !$isDriverOnTrip) {
    redirectWith('/?page=requests', 'danger', 'You do not have permission to view this request.');
}

// Non-approver drivers may print only completed trips they belong to
if ($isDriverOnTrip && !isApprover() && $request->user_id !== userId() && $request->status !== STATUS_COMPLETED) {
    redirectWith(
        '/?page=requests&action=view&id=' . $requestId,
        'warning',
        'You can print the trip form only after the trip is completed.'
    );
}

// Get approval records - fetch the LATEST one for each type (in case of revision -> approved)
$deptApproval = db()->fetch(
    "SELECT a.*, u.name as approver_name 
     FROM approvals a 
     JOIN users u ON a.approver_id = u.id 
     WHERE a.request_id = ? AND a.approval_type = 'department'
     ORDER BY a.id DESC LIMIT 1",
    [$requestId]
);

$motorpoolApproval = db()->fetch(
    "SELECT a.*, u.name as approver_name 
     FROM approvals a 
     JOIN users u ON a.approver_id = u.id 
     WHERE a.request_id = ? AND a.approval_type = 'motorpool'
     ORDER BY a.id DESC LIMIT 1",
    [$requestId]
);

// Get workflow for pending approvers
$workflow = db()->fetch("SELECT * FROM approval_workflow WHERE request_id = ?", [$requestId]);

// Get passengers (users and guests)
$passengers = db()->fetchAll(
    "SELECT u.name, d.name as department_name, rp.guest_name
     FROM request_passengers rp
     LEFT JOIN users u ON rp.user_id = u.id
     LEFT JOIN departments d ON u.department_id = d.id
     WHERE rp.request_id = ?
     ORDER BY u.name, rp.guest_name",
    [$requestId]
);

// Get guard tracking info
$dispatchGuard = null;
$arrivalGuard = null;
if ($request->dispatch_guard_id) {
    $dispatchGuard = db()->fetch("SELECT name FROM users WHERE id = ?", [$request->dispatch_guard_id]);
}
if ($request->arrival_guard_id) {
    $arrivalGuard = db()->fetch("SELECT name FROM users WHERE id = ?", [$request->arrival_guard_id]);
}

// Get assignment history
$assignmentHistory = db()->fetchAll(
    "SELECT ah.*, 
            u.name as assigned_by_name,
            v.plate_number, v.make, v.model as vehicle_model,
            pv.plate_number as prev_plate_number, pv.make as prev_make, pv.model as prev_vehicle_model,
            d_user.name as driver_name,
            pd_user.name as prev_driver_name
     FROM assignment_history ah
     JOIN users u ON ah.assigned_by = u.id
     LEFT JOIN vehicles v ON ah.vehicle_id = v.id
     LEFT JOIN vehicles pv ON ah.previous_vehicle_id = pv.id
     LEFT JOIN drivers d ON ah.driver_id = d.id
     LEFT JOIN users d_user ON d.user_id = d_user.id
     LEFT JOIN drivers pd ON ah.previous_driver_id = pd.id
     LEFT JOIN users pd_user ON pd.user_id = pd_user.id
     WHERE ah.request_id = ?
     ORDER BY ah.created_at ASC",
    [$requestId]
);

// Get original assignment (first record)
$originalAssignment = null;
if (!empty($assignmentHistory)) {
    $originalAssignment = $assignmentHistory[0];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trip Request Form #<?= $requestId ?> - LOKA</title>
    <style>
        @page {
            size: auto;
            margin: 15mm 12mm;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Times New Roman', Times, serif;
            font-size: 11pt;
            line-height: 1.3;
            color: #000;
            background: #fff;
        }

        .document {
            max-width: 100%;
            margin: 0 auto;
            background: #fff;
        }

        .header {
            text-align: center;
            margin-bottom: 12px;
            border-bottom: 2px solid #000;
            padding-bottom: 8px;
        }

        .header h1 {
            font-size: 14pt;
            font-weight: bold;
            margin-bottom: 3px;
            text-transform: uppercase;
        }

        .header h2 {
            font-size: 12pt;
            font-weight: bold;
            margin-bottom: 3px;
        }

        .header .control-no {
            font-size: 10pt;
            margin-top: 5px;
        }

        .section {
            margin-bottom: 10px;
            page-break-inside: avoid;
        }

        .section-title {
            font-weight: bold;
            font-size: 10pt;
            background: #e8e8e8;
            padding: 3px 8px;
            border: 1px solid #000;
            border-bottom: none;
        }

        .section-content {
            border: 1px solid #000;
            padding: 6px 8px;
        }

        .row {
            display: flex;
            margin-bottom: 4px;
        }

        .row:last-child {
            margin-bottom: 0;
        }

        .label {
            font-weight: bold;
            width: 120px;
            flex-shrink: 0;
            font-size: 10pt;
        }

        .value {
            flex: 1;
            border-bottom: 1px dotted #999;
            padding-left: 5px;
            font-size: 10pt;
            min-height: 16px;
        }

        .col-2 {
            display: flex;
            gap: 15px;
        }

        .col-2 > div {
            flex: 1;
        }

        .approval-box {
            border: 1px solid #000;
            padding: 8px;
            min-height: 80px;
        }

        .approval-box h3 {
            font-size: 10pt;
            text-align: center;
            margin-bottom: 8px;
            padding-bottom: 3px;
            border-bottom: 1px solid #000;
        }

        .approval-status {
            text-align: center;
            margin-bottom: 8px;
            padding: 4px;
            font-weight: bold;
            font-size: 10pt;
        }

        .status-approved {
            background: #d4edda;
            color: #155724;
            border: 1px solid #155724;
        }

        .status-rejected {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #721c24;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #856404;
        }

        .checkbox-row {
            display: flex;
            gap: 15px;
            margin: 6px 0;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 10pt;
        }

        .checkbox {
            width: 16px;
            height: 16px;
            border: 2px solid #000;
            display: inline-block;
            position: relative;
            vertical-align: middle;
        }

        .checkbox.checked {
            background: #000;
        }

        .checkbox.checked::after {
            content: '✔';
            color: #fff;
            font-size: 12px;
            font-weight: bold;
            position: absolute;
            top: -2px;
            left: 1px;
            line-height: 1;
        }

        .guard-section {
            margin-bottom: 10px;
        }

        .guard-section .section-title {
            background: #fff3cd;
        }

        .guard-box {
            border: 1px solid #000;
            padding: 6px;
            text-align: center;
            min-height: 50px;
        }

        .guard-box h4 {
            font-size: 9pt;
            margin-bottom: 5px;
            border-bottom: 1px solid #000;
            padding-bottom: 3px;
        }

        .guard-time {
            font-size: 11pt;
            font-weight: bold;
            margin: 5px 0;
        }

        .guard-notes {
            font-size: 9pt;
            padding: 5px;
            background: #f9f9f9;
            border: 1px solid #ddd;
            margin-top: 5px;
        }

        .print-name {
            font-weight: 700;
            text-transform: uppercase;
        }

        .footer {
            margin-top: 15px;
            text-align: center;
            font-size: 8pt;
            color: #666;
            border-top: 1px solid #ccc;
            padding-top: 5px;
        }

        .print-btn {
            position: fixed;
            top: 15px;
            right: 15px;
            padding: 8px 16px;
            background: #0d6efd;
            color: #fff;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            z-index: 1000;
        }

        .print-btn:hover {
            background: #0b5ed7;
        }

        .back-btn {
            position: fixed;
            top: 15px;
            right: 100px;
            padding: 8px 16px;
            background: #6c757d;
            color: #fff;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            text-decoration: none;
            z-index: 1000;
        }

        .passengers-list {
            font-size: 9pt;
        }

        .trip-duration {
            font-size: 9pt;
            font-weight: bold;
            margin-top: 5px;
        }

        @media print {
            @page {
                size: auto;
                margin: 10mm 8mm;
            }

            body {
                font-size: 10pt;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .checkbox.checked {
                background: #000 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .print-btn,
            .back-btn {
                display: none !important;
            }

            .document {
                max-width: 100%;
            }

            .section {
                page-break-inside: avoid;
            }

            .col-2 {
                page-break-inside: avoid;
            }
        }

        @media screen and (max-width: 8.5in) {
            body {
                padding: 10px;
            }
        }
    </style>
</head>

<body>
    <a href="<?= APP_URL ?>/?page=requests&action=view&id=<?= $requestId ?>" class="back-btn">← Back</a>
    <button class="print-btn" onclick="window.print()">🖨️ Print</button>

    <div class="document">
        <!-- Header -->
        <div class="header">
            <h1>DEPARTMENT OF INFORMATION AND COMMUNICATIONS TECHNOLOGY</h1>
            <h2>VEHICLE REQUEST FORM</h2>
            <div class="control-no">Control No.:
                <strong>VRF-<?= date('Y') ?>-<?= str_pad($requestId, 5, '0', STR_PAD_LEFT) ?></strong></div>
        </div>

        <!-- Requester Information -->
        <div class="section">
            <div class="section-title">I. REQUESTER INFORMATION</div>
            <div class="section-content">
                <div class="row">
                    <span class="label">Name:</span>
                    <span class="value print-name"><?= e(strtoupper($request->requester_name)) ?></span>
                </div>
                <div class="row">
                    <span class="label">Department:</span>
                    <span class="value"><?= e($request->department_name) ?></span>
                </div>
                <div class="row">
                    <span class="label">Contact No.:</span>
                    <span class="value"><?= e($request->requester_phone ?: 'N/A') ?></span>
                </div>
                <div class="row">
                    <span class="label">Email:</span>
                    <span class="value"><?= e($request->requester_email) ?></span>
                </div>
            </div>
        </div>

        <!-- Trip Details -->
        <div class="section">
            <div class="section-title">II. TRIP DETAILS</div>
            <div class="section-content">
                <div class="row">
                    <span class="label">Purpose:</span>
                    <span class="value"><?= e($request->purpose) ?></span>
                </div>
                <div class="row">
                    <span class="label">Destination:</span>
                    <span class="value"><?= e($request->destination) ?></span>
                </div>
                <div class="row">
                    <span class="label">Departure:</span>
                    <span class="value"><?= date('F j, Y - g:i A', strtotime($request->start_datetime)) ?></span>
                </div>
                <div class="row">
                    <span class="label">Return:</span>
                    <span class="value"><?= date('F j, Y - g:i A', strtotime($request->end_datetime)) ?></span>
                </div>
                <div class="row">
                    <span class="label">No. of Passengers:</span>
                    <span class="value"><?= $request->passenger_count ?></span>
                </div>
                <div class="row">
                    <span class="label">Passengers:</span>
                    <span class="value print-name">
                        <?= e(strtoupper($request->requester_name)) ?> (REQUESTER)<?php if (!empty($passengers)): ?>,
                            <?= implode(', ', array_map(function ($p) {
                                return e(strtoupper($p->name ?: $p->guest_name)); }, $passengers)) ?>
                        <?php endif; ?>
                    </span>
                </div>
                <?php if ($request->notes): ?>
                    <div class="row">
                        <span class="label">Remarks:</span>
                        <span class="value"><?= e($request->notes) ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Vehicle Assignment (if approved) -->
        <?php if ($request->vehicle_id): ?>
            <div class="section">
                <div class="section-title">III. VEHICLE & DRIVER ASSIGNMENT</div>
                <div class="section-content">
                    <div class="row">
                        <span class="label">Vehicle:</span>
                        <span class="value"><?= e($request->plate_number) ?> -
                            <?= e($request->make . ' ' . $request->vehicle_model) ?></span>
                    </div>
                    <div class="row">
                        <span class="label">Vehicle Type:</span>
                        <span class="value"><?= e($request->vehicle_type) ?></span>
                    </div>
                    <div class="row">
                        <span class="label">Driver:</span>
                        <span class="value print-name"><?= e(strtoupper($request->driver_name)) ?></span>
                    </div>
                    <div class="row">
                        <span class="label">Driver Contact:</span>
                        <span class="value"><?= e($request->driver_phone ?: 'N/A') ?></span>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($assignmentHistory) && count($assignmentHistory) > 1): ?>
            <div class="section">
                <div class="section-title" style="background: #fff3cd;">III-A. ASSIGNMENT CHANGE HISTORY</div>
                <div class="section-content">
                    <?php foreach ($assignmentHistory as $i => $ah): ?>
                        <?php if ($i === 0): ?>
                            <div style="margin-bottom: 6px; padding-bottom: 4px; border-bottom: 1px dotted #ccc;">
                                <strong style="font-size: 9pt;">Original Assignment (<?= formatDateTime($ah->created_at) ?>)</strong><br>
                                 <span style="font-size: 9pt;">
                                    Vehicle: <?= e($ah->plate_number) ?> - <?= e($ah->make . ' ' . $ah->vehicle_model) ?><br>
                                    Driver: <span class="print-name"><?= e(strtoupper($ah->driver_name)) ?></span>
                                </span>
                            </div>
                        <?php elseif ($ah->action === 'overridden'): ?>
                            <div style="margin-bottom: 6px; padding-bottom: 4px; <?= $i < count($assignmentHistory) - 1 ? 'border-bottom: 1px dotted #ccc;' : '' ?>">
                                <strong style="font-size: 9pt; color: #856404;">
                                    Override #<?= $i ?> - <?= formatDateTime($ah->created_at) ?> by <span class="print-name"><?= e(strtoupper($ah->assigned_by_name)) ?></span>
                                </strong><br>
                                <span style="font-size: 9pt;">
                                    <?php if ($ah->prev_plate_number): ?>
                                        Vehicle: <span style="text-decoration: line-through; color: #999;"><?= e($ah->prev_plate_number) ?></span>
                                        → <strong><?= e($ah->plate_number) ?></strong><br>
                                    <?php endif; ?>
                                    <?php if ($ah->prev_driver_name): ?>
                                        Driver: <span style="text-decoration: line-through; color: #999;"><?= e(strtoupper($ah->prev_driver_name)) ?></span>
                                        → <strong class="print-name"><?= e(strtoupper($ah->driver_name)) ?></strong><br>
                                    <?php endif; ?>
                                    <?php if ($ah->reason): ?>
                                        <em style="color: #666;">Reason: <?= e($ah->reason) ?></em>
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Approval Section -->
        <div class="section">
            <?php 
            $approvalSectionNum = 'III';
            if ($request->vehicle_id) {
                if (!empty($assignmentHistory) && count($assignmentHistory) > 1) {
                    $approvalSectionNum = 'V';
                } else {
                    $approvalSectionNum = 'IV';
                }
            }
            ?>
            <div class="section-title"><?= $approvalSectionNum ?>. APPROVAL</div>
            <div class="section-content">
                <div class="col-2">
                    <!-- Department Approver -->
                    <div class="approval-box">
                        <h3>DEPARTMENT APPROVER</h3>

                        <?php if ($deptApproval): ?>
                            <?php 
                            $statusClass = 'status-pending';
                            $statusText = strtoupper($deptApproval->status);
                            if ($deptApproval->status === 'approved') {
                                $statusClass = 'status-approved';
                                $statusText = 'APPROVED';
                            } elseif ($deptApproval->status === 'rejected') {
                                $statusClass = 'status-rejected';
                                $statusText = 'DISAPPROVED';
                            } elseif ($deptApproval->status === 'revision') {
                                $statusClass = 'status-pending';
                                $statusText = 'REVISION REQUESTED';
                            }
                            ?>
                            <div class="approval-status <?= $statusClass ?>">
                                <?= $statusText ?>
                            </div>

                            <div class="checkbox-row">
                                <div class="checkbox-item">
                                    <span
                                        class="checkbox <?= $deptApproval->status === 'approved' ? 'checked' : '' ?>"></span>
                                    <span>Approved</span>
                                </div>
                                <div class="checkbox-item">
                                    <span
                                        class="checkbox <?= $deptApproval->status === 'rejected' ? 'checked' : '' ?>"></span>
                                    <span>Disapproved</span>
                                </div>
                            </div>

                            <?php if ($deptApproval->comments): ?>
                                <div class="guard-notes">
                                    <em>Comments: <?= e($deptApproval->comments) ?></em>
                                </div>
                            <?php endif; ?>

                            <div style="text-align: center; margin-top: 8px; font-size: 9pt;">
                                <strong class="print-name"><?= e(strtoupper($deptApproval->approver_name)) ?></strong><br>
                                <span style="color: #555;">
                                    <?php if ($deptApproval->status === 'approved'): ?>
                                        Approved on <?= date('F j, Y', strtotime($deptApproval->created_at)) ?>
                                    <?php elseif ($deptApproval->status === 'rejected'): ?>
                                        Disapproved on <?= date('F j, Y', strtotime($deptApproval->created_at)) ?>
                                    <?php else: ?>
                                        Revision requested on <?= date('F j, Y', strtotime($deptApproval->created_at)) ?>
                                    <?php endif; ?>
                                </span>
                            </div>

                        <?php else: ?>
                            <div class="approval-status status-pending">PENDING</div>

                            <div class="checkbox-row">
                                <div class="checkbox-item">
                                    <span class="checkbox"></span>
                                    <span>Approved</span>
                                </div>
                                <div class="checkbox-item">
                                    <span class="checkbox"></span>
                                    <span>Disapproved</span>
                                </div>
                            </div>

                            <div style="text-align: center; margin-top: 8px; font-size: 9pt; color: #666;">
                                Awaiting approval
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Motorpool Head -->
                    <div class="approval-box">
                        <h3>MOTORPOOL HEAD</h3>

                        <?php if ($motorpoolApproval): ?>
                            <?php 
                            $mpStatusClass = 'status-pending';
                            $mpStatusText = strtoupper($motorpoolApproval->status);
                            if ($motorpoolApproval->status === 'approved') {
                                $mpStatusClass = 'status-approved';
                                $mpStatusText = 'APPROVED';
                            } elseif ($motorpoolApproval->status === 'rejected') {
                                $mpStatusClass = 'status-rejected';
                                $mpStatusText = 'DISAPPROVED';
                            } elseif ($motorpoolApproval->status === 'revision') {
                                $mpStatusClass = 'status-pending';
                                $mpStatusText = 'REVISION REQUESTED';
                            }
                            ?>
                            <div class="approval-status <?= $mpStatusClass ?>">
                                <?= $mpStatusText ?>
                            </div>

                            <div class="checkbox-row">
                                <div class="checkbox-item">
                                    <span
                                        class="checkbox <?= $motorpoolApproval->status === 'approved' ? 'checked' : '' ?>"></span>
                                    <span>Approved</span>
                                </div>
                                <div class="checkbox-item">
                                    <span
                                        class="checkbox <?= $motorpoolApproval->status === 'rejected' ? 'checked' : '' ?>"></span>
                                    <span>Disapproved</span>
                                </div>
                            </div>

                            <?php if ($motorpoolApproval->comments): ?>
                                <div class="guard-notes">
                                    <em>Comments: <?= e($motorpoolApproval->comments) ?></em>
                                </div>
                            <?php endif; ?>

                            <div style="text-align: center; margin-top: 8px; font-size: 9pt;">
                                <strong class="print-name"><?= e(strtoupper($motorpoolApproval->approver_name)) ?></strong><br>
                                <span style="color: #555;">
                                    <?php if ($motorpoolApproval->status === 'approved'): ?>
                                        Approved on <?= date('F j, Y', strtotime($motorpoolApproval->created_at)) ?>
                                    <?php elseif ($motorpoolApproval->status === 'rejected'): ?>
                                        Disapproved on <?= date('F j, Y', strtotime($motorpoolApproval->created_at)) ?>
                                    <?php else: ?>
                                        Revision requested on <?= date('F j, Y', strtotime($motorpoolApproval->created_at)) ?>
                                    <?php endif; ?>
                                </span>
                            </div>

                        <?php else: ?>
                            <div class="approval-status status-pending">
                                <?= $request->status === STATUS_PENDING ? 'AWAITING DEPT. APPROVAL' : 'PENDING' ?>
                            </div>

                            <div class="checkbox-row">
                                <div class="checkbox-item">
                                    <span class="checkbox"></span>
                                    <span>Approved</span>
                                </div>
                                <div class="checkbox-item">
                                    <span class="checkbox"></span>
                                    <span>Disapproved</span>
                                </div>
                            </div>

                            <div style="text-align: center; margin-top: 8px; font-size: 9pt; color: #666;">
                                Awaiting approval
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Guard Tracking Section -->
        <?php if ($request->status === STATUS_COMPLETED || $request->actual_dispatch_datetime || $request->actual_arrival_datetime): ?>
        <div class="section guard-section">
            <div class="section-title"><?= $request->vehicle_id ? 'V' : 'IV' ?>. GUARD TRACKING RECORD</div>
            <div class="section-content">
                <div class="col-2">
                    <!-- Dispatch Record -->
                    <div class="guard-box">
                        <h4>DISPATCH (Time Out)</h4>
                        <?php if ($request->actual_dispatch_datetime): ?>
                            <div class="guard-time">
                                <?= date('M j, Y - g:i A', strtotime($request->actual_dispatch_datetime)) ?>
                            </div>
                            <div style="font-size: 9pt; margin-top: 5px;">
                                <strong class="print-name"><?= e(strtoupper($dispatchGuard->name ?? 'Guard')) ?></strong>
                            </div>
                        <?php else: ?>
                            <div class="approval-status status-pending">PENDING</div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Arrival Record -->
                    <div class="guard-box">
                        <h4>ARRIVAL (Time In)</h4>
                        <?php if ($request->actual_arrival_datetime): ?>
                            <div class="guard-time">
                                <?= date('M j, Y - g:i A', strtotime($request->actual_arrival_datetime)) ?>
                            </div>
                            <div style="font-size: 9pt; margin-top: 5px;">
                                <strong class="print-name"><?= e(strtoupper($arrivalGuard->name ?? 'Guard')) ?></strong>
                            </div>
                            <?php
                            if ($request->actual_dispatch_datetime && $request->actual_arrival_datetime) {
                                $dispatch = new DateTime($request->actual_dispatch_datetime);
                                $arrival = new DateTime($request->actual_arrival_datetime);
                                $interval = $dispatch->diff($arrival);
                                $hours = $interval->h + ($interval->days * 24);
                                $minutes = $interval->i;
                            ?>
                            <div class="trip-duration">
                                Duration: <?= $hours ?>h <?= $minutes ?>m
                            </div>
                            <?php } ?>
                        <?php else: ?>
                            <div class="approval-status status-pending">PENDING</div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if ($request->guard_notes): ?>
                <div class="guard-notes">
                    <strong>Notes:</strong> <?= nl2br(e($request->guard_notes)) ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Footer -->
        <div class="footer">
            <p>Generated from LOKA Fleet Management System on <?= date('F j, Y g:i A') ?></p>
            <p>Document Reference: VRF-<?= date('Y') ?>-<?= str_pad($requestId, 5, '0', STR_PAD_LEFT) ?></p>
        </div>
    </div>
</body>

</html>