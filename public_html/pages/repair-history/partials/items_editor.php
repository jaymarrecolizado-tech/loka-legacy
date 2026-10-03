<?php
/**
 * Plan #38 — shared purchased-items line-item editor.
 *
 * Expects: $items (list of normalised rows: description/unit/quantity/amount),
 * $errors (list of strings), $disabled (bool, read-only print view).
 */
$items = $items ?? [];
$disabled = $disabled ?? false;
?>
<div class="card mb-4">
    <div class="card-header d-flex align-items-center">
        <h6 class="mb-0"><i class="bi bi-list-columns me-2"></i>Purchased items</h6>
        <span class="badge bg-secondary ms-2" id="itemCount"><?= count($items) ?></span>
    </div>
    <div class="card-body">
        <?php if ($disabled): ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Description</th><th>Unit</th><th class="text-end">Qty</th><th class="text-end">Price</th></tr></thead>
                    <tbody>
                    <?php if ($items === []): ?>
                        <tr><td colspan="4" class="text-center text-muted py-3">No line items recorded.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($items as $it): ?>
                        <tr>
                            <td><?= e($it['description']) ?></td>
                            <td><?= e($it['unit'] ?: '—') ?></td>
                            <td class="text-end"><?= e(rtrim(rtrim(number_format((float) $it['quantity'], 2, '.', ''), '0'), '.')) ?></td>
                            <td class="text-end"><?= $it['amount'] !== null ? '₱' . number_format((float) $it['amount'], 2) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="fw-semibold">
                            <td colspan="3" class="text-end">Total</td>
                            <td class="text-end">₱<?= number_format(repairHistoryItemsTotal($items), 2) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-2" id="itemTable">
                    <thead>
                        <tr>
                            <th style="width:50%">Description</th>
                            <th style="width:15%">Unit</th>
                            <th style="width:12%">Quantity</th>
                            <th style="width:18%">Price (₱)</th>
                            <th style="width:5%"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $rows = $items ?: [['description' => '', 'unit' => '', 'quantity' => '1', 'amount' => '']];
                        foreach ($rows as $ri => $row):
                            ?>
                            <tr>
                                <td><input type="text" class="form-control form-control-sm" name="item[<?= (int) $ri ?>][description]" maxlength="255" value="<?= e($row['description'] ?? '') ?>" placeholder="e.g. Filter Oil, Labor"></td>
                                <td><input type="text" class="form-control form-control-sm" name="item[<?= (int) $ri ?>][unit]" maxlength="30" value="<?= e($row['unit'] ?? '') ?>" placeholder="Pc, lot, LTR"></td>
                                <td><input type="number" class="form-control form-control-sm" name="item[<?= (int) $ri ?>][quantity]" min="0.01" step="0.01" value="<?= e((string) ($row['quantity'] ?? '1')) ?>"></td>
                                <td><input type="number" class="form-control form-control-sm text-end" name="item[<?= (int) $ri ?>][amount]" min="0" step="0.01" value="<?= e((string) ($row['amount'] ?? '')) ?>" placeholder="0.00"></td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-sm btn-link text-danger p-0" data-remove-row title="Remove line">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-3">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="addItemRow">
                    <i class="bi bi-plus-lg me-1"></i>Add line
                </button>
                <span class="ms-auto fs-6 fw-semibold">
                    Total: ₱<span id="itemTotal"><?= number_format(repairHistoryItemsTotal($items), 2) ?></span>
                </span>
            </div>
            <p class="form-text small mb-0 mt-2">
                <i class="bi bi-info-circle me-1"></i>
                Price is the <strong>amount for the line</strong> (as printed in the workbook), not a per-unit price.
                Labor is entered as a normal line with unit <code>lot</code>.
            </p>
        <?php endif; ?>
    </div>
</div>

<?php if (!$disabled): ?>
<script>
(function () {
    var table = document.getElementById('itemTable');
    if (!table) return;
    var tbody = table.querySelector('tbody');
    var counter = document.getElementById('itemCount');
    var totalEl = document.getElementById('itemTotal');

    function renumber() {
        tbody.querySelectorAll('tr').forEach(function (tr, idx) {
            tr.querySelectorAll('input[name*="[description]"], input[name*="[unit]"], input[name*="[quantity]"], input[name*="[amount]"]')
                .forEach(function (input) {
                    input.name = input.name.replace(/^item\[\d+\]/, 'item[' + idx + ']');
                });
        });
        if (counter) counter.textContent = tbody.querySelectorAll('tr').length;
        recalc();
    }

    function recalc() {
        if (!totalEl) return;
        var sum = 0;
        tbody.querySelectorAll('tr').forEach(function (tr) {
            var v = parseFloat(tr.querySelector('input[name*="[amount]"]').value);
            if (!isNaN(v)) sum += v;
        });
        totalEl.textContent = sum.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    document.getElementById('addItemRow').addEventListener('click', function () {
        var idx = tbody.querySelectorAll('tr').length;
        var tr = document.createElement('tr');
        tr.innerHTML =
            '<td><input type="text" class="form-control form-control-sm" name="item[' + idx + '][description]" maxlength="255"></td>' +
            '<td><input type="text" class="form-control form-control-sm" name="item[' + idx + '][unit]" maxlength="30"></td>' +
            '<td><input type="number" class="form-control form-control-sm" name="item[' + idx + '][quantity]" min="0.01" step="0.01" value="1"></td>' +
            '<td><input type="number" class="form-control form-control-sm text-end" name="item[' + idx + '][amount]" min="0" step="0.01"></td>' +
            '<td class="text-center"><button type="button" class="btn btn-sm btn-link text-danger p-0" data-remove-row title="Remove line"><i class="bi bi-trash"></i></button></td>';
        tbody.appendChild(tr);
        renumber();
        tr.querySelector('input[name*="[description]"]').focus();
    });

    tbody.addEventListener('click', function (ev) {
        var btn = ev.target.closest('[data-remove-row]');
        if (!btn) return;
        var rows = tbody.querySelectorAll('tr');
        if (rows.length === 1) {
            rows[0].querySelectorAll('input').forEach(function (i) { i.value = i.name.includes('quantity') ? '1' : ''; });
        } else {
            btn.closest('tr').remove();
        }
        renumber();
    });

    tbody.addEventListener('input', function (ev) {
        if (ev.target.name && ev.target.name.includes('[amount]')) recalc();
    });

    renumber();
})();
</script>
<?php endif; ?>