<?php
/**
 * Guard odometer reminder + reading / broken bypass (Bootstrap 5)
 *
 * Expects:
 * - $trip (object with plate_number, mileage_start, vehicle_mileage optional)
 * - $odoPhase: 'dispatch' | 'arrival'
 * - $tripId: int
 *
 * Requires includes/odometer.php (vehicleOdometerIsBroken).
 */
if (!function_exists('vehicleOdometerIsBroken')) {
    require_once INCLUDES_PATH . '/odometer.php';
}
$odoPhase = $odoPhase ?? 'dispatch';
$tripId = (int) ($tripId ?? ($trip->id ?? 0));
$plate = (string) ($trip->plate_number ?? '');
$knownBroken = vehicleOdometerIsBroken(
    (object) [
        'plate_number' => $plate,
        'odometer_broken' => $trip->odometer_broken ?? 0,
    ],
    $plate
);
$isDispatch = $odoPhase === 'dispatch';
$fieldName = $isDispatch ? 'mileage_start' : 'mileage_end';
$fieldLabel = $isDispatch ? 'Starting odometer (km)' : 'Ending odometer (km)';
$minMileage = $isDispatch
    ? (int) ($trip->vehicle_mileage ?? 0)
    : (int) ($trip->mileage_start ?? 0);
$driverName = trim((string) ($trip->driver_name ?? 'the driver'));
?>
<div class="border border-warning bg-warning bg-opacity-10 rounded p-3" data-odo-block="<?= $tripId ?>">
    <div class="small">
        <strong class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>Remind the driver</strong>
        <p class="mb-0 mt-1 small">
            Please ask <strong><?= e($driverName) ?></strong> to read the vehicle odometer
            (<?= e($plate !== '' ? $plate : 'this vehicle') ?>) before you confirm
            <?= $isDispatch ? 'dispatch' : 'arrival' ?>.
        </p>
    </div>

    <?php if ($knownBroken): ?>
    <div class="alert alert-warning small py-2 mb-2 mt-2">
        This vehicle is marked with a <strong>broken / unreadable odometer</strong>.
        You may skip the numeric reading — check the box below to continue.
    </div>
    <?php endif; ?>

    <div class="mt-2" id="odoInputWrap<?= $tripId ?>">
        <label class="form-label small fw-semibold text-muted text-uppercase mb-0">
            <?= e($fieldLabel) ?>
            <?php if (!$knownBroken): ?><span class="text-danger">*</span><?php endif; ?>
        </label>
        <input type="number"
               class="form-control form-control-sm"
               name="<?= e($fieldName) ?>"
               id="odoInput<?= $tripId ?>"
               min="<?= max(0, $minMileage) ?>"
               step="1"
               placeholder="Ask driver for current odometer reading"
               <?= $knownBroken ? '' : 'required' ?>
               <?= $knownBroken ? 'disabled' : '' ?>>
        <?php if ($minMileage > 0): ?>
        <span class="small text-muted">
            <?= $isDispatch ? 'Vehicle last recorded mileage' : 'Starting mileage' ?>:
            <strong><?= number_format($minMileage) ?> km</strong>
        </span>
        <?php endif; ?>
    </div>

    <label class="d-flex align-items-start gap-2 border rounded p-2 mt-2 mb-0 bg-white">
        <input type="checkbox"
               class="form-check-input mt-1"
               name="odometer_broken"
               id="odoBroken<?= $tripId ?>"
               value="1"
               role="switch"
               <?= $knownBroken ? 'checked' : '' ?>
               onchange="toggleGuardOdometerBroken(<?= $tripId ?>)">
        <span class="small">
            <strong>Odometer broken / unreadable</strong>
            <span class="d-block text-muted">
                Check this to continue without a reading (special / damaged units). Still remind the driver.
            </span>
        </span>
    </label>
</div>
