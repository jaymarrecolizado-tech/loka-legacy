<?php
/**
 * Travel documents for a vehicle request: uploaded TO/OB file, authority TO link,
 * and attached OB Pass Slip (with optional late-bind form for the requester).
 *
 * Expects: $request
 * Optional: $travelDocsShowLateBind (bool) — default false. Set true on requests/view.
 */

if (!function_exists('authorityLinkToUrl') && is_file(INCLUDES_PATH . '/authority_link.php')) {
    require_once INCLUDES_PATH . '/authority_link.php';
}

$travelDocsShowLateBind = !empty($travelDocsShowLateBind);

$boundOb = !empty($request->ob_request_id)
    ? db()->fetch(
        "SELECT o.*, u.name AS employee_name
         FROM ob_requests o
         JOIN users u ON o.user_id = u.id
         WHERE o.id = ? AND o.deleted_at IS NULL",
        [(int) $request->ob_request_id]
    )
    : null;

$canBindLate = $travelDocsShowLateBind
    && function_exists('obAttachAfterSubmitAllowed')
    && obAttachAfterSubmitAllowed()
    && !$boundOb
    && ((int) $request->user_id === (int) userId() || isAdmin())
    && !in_array($request->status, ['completed', 'cancelled'], true);

$bindableObs = ($canBindLate && function_exists('obBindableForRequest'))
    ? obBindableForRequest((int) $request->user_id, $request->start_datetime)
    : [];
?>

<!-- Uploaded Travel Order / OB Slip File -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="bi bi-paperclip me-2"></i>Uploaded Travel Order / OB Slip</h5>
    </div>
    <div class="card-body">
        <?php if (!empty($request->travel_order_file)): ?>
            <div class="d-flex align-items-center justify-content-between p-3 bg-light rounded flex-wrap gap-2">
                <div class="me-3">
                    <div class="fw-bold"><i class="bi bi-file-earmark-text me-1"></i>
                        <?= e($request->travel_order_original_name ?? basename($request->travel_order_file)) ?>
                    </div>
                    <small class="text-muted">
                        Uploaded <?= $request->travel_order_uploaded_at ? e(formatDateTime($request->travel_order_uploaded_at)) : '—' ?>
                    </small>
                </div>
                <?= fileDownloadLink($request->travel_order_file, 'Download') ?>
            </div>
        <?php else: ?>
            <div class="alert alert-warning mb-0">
                <i class="bi bi-exclamation-triangle me-1"></i>
                No Travel Order or OB Slip file was uploaded for this request.
                <?php if (!$boundOb): ?>
                    <span class="d-block small mt-1">Attach a PDF/JPG/PNG on Edit, or attach an approved OB Pass Slip below / on create.</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($request->to_request_id) && function_exists('authorityLinkToUrl')): ?>
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-signpost-2 me-2"></i>Authority Document — Travel Order</h5>
        </div>
        <div class="card-body">
            <div class="d-flex align-items-center justify-content-between p-3 bg-light rounded flex-wrap gap-2">
                <div>
                    <div class="fw-bold"><i class="bi bi-file-earmark-text me-1"></i><?= e($request->to_code ?: ('TO #' . $request->to_request_id)) ?></div>
                    <small class="text-muted">Purpose, dates, destination and travelers are managed by the Travel Order — edit them there, not here.</small>
                </div>
                <a href="<?= e(authorityLinkToUrl((int) $request->to_request_id)) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary">
                    Open in Travel Order <i class="bi bi-box-arrow-up-right ms-1"></i>
                </a>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($boundOb): ?>
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-file-earmark-ruled me-2"></i>Attached OB Pass Slip</h5>
        </div>
        <div class="card-body">
            <div class="d-flex align-items-center justify-content-between p-3 bg-light rounded flex-wrap gap-2">
                <div>
                    <div class="fw-bold"><i class="bi bi-file-earmark-text me-1"></i><?= e($boundOb->pass_slip_no) ?>
                        <span class="badge bg-<?= e(obStatusColor($boundOb->status)) ?> ms-1"><?= e(obStatusLabel($boundOb->status)) ?></span>
                    </div>
                    <small class="text-muted"><?= e($boundOb->employee_name) ?> · <?= e(date('M j, Y', strtotime($boundOb->ob_date))) ?> · satisfies the travel-document requirement (no TO file needed)</small>
                </div>
                <div class="d-flex gap-2">
                    <a href="<?= APP_URL ?>/?page=ob-requests&action=view&id=<?= (int) $boundOb->id ?>" class="btn btn-sm btn-outline-primary">View OB</a>
                    <?php if (in_array($boundOb->status, ['approved', 'departed', 'coa_received', 'completed'], true)): ?>
                    <a href="<?= APP_URL ?>/?page=ob-requests&action=print&id=<?= (int) $boundOb->id ?>" target="_blank" class="btn btn-sm btn-outline-dark">Print</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php elseif ($canBindLate && !empty($bindableObs)): ?>
    <div class="card mb-4 border-primary-subtle">
        <div class="card-body py-2">
            <form method="POST" action="<?= APP_URL ?>/?page=ob-requests&action=process" class="row g-2 align-items-end">
                <?= csrfField() ?>
                <div class="col-auto"><strong class="small">Attach approved OB:</strong></div>
                <div class="col-auto">
                    <select class="form-select form-select-sm" name="ob_id" required>
                        <?php foreach ($bindableObs as $obOpt): ?>
                        <option value="<?= (int) $obOpt->id ?>"><?= e($obOpt->pass_slip_no) ?> — <?= e(date('M j, Y', strtotime($obOpt->ob_date))) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <input type="hidden" name="request_id" value="<?= (int) $request->id ?>">
                <div class="col-auto">
                    <button type="submit" name="action" value="vehicle_bind" class="btn btn-sm btn-outline-primary">Attach</button>
                </div>
                <div class="col-auto"><small class="text-muted">Delayed attach is enabled (All Father setting). 1 OB = 1 request.</small></div>
            </form>
        </div>
    </div>
<?php elseif (!$boundOb && empty($request->travel_order_file) && empty($request->to_request_id)): ?>
    <div class="card mb-4 border-0">
        <div class="card-body py-0 px-0">
            <p class="text-muted small mb-0">
                <i class="bi bi-info-circle me-1"></i>
                No OB Pass Slip is linked to this request. Requesters can attach one on Create/Edit, or upload a Travel Order file.
            </p>
        </div>
    </div>
<?php endif; ?>
