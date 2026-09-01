<?php
/**
 * LOKA - Anonymous Driver Evaluation (Public token-gated)
 *
 * Route: ?page=evaluations&action=submit&token=RAW
 * No login required. Token proves passenger participation.
 * Identity is never shown in reports — ratings are anonymous (GRAB-like).
 */

$rawToken = trim((string) get('token', ''));
$eval = $rawToken !== '' ? findDriverEvaluationByToken($rawToken) : null;

$error = '';
$done = false;
$expired = false;

if (!$rawToken || !$eval) {
    $error = 'This evaluation link is invalid or has expired.';
} elseif (!empty($eval->submitted_at)) {
    $done = true;
} else {
    $expiryDays = driverEvaluationExpiryDays();
    $createdTs = strtotime($eval->created_at);
    if ($createdTs && (time() - $createdTs) > ($expiryDays * 86400)) {
        $expired = true;
        $error = 'This evaluation link has expired (' . $expiryDays . ' days limit). Please contact the motorpool if you still wish to provide feedback.';
    }
}

// Handle POST submission
if (!$error && !$done && !$expired && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // No CSRF for public token page — token is the capability
    $ratings = [];
    $criteria = ['punctuality','safety','courtesy','driving','vehicle'];
    $valid = true;
    $errMsg = '';
    foreach ($criteria as $c) {
        $key = 'rating_' . $c;
        $val = postInt($key, 0);
        if ($val < 1 || $val > 5) {
            $valid = false;
            $errMsg = 'Please rate all 5 criteria (1-5 stars).';
            break;
        }
        $ratings[$c] = $val;
    }
    $remarks = trim((string) postSafe('remarks', '', 2000));

    if (!$valid) {
        $error = $errMsg;
    } else {
        $overall = round(array_sum($ratings) / count($ratings), 2);
        $now = date(DATETIME_FORMAT);

        // Re-fetch with lock to prevent double submit
        $fresh = db()->fetch("SELECT id, submitted_at FROM driver_evaluations WHERE id = ? FOR UPDATE", [$eval->id]);
        if ($fresh && $fresh->submitted_at !== null) {
            $done = true;
        } else {
            $affected = db()->update('driver_evaluations', [
                'rating_punctuality' => $ratings['punctuality'],
                'rating_safety' => $ratings['safety'],
                'rating_courtesy' => $ratings['courtesy'],
                'rating_driving' => $ratings['driving'],
                'rating_vehicle' => $ratings['vehicle'],
                'overall' => $overall,
                'remarks' => $remarks !== '' ? $remarks : null,
                'submitted_at' => $now
            ], 'id = ? AND submitted_at IS NULL', [$eval->id]);

            if ($affected > 0) {
                auditLog('evaluation_submitted', 'driver_evaluation', (int) $eval->id, null, [
                    'request_id' => (int) $eval->request_id,
                    'driver_id' => (int) $eval->driver_id,
                    'overall' => $overall
                ]);
                $done = true;
                // Refresh eval for display
                $eval->submitted_at = $now;
                $eval->overall = $overall;
            } else {
                $error = 'This evaluation was already submitted. Thank you!';
                $done = true;
            }
        }
    }
}

// Helper to render stars
function renderStarsStatic($rating) {
    $out = '';
    for ($i=1; $i<=5; $i++) {
        $out .= $i <= $rating ? '<i class="bi bi-star-fill text-warning"></i>' : '<i class="bi bi-star text-muted"></i>';
    }
    return $out;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Rate Your Driver - <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        .star-rating { display:flex; gap:4px; font-size:1.8rem; cursor:pointer; }
        .star-rating i { transition: transform 0.1s; }
        .star-rating i:hover { transform: scale(1.2); }
        .criterion-label { font-weight:600; min-width:160px; }
    </style>
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:700px;">

<?php if ($error && !$done): ?>
    <div class="card shadow-sm">
        <div class="card-body text-center py-5">
            <i class="bi bi-x-octagon-fill text-danger fs-1"></i>
            <h4 class="mt-3"><?= $expired ? 'Link Expired' : 'Link Not Usable' ?></h4>
            <p class="text-muted mb-0"><?= e($error) ?></p>
            <a href="<?= e(APP_URL) ?>" class="btn btn-outline-secondary mt-3"><i class="bi bi-house me-1"></i>Go to Home</a>
        </div>
    </div>

<?php elseif ($done): ?>
    <div class="card shadow-sm">
        <div class="card-body text-center py-5">
            <i class="bi bi-check-circle-fill text-success fs-1"></i>
            <h4 class="mt-3">Thank You!</h4>
            <p class="text-muted">Your evaluation has been recorded. Your feedback is <strong>completely anonymous</strong> — the driver and motorpool will never see who submitted which rating or comment.<br><br>
            <?php if (isset($eval->overall) && $eval->overall !== null): ?>
                <span class="badge bg-success fs-6">Overall: <?= e(number_format((float)$eval->overall,2)) ?> / 5.00</span>
            <?php endif; ?>
            </p>
            <p class="text-muted small mb-0"><i class="bi bi-shield-lock me-1"></i>Like GRAB, your identity is protected.</p>
        </div>
    </div>

<?php elseif ($eval): ?>
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white text-center">
            <h5 class="mb-0"><i class="bi bi-star-half me-2"></i>Rate Your Driver</h5>
        </div>
        <div class="card-body p-4">
            <div class="alert alert-info">
                <i class="bi bi-shield-lock me-1"></i><strong>Anonymous evaluation</strong> — your name will <u>never</u> be shown with your ratings or comments. Only aggregated results appear in reports.
            </div>

            <div class="border rounded p-3 bg-white mb-4">
                <p class="mb-1"><strong>Trip #:</strong> <?= (int) $eval->request_id ?> — <?= e($eval->destination) ?></p>
                <p class="mb-1"><strong>Driver:</strong> <?= e($eval->driver_name ?: '—') ?></p>
                <p class="mb-1"><strong>Vehicle:</strong> <?= e(trim(($eval->plate_number ?? '') . ' ' . ($eval->vehicle_make ?? '') . ' ' . ($eval->vehicle_model ?? ''))) ?: '—' ?></p>
                <p class="mb-0"><strong>Trip date:</strong> <?= e(formatDateTime($eval->trip_start)) ?></p>
            </div>

            <form method="POST" id="evalForm">
                <?php
                $criteriaInfo = [
                    'punctuality' => ['label'=>'Punctuality','hint'=>'On time, efficient'],
                    'safety' => ['label'=>'Safety','hint'=>'Safe driving practices'],
                    'courtesy' => ['label'=>'Courtesy','hint'=>'Polite & helpful'],
                    'driving' => ['label'=>'Driving Skill','hint'=>'Smooth, skilled driving'],
                    'vehicle' => ['label'=>'Vehicle Condition','hint'=>'Clean & well-maintained'],
                ];
                foreach ($criteriaInfo as $key => $info):
                ?>
                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center flex-wrap">
                        <div>
                            <span class="criterion-label"><?= e($info['label']) ?></span>
                            <small class="text-muted d-block"><?= e($info['hint']) ?></small>
                        </div>
                        <div class="star-rating" data-criterion="<?= e($key) ?>">
                            <?php for ($i=1; $i<=5; $i++): ?>
                                <i class="bi bi-star" data-value="<?= $i ?>"></i>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <input type="hidden" name="rating_<?= e($key) ?>" id="rating_<?= e($key) ?>" required>
                    <div class="invalid-feedback d-block" id="err_<?= e($key) ?>" style="display:none !important; color:#dc3545; font-size:0.875em;">Required</div>
                </div>
                <?php endforeach; ?>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Comments / Suggestions <span class="text-muted">(optional, anonymous)</span></label>
                    <textarea class="form-control" name="remarks" rows="3" maxlength="2000" placeholder="Your suggestions will be shown anonymously to help improve service..."></textarea>
                    <small class="text-muted">Max 2000 characters. Will be shown without your name.</small>
                </div>

                <button type="submit" class="btn btn-primary btn-lg w-100"><i class="bi bi-send me-1"></i>Submit Evaluation</button>
                <p class="text-center text-muted small mt-3 mb-0"><i class="bi bi-lock me-1"></i>Secure single-use link • Expires in <?= driverEvaluationExpiryDays() ?> days</p>
            </form>
        </div>
    </div>
<?php endif; ?>

    <p class="text-center text-muted small mt-4 mb-0"><i class="bi bi-shield-lock me-1"></i><?= e(APP_NAME) ?> — anonymous driver evaluation</p>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('.star-rating').forEach(function(group){
    const hidden = document.getElementById('rating_' + group.dataset.criterion);
    const stars = group.querySelectorAll('i');
    let current = 0;
    function paint(val){
        stars.forEach(function(s,i){
            if (i < val) { s.classList.remove('bi-star'); s.classList.add('bi-star-fill','text-warning'); }
            else { s.classList.remove('bi-star-fill','text-warning'); s.classList.add('bi-star'); }
        });
    }
    stars.forEach(function(s){
        s.addEventListener('click', function(){
            current = parseInt(s.dataset.value);
            hidden.value = current;
            paint(current);
            document.getElementById('err_' + group.dataset.criterion).style.display = 'none';
        });
        s.addEventListener('mouseenter', function(){
            paint(parseInt(s.dataset.value));
        });
    });
    group.addEventListener('mouseleave', function(){ paint(current); });
});
document.getElementById('evalForm')?.addEventListener('submit', function(e){
    let ok = true;
    document.querySelectorAll('.star-rating').forEach(function(g){
        const hid = document.getElementById('rating_' + g.dataset.criterion);
        if (!hid.value) { document.getElementById('err_' + g.dataset.criterion).style.display = 'block'; ok = false; }
    });
    if (!ok) { e.preventDefault(); alert('Please rate all 5 criteria.'); }
});
</script>
</body>
</html>
