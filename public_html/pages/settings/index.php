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
    
    // Clamp to sane bounds so a bad value can't disable or brick the booking rules
    $clamp = function ($val, $min, $max, $default) {
        $val = (int) trim((string)$val);
        if ($val < $min || $val > $max) return (string)$default;
        return (string)$val;
    };
    
    // Track out-of-range values that were corrected so the admin isn't surprised
    $corrected = [];
    $trackClamp = function ($label, $val, $min, $max, $default) use ($clamp, &$corrected) {
        $raw = (int) trim((string)$val);
        $result = $clamp($val, $min, $max, $default);
        if ($raw !== (int) $result && ($raw < $min || $raw > $max)) {
            $corrected[] = "{$label} was reset to {$result} (allowed range: {$min}–{$max}).";
        }
        return $result;
    };

    $settingsToUpdate = [
        'system_name' => post('system_name', APP_NAME),
        'max_advance_booking_days' => $trackClamp('Maximum advance booking', post('max_advance_booking_days', '30'), 1, 365, 30),
        'min_advance_booking_hours' => $trackClamp('Minimum notice', post('min_advance_booking_hours', '24'), 0, 168, 24),
        'max_trip_duration_hours' => $trackClamp('Maximum trip duration', post('max_trip_duration_hours', '72'), 1, 720, 72),
        'require_return_confirmation' => post('require_return_confirmation', '0') === '1' ? '1' : '0',
        // Travel Order / OB Slip toggle — Admin and All Father only (both pass requireRole admin via level)
        'require_travel_order_upload' => post('require_travel_order_upload', '0') === '1' ? '1' : '0',
        // Trip confirmation settings
        'trip_confirmation_enabled' => post('trip_confirmation_enabled', '1') === '1' ? '1' : '0',
        'trip_confirmation_lead_hours' => $trackClamp('Confirmation lead (hours)', post('trip_confirmation_lead_hours', '24'), 1, 168, 24),
        'trip_confirmation_same_day_lead_minutes' => $trackClamp('Same-day lead (minutes)', post('trip_confirmation_same_day_lead_minutes', '60'), 5, 1440, 60),
        'trip_confirmation_window_minutes' => $trackClamp('Confirmation window (minutes)', post('trip_confirmation_window_minutes', '60'), 15, 720, 60),
        // Overdue + evaluation settings
        'trip_overdue_renotify_hours' => $trackClamp('Overdue renotify (hours)', post('trip_overdue_renotify_hours', '24'), 1, 168, 24),
        'driver_evaluation_reminder_hours' => $trackClamp('Evaluation reminder (hours)', post('driver_evaluation_reminder_hours', '48'), 1, 720, 48),
        'driver_evaluation_expiry_days' => $trackClamp('Evaluation expiry (days)', post('driver_evaluation_expiry_days', '30'), 1, 90, 30),
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
        <?php if (!empty($corrected)): ?>
        <hr>
        <ul class="mb-0 ps-3">
            <?php foreach ($corrected as $notice): ?>
            <li><?= e($notice) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
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
                            <small class="text-muted form-help">Displayed in the header and emails</small>
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
                                <small class="text-muted form-help">How far in advance can requests be made</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Minimum Notice (hours)</label>
                                <input type="number" class="form-control" name="min_advance_booking_hours" 
                                       value="<?= e($settings['min_advance_booking_hours'] ?? '24') ?>" min="0" max="168">
                                <small class="text-muted form-help">Minimum hours before trip start</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Maximum Trip Duration (hours)</label>
                                <input type="number" class="form-control" name="max_trip_duration_hours" 
                                       value="<?= e($settings['max_trip_duration_hours'] ?? '72') ?>" min="1" max="720">
                                <small class="text-muted form-help">Maximum allowed trip length</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Require Return Confirmation</label>
                                <select class="form-select" name="require_return_confirmation">
                                    <option value="0" <?= ($settings['require_return_confirmation'] ?? '0') === '0' ? 'selected' : '' ?>>No</option>
                                    <option value="1" <?= ($settings['require_return_confirmation'] ?? '0') === '1' ? 'selected' : '' ?>>Yes</option>
                                </select>
                                <small class="text-muted form-help">Require users to confirm vehicle return</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Travel Documents Enforcement -->
                <div class="card mb-4">
                    <div class="card-header"><h5 class="mb-0"><i class="bi bi-file-earmark-check me-2"></i>Travel Documents</h5></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Enforce Travel Order / Official Business Slip Upload <span class="badge bg-warning text-dark ms-1 badge-keep">Admin / All Father</span></label>
                                <select class="form-select" name="require_travel_order_upload">
                                    <option value="0" <?= ($settings['require_travel_order_upload'] ?? '0') === '0' ? 'selected' : '' ?>>No — optional</option>
                                    <option value="1" <?= ($settings['require_travel_order_upload'] ?? '0') === '1' ? 'selected' : '' ?>>Yes — required during application</option>
                                </select>
                                <small class="text-muted form-help">When Yes, the application form blocks submission if no TO/OB Slip file is attached. Toggleable by Admin and All Father only.</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Trip Confirmation -->
                <div class="card mb-4">
                    <div class="card-header"><h5 class="mb-0"><i class="bi bi-envelope-check me-2"></i>Trip Confirmation</h5></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Enable Confirmatory Email</label>
                                <select class="form-select" name="trip_confirmation_enabled">
                                    <option value="0" <?= ($settings['trip_confirmation_enabled'] ?? '1') === '0' ? 'selected' : '' ?>>Disabled</option>
                                    <option value="1" <?= ($settings['trip_confirmation_enabled'] ?? '1') === '1' ? 'selected' : '' ?>>Enabled</option>
                                </select>
                                <small class="text-muted form-help">Sends Proceed / Don't Proceed email before approved trips</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Lead Time — Multi-day trips (hours)</label>
                                <input type="number" class="form-control" name="trip_confirmation_lead_hours" value="<?= e($settings['trip_confirmation_lead_hours'] ?? '24') ?>" min="1" max="168">
                                <small class="text-muted form-help">Hours before start when trip is on a later day than creation (default 24 = 1 day)</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Lead Time — Same-day trips (minutes)</label>
                                <input type="number" class="form-control" name="trip_confirmation_same_day_lead_minutes" value="<?= e($settings['trip_confirmation_same_day_lead_minutes'] ?? '60') ?>" min="5" max="1440">
                                <small class="text-muted form-help">Minutes before start when trip is same calendar day as request creation</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Response Window (minutes)</label>
                                <input type="number" class="form-control" name="trip_confirmation_window_minutes" value="<?= e($settings['trip_confirmation_window_minutes'] ?? '60') ?>" min="15" max="720">
                                <small class="text-muted form-help">Deadline = start − window. No response = proceed (default). Min 15 minutes.</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Trip Monitoring & Evaluations -->
                <div class="card mb-4">
                    <div class="card-header"><h5 class="mb-0"><i class="bi bi-shield-exclamation me-2"></i>Trip Monitoring &amp; Evaluations</h5></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Overdue Renotify (hours)</label>
                                <input type="number" class="form-control" name="trip_overdue_renotify_hours" value="<?= e($settings['trip_overdue_renotify_hours'] ?? '24') ?>" min="1" max="168">
                                <small class="text-muted form-help">Re-alert motorpool while trip exceeds end time</small>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Evaluation Reminder (hours)</label>
                                <input type="number" class="form-control" name="driver_evaluation_reminder_hours" value="<?= e($settings['driver_evaluation_reminder_hours'] ?? '48') ?>" min="1" max="720">
                                <small class="text-muted form-help">Hours after trip completion before reminder email</small>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Evaluation Link Expiry (days)</label>
                                <input type="number" class="form-control" name="driver_evaluation_expiry_days" value="<?= e($settings['driver_evaluation_expiry_days'] ?? '30') ?>" min="1" max="90">
                                <small class="text-muted form-help">Token expiry for anonymous evaluation</small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-check-lg me-1"></i>Save Settings</button>
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
