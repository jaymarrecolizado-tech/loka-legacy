<?php
/**
 * LOKA - Edit Driver Page
 */

requireRole(ROLE_APPROVER);

$driverId = (int) get('id');
$driver = db()->fetch(
    "SELECT d.*, u.name as driver_name, u.email FROM drivers d JOIN users u ON d.user_id = u.id WHERE d.id = ? AND d.deleted_at IS NULL FOR UPDATE",
    [$driverId]
);

if (!$driver)
    redirectWith('/?page=drivers', 'danger', 'Driver not found.');

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $licenseNumber = postSafe('license_number', '', 50);
    $licenseExpiry = postSafe('license_expiry', '', 20);
    $licenseClass = postSafe('license_class', 'B', 10);
    $yearsExperience = postInt('years_experience', 0);
    $status = postSafe('status', '', 20);
    $emergencyName = postSafe('emergency_contact_name', '', 100);
    $emergencyPhone = postSafe('emergency_contact_phone', '', 20);
    $notes = postSafe('notes', '', 500);

    if (empty($licenseNumber))
        $errors[] = 'License number is required';
    if (empty($licenseExpiry))
        $errors[] = 'License expiry is required';

    // Validate license expiry date
    if (!empty($licenseExpiry)) {
        $expiryDate = DateTime::createFromFormat('Y-m-d', $licenseExpiry);
        $now = new DateTime();

        if ($expiryDate < $now) {
            $errors[] = 'License expiry date cannot be in the past';
        }
    }

    if (empty($errors)) {
        db()->beginTransaction();

        try {
            // Re-fetch with lock to ensure atomicity
            $driver = db()->fetch(
                "SELECT d.*, u.name as driver_name, u.email FROM drivers d JOIN users u ON d.user_id = u.id WHERE d.id = ? AND d.deleted_at IS NULL FOR UPDATE",
                [$driverId]
            );

            // Check unique license (exclude current)
            if ($licenseNumber && $licenseNumber !== $driver->license_number) {
                $existing = db()->fetch("SELECT id FROM drivers WHERE license_number = ? AND id != ? AND deleted_at IS NULL", [$licenseNumber, $driverId]);
                if ($existing) {
                    db()->rollback();
                    $errors[] = 'License number already exists';
                }
            }

            if (empty($errors)) {
                db()->update('drivers', [
                    'license_number' => $licenseNumber,
                    'license_expiry' => $licenseExpiry,
                    'license_class' => $licenseClass,
                    'years_experience' => $yearsExperience,
                    'status' => $status,
                    'emergency_contact_name' => $emergencyName,
                    'emergency_contact_phone' => $emergencyPhone,
                    'notes' => $notes,
                    'updated_at' => date(DATETIME_FORMAT)
                ], 'id = ?', [$driverId]);

                auditLog('driver_updated', 'driver', $driverId);
                db()->commit();
                redirectWith('/?page=drivers', 'success', 'Driver updated successfully.');
            }
        } catch (Exception $e) {
            db()->rollback();
            $errors[] = 'Failed to update driver';
        }
    }
}

$pageTitle = 'Edit Driver';
require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="mb-4">
        <h4 class="mb-1">Edit Driver: <?= e($driver->driver_name) ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>/?page=drivers">Drivers</a></li>
                <li class="breadcrumb-item active">Edit</li>
            </ol>
        </nav>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-pencil me-2"></i>Edit Driver</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <ul class="mb-0"><?php foreach ($errors as $e): ?>
                                    <li><?= e($e) ?></li><?php endforeach; ?>
                            </ul>
                        </div><?php endif; ?>

                    <form method="POST">
                        <?= csrfField() ?>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">User</label>
                                <input type="text" class="form-control"
                                    value="<?= e($driver->driver_name) ?> (<?= e($driver->email) ?>)" disabled>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">License Number <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="license_number"
                                    value="<?= e(post('license_number', $driver->license_number)) ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">License Expiry <span class="text-danger">*</span></label>
                                <input type="text" class="form-control datepicker" name="license_expiry"
                                    value="<?= e(post('license_expiry', $driver->license_expiry)) ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">License Class</label>
                                <input type="text" class="form-control" name="license_class"
                                    value="<?= e(post('license_class', $driver->license_class)) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status">
                                    <?php foreach (DRIVER_STATUS_LABELS as $key => $info): ?>
                                        <option value="<?= $key ?>" <?= post('status', $driver->status) === $key ? 'selected' : '' ?>><?= e($info['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Years Experience</label>
                                <input type="number" class="form-control" name="years_experience"
                                    value="<?= e(post('years_experience', $driver->years_experience)) ?>" min="0">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Emergency Contact Name</label>
                                <input type="text" class="form-control" name="emergency_contact_name"
                                    value="<?= e(post('emergency_contact_name', $driver->emergency_contact_name)) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Emergency Contact Phone</label>
                                <input type="text" class="form-control" name="emergency_contact_phone"
                                    value="<?= e(post('emergency_contact_phone', $driver->emergency_contact_phone)) ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Notes</label>
                                <textarea class="form-control" name="notes"
                                    rows="2"><?= e(post('notes', $driver->notes)) ?></textarea>
                            </div>
                        </div>
                        <hr class="my-4">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save
                            Changes</button>
                        <a href="<?= APP_URL ?>/?page=drivers" class="btn btn-outline-secondary">Cancel</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>