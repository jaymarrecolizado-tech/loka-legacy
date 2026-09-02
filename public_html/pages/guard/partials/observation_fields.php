<?php
/**
 * Shared condition + photo fields for dispatch/arrival modals. (Bootstrap 5)
 * Expects: $obsPhase ('dispatch'|'arrival'), $tripId (int)
 */
$obsPhase = $obsPhase ?? 'dispatch';
$tripId = (int) ($tripId ?? 0);
$prefix = $obsPhase . $tripId;
?>
<div class="border rounded p-3 d-flex flex-column gap-3 bg-light">
    <div class="small fw-semibold text-uppercase text-muted">
        Vehicle condition (<?= $obsPhase === 'arrival' ? 'upon arrival' : 'before dispatch' ?>)
        <span class="text-danger">*</span>
    </div>

    <div class="d-flex flex-column gap-1">
        <label class="form-label small fw-semibold mb-0">Overall condition <span class="text-danger">*</span></label>
        <select name="overall_condition" class="form-select form-select-sm" required>
            <option value="">Select...</option>
            <option value="good">Good</option>
            <option value="fair">Fair</option>
            <option value="poor">Poor</option>
            <option value="damaged">Damaged</option>
        </select>
    </div>

    <div>
        <div class="form-label small fw-semibold mb-1">Checklist</div>
        <div class="row g-1 small">
            <?php
            $labels = [
                'exterior_damage' => 'Exterior damage',
                'interior_damage' => 'Interior damage',
                'tire_issue' => 'Tire issue',
                'lights_issue' => 'Lights issue',
                'fuel_low' => 'Fuel low',
                'unclean' => 'Unclean',
                'missing_items' => 'Missing items',
                'other' => 'Other',
            ];
            foreach ($labels as $key => $label):
            ?>
            <div class="col-6">
                <label class="form-check-label d-flex align-items-center gap-2">
                    <input type="checkbox" class="form-check-input" name="condition_flags[<?= e($key) ?>]" value="1">
                    <span><?= e($label) ?></span>
                </label>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="d-flex flex-column gap-1">
        <label class="form-label small fw-semibold mb-0">Condition notes</label>
        <textarea class="form-control form-control-sm" rows="2"
                  name="condition_notes"
                  maxlength="1000"
                  placeholder="Describe what you see (required if damaged)"></textarea>
    </div>

    <div class="d-flex flex-column gap-1">
        <label class="form-label small fw-semibold mb-0">
            Photos <span class="text-danger">*</span>
            <span class="fw-normal text-muted">(1–6, compressed automatically)</span>
        </label>
        <input type="file"
               id="obsPhoto<?= e($prefix) ?>"
               class="form-control form-control-sm obs-photo-input"
               name="observation_photos[]"
               accept="image/*"
               capture="environment"
               multiple
               required
               data-preview="obsPreview<?= e($prefix) ?>"
               data-size="obsSize<?= e($prefix) ?>">
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-sm btn-outline-primary flex-fill" onclick="(function(el){el.setAttribute('capture','environment'); el.click();})(document.getElementById('obsPhoto<?= e($prefix) ?>'))"><i class="bi bi-camera me-1"></i>Take Photo</button>
            <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" onclick="(function(el){el.removeAttribute('capture'); el.click();})(document.getElementById('obsPhoto<?= e($prefix) ?>'))"><i class="bi bi-images me-1"></i>Gallery</button>
        </div>
        <div id="obsSize<?= e($prefix) ?>" class="small text-muted">No photos selected</div>
        <div id="obsPreview<?= e($prefix) ?>" class="d-flex flex-wrap gap-2 mt-1"></div>
        <small class="text-muted" style="font-size:10px;">On phone: Take Photo opens camera directly; Gallery opens file picker.</small>
    </div>
</div>
