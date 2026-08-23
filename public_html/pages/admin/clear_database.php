<?php
/**
 * LOKA Fleet Management - Clear Requests & Notifications (Web Interface)
 *
 * Safe web interface to clear requests and notifications
 * Includes multiple confirmation steps and safety checks
 *
 * Author: Commander Valkyrie Chen
 * Date: January 28, 2026
 */

require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';

// Security: Only admins can access this
session_start();
if (!isset($_SESSION['user_id']) || !isAdmin()) {
    die('<h1>Access Denied</h1><p>You must be an admin to access this page.</p>');
}

// Production environment check
if (APP_ENV === 'production') {
    die('<h1>Access Denied</h1><p>This operation is disabled in production mode.</p>');
}

$db = Database::getInstance();
$step = getInt('step', 1);
$message = '';
$messageType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = postSafe('action', '', 20);

    try {
        if ($action === 'clear') {
            // Disable foreign key checks
            $db->query("SET FOREIGN_KEY_CHECKS = 0");

            // Clear notifications
            $db->query("DELETE FROM notifications");
            $db->query("ALTER TABLE notifications AUTO_INCREMENT = 1");

            // Clear requests (cascades to related tables)
            $db->query("DELETE FROM requests");
            $db->query("ALTER TABLE requests AUTO_INCREMENT = 1");

            // Clear email queue entries related to requests
            $db->query("DELETE FROM email_queue WHERE request_id IS NOT NULL");

            // Re-enable foreign key checks
            $db->query("SET FOREIGN_KEY_CHECKS = 1");

            $message = "Database clearance completed successfully!";
            $messageType = "success";
            $step = 4; // Show completion screen
        }
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $messageType = "error";
    }
}

// Get current row counts
$counts = [
    'notifications' => $db->fetch("SELECT COUNT(*) as count FROM notifications")->count,
    'requests' => $db->fetch("SELECT COUNT(*) as count FROM requests")->count,
    'approval_workflow' => $db->fetch("SELECT COUNT(*) as count FROM approval_workflow")->count,
    'approvals' => $db->fetch("SELECT COUNT(*) as count FROM approvals")->count,
    'request_passengers' => $db->fetch("SELECT COUNT(*) as count FROM request_passengers")->count,
    'email_queue' => $db->fetch("SELECT COUNT(*) as count FROM email_queue")->count,
];

function isAdmin() {
    // Check if current user is admin (implement based on your auth system)
    if (!isset($_SESSION['role'])) {
        return false;
    }
    return $_SESSION['role'] === 'admin';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clear Requests & Notifications - LOKA Fleet Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        .warning-banner {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
        }
        .count-display {
            font-size: 2.5rem;
            font-weight: bold;
            color: #667eea;
        }
        .step-indicator {
            display: flex;
            justify-content: center;
            margin-bottom: 30px;
        }
        .step {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #e0e0e0;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 10px;
            font-weight: bold;
            color: #666;
        }
        .step.active {
            background: #667eea;
            color: white;
        }
        .step.completed {
            background: #28a745;
            color: white;
        }
    </style>
</head>
<body>
    <div class="container mt-5 mb-5">
        <div class="row justify-content-center">
            <div class="col-md-10">
                <div class="card p-4">
                    <div class="text-center mb-4">
                        <h1 class="display-6 fw-bold text-primary">🗑️ Clear Database</h1>
                        <p class="text-muted">Requests & Notifications Management</p>
                    </div>

                    <?php if ($message): ?>
                        <div class="alert alert-<?= $messageType === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
                            <?= htmlspecialchars($message) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <?php if ($step === 1): ?>
                        <!-- STEP 1: Warning & Information -->
                        <div class="warning-banner text-center">
                            <h4 class="mb-3"><i class="bi bi-exclamation-triangle-fill"></i> DANGER ZONE</h4>
                            <p class="mb-0">This operation will <strong>PERMANENTLY DELETE</strong> all requests and notifications from the database.</p>
                        </div>

                        <h5 class="mb-4">📊 Current Data Overview</h5>
                        <div class="row g-4 mb-4">
                            <div class="col-md-4">
                                <div class="card text-center p-3 bg-light">
                                    <div class="count-display"><?= number_format($counts['requests']) ?></div>
                                    <div class="text-muted">Requests</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card text-center p-3 bg-light">
                                    <div class="count-display"><?= number_format($counts['notifications']) ?></div>
                                    <div class="text-muted">Notifications</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card text-center p-3 bg-light">
                                    <div class="count-display"><?= number_format($counts['email_queue']) ?></div>
                                    <div class="text-muted">Email Queue</div>
                                </div>
                            </div>
                        </div>

                        <h5 class="mb-3">🗂️ Tables That Will Be Cleared</h5>
                        <div class="card bg-light mb-4">
                            <div class="card-body">
                                <ul class="list-unstyled mb-0">
                                    <li class="mb-2"><i class="bi bi-check-circle text-danger"></i> <strong>notifications</strong> - All notification records</li>
                                    <li class="mb-2"><i class="bi bi-check-circle text-danger"></i> <strong>requests</strong> - All request records</li>
                                    <li class="mb-2"><i class="bi bi-check-circle text-danger"></i> <strong>approval_workflow</strong> - Auto-cascade from requests</li>
                                    <li class="mb-2"><i class="bi bi-check-circle text-danger"></i> <strong>approvals</strong> - Auto-cascade from requests</li>
                                    <li class="mb-2"><i class="bi bi-check-circle text-danger"></i> <strong>request_passengers</strong> - Auto-cascade from requests</li>
                                    <li class="mb-0"><i class="bi bi-check-circle text-danger"></i> <strong>email_queue</strong> - Request-related emails</li>
                                </ul>
                            </div>
                        </div>

                        <div class="alert alert-warning">
                            <i class="bi bi-shield-exclamation"></i>
                            <strong>Recommendation:</strong> Create a database backup before proceeding.
                            <br>
                            <code>mysqldump -u user -p fleet_management > backup.sql</code>
                        </div>

                        <div class="d-grid gap-2">
                            <a href="?step=2" class="btn btn-warning btn-lg">
                                <i class="bi bi-arrow-right-circle"></i> I Understand the Risks - Continue
                            </a>
                            <a href="/" class="btn btn-secondary">
                                <i class="bi bi-x-circle"></i> Cancel - Return to Dashboard
                            </a>
                        </div>

                    <?php elseif ($step === 2): ?>
                        <!-- STEP 2: Final Confirmation -->
                        <div class="alert alert-danger text-center">
                            <h4><i class="bi bi-exclamation-triangle-fill"></i> FINAL WARNING</h4>
                            <p class="mb-0">This action cannot be undone. Once deleted, all data will be permanently lost.</p>
                        </div>

                        <div class="card bg-light mb-4">
                            <div class="card-body">
                                <h5 class="card-title">You are about to delete:</h5>
                                <ul class="list-unstyled">
                                    <li class="mb-2"><strong><?= number_format($counts['requests']) ?></strong> request records</li>
                                    <li class="mb-2"><strong><?= number_format($counts['notifications']) ?></strong> notification records</li>
                                    <li class="mb-2"><strong><?= number_format($counts['approval_workflow']) ?></strong> approval workflow records</li>
                                    <li class="mb-2"><strong><?= number_format($counts['approvals']) ?></strong> approval records</li>
                                    <li class="mb-2"><strong><?= number_format($counts['request_passengers']) ?></strong> request passenger records</li>
                                    <li class="mb-0"><strong><?= number_format($counts['email_queue']) ?></strong> email queue records</li>
                                </ul>
                            </div>
                        </div>

                        <form method="POST">
                            <input type="hidden" name="action" value="clear">
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-danger btn-lg" onclick="return confirm('Are you absolutely sure? This cannot be undone!')">
                                    <i class="bi bi-trash-fill"></i> YES, Delete All Data
                                </button>
                                <a href="/" class="btn btn-secondary">
                                    <i class="bi bi-x-circle"></i> Cancel
                                </a>
                            </div>
                        </form>

                    <?php elseif ($step === 4): ?>
                        <!-- STEP 4: Success -->
                        <div class="alert alert-success text-center">
                            <h4><i class="bi bi-check-circle-fill"></i> Clearance Complete</h4>
                            <p class="mb-0">All requests and notifications have been successfully removed from the database.</p>
                        </div>

                        <div class="card bg-light mb-4">
                            <div class="card-body">
                                <h5 class="card-title">Summary of Changes:</h5>
                                <ul class="list-unstyled">
                                    <li class="mb-2"><i class="bi bi-check-circle text-success"></i> All notification records deleted</li>
                                    <li class="mb-2"><i class="bi bi-check-circle text-success"></i> All request records deleted</li>
                                    <li class="mb-2"><i class="bi bi-check-circle text-success"></i> Related approval workflows deleted</li>
                                    <li class="mb-2"><i class="bi bi-check-circle text-success"></i> Related approvals deleted</li>
                                    <li class="mb-2"><i class="bi bi-check-circle text-success"></i> Related request passengers deleted</li>
                                    <li class="mb-0"><i class="bi bi-check-circle text-success"></i> Related email queue entries deleted</li>
                                </ul>
                            </div>
                        </div>

                        <div class="d-grid">
                            <a href="/" class="btn btn-primary btn-lg">
                                <i class="bi bi-house"></i> Return to Dashboard
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
