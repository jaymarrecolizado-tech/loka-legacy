<?php
/**
 * LOKA - Gas Voucher Report
 */

require_once INCLUDES_PATH . '/gas_voucher_report.php';
requireGasVoucherReportAccess();

$pageTitle = 'Gas Voucher Report';
$f = gasVoucherReportParseFilters();
$preset = (string) ($f['preset'] ?? '');
$perPage = resolvePerPage();
$options = gasVoucherReportFilterOptions();
$kpis = gasVoucherReportKpis($f);
$analytics = gasVoucherReportAnalytics($f);

$allowedSortColumns = [
    'voucher_no' => 'gv.voucher_no',
    'request_date' => 'gv.request_date',
    'created_at' => 'gv.created_at',
    'requester' => 'u.name',
    'department' => 'd.name',
    'vehicle_plate' => 'gv.vehicle_plate',
    'status' => 'gv.status',
    'payment_status' => 'gv.payment_status',
    'approved_at' => 'gv.approved_at',
];
$sortState = resolveTableSort($allowedSortColumns, 'request_date', 'DESC');
$sort = $sortState['key'];
$sortDir = $sortState['dir'];
$pag = listPaginationState((int) ($kpis->total ?? 0), null, $perPage);
$rows = gasVoucherReportRows($f, $pag['perPage'], $sortState['orderSql'], $pag['offset']);

$baseParams = tableSortQueryParams($sortState, gasVoucherReportQueryParams($f, [
    'action' => 'gas-vouchers',
    'per_page' => $pag['perPage'],
    'q' => $f['search'],
    'preset' => $preset,
]));
$csvUrl = gasVoucherReportUrl(gasVoucherReportQueryParams($f, ['action' => 'export-gas-vouchers-csv']));
$pdfUrl = gasVoucherReportUrl(gasVoucherReportQueryParams($f, ['action' => 'export-gas-vouchers-pdf']));

$approved = (int) $kpis->approved;
$unpaidPct = $approved > 0
    ? round(((int) $kpis->approved_unpaid_count / $approved) * 100, 1)
    : 0.0;

require_once INCLUDES_PATH . '/header.php';
?>

<div class="w-full px-4 py-4 sm:px-6 lg:px-8">
    <div class="flex flex-wrap justify-between items-start gap-3 mb-4">
        <div>
            <h4 class="mb-1">Gas Voucher Report</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/?page=reports">Reports</a></li>
                    <li class="breadcrumb-item active">Gas Vouchers</li>
                </ol>
            </nav>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="<?= e($csvUrl) ?>" class="loka-btn-outline-primary">
                <i class="bi bi-file-earmark-csv me-1"></i>Export CSV
            </a>
            <a href="<?= e($pdfUrl) ?>" class="loka-btn-outline-error">
                <i class="bi bi-file-earmark-pdf me-1"></i>Export PDF
            </a>
        </div>
    </div>

    <!-- Filters -->
    <div class="loka-card mb-4">
        <form method="GET" class="loka-filter-form">
            <input type="hidden" name="page" value="reports">
            <input type="hidden" name="action" value="gas-vouchers">
            <?= reportPresetFieldHtml($preset) ?>
            <div class="min-w-[140px]">
                <label class="loka-form-label">Start Date</label>
                <input type="date" class="loka-form-input" name="start_date" value="<?= e($f['start_date']) ?>">
            </div>
            <div class="min-w-[140px]">
                <label class="loka-form-label">End Date</label>
                <input type="date" class="loka-form-input" name="end_date" value="<?= e($f['end_date']) ?>">
            </div>
            <div class="min-w-[140px]">
                <label class="loka-form-label">Date Field</label>
                <select class="loka-form-input" name="date_field">
                    <option value="request_date" <?= $f['date_field'] === 'request_date' ? 'selected' : '' ?>>Request date</option>
                    <option value="created_at" <?= $f['date_field'] === 'created_at' ? 'selected' : '' ?>>Created</option>
                    <option value="approved_at" <?= $f['date_field'] === 'approved_at' ? 'selected' : '' ?>>Approved</option>
                </select>
            </div>
            <div class="min-w-[140px]">
                <label class="loka-form-label">Status</label>
                <select class="loka-form-input" name="status">
                    <option value="">All statuses</option>
                    <?php foreach (GAS_VOUCHER_STATUSES as $key => $meta): ?>
                    <option value="<?= e($key) ?>" <?= $f['status'] === $key ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="min-w-[140px]">
                <label class="loka-form-label">Payment</label>
                <select class="loka-form-input" name="payment_status">
                    <option value="">All payment</option>
                    <?php foreach (['unpaid', 'paid', 'processed', 'cancelled'] as $ps): ?>
                    <option value="<?= $ps ?>" <?= $f['payment_status'] === $ps ? 'selected' : '' ?>><?= e(ucfirst($ps)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="min-w-[140px]">
                <label class="loka-form-label">Fuel Type</label>
                <select class="loka-form-input" name="fuel_type">
                    <option value="">All fuel</option>
                    <option value="Gasoline" <?= $f['fuel_type'] === 'Gasoline' ? 'selected' : '' ?>>Gasoline</option>
                    <option value="Diesel" <?= $f['fuel_type'] === 'Diesel' ? 'selected' : '' ?>>Diesel</option>
                </select>
            </div>
            <div class="min-w-[160px]">
                <label class="loka-form-label">Fund Source</label>
                <select class="loka-form-input" name="fund_source">
                    <option value="">All funds</option>
                    <?php foreach ($options['funds'] as $fund): ?>
                    <option value="<?= e($fund) ?>" <?= $f['fund_source'] === $fund ? 'selected' : '' ?>><?= e($fund) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="min-w-[160px]">
                <label class="loka-form-label">Gas Station</label>
                <select class="loka-form-input" name="gas_station">
                    <option value="">All stations</option>
                    <?php foreach ($options['stations'] as $st): ?>
                    <option value="<?= e($st) ?>" <?= $f['gas_station'] === $st ? 'selected' : '' ?>><?= e($st) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="min-w-[160px]">
                <label class="loka-form-label">Department</label>
                <select class="loka-form-input" name="department_id">
                    <option value="">All departments</option>
                    <?php foreach ($options['departments'] as $dept): ?>
                    <option value="<?= (int) $dept->id ?>" <?= (int) $f['department_id'] === (int) $dept->id ? 'selected' : '' ?>><?= e($dept->name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?= perPageFieldHtml($pag['perPage'], 'loka-form-input') ?>
            <?= listSearchFieldHtml($f['search'], 'Voucher, plate, driver…', 'loka-form-input') ?>
            <div class="flex flex-wrap items-center gap-3">
                <label class="inline-flex items-center gap-2 text-sm">
                    <input type="checkbox" name="include_drafts" value="1" <?= $f['include_drafts'] ? 'checked' : '' ?>>
                    Include drafts
                </label>
                <button type="submit" class="loka-btn-primary"><i class="bi bi-search me-1"></i>Filter</button>
                <a href="<?= APP_URL ?>/?page=reports&action=gas-vouchers" class="loka-btn-secondary">Reset</a>
            </div>
        </form>
    </div>

    <?php if ((int) ($kpis->total ?? 0) > 500): ?>
    <div class="loka-alert loka-alert-warning mb-4">
        Large result set (<?= number_format((int) $kpis->total) ?> rows). PDF export is capped at 500 rows; CSV at 10,000. Narrow filters if you need a complete export.
    </div>
    <?php endif; ?>

    <!-- KPIs -->
    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-7 gap-3 mb-4">
        <div class="loka-card"><div class="loka-card-body text-center py-3">
            <div class="text-xl font-bold text-primary"><?= (int) $kpis->total ?></div>
            <div class="text-xs text-base-content/60">Total vouchers</div>
        </div></div>
        <div class="loka-card"><div class="loka-card-body text-center py-3">
            <div class="text-xl font-bold text-warning"><?= (int) $kpis->pending_review ?></div>
            <div class="text-xs text-base-content/60">Pending review</div>
        </div></div>
        <div class="loka-card"><div class="loka-card-body text-center py-3">
            <div class="text-xl font-bold text-warning"><?= (int) $kpis->pending_approval ?></div>
            <div class="text-xs text-base-content/60">Pending CAF</div>
        </div></div>
        <div class="loka-card"><div class="loka-card-body text-center py-3">
            <div class="text-xl font-bold text-success"><?= (int) $kpis->approved ?></div>
            <div class="text-xs text-base-content/60">Approved</div>
        </div></div>
        <div class="loka-card"><div class="loka-card-body text-center py-3">
            <div class="text-xl font-bold text-error"><?= (int) $kpis->approved_unpaid_count ?></div>
            <div class="text-xs text-base-content/60">Approved unpaid</div>
        </div></div>
        <div class="loka-card"><div class="loka-card-body text-center py-3">
            <div class="text-xl font-bold text-success"><?= (int) $kpis->paid_count ?></div>
            <div class="text-xs text-base-content/60">Paid / processed</div>
        </div></div>
        <div class="loka-card"><div class="loka-card-body text-center py-3">
            <div class="text-xl font-bold"><?= (int) $kpis->rejected_cancelled ?></div>
            <div class="text-xs text-base-content/60">Rejected / cancelled</div>
        </div></div>
    </div>

    <!-- Insights -->
    <div class="loka-card mb-4">
        <div class="loka-card-body text-sm">
            <strong class="mr-1">Insights:</strong>
            <?= e((string) $unpaidPct) ?>% of approved still unpaid
            <?php if (!empty($analytics['topFund'])): ?>
            · Top fund: <?= e($analytics['topFund']->label) ?>
                (<?= (int) $analytics['topFund']->count ?> vouchers)
            <?php endif; ?>
            <?php if (!empty($analytics['topStation'])): ?>
            · Top station: <?= e($analytics['topStation']->label) ?>
                (<?= (int) $analytics['topStation']->count ?> vouchers)
            <?php endif; ?>
            <?php if ($kpis->avg_approve_days !== null): ?>
            · Avg approval lag: <?= e(number_format((float) $kpis->avg_approve_days, 1)) ?> days
            <?php endif; ?>
        </div>
    </div>

    <!-- Charts -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">
        <div class="loka-card">
            <div class="border-b border-base-200 px-4 py-3 font-semibold text-sm">Status distribution</div>
            <div class="p-4"><canvas id="gvStatusChart" height="220"></canvas></div>
        </div>
        <div class="loka-card">
            <div class="border-b border-base-200 px-4 py-3 font-semibold text-sm">Vouchers by fund source</div>
            <div class="p-4"><canvas id="gvFundChart" height="220"></canvas></div>
        </div>
        <div class="loka-card">
            <div class="border-b border-base-200 px-4 py-3 font-semibold text-sm">Payment (approved)</div>
            <div class="p-4"><canvas id="gvPaymentChart" height="220"></canvas></div>
        </div>
    </div>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
        <div class="loka-card">
            <div class="border-b border-base-200 px-4 py-3 font-semibold text-sm">Vouchers by gas station</div>
            <div class="p-4"><canvas id="gvStationChart" height="220"></canvas></div>
        </div>
        <div class="loka-card">
            <div class="border-b border-base-200 px-4 py-3 font-semibold text-sm">Daily voucher volume</div>
            <div class="p-4"><canvas id="gvTrendChart" height="220"></canvas></div>
        </div>
    </div>

    <!-- Table -->
    <div class="loka-card">
        <div class="loka-card-header flex justify-between items-center">
            <h5 class="loka-card-title font-semibold mb-0">Vouchers <span class="text-base-content/50 text-sm font-normal">(<?= number_format((int) ($kpis->total ?? 0)) ?> total)</span></h5>
        </div>
        <div class="loka-table-responsive">
            <table class="loka-table mb-0">
                <thead>
                    <tr>
                        <?= tableSortTh('voucher_no', 'Voucher', $sort, $sortDir, $baseParams) ?>
                        <?= tableSortTh('request_date', 'Request date', $sort, $sortDir, $baseParams) ?>
                        <?= tableSortTh('requester', 'Requester', $sort, $sortDir, $baseParams) ?>
                        <?= tableSortTh('department', 'Dept', $sort, $sortDir, $baseParams) ?>
                        <th>Driver</th>
                        <?= tableSortTh('vehicle_plate', 'Plate', $sort, $sortDir, $baseParams) ?>
                        <th>Fuel</th>
                        <?= tableSortTh('status', 'Status', $sort, $sortDir, $baseParams) ?>
                        <?= tableSortTh('payment_status', 'Payment', $sort, $sortDir, $baseParams) ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                    <tr><td colspan="9" class="text-center text-base-content/50 py-6">No vouchers match these filters.</td></tr>
                    <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                    <tr>
                        <td>
                            <a href="<?= APP_URL ?>/?page=gas-vouchers&action=view&id=<?= (int) $row->id ?>" class="text-primary no-underline hover:underline">
                                <?= e($row->voucher_no) ?>
                            </a>
                        </td>
                        <td><?= e($row->request_date ? formatDate($row->request_date) : '-') ?></td>
                        <td><?= e($row->requester_name ?? '-') ?></td>
                        <td><?= e($row->department_name ?? '-') ?></td>
                        <td><?= e($row->driver_name ?? '-') ?></td>
                        <td><?= e($row->vehicle_plate ?? '-') ?></td>
                        <td class="text-xs"><?= e(($row->fuel_type ?? '-') . ' / ' . ($row->quantity ?? '') . ' ' . ($row->unit ?? '')) ?></td>
                        <td><?= gasVoucherStatusBadge((string) $row->status) ?></td>
                        <td><span class="loka-badge loka-status-info"><?= e(ucfirst((string) ($row->payment_status ?? '-'))) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?= listPaginationFooter($pag, $baseParams) ?>
    </div>
</div>

<script>
window.gasVoucherReportAnalytics = <?= json_encode($analytics, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="<?= ASSETS_PATH ?>/js/charts/gas-voucher-reports.js" defer></script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
