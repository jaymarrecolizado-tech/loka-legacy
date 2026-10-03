<?php
/**
 * LOKA - Repair History workbook importer (Plan #38, experimental)
 * All Father only. Route: ?page=repair-history&action=import
 */

require_once INCLUDES_PATH . '/repair_history.php';
require_once INCLUDES_PATH . '/repair_history_import.php';
requireSystemControl();

if (!repairHistoryEnabled()) {
    redirectWith('/?page=repair-history', 'warning', 'Enable Repair History before importing.');
}

$report = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $op = postSafe('op', '', 20);
    $files = repairHistoryScanWorkbooks();
    $byRel = [];
    foreach ($files as $f) {
        $byRel[$f['rel']] = $f;
    }

    if ($op === 'import_all' || $op === 'import_one') {
        $targets = $op === 'import_all'
            ? $files
            : array_values(array_filter($files, static fn($f) => $f['rel'] === post('rel', '')));

        if ($targets === []) {
            $errors[] = 'No matching workbook was found.';
        }
        foreach ($targets as $f) {
            try {
                $r = repairHistoryImportWorkbook($f['path']);
                $report[] = ['file' => $f['rel'], 'result' => $r, 'error' => null];
            } catch (Throwable $e) {
                error_log('repair history import ' . $f['rel'] . ': ' . $e->getMessage());
                $report[] = ['file' => $f['rel'], 'result' => null, 'error' => $e->getMessage()];
            }
        }
    } elseif ($op === 'preview_one') {
        $rel = post('rel', '');
        $target = $byRel[$rel] ?? null;
        if (!$target) {
            $errors[] = 'Workbook not found.';
        } else {
            try {
                $parsed = repairHistoryParseWorkbook($target['path']);
                $report[] = [
                    'file' => $rel,
                    'result' => null,
                    'error' => null,
                    'preview' => $parsed,
                    'dry' => repairHistoryImportWorkbook($target['path'], true),
                ];
            } catch (Throwable $e) {
                $report[] = ['file' => $rel, 'result' => null, 'error' => $e->getMessage()];
            }
        }
    }
}

$files = repairHistoryScanWorkbooks();
$dirOk = is_dir(repairHistoryReferenceDir());

$pageTitle = 'Import repair history';
require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid px-4 py-4">
    <div class="d-flex flex-wrap align-items-start gap-3 mb-4">
        <div class="me-auto">
            <h4 class="mb-1"><i class="bi bi-file-earmark-arrow-up me-2"></i>Import Repair History Workbooks</h4>
            <p class="text-muted small mb-0">
                Reads <code>Reference/Repair History/**/*.xlsx</code> and creates one repair event per
                (date, nature) pair with its purchased-item lines. Source is recorded as
                <strong>Imported</strong>. Re-importing the same workbook is a no-op.
            </p>
        </div>
        <a class="btn btn-outline-secondary" href="<?= APP_URL ?>/?page=repair-history"><i class="bi bi-arrow-left me-1"></i>Back to hub</a>
    </div>

    <?php foreach ($errors as $err): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i><?= e($err) ?></div>
    <?php endforeach; ?>

    <?php if ($report !== []): ?>
        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0">Import report</h5></div>
            <div class="card-body">
                <?php foreach ($report as $row): ?>
                    <div class="border rounded p-3 mb-2">
                        <div class="fw-semibold font-monospace small"><?= e($row['file']) ?></div>
                        <?php if ($row['error']): ?>
                            <div class="text-danger small mt-1"><i class="bi bi-x-circle me-1"></i><?= e($row['error']) ?></div>
                        <?php elseif (isset($row['preview'])): ?>
                            <?php $pv = $row['preview']; ?>
                            <div class="small text-muted mt-1">
                                Plate <strong><?= e($pv['plate'] ?: '—') ?></strong> ·
                                Engine <span class="font-monospace"><?= e($pv['engine_no'] ?: '—') ?></span> ·
                                <?= e($pv['brand_model'] ?: '—') ?> ·
                                <?= count($pv['entries']) ?> event(s)
                            </div>
                            <div class="table-responsive mt-2" style="max-height:22rem;overflow:auto;">
                                <table class="table table-sm small mb-0">
                                    <thead class="table-light" style="position:sticky;top:0;">
                                        <tr><th>Date</th><th>Nature of Repair</th><th>Description</th><th>Unit</th><th class="text-end">Qty</th><th class="text-end">Amount</th></tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($pv['entries'] as $entry): ?>
                                        <?php $first = true; ?>
                                        <?php foreach ($entry['items'] as $it): ?>
                                        <tr>
                                            <?php if ($first): ?>
                                                <td rowspan="<?= count($entry['items']) ?>"><?= formatDate($entry['date']) ?></td>
                                                <td rowspan="<?= count($entry['items']) ?>"><?= e($entry['nature']) ?></td>
                                                <?php $first = false; ?>
                                            <?php endif; ?>
                                            <td><?= e($it['description']) ?></td>
                                            <td><?= e($it['unit'] ?: '—') ?></td>
                                            <td class="text-end"><?= e(rtrim(rtrim(number_format($it['quantity'], 2, '.', ''), '0'), '.')) ?></td>
                                            <td class="text-end"><?= $it['amount'] !== null ? number_format($it['amount'], 2) : '—' ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="small mt-2">
                                <i class="bi bi-info-circle me-1"></i>
                                Preview only — nothing written. Would create
                                <strong><?= (int) $row['dry']['entries'] ?></strong> event(s),
                                <strong><?= (int) $row['dry']['items'] ?></strong> line(s), total ₱<?= number_format($row['dry']['total'], 2) ?>.
                            </div>
                        <?php else: ?>
                            <?php $r = $row['result']; ?>
                            <div class="small mt-1">
                                Plate <strong><?= e($r['plate'] ?: '—') ?></strong> ·
                                created <strong><?= (int) $r['entries'] ?></strong> event(s),
                                <strong><?= (int) $r['items'] ?></strong> line(s), ₱<?= number_format($r['total'], 2) ?>
                                <?php if ($r['skipped']): ?>
                                    <span class="text-muted">· skipped: <?= e(implode('; ', $r['skipped'])) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="alert alert-info small d-flex align-items-start" role="alert">
        <i class="bi bi-info-circle-fill flex-shrink-0 me-2"></i>
        <div>Pre/post-inspection <em>Request 4</em> workbooks are intentionally ignored — they are out of scope for Plan #38.</div>
    </div>

    <?php if (!$dirOk): ?>
        <div class="alert alert-danger">Reference folder not found: <code><?= e(repairHistoryReferenceDir()) ?></code></div>
    <?php elseif ($files === []): ?>
        <div class="alert alert-warning">No repair-history workbooks found under <code>Reference/Repair History</code>.</div>
    <?php else: ?>
        <div class="card">
            <div class="card-header d-flex align-items-center">
                <h5 class="mb-0 me-auto">Workbooks <span class="badge bg-secondary ms-1"><?= count($files) ?></span></h5>
                <form method="POST" onsubmit="return confirm('Import every workbook listed below? Entries already imported are skipped.');">
                    <?= csrfField() ?>
                    <input type="hidden" name="op" value="import_all">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-download me-1"></i>Import all</button>
                </form>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead><tr><th>Workbook</th><th class="text-end">Size</th><th class="text-end">Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($files as $f): ?>
                            <tr>
                                <td class="font-monospace small"><?= e($f['rel']) ?></td>
                                <td class="text-end small text-muted"><?= number_format($f['size'] / 1024, 1) ?> KB</td>
                                <td class="text-end text-nowrap">
                                    <div class="btn-group btn-group-sm">
                                        <form method="POST">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="op" value="preview_one">
                                            <input type="hidden" name="rel" value="<?= e($f['rel']) ?>">
                                            <button class="btn btn-outline-secondary"><i class="bi bi-eye"></i> Preview</button>
                                        </form>
                                        <form method="POST" onsubmit="return confirm('Import <?= e($f['name']) ?>? Entries already imported are skipped.');">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="op" value="import_one">
                                            <input type="hidden" name="rel" value="<?= e($f['rel']) ?>">
                                            <button class="btn btn-outline-primary"><i class="bi bi-download"></i> Import</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>