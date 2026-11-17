<?php
/** @var array<string, mixed> $dash */
$kpis = $dash['kpis'] ?? [];
if ($kpis === []) {
    return;
}
?>
<div class="row g-4 mb-4 stats-row">
    <?php foreach ($kpis as $card):
        $tone = $card['tone'] === 'error' ? 'danger' : $card['tone'];
        $href = (string) ($card['href'] ?? '');
        ?>
    <div class="col-6 col-md-4 col-xl">
        <a href="<?= e($href) ?>" class="card stat-card h-100 stat-card-link">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-value text-<?= e($tone) ?>"><?= (int) $card['value'] ?></div>
                        <div class="stat-label"><?= e((string) $card['label']) ?></div>
                        <div class="stat-hint"><i class="bi bi-box-arrow-up-right me-1"></i>View</div>
                    </div>
                    <div class="stat-icon bg-<?= e($tone) ?> bg-opacity-10 text-<?= e($tone) ?>">
                        <i class="bi <?= e((string) ($card['icon'] ?? 'bi-speedometer2')) ?>"></i>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <?php endforeach; ?>
</div>
