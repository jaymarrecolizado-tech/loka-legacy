<?php
/**
 * LOKA - Settings Page
 */

requireRole(ROLE_ADMIN);

$pageTitle = 'Settings';
$success = false;

// Get current settings
$settings = [];
$settingsData = db()->fetchAll("SELECT * FROM settings");
foreach ($settingsData as $s) {
    $settings[$s->key] = $s->value;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    
    $settingsToUpdate = [
        'system_name' => post('system_name', APP_NAME),
        'max_advance_booking_days' => post('max_advance_booking_days', '30'),
        'min_advance_booking_hours' => post('min_advance_booking_hours', '24'),
        'max_trip_duration_hours' => post('max_trip_duration_hours', '72'),
        'require_return_confirmation' => post('require_return_confirmation', '0'),
    ];
    
    foreach ($settingsToUpdate as $key => $value) {
        $existing = db()->fetch("SELECT id FROM settings WHERE `key` = ?", [$key]);
        if ($existing) {
            db()->update('settings', ['value' => $value, 'updated_at' => date(DATETIME_FORMAT)], '`key` = ?', [$key]);
        } else {
            db()->query(
                "INSERT INTO settings (`key`, value, type, category, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)",
                [$key, $value, 'string', 'booking', date(DATETIME_FORMAT), date(DATETIME_FORMAT)]
            );
        }
        $settings[$key] = $value;
    }
    
    auditLog('settings_updated', 'settings', null);
    $success = true;
}

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="mb-4">
        <h4 class="mb-1">Settings</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0"><li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li><li class="breadcrumb-item active">Settings</li></ol></nav>
    </div>
    
    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show">
        Settings saved successfully.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
    <div class="row">
        <div class="col-lg-8">
            <form method="POST">
                <?= csrfField() ?>
                
                <!-- General Settings -->
                <div class="card mb-4">
                    <div class="card-header"><h5 class="mb-0"><i class="bi bi-gear me-2"></i>General Settings</h5></div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">System Name</label>
                            <input type="text" class="form-control" name="system_name" 
                                   value="<?= e($settings['system_name'] ?? APP_NAME) ?>">
                            <small class="text-muted">Displayed in the header and emails</small>
                        </div>
                    </div>
                </div>
                
                <!-- Booking Settings -->
                <div class="card mb-4">
                    <div class="card-header"><h5 class="mb-0"><i class="bi bi-calendar-check me-2"></i>Booking Rules</h5></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Maximum Advance Booking (days)</label>
                                <input type="number" class="form-control" name="max_advance_booking_days" 
                                       value="<?= e($settings['max_advance_booking_days'] ?? '30') ?>" min="1" max="365">
                                <small class="text-muted">How far in advance can requests be made</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Minimum Notice (hours)</label>
                                <input type="number" class="form-control" name="min_advance_booking_hours" 
                                       value="<?= e($settings['min_advance_booking_hours'] ?? '24') ?>" min="0" max="168">
                                <small class="text-muted">Minimum hours before trip start</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Maximum Trip Duration (hours)</label>
                                <input type="number" class="form-control" name="max_trip_duration_hours" 
                                       value="<?= e($settings['max_trip_duration_hours'] ?? '72') ?>" min="1" max="720">
                                <small class="text-muted">Maximum allowed trip length</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Require Return Confirmation</label>
                                <select class="form-select" name="require_return_confirmation">
                                    <option value="0" <?= ($settings['require_return_confirmation'] ?? '0') === '0' ? 'selected' : '' ?>>No</option>
                                    <option value="1" <?= ($settings['require_return_confirmation'] ?? '0') === '1' ? 'selected' : '' ?>>Yes</option>
                                </select>
                                <small class="text-muted">Require users to confirm vehicle return</small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save Settings</button>
            </form>
        </div>
        
        <!-- System Info & Quick Links -->
        <div class="col-lg-4">
            <div class="card mb-4">
                <div class="card-header"><h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>System Information</h6></div>
                <div class="card-body">
                    <p class="mb-1"><strong>Version:</strong> <?= APP_VERSION ?></p>
                    <p class="mb-1"><strong>PHP:</strong> <?= phpversion() ?></p>
                    <p class="mb-1"><strong>Server:</strong> <?= $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown' ?></p>
                    <p class="mb-0"><strong>Database:</strong> MySQL</p>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header"><h6 class="mb-0"><i class="bi bi-tools me-2"></i>Admin Tools</h6></div>
                <div class="list-group list-group-flush">
                    <a href="<?= APP_URL ?>/?page=settings&action=email-queue" class="list-group-item list-group-item-action d-flex align-items-center">
                        <i class="bi bi-envelope-paper me-3 text-primary"></i>
                        <div>
                            <strong>Email Queue</strong><br>
                            <small class="text-muted">Manage email delivery queue</small>
                        </div>
                    </a>
                    <a href="<?= APP_URL ?>/?page=audit" class="list-group-item list-group-item-action d-flex align-items-center">
                        <i class="bi bi-journal-text me-3 text-info"></i>
                        <div>
                            <strong>Audit Logs</strong><br>
                            <small class="text-muted">View system activity</small>
                        </div>
                    </a>
                    <a href="<?= APP_URL ?>/?page=users" class="list-group-item list-group-item-action d-flex align-items-center">
                        <i class="bi bi-people me-3 text-success"></i>
                        <div>
                            <strong>User Management</strong><br>
                            <small class="text-muted">Manage user accounts</small>
                        </div>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
