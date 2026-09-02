<?php
/**
 * LOKA - Gas Voucher Create / Edit Page
 */

if (!canAccessGasVouchers()) {
    redirectWith('/?page=dashboard', 'danger', 'You do not have permission to access this page.');
}


$isEdit  = (get('action') === 'edit');
$voucherId = (int) get('id', 0);
$voucher = null;
$errors  = [];

// Auto-generate voucher number: YYYY-MM-NNN
function generateVoucherNo(): string {
    $year  = date('Y');
    $month = date('m');
    $prefix = "{$year}-{$month}-";
    $last = db()->fetchColumn(
        "SELECT voucher_no FROM gas_vouchers WHERE voucher_no LIKE ? ORDER BY voucher_no DESC LIMIT 1",
        ["{$prefix}%"]
    );
    if ($last) {
        $parts = explode('-', $last);
        $seq = (int) end($parts) + 1;
    } else {
        $seq = 1;
    }
    return $prefix . str_pad($seq, 3, '0', STR_PAD_LEFT);
}

// Fetch for edit
if ($isEdit) {
    $voucher = db()->fetch("SELECT * FROM gas_vouchers WHERE id = ? AND deleted_at IS NULL", [$voucherId]);
    if (!$voucher) {
        redirectWith('/?page=gas-vouchers', 'danger', 'Voucher not found.');
    }
    // Rules: Owner/Admin edit 'draft'. Approver/Motorpool/Admin edit 'pending_review'/'pending_approval'.
    $canEdit = false;
    if ($voucher->status === 'draft') {
        if ($voucher->requested_by_user_id == userId() || isAdmin()) $canEdit = true;
    } elseif (in_array($voucher->status, ['pending_review', 'pending_approval'])) {
        if (isApprover() || isMotorpool() || isAdmin() || isChiefAdminFinance()) $canEdit = true;
    }

    if (!$canEdit) {
        if ($voucher->status !== 'draft') {
            redirectWith('/?page=gas-vouchers&action=view&id=' . $voucherId, 'warning', 'Voucher cannot be edited at this stage.');
        } else {
            redirectWith('/?page=gas-vouchers', 'danger', 'Access denied.');
        }
    }
    $pageTitle = 'Edit Gas Voucher';
} else {
    $pageTitle = 'New Gas Voucher';
}

// Build vehicles list for datalist
$vehicles = db()->fetchAll("SELECT plate_number FROM vehicles WHERE deleted_at IS NULL ORDER BY plate_number ASC");

// Build drivers list for datalist
$driversList = db()->fetchAll("SELECT u.name FROM drivers d JOIN users u ON d.user_id = u.id WHERE d.deleted_at IS NULL ORDER BY u.name ASC");

// Fetch Motorpool Heads for signatory selection
$motorpoolHeads = db()->fetchAll(
    "SELECT id, name FROM users WHERE role = ? AND deleted_at IS NULL AND status = 'active' ORDER BY name",
    [ROLE_MOTORPOOL]
);

// Fetch Chief Admin & Finance users for signatory selection
$chiefFinanceUsers = db()->fetchAll(
    "SELECT id, name FROM users WHERE role IN (?, ?) AND deleted_at IS NULL AND status = 'active' ORDER BY name",
    [ROLE_CHIEF_ADMIN_FINANCE, ROLE_OIC_CHIEF_ADMIN_FINANCE]
);

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $action       = post('form_action', 'save_draft');
    $driverName   = postSafe('driver_name', '', 100);
    $vehiclePlate = postSafe('vehicle_plate', '', 30);
    $gasStation   = postSafe('gas_station', '', 150);
    $fuelType     = post('fuel_type', 'Diesel');
    $quantityMode = post('quantity_mode', 'liters'); // 'full' or 'liters'
    if ($quantityMode === 'full') {
        $quantity = 0;          // sentinel for FULL TANK
        $unit     = 'FULL TANK';
    } else {
        $rawQty   = post('quantity', '20');
        $quantity = (float) $rawQty;
        $unit     = postSafe('unit', 'Liters', 20);
    }
    $otherItems   = postSafe('other_items', '', 500);
    $otherQty     = post('other_qty', '');
    $otherUnit    = postSafe('other_unit', '', 20);
    $fundSource   = postSafe('fund_source', '', 100);
    $purpose      = postSafe('purpose', '', 1000);
    $chargeableAgainst = postSafe('chargeable_against', '', 100);
    $requestDate  = post('request_date', date('Y-m-d'));
    $saro         = postSafe('saro_no', '', 50);
    $dateWithdrawn = post('date_withdrawn', '');
    $requestedReviewerId = (int) post('requested_reviewer_id', 0) ?: null;
    $requestedApproverId = (int) post('requested_approver_id', 0) ?: null;

    $allowedStations = getActiveGasStations();
    // Allow editing a voucher that already has an inactive station
    if ($isEdit && $voucher && $voucher->gas_station && !in_array($voucher->gas_station, $allowedStations, true)) {
        $allowedStations[] = $voucher->gas_station;
    }

    // Validation
    if (empty($driverName))   $errors[] = 'Driver name is required.';
    if (empty($vehiclePlate)) $errors[] = 'Vehicle plate number is required.';
    if (empty($gasStation) || !in_array($gasStation, $allowedStations, true)) $errors[] = 'Please select a valid gas station.';
    if (empty($fuelType) || !in_array($fuelType, ['Gasoline', 'Diesel'])) $errors[] = 'Invalid fuel type.';
    if ($quantityMode !== 'full' && $quantity <= 0) $errors[] = 'Quantity must be greater than 0.';
    if (empty($unit))         $errors[] = 'Unit is required.';
    if (empty($fundSource))   $errors[] = 'Fund source is required.';
    if (empty($purpose))      $errors[] = 'Purpose is required.';
    if (empty($requestDate))  $errors[] = 'Request date is required.';

    // Lookup vehicle_id if plate matches
    $vehicleRow = db()->fetch("SELECT id FROM vehicles WHERE plate_number = ? AND deleted_at IS NULL", [$vehiclePlate]);
    $vehicleId  = $vehicleRow ? $vehicleRow->id : null;

    if (empty($errors)) {
        if ($isEdit && in_array($voucher->status, ['pending_review', 'pending_approval'])) {
            $newStatus = $voucher->status; // Preserve status for approvers making corrections
        } else {
            $newStatus = ($action === 'submit') ? 'pending_review' : 'draft';
        }

        $data = [
            'driver_name'        => $driverName,
            'vehicle_plate'      => strtoupper($vehiclePlate),
            'vehicle_id'         => $vehicleId,
            'gas_station'        => $gasStation,
            'fuel_type'          => $fuelType,
            'quantity'           => $quantity,
            'unit'               => $unit,
            'other_items'        => $otherItems ?: null,
            'other_qty'          => ($otherQty !== '' && $otherQty !== null) ? (float) $otherQty : null,
            'other_unit'         => $otherUnit ?: null,
            'fund_source'        => $fundSource,
            'purpose'            => $purpose,
            'chargeable_against' => $chargeableAgainst ?: null,
            'request_date'       => $requestDate,
            'saro_no'            => $saro ?: null,
            'date_withdrawn'     => $dateWithdrawn ?: null,
            'status'             => $newStatus,
            'requested_reviewer_id' => $requestedReviewerId,
            'requested_approver_id' => $requestedApproverId,
            'updated_at'         => date(DATETIME_FORMAT),
        ];

        db()->beginTransaction();
        try {
            if ($isEdit) {
                db()->update('gas_vouchers', $data, 'id = ?', [$voucherId]);
                $message = $newStatus === 'pending_review'
                    ? 'Gas voucher submitted for review.'
                    : 'Gas voucher updated.';
                auditLog('update', 'gas_voucher', $voucherId);
                db()->commit();

                if ($newStatus === 'pending_review' && $voucher->status !== 'pending_review') {
                    // Notify only the selected reviewer, or all approvers if none selected
                    if ($requestedReviewerId) {
                        $requester = currentUser();
                        notify($requestedReviewerId, 'gas_voucher_submitted', 'New Gas Voucher Request', "A new gas voucher has been submitted for review by {$requester->name}.", '/?page=gas-vouchers&action=view&id=' . $voucherId, $voucherId);
                    } else {
                        $approvers = db()->fetchAll(
                            "SELECT id FROM users WHERE role IN (?, ?, ?) AND deleted_at IS NULL",
                            [ROLE_APPROVER, ROLE_MOTORPOOL, ROLE_ADMIN]
                        );
                        $requester = currentUser();
                        foreach ($approvers as $approver) {
                            notify($approver->id, 'gas_voucher_submitted', 'New Gas Voucher Request', "A new gas voucher has been submitted for review by {$requester->name}.", '/?page=gas-vouchers&action=view&id=' . $voucherId, $voucherId);
                        }
                    }
                }

                redirectWith('/?page=gas-vouchers&action=view&id=' . $voucherId, 'success', $message);
            } else {
                $data['voucher_no']          = generateVoucherNo();
                $data['requested_by_user_id'] = userId();
                $data['payment_status']       = 'unpaid';
                $data['created_at']           = date(DATETIME_FORMAT);
                $id = db()->insert('gas_vouchers', $data);
                auditLog('create', 'gas_voucher', $id);
                db()->commit();
                $message = $newStatus === 'pending_review'
                    ? 'Gas voucher submitted for review.'
                    : 'Gas voucher saved as draft.';

                if ($newStatus === 'pending_review') {
                    // Notify only selected reviewers/approvers
                    $notifiedIds = [];
                    $requester = currentUser();
                    $url = '/?page=gas-vouchers&action=view&id=' . $id;

                    if ($requestedReviewerId) {
                        notify($requestedReviewerId, 'gas_voucher_submitted', 'New Gas Voucher Request', "A new gas voucher has been submitted for review by {$requester->name}.", $url, $id);
                        $notifiedIds[] = $requestedReviewerId;
                    }
                    if ($requestedApproverId) {
                        notify($requestedApproverId, 'gas_voucher_submitted', 'New Gas Voucher Request', "A new gas voucher has been submitted for your final approval by {$requester->name}.", $url, $id);
                        $notifiedIds[] = $requestedApproverId;
                    }
                    if (empty($notifiedIds)) {
                        $approvers = db()->fetchAll(
                            "SELECT id FROM users WHERE role IN (?, ?, ?, ?, ?) AND deleted_at IS NULL",
                            [ROLE_APPROVER, ROLE_MOTORPOOL, ROLE_ADMIN, ROLE_CHIEF_ADMIN_FINANCE, ROLE_OIC_CHIEF_ADMIN_FINANCE]
                        );
                        foreach ($approvers as $approver) {
                            notify($approver->id, 'gas_voucher_submitted', 'New Gas Voucher Request', "A new gas voucher has been submitted for review by {$requester->name}.", $url, $id);
                        }
                    }
                }

                redirectWith('/?page=gas-vouchers&action=view&id=' . $id, 'success', $message);
            }
        } catch (Exception $e) {
            db()->rollback();
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

// Pre-fill defaults
$d = $voucher ?? null;
$defaultDate = date('Y-m-d');

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="max-w-3xl mx-auto">

        <!-- Page Header -->
        <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-sm-between gap-4 mb-4">
            <div>
                <h1 class="fs-3 fw-bold d-flex align-items-center gap-2">
                    <i class="bi bi-fuel-pump"></i><?= $pageTitle ?>
                </h1>
                <div class="small text-muted mt-1">
                    <a href="<?= APP_URL ?>" class="">Dashboard</a>
                    <span class="mx-1">/</span>
                    <a href="<?= APP_URL ?>/?page=gas-vouchers" class="">Gas Vouchers</a>
                    <span class="mx-1">/</span>
                    <span><?= $pageTitle ?></span>
                </div>
            </div>
            <a href="<?= APP_URL ?>/?page=gas-vouchers" class="btn btn-secondary">
                <i class="bi bi-arrow-left me-1"></i>Back
            </a>
        </div>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger mb-4">
            <ul class="mb-0 list-disc list-inside">
                <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <form method="POST" id="voucherForm">
            <?= csrfField() ?>

            <!-- Voucher Info Card -->
            <div class="card mb-4">
                <div class="card-header bg-primary">
                    <h3 class="card-title"><i class="bi bi-info-circle me-2"></i>Voucher Information</h3>
                </div>
                <div class="card-body">
                    <div class="row g-4">
                        <?php if ($isEdit): ?>
                        <div class="col-md-6">
                            <label class="form-label small">Voucher No.</label>
                            <input type="text" class="form-control w-100" value="<?= e($voucher->voucher_no) ?>" disabled>
                        </div>
                        <?php endif; ?>
                        <div class="col-md-<?= $isEdit ? '6' : '4' ?>">
                            <label class="form-label small">Request Date <span class="text-danger">*</span></label>
                            <input type="date" name="request_date" class="form-control w-100"
                                   value="<?= e($d ? $d->request_date : $defaultDate) ?>" required>
                        </div>
                        <div class="col-md-<?= $isEdit ? '12' : '8' ?>">
                            <label class="form-label small">Gas Station <span class="text-danger">*</span></label>
                            <select name="gas_station" class="form-select w-100" required>
                                <option value="">-- Select Gas Station --</option>
                                <?php
                                $stations = getActiveGasStations();
                                $currentStation = $d?->gas_station ?? '';
                                if ($currentStation !== '' && !in_array($currentStation, $stations, true)) {
                                    // keep inactive value visible when editing
                                    $stations[] = $currentStation;
                                }
                                foreach ($stations as $st):
                                ?>
                                <option value="<?= e($st) ?>" <?= $currentStation === $st ? 'selected' : '' ?>><?= e($st) ?><?php if (!getActiveGasStations() || !in_array($st, getActiveGasStations(), true)) echo ' (inactive)'; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label class="form-label">
                                <span class="form-text text-muted d-block small text-muted">Select the gas station where the voucher will be used.</span>
                            </label>
                        </div>
                        <?php if (!$isEdit): ?>
                        <div class="col-md-6">
                            <label class="form-label small">Motorpool Head</label>
                            <select name="requested_reviewer_id" class="form-select w-100">
                                <option value="">-- Auto-assign --</option>
                                <?php foreach ($motorpoolHeads as $mp): ?>
                                <option value="<?= $mp->id ?>"><?= e($mp->name) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label class="form-label">
                                <span class="form-text text-muted d-block small text-muted">Select a preferred reviewer (optional)</span>
                            </label>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">Chief Admin & Finance</label>
                            <select name="requested_approver_id" class="form-select w-100">
                                <option value="">-- Auto-assign --</option>
                                <?php foreach ($chiefFinanceUsers as $cf): ?>
                                <option value="<?= $cf->id ?>"><?= e($cf->name) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label class="form-label">
                                <span class="form-text text-muted d-block small text-muted">Select a preferred approver (optional)</span>
                            </label>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Vehicle & Driver Card -->
            <div class="card mb-4">
                <div class="card-header bg-secondary text-secondary-content">
                    <h3 class="card-title"><i class="bi bi-car-front me-2"></i>Vehicle & Driver</h3>
                </div>
                <div class="card-body">
                    <div class="row g-4">
                        <div>
                            <label class="form-label small">Driver / Bearer <span class="text-danger">*</span></label>
                            <input type="text" name="driver_name" class="form-control w-100"
                                   list="driverList"
                                   placeholder="Select or type full name"
                                   value="<?= e($d?->driver_name ?? '') ?>" required>
                            <datalist id="driverList">
                                <?php foreach ($driversList as $drv): ?>
                                <option value="<?= e($drv->name) ?>">
                                <?php endforeach; ?>
                            </datalist>
                            <label class="form-label">
                                <span class="form-text text-muted d-block small text-muted">Select an existing driver or type a name manually if they're not listed.</span>
                            </label>
                        </div>
                        <div>
                            <label class="form-label small">Vehicle Plate Number <span class="text-danger">*</span></label>
                            <input type="text" name="vehicle_plate" class="form-control w-100"
                                   list="plateList"
                                   placeholder="e.g., SJN 940"
                                   value="<?= e($d?->vehicle_plate ?? '') ?>" required>
                            <datalist id="plateList">
                                <?php foreach ($vehicles as $veh): ?>
                                <option value="<?= e($veh->plate_number) ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Fuel/Items Card -->
            <div class="card mb-4">
                <div class="card-header bg-warning text-dark">
                    <h3 class="card-title"><i class="bi bi-fuel-pump me-2"></i>Fuel / Articles Requested</h3>
                </div>
                <div class="card-body">
                    <div class="row g-4">
                        <div class="col-md-4">
                            <label class="form-label small">Fuel Type <span class="text-danger">*</span></label>
                            <select name="fuel_type" class="form-select w-100" required>
                                <option value="Gasoline" <?= ($d?->fuel_type ?? 'Diesel') === 'Gasoline' ? 'selected' : '' ?>>Gasoline</option>
                                <option value="Diesel" <?= ($d?->fuel_type ?? 'Diesel') === 'Diesel' ? 'selected' : '' ?>>Diesel</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label small">Quantity <span class="text-danger">*</span></label>
                            <?php
                                // Determine initial mode from existing data
                                $initMode = 'liters';
                                $initQty  = 20;
                                if ($d) {
                                    if ($d->unit === 'FULL TANK' || $d->quantity == 0) {
                                        $initMode = 'full';
                                    } else {
                                        $initQty = $d->quantity ? (float)$d->quantity : 20;
                                    }
                                }
                            ?>
                            <!-- Hidden mode field -->
                            <input type="hidden" name="quantity_mode" id="quantityMode" value="<?= $initMode ?>">
                            <input type="hidden" name="unit" id="unitHidden" value="<?= $initMode === 'full' ? 'FULL TANK' : 'Liters' ?>">

                            <!-- Toggle buttons -->
                            <div class="btn-group w-100 mb-2" role="group" id="qtyToggleGroup">
                                <button type="button" id="btnFullTank"
                                        class="btn <?= $initMode === 'full' ? 'btn-warning' : 'btn-outline-warning' ?> fw-semibold flex-grow-1"
                                        onclick="setQtyMode('full')">
                                    <i class="bi bi-fuel-pump-fill me-1"></i>Full Tank
                                </button>
                                <button type="button" id="btnLiters"
                                        class="btn <?= $initMode === 'liters' ? 'btn-primary' : 'btn-outline-primary' ?> fw-semibold flex-grow-1"
                                        onclick="setQtyMode('liters')">
                                    <i class="bi bi-123 me-1"></i>Specify Liters
                                </button>
                            </div>

                            <!-- Numeric input (shown only in 'liters' mode) -->
                            <div id="litersInputWrap" style="<?= $initMode === 'full' ? 'display:none;' : '' ?>">
                                <div class="input-group w-100">
                                    <input type="number" name="quantity" id="quantityInput"
                                           class="form-control"
                                           step="0.01" min="0.01"
                                           placeholder="20"
                                           value="<?= e($initMode === 'liters' ? $initQty : 20) ?>">
                                    <span class="input-group-text">liters</span>
                                </div>
                                <label class="form-label">
                                    <span class="form-text text-muted d-block small text-muted">Default: 20 liters. Enter the exact amount needed.</span>
                                </label>
                            </div>

                            <!-- Full Tank indicator (shown only in 'full' mode) -->
                            <div id="fullTankIndicator" style="<?= $initMode === 'full' ? '' : 'display:none;' ?>">
                                <div class="alert alert-warning small d-flex align-items-center gap-2">
                                    <i class="bi bi-fuel-pump-fill fs-5"></i>
                                    <span><strong>Full Tank</strong> — Fill the vehicle to full capacity.</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-12 mt-2">
                            <hr class="border-base-300">
                            <label class="form-label small">Other Articles / Particulars (Optional)</label>
                            <div class="row g-2">
                                <div class="col-md-3">
                                    <input type="number" name="other_qty" class="form-control form-control-sm w-100" step="0.01" min="0.01" placeholder="Qty" value="<?= e($d?->other_qty ?? '') ?>">
                                </div>
                                <div class="col-md-3">
                                    <input type="text" name="other_unit" class="form-control form-control-sm w-100" placeholder="Unit" value="<?= e($d?->other_unit ?? '') ?>">
                                </div>
                                <div class="col-md-6">
                                    <input type="text" name="other_items" class="form-control form-control-sm w-100" placeholder="e.g., Engine Oil, Brake Fluid" value="<?= e($d?->other_items ?? '') ?>">
                                </div>
                            </div>
                            <label class="form-label">
                                <span class="form-text text-muted d-block small text-muted">Specify any additional items needing separate quantity and unit on the voucher.</span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Fund & Purpose Card -->
            <div class="card mb-4">
                <div class="card-header bg-info text-info-content">
                    <h3 class="card-title"><i class="bi bi-clipboard-data me-2"></i>Fund Source & Purpose</h3>
                </div>
                <div class="card-body">
                    <div class="row g-4">
                        <div>
                            <label class="form-label small">Fund Source <span class="text-danger">*</span></label>
                            <input type="text" name="fund_source" class="form-control w-100"
                                   list="fundList"
                                   placeholder="e.g., Free WiFi, GASS, ELGU"
                                   value="<?= e($d?->fund_source ?? '') ?>" required>
                            <datalist id="fundList">
                                <option value="GASS">
                                <option value="Free WiFi">
                                <option value="ELGU">
                                <option value="ILCDB">
                                <option value="DRRM/GECS">
                                <option value="DTC/Tech4ED">
                                <option value="PNPKI">
                                <option value="IIDB">
                                <option value="NBP/GovNet">
                                <option value="GENERAL FUNDS">
                                <option value="Cybersecurity">
                                <option value="eGOV">
                                <option value="GOVNET">
                            </datalist>
                            <label class="form-label">
                                <span class="form-text text-muted d-block small text-muted">The project/program the fuel is derived from.</span>
                            </label>
                        </div>
                        <div>
                            <label class="form-label small">Purpose <span class="text-danger">*</span></label>
                            <textarea name="purpose" class="form-control w-100" rows="3"
                                      placeholder="Describe the purpose of this fuel request..."
                                      required maxlength="1000"><?= e($d?->purpose ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="d-flex flex-wrap gap-2 justify-content-end mb-4">
                <a href="<?= APP_URL ?>/?page=gas-vouchers<?= $isEdit ? '&action=view&id=' . $voucherId : '' ?>" class="btn btn-secondary">
                    <i class="bi bi-x-lg me-1"></i>Cancel
                </a>
                <?php if ($isEdit && in_array($voucher->status, ['pending_review', 'pending_approval'])): ?>
                    <button type="submit" name="form_action" value="save" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i>Save Corrections
                    </button>
                <?php else: ?>
                    <button type="submit" name="form_action" value="save_draft" class="btn btn-outline-primary">
                        <i class="bi bi-floppy me-1"></i>Save as Draft
                    </button>
                    <button type="submit" name="form_action" value="submit" class="btn btn-success">
                        <i class="bi bi-send me-1"></i>Submit for Review
                    </button>
                <?php endif; ?>
            </div>
        </form>

    </div>
</div>

<script>
function setQtyMode(mode) {
    var modeInput     = document.getElementById('quantityMode');
    var unitInput     = document.getElementById('unitHidden');
    var wrap          = document.getElementById('litersInputWrap');
    var fullIndicator = document.getElementById('fullTankIndicator');
    var qtyInput      = document.getElementById('quantityInput');
    var btnFull       = document.getElementById('btnFullTank');
    var btnLiters     = document.getElementById('btnLiters');

    if (mode === 'full') {
        modeInput.value = 'full';
        unitInput.value = 'FULL TANK';
        wrap.style.display          = 'none';
        fullIndicator.style.display = '';
        qtyInput.removeAttribute('required');
        // Toggle button styles
        btnFull.classList.remove('btn-outline-warning');
        btnFull.classList.add('btn-warning');
        btnLiters.classList.remove('btn-primary');
        btnLiters.classList.add('btn-outline-primary');
    } else {
        modeInput.value = 'liters';
        unitInput.value = 'Liters';
        wrap.style.display          = '';
        fullIndicator.style.display = 'none';
        if (!qtyInput.value || parseFloat(qtyInput.value) <= 0) {
            qtyInput.value = '20';
        }
        qtyInput.setAttribute('required', 'required');
        // Toggle button styles
        btnFull.classList.remove('btn-warning');
        btnFull.classList.add('btn-outline-warning');
        btnLiters.classList.remove('btn-outline-primary');
        btnLiters.classList.add('btn-primary');
    }
}
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>

