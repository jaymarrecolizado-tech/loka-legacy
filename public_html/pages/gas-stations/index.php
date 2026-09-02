<?php
/**
 * Gas Stations — Master List (All Father / Admin)
 */
if (!canAccessSystemControl() && !isAdmin()) {
    redirectWith('/?page=dashboard', 'danger', 'Access denied.');
}
$pageTitle = 'Gas Stations';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $op = post('op','');
    $id = (int) post('id',0);
    if ($op === 'toggle' && $id) {
        $row = db()->fetch("SELECT * FROM gas_stations WHERE id=? AND deleted_at IS NULL", [$id]);
        if ($row) {
            $new = $row->status === 'active' ? 'inactive' : 'active';
            db()->update('gas_stations', ['status'=>$new, 'updated_at'=>date(DATETIME_FORMAT)], 'id=?', [$id]);
            auditLog('gas_station_toggled','gas_station',$id,['status'=>$row->status],['status'=>$new]);
            redirectWith('/?page=gas-stations', 'success', $row->name . ' is now ' . $new . '.');
        }
    }
    if ($op === 'delete' && $id) {
        $row = db()->fetch("SELECT * FROM gas_stations WHERE id=? AND deleted_at IS NULL", [$id]);
        if ($row) {
            $cnt = db()->fetchColumn("SELECT COUNT(*) FROM gas_vouchers WHERE gas_station=? AND deleted_at IS NULL", [$row->name]);
            if ($cnt > 0) {
                redirectWith('/?page=gas-stations', 'warning', 'Cannot delete: ' . $cnt . ' voucher(s) use this station. Deactivate instead.');
            }
            db()->update('gas_stations', ['deleted_at'=>date(DATETIME_FORMAT)], 'id=?', [$id]);
            auditLog('gas_station_deleted','gas_station',$id);
            redirectWith('/?page=gas-stations', 'success', 'Station deleted.');
        }
    }
}

$stations = db()->fetchAll("SELECT gs.*, (SELECT COUNT(*) FROM gas_vouchers gv WHERE gv.gas_station=gs.name AND gv.deleted_at IS NULL) as voucher_count FROM gas_stations gs WHERE gs.deleted_at IS NULL ORDER BY gs.status='active' DESC, gs.name");

require_once INCLUDES_PATH . '/header.php';
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-fuel-pump me-2"></i>Gas Stations</h4>
            <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0"><li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li><li class="breadcrumb-item active">Gas Stations</li></ol></nav>
        </div>
        <a href="<?= APP_URL ?>/?page=gas-stations&action=create" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Add Station</a>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <?php if (empty($stations)): ?>
                <div class="text-center py-5 text-muted">No stations yet.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Name</th><th>Address</th><th>Contact</th><th>Status</th><th>Vouchers</th><th>Created</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($stations as $s): ?>
                        <tr>
                            <td class="fw-bold"><?= e($s->name) ?></td>
                            <td><small class="text-muted"><?= e($s->address ?: '—') ?></small></td>
                            <td><small><?= e($s->contact ?: '—') ?></small></td>
                            <td><span class="badge bg-<?= $s->status==='active'?'success':'secondary' ?>"><?= e(ucfirst($s->status)) ?></span></td>
                            <td><span class="badge bg-light text-dark"><?= (int)$s->voucher_count ?></span></td>
                            <td><small class="text-muted"><?= e(formatDate($s->created_at)) ?></small></td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <a href="<?= APP_URL ?>/?page=gas-stations&action=edit&id=<?= (int)$s->id ?>" class="btn btn-outline-primary"><i class="bi bi-pencil"></i></a>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Toggle active/inactive for <?= e($s->name) ?>?')">
                                        <?= csrfField() ?><input type="hidden" name="op" value="toggle"><input type="hidden" name="id" value="<?= (int)$s->id ?>"><button type="submit" class="btn btn-outline-warning"><i class="bi bi-toggle-on"></i></button>
                                    </form>
                                    <?php if ((int)$s->voucher_count===0): ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete <?= e($s->name) ?>?')">
                                        <?= csrfField() ?><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?= (int)$s->id ?>"><button type="submit" class="btn btn-outline-danger"><i class="bi bi-trash"></i></button>
                                    </form>
                                    <?php else: ?>
                                    <button class="btn btn-outline-secondary" disabled title="Has vouchers, deactivate instead"><i class="bi bi-trash"></i></button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require_once INCLUDES_PATH . '/footer.php'; ?>
