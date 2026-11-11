<?php
/** @var array<string, mixed> $dash */
$queue = $dash['queue'] ?? ['title' => 'Queue', 'href' => APP_URL . '/?page=requests', 'rows' => []];
$upcoming = $dash['upcoming'] ?? [];
$vehicleStats = $dash['vehicleStats'] ?? [];
$queueKind = $queue['kind'] ?? 'request';
$queueCol = !empty($dash['showUpcoming']) ? 'col-12 col-lg-6' : 'col-12';
?>
<div class="row g-4">
    <div class="<?= e($queueCol) ?>">
        <div class="card table-card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-list-task me-2"></i><?= e((string) $queue['title']) ?></h5>
                <a href="<?= e((string) $queue['href']) ?>" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($queue['rows'])): ?>
                <div class="empty-state py-4">
                    <i class="bi bi-inbox"></i>
                    <p class="mb-0">Nothing waiting right now</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 no-datatable">
                        <thead>
                            <tr>
                                <th><?= $queueKind === 'voucher' ? 'Voucher' : 'Request' ?></th>
                                <th>Destination</th>
                                <th>Age</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($queue['rows'] as $row): ?>
                            <tr>
                                <td>
                                    <div class="fw-medium"><?= truncate((string) $row['title'], 40) ?></div>
                                    <small class="text-muted"><?= e((string) $row['meta']) ?></small>
                                    <div class="mt-1">
                                        <?php if ($queueKind === 'voucher'): ?>
                                            <?= gasVoucherStatusBadge((string) $row['status']) ?>
                                        <?php else: ?>
                                            <?= requestStatusBadge((string) $row['status']) ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td><?= $row['destination'] !== '' ? truncate((string) $row['destination'], 40) : '<span class="text-muted">—</span>' ?></td>
                                <td class="text-nowrap"><small class="text-muted"><?= e((string) $row['age']) ?></small></td>
                                <td class="text-end">
                                    <a href="<?= e((string) $row['href']) ?>" class="btn btn-sm btn-outline-primary">Open</a>
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

    <?php if (!empty($dash['showUpcoming'])): ?>
    <div class="col-12 col-lg-6">
        <div class="card table-card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-calendar-event me-2"></i>Upcoming 7 Days</h5>
                <a href="<?= e((string) ($dash['requestsHref'] ?? (APP_URL . '/?page=requests'))) ?>" class="btn btn-sm btn-outline-primary">Requests</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($upcoming)): ?>
                <div class="empty-state py-4">
                    <i class="bi bi-calendar-x"></i>
                    <p class="mb-2">No upcoming trips in the next 7 days</p>
                    <a href="<?= e((string) ($dash['requestsHref'] ?? (APP_URL . '/?page=requests'))) ?>" class="small">Open Requests</a>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 no-datatable">
                        <thead>
                            <tr>
                                <th>Date/Time</th>
                                <th>Requester</th>
                                <th>Plate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($upcoming as $trip): ?>
                            <tr>
                                <td>
                                    <a href="<?= APP_URL ?>/?page=requests&action=view&id=<?= (int) $trip->id ?>" class="text-decoration-none fw-medium">
                                        <?= formatDateTime($trip->start_datetime) ?>
                                    </a>
                                    <?php if (!empty($trip->destination)): ?>
                                    <div class="small text-muted"><?= truncate((string) $trip->destination, 40) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= e((string) ($trip->requester_name ?? '')) ?></td>
                                <td>
                                    <?php if (!empty($trip->plate_number)): ?>
                                    <span class="badge bg-light text-dark"><?= e((string) $trip->plate_number) ?></span>
                                    <?php else: ?>
                                    <span class="text-muted">-</span>
                                    <?php endif; ?>
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
    <?php endif; ?>
</div>

<?php if (!empty($dash['showUtilization']) && !empty($vehicleStats)): ?>
<div class="row g-4 mt-1">
    <div class="col-12">
        <div class="card table-card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-pie-chart me-2"></i>Vehicle Status Overview</h5>
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <?php foreach ($vehicleStats as $stat): ?>
                    <div class="col-md-3 col-6 mb-3">
                        <div class="py-3">
                            <div class="h3 mb-1"><?= (int) $stat->count ?></div>
                            <div><?= vehicleStatusBadge((string) $stat->status) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
