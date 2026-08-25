<?php
/**
 * Vehicle condition observations for request view. (Bootstrap 5)
 * Expects: $request (object with id)
 *
 * Requires includes/vehicle_observations.php.
 */
if (!function_exists('observationGetForRequest')) {
    require_once INCLUDES_PATH . '/vehicle_observations.php';
}
$observations = observationGetForRequest((int) $request->id);
if (empty($observations)) {
    return;
}

$conditionBadge = [
    'good' => 'bg-success',
    'fair' => 'bg-warning text-dark',
    'poor' => 'bg-danger',
    'damaged' => 'bg-danger',
];
?>
<div class="card mt-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="bi bi-camera"></i> Vehicle condition (Guard)</h5>
    </div>
    <div class="card-body">
        <div class="row g-4">
            <?php foreach ($observations as $obs):
                $photos = observationPhotos((int) $obs->id);
                $flags = json_decode($obs->flags_json ?: '{}', true) ?: [];
                $flagLabels = array_keys(array_filter($flags));
                $phaseLabel = $obs->phase === 'arrival' ? 'Upon arrival' : 'Before dispatch';
                $badge = $conditionBadge[$obs->overall_condition] ?? 'bg-secondary';
            ?>
                <div class="col-md-6">
                    <div class="border rounded p-3">
                        <div class="d-flex align-items-center justify-content-between gap-2">
                            <div class="fw-semibold"><?= e($phaseLabel) ?></div>
                            <span class="badge <?= e($badge) ?>"><?= e(ucfirst($obs->overall_condition)) ?></span>
                        </div>
                        <div class="small text-muted">
                            <?= e(formatDateTime($obs->observed_at)) ?>
                            <?php if (!empty($obs->guard_name)): ?>
                                · <?= e($obs->guard_name) ?>
                            <?php endif; ?>
                        </div>
                        <?php if ($flagLabels): ?>
                            <div class="d-flex flex-wrap gap-1 mt-2">
                                <?php foreach ($flagLabels as $f): ?>
                                    <span class="badge bg-secondary"><?= e(str_replace('_', ' ', $f)) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($obs->notes)): ?>
                            <p class="small mb-0 mt-2"><?= nl2br(e($obs->notes)) ?></p>
                        <?php endif; ?>
                        <?php if ($photos): ?>
                            <div class="d-flex flex-wrap gap-2 mt-2">
                                <?php foreach ($photos as $photo):
                                    $thumb = $photo->thumb_path ?: $photo->file_path;
                                    $full = $photo->full_path ?: $photo->file_path;
                                ?>
                                    <a href="<?= e(observationFileUrl($full)) ?>" target="_blank" rel="noopener" class="d-block">
                                        <img src="<?= e(observationFileUrl($thumb)) ?>"
                                             alt="Vehicle photo"
                                             class="rounded border"
                                             style="width:5rem;height:5rem;object-fit:cover;"
                                             loading="lazy">
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
