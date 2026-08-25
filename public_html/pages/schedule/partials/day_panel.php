<?php
/**
 * Selected day/range detail: trips + free assets.
 *
 * Expects: $window, $windowTrips, $freeVehicles, $freeDrivers,
 * $fleetVehicles, $showRequester, $vehicleTypes, $vehicleTypeFilter,
 * $createRequestUrl
 */
$label = $window['is_range']
    ? date('M j, Y', strtotime($window['start_day'])) . ' – ' . date('M j, Y', strtotime($window['end_day']))
    : date('l, M j, Y', strtotime($window['start_day']));
?>
<div class="avail-panel">
    <div class="avail-panel-head">
        <div>
            <h5 class="avail-panel-title mb-0">
                <i class="bi bi-calendar-check me-2"></i><?= e($label) ?>
            </h5>
            <p class="avail-panel-sub mb-0">
                <?= count($windowTrips) ?> trip(s) ·
                <?= count($freeVehicles) ?> free vehicle(s) ·
                <?= count($freeDrivers) ?> free driver(s)
            </p>
        </div>
        <a href="<?= e($createRequestUrl) ?>" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>New Request
        </a>
    </div>

    <form method="get" class="avail-filters" action="<?= e(rtrim(APP_URL, '/') . '/') ?>">
        <input type="hidden" name="page" value="schedule">
        <input type="hidden" name="action" value="calendar">
        <input type="hidden" name="year" value="<?= (int) date('Y', strtotime($window['start_day'])) ?>">
        <input type="hidden" name="month" value="<?= (int) date('n', strtotime($window['start_day'])) ?>">
        <input type="hidden" name="date" value="<?= e($window['start_day']) ?>">
        <?php if ($window['is_range']): ?>
            <input type="hidden" name="end_date" value="<?= e($window['end_day']) ?>">
        <?php endif; ?>
        <label class="avail-filter-label">
            Vehicle type
            <select name="vehicle_type" class="form-control form-control form-control-sm" onchange="this.form.submit()">
                <option value="">All types</option>
                <?php foreach ($vehicleTypes as $vt): ?>
                    <option value="<?= (int) $vt->id ?>" <?= (string) $vehicleTypeFilter === (string) $vt->id ? 'selected' : '' ?>>
                        <?= e($vt->name) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php if ($vehicleTypeFilter): ?>
            <a class="btn btn-secondary btn-sm" href="<?= e(availabilityBuildUrl([
                'date' => $window['start_day'],
                'end_date' => $window['is_range'] ? $window['end_day'] : null,
                'year' => (int) date('Y', strtotime($window['start_day'])),
                'month' => (int) date('n', strtotime($window['start_day'])),
            ])) ?>">Clear filter</a>
        <?php endif; ?>
    </form>

    <!-- Mobile tabs -->
    <div class="avail-tabs" role="tablist">
        <button type="button" class="avail-tab is-active" data-tab="trips">Trips</button>
        <button type="button" class="avail-tab" data-tab="vehicles">Vehicles</button>
        <button type="button" class="avail-tab" data-tab="drivers">Drivers</button>
    </div>

    <div class="avail-sections">
        <section class="avail-section is-active" data-panel="trips">
            <h6 class="avail-section-title"><i class="bi bi-signpost-2 me-1"></i>Scheduled trips</h6>
            <?php if (empty($windowTrips)): ?>
                <div class="avail-empty">
                    <i class="bi bi-calendar-plus"></i>
                    <p>No trips in this window — good time to request.</p>
                </div>
            <?php else: ?>
                <ul class="avail-trip-list">
                    <?php foreach ($windowTrips as $trip):
                        $statusClass = $trip->status === 'approved' ? 'approved' : 'pending';
                        $statusLabel = $trip->status === 'approved' ? 'Approved' : 'Pending Motorpool';
                    ?>
                        <li class="avail-trip-item">
                            <div class="avail-trip-time">
                                <?= e(date('M j, g:i A', strtotime($trip->start_datetime))) ?>
                                <span class="text-muted">→</span>
                                <?= e(date('M j, g:i A', strtotime($trip->end_datetime))) ?>
                            </div>
                            <div class="avail-trip-main">
                                <a href="<?= APP_URL ?>/?page=requests&action=view&id=<?= (int) $trip->id ?>" class="avail-trip-dest">
                                    <?= e($trip->destination ?: 'No destination') ?>
                                </a>
                                <div class="avail-trip-meta">
                                    <span class="badge bg-light text-dark <?= $trip->plate_number ? 'bg-primary' : 'bg-secondary' ?>">
                                        <?= e($trip->plate_number ?: 'TBA') ?>
                                    </span>
                                    <?php if (!empty($trip->driver_name)): ?>
                                        <span class="text-sm text-muted"><?= e($trip->driver_name) ?></span>
                                    <?php endif; ?>
                                    <?php if ($showRequester): ?>
                                        <span class="text-sm text-muted"><?= e($trip->requester_name) ?></span>
                                    <?php endif; ?>
                                    <span class="badge bg-light text-dark <?= $statusClass === 'approved' ? 'bg-success' : 'bg-warning' ?>">
                                        <?= e($statusLabel) ?>
                                    </span>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="avail-section" data-panel="vehicles">
            <h6 class="avail-section-title">
                <i class="bi bi-truck me-1"></i>Available vehicles
                <span class="avail-count"><?= count($freeVehicles) ?>/<?= count($fleetVehicles) ?></span>
            </h6>
            <?php if (empty($freeVehicles)): ?>
                <div class="avail-empty">
                    <i class="bi bi-slash-circle"></i>
                    <p>No free vehicles in this window<?= $vehicleTypeFilter ? ' for this type' : '' ?>.</p>
                </div>
            <?php else: ?>
                <ul class="avail-asset-list">
                    <?php foreach ($freeVehicles as $v): ?>
                        <li>
                            <span class="avail-asset-name"><?= e($v->plate_number) ?></span>
                            <span class="avail-asset-meta">
                                <?= e(trim(($v->make ?? '') . ' ' . ($v->model ?? '')) ?: ($v->type_name ?? 'Vehicle')) ?>
                                <?php if (!empty($v->type_name)): ?>
                                    · <?= e($v->type_name) ?>
                                <?php endif; ?>
                            </span>
                            <span class="badge bg-success">Free</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="avail-section" data-panel="drivers">
            <h6 class="avail-section-title">
                <i class="bi bi-person-badge me-1"></i>Available drivers
                <span class="avail-count"><?= count($freeDrivers) ?></span>
            </h6>
            <?php if (empty($freeDrivers)): ?>
                <div class="avail-empty">
                    <i class="bi bi-person-x"></i>
                    <p>No free drivers in this window.</p>
                </div>
            <?php else: ?>
                <ul class="avail-asset-list">
                    <?php foreach ($freeDrivers as $d): ?>
                        <li>
                            <span class="avail-asset-name"><?= e($d->driver_name) ?></span>
                            <span class="avail-asset-meta">
                                <?= e($d->license_number ?: 'No license #') ?>
                                <?php if (!empty($d->driver_phone)): ?>
                                    · <?= e($d->driver_phone) ?>
                                <?php endif; ?>
                            </span>
                            <span class="badge bg-success">Free</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
</div>
