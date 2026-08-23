<?php
/**
 * LOKA - Patch Notes Page
 * 
 * Displays version history and changelog for all users
 */

$pageTitle = 'Patch Notes';

// Patch notes data - organized by version (from git history)
$patchNotes = [
    [
        'version' => '1.0.0',
        'date' => '2026-02-22',
        'title' => 'Initial Release',
        'changes' => [
            ['type' => 'feature', 'text' => 'Fleet management system launched with core functionality'],
            ['type' => 'feature', 'text' => 'User authentication and role-based access control'],
            ['type' => 'feature', 'text' => 'Vehicle request and approval workflow'],
            ['type' => 'feature', 'text' => 'Driver assignment and tracking system'],
            ['type' => 'feature', 'text' => 'Motorpool head approval system'],
            ['type' => 'feature', 'text' => 'Guard dispatch and arrival tracking'],
        ]
    ],
    [
        'version' => '1.1.0',
        'date' => '2026-02-22',
        'title' => 'Admin Reports & Export',
        'changes' => [
            ['type' => 'feature', 'text' => 'Added admin export reports with CSV support'],
            ['type' => 'feature', 'text' => 'Added PDF export using TCPDF library'],
            ['type' => 'feature', 'text' => 'Support exports: requests, users, vehicles, departments, maintenance, audit logs'],
        ]
    ],
    [
        'version' => '1.2.0',
        'date' => '2026-02-23',
        'title' => 'Bug Fixes & Profile Editing',
        'changes' => [
            ['type' => 'fix', 'text' => 'Fixed session persistence issues after login'],
            ['type' => 'fix', 'text' => 'Fixed Cache class returning arrays instead of objects'],
            ['type' => 'feature', 'text' => 'Added multiple sequential destinations support (arrow separator)'],
            ['type' => 'feature', 'text' => 'Allow users to edit phone number and department in profile'],
            ['type' => 'fix', 'text' => 'Fixed printable form showing latest approval status correctly'],
            ['type' => 'improvement', 'text' => 'Added vehicle selection validation on request creation'],
        ]
    ],
    [
        'version' => '1.3.0',
        'date' => '2026-02-23',
        'title' => 'Reports Enhancement',
        'changes' => [
            ['type' => 'feature', 'text' => 'Added PDF export button to Reports page'],
            ['type' => 'feature', 'text' => 'Added Vehicle History and Driver reports'],
            ['type' => 'feature', 'text' => 'Added comprehensive Trip Requests report'],
            ['type' => 'fix', 'text' => 'Removed non-existent control_number column from reports queries'],
            ['type' => 'improvement', 'text' => 'Restructured reports page with cleaner card layout'],
        ]
    ],
    [
        'version' => '1.4.0',
        'date' => '2026-02-23',
        'title' => 'Export Improvements',
        'changes' => [
            ['type' => 'feature', 'text' => 'Enhanced CSV exports with 30 fields including vehicle details, driver license, guard info'],
            ['type' => 'feature', 'text' => 'Added summary stats to PDF exports'],
            ['type' => 'feature', 'text' => 'Added actual dispatch/arrival times and duration to exports'],
            ['type' => 'feature', 'text' => 'New CSV export for vehicle history with all fields'],
            ['type' => 'feature', 'text' => 'New CSV export for driver reports'],
        ]
    ],
    [
        'version' => '1.5.0',
        'date' => '2026-02-27',
        'title' => 'End-User Improvements v1.0',
        'changes' => [
            ['type' => 'feature', 'text' => 'Driver Trip History View - Added mileage columns to my-trips page'],
            ['type' => 'feature', 'text' => 'Mileage Tracking - Optional mileage_start (motorpool) and mileage_end (guard) with auto-calculation'],
            ['type' => 'feature', 'text' => 'Guard Completed Trips Export - CSV export button on completed tab'],
            ['type' => 'feature', 'text' => 'Travel Documents Tracking - Travel order/OB slip checkboxes with required reference numbers'],
            ['type' => 'feature', 'text' => 'Requester Cancel at Any Stage - Allow cancellation for all non-completed statuses'],
            ['type' => 'feature', 'text' => 'Approver Vehicle Display - Added vehicle column to approval list'],
            ['type' => 'feature', 'text' => 'Mobile-First Dashboard - Fixed sidebar toggle, overlay for mobile, CSS improvements'],
            ['type' => 'feature', 'text' => 'Vehicle Type CRUD Module - Full admin module for managing vehicle types'],
        ]
    ],
    [
        'version' => '1.6.0',
        'date' => '2026-02-27',
        'title' => 'PDF & API Improvements',
        'changes' => [
            ['type' => 'fix', 'text' => 'Fixed PDF column width overflow - adjusted margins and column widths'],
            ['type' => 'feature', 'text' => 'Driver Trip History PDF Export - PDF export for manual trip tickets'],
            ['type' => 'feature', 'text' => 'Guard Completed Trips Standalone Page - Dedicated page with filters and export'],
            ['type' => 'feature', 'text' => 'Vehicle Details Card in Approval View - Show vehicle and driver information'],
            ['type' => 'feature', 'text' => 'Vehicle Types CRUD API - RESTful endpoints for vehicle type operations'],
            ['type' => 'feature', 'text' => 'Requests API - Endpoints for cancel, mileage, and documents'],
        ]
    ],
    [
        'version' => '1.7.0',
        'date' => '2026-02-27',
        'title' => 'Major Features: Analytics, Email & Maintenance',
        'changes' => [
            ['type' => 'feature', 'text' => 'Dashboard Analytics & Charts - Chart.js integration with daily trips, status distribution, department trips, peak hours'],
            ['type' => 'feature', 'text' => 'Email Notification System - 8 HTML email templates (approved, rejected, assigned, cancelled, revision, reminder, digest)'],
            ['type' => 'feature', 'text' => 'Vehicle Maintenance Scheduler - 7 recurring maintenance types with mileage/time intervals'],
            ['type' => 'feature', 'text' => 'Maintenance calendar view with upcoming and overdue alerts'],
            ['type' => 'improvement', 'text' => 'Added mileage_at_completion tracking for maintenance history'],
        ]
    ],
    [
        'version' => '1.8.0',
        'date' => '2026-02-27',
        'title' => 'Mobile Responsiveness',
        'changes' => [
            ['type' => 'feature', 'text' => 'Mobile-First Dashboard - Responsive charts and cards'],
            ['type' => 'improvement', 'text' => 'Enhanced mobile styles with 44px touch targets'],
            ['type' => 'fix', 'text' => 'Fixed hamburger menu items visibility on mobile devices'],
            ['type' => 'improvement', 'text' => 'Added z-index fixes for sidebar overlay on mobile'],
        ]
    ],
    [
        'version' => '1.9.0',
        'date' => '2026-02-27',
        'title' => 'Cancellation & Access Control',
        'changes' => [
            ['type' => 'feature', 'text' => 'Enhanced request cancellation - Allow cancellation for more statuses with mandatory reason'],
            ['type' => 'feature', 'text' => 'Extended Vehicle Types access to Motorpool Head and Approver roles'],
            ['type' => 'fix', 'text' => 'Fixed "Cannot modify header information" error in cancel.php'],
            ['type' => 'fix', 'text' => 'Fixed approval_workflow ENUM data truncation error'],
            ['type' => 'improvement', 'text' => 'Added cancellation details card for admins/approvers/motorpool'],
        ]
    ],
    [
        'version' => '2.0.0',
        'date' => '2026-03-01',
        'title' => 'Maintenance & Utilities',
        'changes' => [
            ['type' => 'feature', 'text' => 'Added delete functionality for maintenance requests (admin only)'],
            ['type' => 'feature', 'text' => 'Added missing helper functions (isPost, setFlashMessage)'],
            ['type' => 'fix', 'text' => 'Fixed vehicle status validation on edit'],
        ]
    ],
    [
        'version' => '2.1.0',
        'date' => '2026-03-01',
        'title' => 'Completed Trips Page',
        'changes' => [
            ['type' => 'feature', 'text' => 'Added role-based Completed Trips page for all users'],
            ['type' => 'feature', 'text' => 'Role-based filtering: Driver (their trips), Guard (dispatched/received), Approver (department), Motorpool/Admin (all)'],
            ['type' => 'feature', 'text' => 'Statistics cards (total trips, distance, hours, passengers)'],
            ['type' => 'feature', 'text' => 'Date range and search filters with CSV export'],
        ]
    ],
    [
        'version' => '2.2.0',
        'date' => '2026-03-01',
        'title' => 'Pagination Improvements',
        'changes' => [
            ['type' => 'feature', 'text' => 'Improved pagination with per-page selector (10, 25, 50, 100)'],
            ['type' => 'feature', 'text' => 'Added first/last page buttons to pagination'],
            ['type' => 'feature', 'text' => 'Show item range (X-Y of Z trips)'],
            ['type' => 'improvement', 'text' => 'Better page number display with ellipsis for large page counts'],
            ['type' => 'improvement', 'text' => 'Completed trips page defaults to showing ALL trips instead of current month'],
        ]
    ],
    [
        'version' => '2.3.0',
        'date' => '2026-03-01',
        'title' => 'UI/UX Fixes',
        'changes' => [
            ['type' => 'fix', 'text' => 'Added vertical scrollbar to sidebar navigation'],
            ['type' => 'fix', 'text' => 'Fixed maintenance schedule error'],
            ['type' => 'feature', 'text' => 'Added table sorting to completed trips page'],
        ]
    ],
    [
        'version' => '2.4.0',
        'date' => '2026-03-01',
        'title' => 'Booking Rules & Settings',
        'changes' => [
            ['type' => 'fix', 'text' => 'Fixed booking rules validation'],
            ['type' => 'fix', 'text' => 'Fixed settings insert query'],
        ]
    ],
    [
        'version' => '2.5.0',
        'date' => '2026-03-01',
        'title' => 'Full-Stack Development Setup',
        'changes' => [
            ['type' => 'feature', 'text' => 'Added Vue 3 + Vite frontend framework with TypeScript'],
            ['type' => 'feature', 'text' => 'Added Tailwind CSS and DaisyUI for styling'],
            ['type' => 'feature', 'text' => 'Added Vitest and Playwright for testing'],
            ['type' => 'feature', 'text' => 'Added ESLint and Prettier for code quality'],
            ['type' => 'feature', 'text' => 'Added Docker and docker-compose configuration'],
            ['type' => 'feature', 'text' => 'Added GitHub Actions CI/CD workflows'],
            ['type' => 'feature', 'text' => 'Added PHPStan and PHPCS configuration'],
            ['type' => 'feature', 'text' => 'Added Pinia stores for state management'],
        ]
    ],
    [
        'version' => '2.5.1',
        'date' => '2026-03-01',
        'title' => 'Guard Dashboard Fix',
        'changes' => [
            ['type' => 'fix', 'text' => 'Fixed guard dashboard card counts and tab badges to reflect actual data'],
            ['type' => 'improvement', 'text' => 'Added 4th stat card for Completed trips'],
            ['type' => 'improvement', 'text' => 'Added count badges to all filter tabs'],
        ]
    ],
];

// Helper function to get badge class for change type
function getChangeBadgeClass($type) {
    switch ($type) {
        case 'feature':
            return 'bg-success';
        case 'fix':
            return 'bg-danger';
        case 'improvement':
            return 'bg-info';
        case 'security':
            return 'bg-warning';
        default:
            return 'bg-secondary';
    }
}

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-journal-text me-2"></i>Patch Notes</h4>
            <p class="text-muted mb-0">Track system updates and improvements</p>
        </div>
        <div>
            <span class="badge bg-light text-dark border">
                <i class="bi bi-tag me-1"></i>Current Version: <?= APP_VERSION ?>
            </span>
        </div>
    </div>

    <!-- Legend -->
    <div class="card mb-4">
        <div class="card-body">
            <h6 class="card-title mb-3">Change Type Legend</h6>
            <div class="d-flex flex-wrap gap-2">
                <span class="badge bg-success"><i class="bi bi-plus-circle me-1"></i>Feature</span>
                <span class="badge bg-danger"><i class="bi bi-bug me-1"></i>Bug Fix</span>
                <span class="badge bg-info"><i class="bi bi-arrow-up-circle me-1"></i>Improvement</span>
                <span class="badge bg-warning"><i class="bi bi-shield-check me-1"></i>Security</span>
            </div>
        </div>
    </div>

    <!-- Patch Notes Timeline -->
    <div class="patch-notes-timeline">
        <?php foreach (array_reverse($patchNotes) as $note): ?>
        <div class="card mb-4 patch-note-item">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                    <div class="version-badge me-3">
                        <span class="badge bg-primary fs-6">v<?= e($note['version']) ?></span>
                    </div>
                    <div>
                        <h5 class="mb-0"><?= e($note['title']) ?></h5>
                        <small class="text-muted">
                            <i class="bi bi-calendar3 me-1"></i><?= formatDate($note['date']) ?>
                        </small>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <ul class="list-unstyled mb-0">
                    <?php foreach ($note['changes'] as $change): ?>
                    <li class="d-flex align-items-start mb-2">
                        <span class="badge <?= getChangeBadgeClass($change['type']) ?> me-2 mt-1" style="min-width: 80px;">
                            <?= ucfirst($change['type']) ?>
                        </span>
                        <span class="change-text"><?= e($change['text']) ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- System Info -->
    <div class="card mt-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>System Information</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <table class="table table-borderless table-sm">
                        <tr>
                            <td class="text-muted">Application Name:</td>
                            <td class="fw-medium"><?= APP_NAME ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Current Version:</td>
                            <td class="fw-medium"><?= APP_VERSION ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Environment:</td>
                            <td class="fw-medium"><?= IS_PRODUCTION ? 'Production' : 'Development' ?></td>
                        </tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-borderless table-sm">
                        <tr>
                            <td class="text-muted">Timezone:</td>
                            <td class="fw-medium">Asia/Manila</td>
                        </tr>
                        <tr>
                            <td class="text-muted">Last Updated:</td>
                            <td class="fw-medium"><?= formatDate(date('Y-m-d')) ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.patch-notes-timeline {
    position: relative;
}

.patch-note-item {
    border-left: 4px solid #0d6efd;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.patch-note-item:hover {
    transform: translateX(5px);
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
}

.version-badge .badge {
    padding: 0.5rem 1rem;
}

.change-text {
    line-height: 1.6;
}

.patch-note-item:last-child {
    margin-bottom: 0;
}
</style>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
