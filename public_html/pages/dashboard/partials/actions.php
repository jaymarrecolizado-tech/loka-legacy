<?php
/** @var array<string, mixed> $dash */
$nextTrip = $dash['nextTrip'] ?? null;
$actions = $dash['actions'] ?? [];
$btnClass = [
    'warning' => 'btn-warning',
    'info' => 'btn-info',
    'error' => 'btn-danger',
    'primary' => 'btn-primary',
    'success' => 'btn-success',
];

if (!empty($nextTrip)): ?>
<div class="alert alert-primary d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <div class="fw-semibold"><i class="bi bi-truck me-1"></i>Next assigned trip</div>
        <div class="small">
            <?= e(formatDateTime((string) $nextTrip['start_datetime'])) ?>
            <?php if ($nextTrip['destination'] !== ''): ?>
                · <?= e(truncate((string) $nextTrip['destination'], 60)) ?>
            <?php endif; ?>
            <?php if ($nextTrip['plate_number'] !== ''): ?>
                · <span class="badge bg-light text-dark"><?= e((string) $nextTrip['plate_number']) ?></span>
            <?php endif; ?>
        </div>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= e((string) $nextTrip['href']) ?>" class="btn btn-sm btn-primary">Open</a>
        <a href="<?= APP_URL ?>/?page=my-trips" class="btn btn-sm btn-outline-primary">My Trips</a>
    </div>
</div>
<?php endif; ?>

<?php
$visible = [];
foreach ($actions as $action) {
    if ($action['count'] !== null && (int) $action['count'] === 0) {
        continue;
    }
    $visible[] = $action;
}
if ($visible === []) {
    return;
}
?>
<div class="d-flex flex-wrap gap-2 mb-4">
    <?php foreach ($visible as $action):
        $cls = $btnClass[$action['tone'] ?? 'info'] ?? 'btn-outline-secondary';
        ?>
    <a href="<?= e((string) $action['href']) ?>" class="btn btn-sm <?= e($cls) ?>">
        <?= e((string) $action['label']) ?>
        <?php if ($action['count'] !== null): ?>
        <span class="badge bg-dark bg-opacity-25 ms-1"><?= (int) $action['count'] ?></span>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>
