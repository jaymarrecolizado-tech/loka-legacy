<?php
/**
 * LOKA - Email Queue Management (Admin Only)
 */

requireRole(ROLE_ADMIN);

$queue = new EmailQueue();
$stats = $queue->getStats();
$message = null;

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    
    $action = postSafe('action', '', 20);
    
    if ($action === 'process') {
        $results = $queue->process(20);
        $message = "Processed: {$results['sent']} sent, {$results['failed']} failed";
    } elseif ($action === 'cleanup') {
        $cleaned = $queue->cleanup(30);
        $message = "Cleaned up {$cleaned} old emails";
    }
    
    // Refresh stats
    $stats = $queue->getStats();
}

// Get recent queue items
$recentEmails = db()->fetchAll(
    "SELECT * FROM email_queue ORDER BY created_at DESC LIMIT 20"
);

$pageTitle = 'Email Queue';
require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">
            <i class="bi bi-envelope-paper me-2"></i>Email Queue
        </h1>
        <a href="<?= APP_URL ?>/?page=settings" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back to Settings
        </a>
    </div>
    
    <?php if ($message): ?>
    <div class="alert alert-info alert-dismissible fade show">
        <?= e($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
    <!-- Stats Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-warning text-dark">
                <div class="card-body text-center">
                    <h2 class="mb-0"><?= $stats['pending'] ?></h2>
                    <small>Pending</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body text-center">
                    <h2 class="mb-0"><?= $stats['processing'] ?></h2>
                    <small>Processing</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body text-center">
                    <h2 class="mb-0"><?= $stats['sent'] ?></h2>
                    <small>Sent</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-danger text-white">
                <div class="card-body text-center">
                    <h2 class="mb-0"><?= $stats['failed'] ?></h2>
                    <small>Failed</small>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Actions -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Queue Actions</h5>
        </div>
        <div class="card-body">
            <form method="post" class="d-flex gap-3">
                <?= csrfField() ?>
                <button type="submit" name="action" value="process" class="btn btn-primary">
                    <i class="bi bi-play-fill me-1"></i>Process Queue Now
                </button>
                <button type="submit" name="action" value="cleanup" class="btn btn-outline-secondary">
                    <i class="bi bi-trash me-1"></i>Cleanup Old Emails
                </button>
            </form>
            <small class="text-muted mt-2 d-block">
                <strong>Note:</strong> The queue is automatically processed every minute via scheduled task.
                Use "Process Queue Now" to manually trigger processing.
            </small>
        </div>
    </div>
    
    <!-- Recent Emails -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">Recent Emails (Last 20)</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 no-datatable">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>To</th>
                            <th>Subject</th>
                            <th>Status</th>
                            <th>Attempts</th>
                            <th>Created</th>
                            <th>Sent</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentEmails as $email): ?>
                        <tr>
                            <td><?= $email->id ?></td>
                            <td>
                                <span title="<?= e($email->to_email) ?>">
                                    <?= truncate($email->to_email, 25) ?>
                                </span>
                            </td>
                            <td><?= truncate($email->subject, 30) ?></td>
                            <td>
                                <?php
                                $statusColors = [
                                    'pending' => 'warning',
                                    'processing' => 'info',
                                    'sent' => 'success',
                                    'failed' => 'danger'
                                ];
                                $color = $statusColors[$email->status] ?? 'secondary';
                                ?>
                                <span class="badge bg-<?= $color ?>"><?= $email->status ?></span>
                            </td>
                            <td><?= $email->attempts ?>/<?= $email->max_attempts ?></td>
                            <td><?= date('M j, g:i A', strtotime($email->created_at)) ?></td>
                            <td>
                                <?= $email->sent_at ? date('M j, g:i A', strtotime($email->sent_at)) : '-' ?>
                            </td>
                        </tr>
                        <?php if ($email->status === 'failed' && $email->error_message): ?>
                        <tr class="table-danger">
                            <td colspan="7">
                                <small><strong>Error:</strong> <?= e($email->error_message) ?></small>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php endforeach; ?>
                        <?php if (empty($recentEmails)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No emails in queue</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Setup Instructions -->
    <div class="card mt-4">
        <div class="card-header">
            <h5 class="mb-0">Setup Instructions</h5>
        </div>
        <div class="card-body">
            <h6>Windows Task Scheduler:</h6>
            <ol>
                <li>Open Task Scheduler</li>
                <li>Create Basic Task → Name: "LOKA Email Queue"</li>
                <li>Trigger: Daily, repeat every 1 minute</li>
                <li>Action: Start a program</li>
                <li>Program: <code>C:\wamp64\bin\php\php8.x.x\php.exe</code></li>
                <li>Arguments: <code><?= realpath(__DIR__ . '/../../cron/process_queue.php') ?></code></li>
            </ol>
            
            <h6 class="mt-3">Linux Cron:</h6>
            <pre class="bg-light p-2"><code>* * * * * /usr/bin/php /path/to/LOKA/cron/process_queue.php >> /var/log/email_queue.log 2>&1</code></pre>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
