<?php
/**
 * LOKA - Gas voucher workflow assignees (Plan #31, hybrid C)
 *
 * Real All Father only (System Control). Toggles the Budget Officer /
 * OIC Budget Officer flags in one place and shows the Motorpool and
 * Chief Admin & Finance pools (role-based) for awareness.
 *
 * Toggling a flag changes who MAY act on the pending_budget step — it does
 * not give All Father any budget-step approval power (decision 2/3).
 */

requireSystemControl();

$pageTitle = 'Gas Voucher Workflow Assignees';

// Flag toggle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $targetUserId = postInt('user_id');
    $flag = post('flag', '');
    $value = post('value', '0') === '1' ? 1 : 0;
    $allowedFlags = ['is_budget_officer', 'is_oic_budget_officer'];

    $target = db()->fetch("SELECT id, name FROM users WHERE id = ? AND deleted_at IS NULL", [$targetUserId]);

    if (!$target || !in_array($flag, $allowedFlags, true)) {
        redirectWith('/?page=settings&action=gas-workflow', 'danger', 'Invalid assignee update.');
    }

    db()->update('users', [$flag => $value, 'updated_at' => date(DATETIME_FORMAT)], 'id = ?', [$targetUserId]);
    auditLog('user_updated', 'user', $targetUserId);
    clearUserCache();

    redirectWith(
        '/?page=settings&action=gas-workflow',
        'success',
        ($value ? 'Assigned ' : 'Removed ') . $target->name . ($flag === 'is_budget_officer' ? ' as Budget Officer.' : ' as OIC Budget Officer.')
    );
}

// Active users: flagged ones first, then by name
$assignees = db()->fetchAll(
    "SELECT id, name, role, is_budget_officer, is_oic_budget_officer
     FROM users
     WHERE status = 'active' AND deleted_at IS NULL
     ORDER BY (is_budget_officer = 1 OR is_oic_budget_officer = 1) DESC, name ASC"
);

// Read-only role pools (assigned via Users → Role)
$motorpoolHeads = db()->fetchAll(
    "SELECT id, name FROM users WHERE role = ? AND status = 'active' AND deleted_at IS NULL ORDER BY name",
    [ROLE_MOTORPOOL]
);
$chiefFinanceUsers = db()->fetchAll(
    "SELECT id, name, role FROM users WHERE role IN (?, ?) AND status = 'active' AND deleted_at IS NULL ORDER BY name",
    [ROLE_CHIEF_ADMIN_FINANCE, ROLE_OIC_CHIEF_ADMIN_FINANCE]
);

$pendingBudgetCount = (int) db()->fetchColumn(
    "SELECT COUNT(*) FROM gas_vouchers WHERE status = 'pending_budget' AND deleted_at IS NULL"
);

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="mb-4">
        <h4 class="mb-1"><i class="bi bi-cash-coin me-2"></i>Gas Voucher Workflow Assignees</h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>/?page=settings">Settings</a></li>
                <li class="breadcrumb-item active">Gas Workflow</li>
            </ol>
        </nav>
    </div>

    <div class="row g-4">

        <!-- Left: budget officer toggles -->
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-person-check me-2"></i>Budget Officer Pool</h5>
                    <span class="badge bg-primary"><?= $pendingBudgetCount ?> pending budget</span>
                </div>
                <div class="card-body">
                    <p class="text-muted small">
                        Flagged accounts — and only these — can certify vouchers at the
                        <strong>pending budget</strong> step (between Motorpool review and Chief Admin &amp; Finance
                        final approval). Roles alone never grant the step, and All Father assigns but never approves it.
                    </p>
                    <input type="text" id="assigneeSearch" class="form-control form-control-sm mb-3"
                           placeholder="Search assignee name...">
                    <div class="table-responsive" style="max-height: 60vh; overflow-y: auto;">
                        <table class="table table-hover align-middle" id="assigneesTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Name</th>
                                    <th>Role</th>
                                    <th class="text-center">Budget Officer</th>
                                    <th class="text-center">OIC Budget Officer</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assignees as $a): ?>
                                <tr data-name="<?= e(mb_strtolower($a->name)) ?>">
                                    <td class="fw-semibold"><?= e($a->name) ?></td>
                                    <td><span class="badge bg-light text-dark"><?= e(ROLE_LABELS[$a->role]['label'] ?? $a->role) ?></span></td>
                                    <td class="text-center">
                                        <form method="POST" class="d-inline">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="user_id" value="<?= (int) $a->id ?>">
                                            <input type="hidden" name="flag" value="is_budget_officer">
                                            <input type="hidden" name="value" value="<?= (int) $a->is_budget_officer === 1 ? '0' : '1' ?>">
                                            <div class="form-check form-switch d-inline-block mb-0">
                                                <input class="form-check-input" type="checkbox" role="switch"
                                                       <?= (int) $a->is_budget_officer === 1 ? 'checked' : '' ?>
                                                       onchange="this.form.submit()" title="<?= (int) $a->is_budget_officer === 1 ? 'Revoke' : 'Assign' ?> Budget Officer">
                                            </div>
                                        </form>
                                    </td>
                                    <td class="text-center">
                                        <form method="POST" class="d-inline">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="user_id" value="<?= (int) $a->id ?>">
                                            <input type="hidden" name="flag" value="is_oic_budget_officer">
                                            <input type="hidden" name="value" value="<?= (int) $a->is_oic_budget_officer === 1 ? '0' : '1' ?>">
                                            <div class="form-check form-switch d-inline-block mb-0">
                                                <input class="form-check-input" type="checkbox" role="switch"
                                                       <?= (int) $a->is_oic_budget_officer === 1 ? 'checked' : '' ?>
                                                       onchange="this.form.submit()" title="<?= (int) $a->is_oic_budget_officer === 1 ? 'Revoke' : 'Assign' ?> OIC Budget Officer">
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right: read-only role pools -->
        <div class="col-lg-5">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-gear me-2"></i>Motorpool Heads <span class="badge bg-secondary ms-1">read-only</span></h5>
                </div>
                <div class="card-body py-2">
                    <?php if (empty($motorpoolHeads)): ?>
                    <p class="text-muted small mb-0">No active Motorpool Heads.</p>
                    <?php else: ?>
                    <ul class="list-unstyled mb-0 py-2">
                        <?php foreach ($motorpoolHeads as $mp): ?>
                        <li class="py-1"><i class="bi bi-person me-2 text-muted"></i><?= e($mp->name) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                    <p class="text-muted small">Step 1 review. Assigned via Users &rarr; Role.</p>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-wallet2 me-2"></i>Chief Admin &amp; Finance <span class="badge bg-secondary ms-1">read-only</span></h5>
                </div>
                <div class="card-body py-2">
                    <?php if (empty($chiefFinanceUsers)): ?>
                    <p class="text-muted small mb-0">No active Chief Admin &amp; Finance.</p>
                    <?php else: ?>
                    <ul class="list-unstyled mb-0 py-2">
                        <?php foreach ($chiefFinanceUsers as $cf): ?>
                        <li class="py-1">
                            <i class="bi bi-person me-2 text-muted"></i><?= e($cf->name) ?>
                            <?php if ($cf->role === ROLE_OIC_CHIEF_ADMIN_FINANCE): ?>
                            <span class="badge bg-info text-dark ms-1">OIC</span>
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                    <p class="text-muted small">Final approval. Assigned via Users &rarr; Role.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('assigneeSearch')?.addEventListener('input', function () {
    const q = this.value.trim().toLowerCase();
    document.querySelectorAll('#assigneesTable tbody tr').forEach(function (row) {
        row.style.display = row.dataset.name.indexOf(q) !== -1 ? '' : 'none';
    });
});
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
