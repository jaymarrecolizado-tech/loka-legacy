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
               multiple
               required
               data-preview="obsPreview<?= e($prefix) ?>"
               data-size="obsSize<?= e($prefix) ?>">
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-sm btn-primary flex-fill" onclick="openCamera('<?= e($prefix) ?>')"><i class="bi bi-camera me-1"></i>Take Photo</button>
            <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" onclick="document.getElementById('obsPhoto<?= e($prefix) ?>').click()"><i class="bi bi-images me-1"></i>Gallery</button>
        </div>
        <div id="obsSize<?= e($prefix) ?>" class="small text-muted">No photos selected</div>
        <div id="obsPreview<?= e($prefix) ?>" class="d-flex flex-wrap gap-2 mt-1"></div>
        <small class="text-muted" style="font-size:10px;">Take Photo will request camera access and the shot will be uploaded with the form.</small>
    </div>
</div>

<!-- Camera Modal (per condition block, unique by prefix) -->
<div class="modal fade" id="cameraModal<?= e($prefix) ?>" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title mb-0"><i class="bi bi-camera me-2"></i>Take Photo</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-0 bg-black text-center">
        <div class="small text-white-50 p-2 bg-dark">LOKA needs camera access to capture vehicle condition photos. Please click <strong>Allow</strong> when your browser asks for permission.</div>
        <video id="cameraVideo<?= e($prefix) ?>" autoplay playsinline style="width:100%; max-height:60vh; object-fit:cover; background:#000;"></video>
        <canvas id="cameraCanvas<?= e($prefix) ?>" style="display:none;"></canvas>
        <div id="cameraError<?= e($prefix) ?>" class="small text-danger p-2 d-none bg-white"></div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" onclick="captureCameraPhoto('<?= e($prefix) ?>')"><i class="bi bi-camera me-1"></i>Capture & Use</button>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  if (!window._cameraStreams) window._cameraStreams = {};
  window.openCamera = window.openCamera || function(p){
    const vid = document.getElementById('cameraVideo'+p);
    const err = document.getElementById('cameraError'+p);
    const modalEl = document.getElementById('cameraModal'+p);
    if (!vid || !modalEl) return;
    if (err) { err.classList.add('d-none'); err.textContent=''; }
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      if (err){ err.textContent='Camera not supported on this browser. Use Gallery.'; err.classList.remove('d-none'); }
      return;
    }
    const constraints = { video: { facingMode: 'environment' }, audio: false };
    navigator.mediaDevices.getUserMedia(constraints).then(stream=>{
      window._cameraStreams[p]=stream;
      vid.srcObject=stream;
      return vid.play();
    }).catch(e=>{
      const msg = (e && e.name==='NotAllowedError') ? 'Permission denied. Please click Allow when prompted, or enable Camera in browser site settings (lock icon → Allow) and try again.' : 'Camera unavailable: '+(e.message||e);
      if (err){ err.textContent=msg; err.classList.remove('d-none'); }
      if (e && e.name==='NotAllowedError') return;
      // try fallback to any camera
      navigator.mediaDevices.getUserMedia({video:true, audio:false}).then(stream=>{
        window._cameraStreams[p]=stream;
        vid.srcObject=stream;
        return vid.play();
      }).catch(e2=>{
        const msg2 = (e2 && e2.name==='NotAllowedError') ? 'Permission denied. Please allow Camera and try again.' : 'Camera access denied or unavailable: '+(e2.message||e2);
        if (err){ err.textContent=msg2; err.classList.remove('d-none'); }
      });
    });
    const onHide = function(){
      const s=window._cameraStreams[p];
      if(s){ s.getTracks().forEach(t=>t.stop()); delete window._cameraStreams[p]; }
      if(vid) vid.srcObject=null;
      modalEl.removeEventListener('hidden.bs.modal', onHide);
    };
    modalEl.addEventListener('hidden.bs.modal', onHide);
  };
  window.captureCameraPhoto = window.captureCameraPhoto || function(p){
    const vid=document.getElementById('cameraVideo'+p);
    const canvas=document.getElementById('cameraCanvas'+p);
    const input=document.getElementById('obsPhoto'+p);
    const modalEl=document.getElementById('cameraModal'+p);
    if(!vid || !canvas || !input) return;
    if(!vid.videoWidth){ alert('Camera not ready yet. Please wait a moment.'); return; }
    canvas.width=vid.videoWidth; canvas.height=vid.videoHeight;
    const ctx=canvas.getContext('2d'); ctx.drawImage(vid,0,0);
    canvas.toBlob(function(blob){
      if(!blob) { alert('Capture failed'); return; }
      const file=new File([blob], 'camera_'+Date.now()+'.jpg', {type:'image/jpeg'});
      const dt=new DataTransfer();
      for(let i=0;i<input.files.length && dt.items.length<6;i++) dt.items.add(input.files[i]);
      if(dt.items.length<6) dt.items.add(file);
      else { alert('Maximum 6 photos reached. Remove one first.'); return; }
      input.files=dt.files;
      input.dispatchEvent(new Event('change', {bubbles:true}));
      const modal=bootstrap.Modal.getInstance(modalEl);
      if(modal) modal.hide();
    }, 'image/jpeg', 0.85);
  };
})();
</script>
