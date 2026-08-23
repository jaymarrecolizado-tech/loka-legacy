<?php
/**
 * LOKA - Create Driver Page
 */

requireRole(ROLE_APPROVER);

$pageTitle = 'Add Driver';
$errors = [];

// Get users that are not already drivers
$availableUsers = db()->fetchAll(
    "SELECT u.id, u.name, u.email FROM users u 
     WHERE u.deleted_at IS NULL AND u.status = 'active'
     AND u.id NOT IN (SELECT user_id FROM drivers WHERE deleted_at IS NULL)
     ORDER BY u.name"
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    
    $userId = postInt('user_id');
    $licenseNumber = postSafe('license_number', '', 50);
    $licenseExpiry = postSafe('license_expiry', '', 20);
    $licenseClass = postSafe('license_class', 'B', 10);
    $yearsExperience = postInt('years_experience', 0);
    $emergencyName = postSafe('emergency_contact_name', '', 100);
    $emergencyPhone = postSafe('emergency_contact_phone', '', 20);
    $notes = postSafe('notes', '', 500);
    
    if (!$userId) $errors[] = 'User is required';
    if (empty($licenseNumber)) $errors[] = 'License number is required';
    if (empty($licenseExpiry)) $errors[] = 'License expiry is required';
    
    if ($licenseNumber) {
        $existing = db()->fetch("SELECT id FROM drivers WHERE license_number = ? AND deleted_at IS NULL", [$licenseNumber]);
        if ($existing) $errors[] = 'License number already exists';
    }
    
    if (empty($errors)) {
        $driverId = db()->insert('drivers', [
            'user_id' => $userId,
            'license_number' => $licenseNumber,
            'license_expiry' => $licenseExpiry,
            'license_class' => $licenseClass,
            'years_experience' => $yearsExperience,
            'emergency_contact_name' => $emergencyName,
            'emergency_contact_phone' => $emergencyPhone,
            'notes' => $notes,
            'status' => DRIVER_AVAILABLE,
            'created_at' => date(DATETIME_FORMAT),
            'updated_at' => date(DATETIME_FORMAT)
        ]);
        
        auditLog('driver_created', 'driver', $driverId);
        redirectWith('/?page=drivers', 'success', 'Driver added successfully.');
    }
}

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="mb-4">
        <h4 class="mb-1">Add Driver</h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>/?page=drivers">Drivers</a></li>
                <li class="breadcrumb-item active">Add</li>
            </ol>
        </nav>
    </div>
    
    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header"><h5 class="mb-0"><i class="bi bi-person-badge me-2"></i>Driver Details</h5></div>
                <div class="card-body">
                    <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul></div>
                    <?php endif; ?>
                    
                    <form method="POST">
                        <?= csrfField() ?>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">User Account <span class="text-danger">*</span></label>
                                <select class="form-select" name="user_id" required>
                                    <option value="">Select user...</option>
                                    <?php foreach ($availableUsers as $user): ?>
                                    <option value="<?= $user->id ?>" <?= post('user_id') == $user->id ? 'selected' : '' ?>><?= e($user->name) ?> (<?= e($user->email) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">License Number <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="license_number" value="<?= e(post('license_number', '')) ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">License Expiry <span class="text-danger">*</span></label>
                                <input type="text" class="form-control datepicker" name="license_expiry" value="<?= e(post('license_expiry', '')) ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">License Class</label>
                                <input type="text" class="form-control" name="license_class" value="<?= e(post('license_class', 'B')) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Years Experience</label>
                                <input type="number" class="form-control" name="years_experience" value="<?= e(post('years_experience', '0')) ?>" min="0">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Emergency Contact Name</label>
                                <input type="text" class="form-control" name="emergency_contact_name" value="<?= e(post('emergency_contact_name', '')) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Emergency Contact Phone</label>
                                <input type="text" class="form-control" name="emergency_contact_phone" value="<?= e(post('emergency_contact_phone', '')) ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Notes</label>
                                <textarea class="form-control" name="notes" rows="2"><?= e(post('notes', '')) ?></textarea>
                            </div>
                        </div>
                        <hr class="my-4">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save Driver</button>
                        <a href="<?= APP_URL ?>/?page=drivers" class="btn btn-outline-secondary">Cancel</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
