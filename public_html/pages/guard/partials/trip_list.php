    <!-- Filter Tabs -->
    <div class="card mb-4">
        <div class="card-header bg-white">
            <ul class="nav nav-tabs card-header-tabs">
                <li class="nav-item">
                    <a class="nav-link <?= $filter === 'today' ? 'active' : '' ?>"
                       href="<?= APP_URL ?>/?page=guard">
                        <i class="bi bi-calendar-day me-1"></i>Today's Trips
                        <span class="badge bg-primary ms-1"><?= $statsToday->total_scheduled ?? 0 ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $filter === 'pending_dispatch' ? 'active' : '' ?>"
                       href="<?= APP_URL ?>/?page=guard&filter=pending_dispatch">
                        <i class="bi bi-clock me-1"></i>Pending Dispatch
                        <span class="badge bg-warning ms-1"><?= $tabCounts->all_pending_dispatch ?? 0 ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $filter === 'pending_arrival' ? 'active' : '' ?>"
                       href="<?= APP_URL ?>/?page=guard&filter=pending_arrival">
                        <i class="bi bi-arrow-return-left me-1"></i>On Trip
                        <span class="badge bg-info ms-1"><?= $tabCounts->all_on_trip ?? 0 ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $filter === 'completed' ? 'active' : '' ?>"
                       href="<?= APP_URL ?>/?page=guard&filter=completed">
                        <i class="bi bi-check-circle me-1"></i>Completed
                        <span class="badge bg-success ms-1"><?= $tabCounts->all_completed ?? 0 ?></span>
                    </a>
                </li>
            </ul>
        </div>
        <div class="card-body">
            <?php if (empty($trips)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-calendar-x fs-1 text-muted"></i>
                    <p class="text-muted mt-3">
                        <?php if ($filter === 'today'): ?>
                            No approved trips scheduled for today.
                        <?php elseif ($filter === 'pending_dispatch'): ?>
                            No trips pending dispatch.
                        <?php elseif ($filter === 'pending_arrival'): ?>
                            No vehicles currently on trip.
                        <?php elseif ($filter === 'completed'): ?>
                            No trips completed today.
                        <?php else: ?>
                            No trips found.
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Request #</th>
                                <th>Time</th>
                                <th>Vehicle</th>
                                <th>Driver</th>
                                <th>Destination</th>
                                <th>Status</th>
                                <th>Dispatch</th>
                                <th>Arrival</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($trips as $trip): ?>
                                <tr>
                                    <td>
                                        <strong>#<?= $trip->id ?></strong><br>
                                        <small class="text-muted"><?= e($trip->requester_name) ?></small>
                                    </td>
                                    <td>
                                        <div class="small">
                                            <i class="bi bi-box-arrow-right text-success me-1"></i>
                                            <?= formatDateTime($trip->start_datetime) ?>
                                        </div>
                                        <div class="small">
                                            <i class="bi bi-box-arrow-in-left text-danger me-1"></i>
                                            <?= formatDateTime($trip->end_datetime) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($trip->plate_number): ?>
                                            <div class="fw-medium"><?= e($trip->plate_number) ?></div>
                                            <small class="text-muted"><?= e($trip->make . ' ' . $trip->vehicle_model) ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">Not assigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($trip->driver_name): ?>
                                            <div class="fw-medium"><?= e($trip->driver_name) ?></div>
                                            <small class="text-muted"><?= e($trip->driver_phone) ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">Not assigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= e($trip->destination) ?>
                                    </td>
                                    <td>
                                        <?php if ($trip->actual_arrival_datetime): ?>
                                            <span class="badge bg-success">Completed</span>
                                        <?php elseif ($trip->actual_dispatch_datetime): ?>
                                            <span class="badge bg-primary">On Trip</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($trip->actual_dispatch_datetime): ?>
                                            <div class="text-success">
                                                <i class="bi bi-check-circle me-1"></i>
                                                <?= formatDateTime($trip->actual_dispatch_datetime) ?>
                                            </div>
                                            <small class="text-muted">by <?= e($trip->dispatch_guard_name ?? 'Unknown') ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($trip->actual_arrival_datetime): ?>
                                            <div class="text-success">
                                                <i class="bi bi-check-circle me-1"></i>
                                                <?= formatDateTime($trip->actual_arrival_datetime) ?>
                                            </div>
                                            <small class="text-muted">by <?= e($trip->arrival_guard_name ?? 'Unknown') ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <?php if (!$trip->actual_dispatch_datetime): ?>
                                                <button type="button" class="btn btn-sm btn-success"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#dispatchModal<?= $trip->id ?>">
                                                    <i class="bi bi-box-arrow-right me-1"></i>Dispatch
                                                </button>
                                            <?php elseif (!$trip->actual_arrival_datetime): ?>
                                                <button type="button" class="btn btn-sm btn-primary"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#arrivalModal<?= $trip->id ?>">
                                                    <i class="bi bi-box-arrow-in-left me-1"></i>Arrival
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" disabled>
                                                    <i class="bi bi-check-all me-1"></i>Done
                                                </button>
                                            <?php endif; ?>

                                            <a href="<?= APP_URL ?>/?page=requests&action=view&id=<?= $trip->id ?>"
                                               class="btn btn-sm btn-outline-primary" target="_blank">
                                                <i class="bi bi-eye"></i>
                                            </a>
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
