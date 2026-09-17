<?php
/**
 * LOKA - OB Pass Slip print (Plan #22)
 * Official DICT letterhead (same logos as the gas voucher) + paper Pass Slip layout.
 * Two copies per A4 page. Route: ?page=ob-requests&action=print&id=
 */

if (!function_exists('obFind')) {
    require_once INCLUDES_PATH . '/ob_requests.php';
}
requireAuth();

$ob = obFind((int) get('id', 0));
if (!$ob) {
    redirectWith('/?page=ob-requests', 'danger', 'OB Pass Slip not found.');
}

$isOwner = (int) $ob->user_id === (int) userId();
$allowed = $isOwner || isApprover() || isGuard()
    || (int) $ob->supervisor_user_id === (int) userId();
if (!$allowed || !in_array($ob->status, ['approved', 'departed', 'coa_received', 'completed'], true)) {
    redirectWith('/?page=ob-requests&action=view&id=' . $ob->id, 'danger',
        'The Pass Slip can be printed once it is approved.');
}

$dateLong = date('F j, Y', strtotime($ob->ob_date));
$dateShort = date('M j, Y', strtotime($ob->ob_date));
$participantNames = obParticipantFullNames((int) $ob->id, (string) $ob->employee_name);
$printedLine = obPrintedEmployeeLine($participantNames);
$coaLine = obCoaAppearanceLine($participantNames, $dateLong);
$empHint = obEmployeePrintedHint($participantNames);

$sigSrc = static function (?string $rel): ?string {
    if ($rel === null || $rel === '') {
        return null;
    }
    if (!is_file(BASE_PATH . '/' . $rel)) {
        return null;
    }
    return APP_URL . '/?page=file-view&file=' . rawurlencode($rel);
};

$clock = static function (?string $raw): string {
    $raw = trim((string) $raw);
    if ($raw === '') {
        return '';
    }
    if (preg_match('/^(\d{1,2}):(\d{2})/', $raw, $m)) {
        $hour = (int) $m[1];
        $ampm = $hour >= 12 ? 'PM' : 'AM';
        $hour12 = $hour % 12;
        if ($hour12 === 0) {
            $hour12 = 12;
        }
        return $hour12 . ':' . $m[2] . ' ' . $ampm;
    }
    return $raw;
};

$blank = '_______________';
$official = obUsesOfficialVehicle($ob);
$plate = $ob->plate_number ? strtoupper((string) $ob->plate_number) : ($official ? $blank : 'PRIVATE VEHICLE');
$depTime = $ob->ob_departure_datetime ? date('g:i A', strtotime($ob->ob_departure_datetime)) : $blank;
$arrTime = $ob->ob_arrival_datetime ? date('g:i A', strtotime($ob->ob_arrival_datetime)) : $blank;
$coaFrom = $clock($ob->coa_time_from) ?: '________';
$coaTo = $clock($ob->coa_time_to) ?: '________';

$sigs = [
    'supervisor' => $sigSrc($ob->supervisor_signature_path ?? null),
    'motorpool' => $sigSrc($ob->motorpool_signature_path ?? null),
    'guard' => $sigSrc($ob->guard_departure_signature_path ?? null),
    'coa' => $sigSrc($ob->coa_signature_path ?? null),
];
$logoDict = APP_URL . '/assets/img/dict_logo.png';
$logoBp = APP_URL . '/assets/img/bp_logo.png';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pass Slip <?= e($ob->pass_slip_no) ?> — DICT RO2</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 10pt; color: #000; background: #fff; }
        .sheet { width: 100%; max-width: 210mm; margin: 0 auto; }
        .copy { padding: 5mm 8mm 3mm; }
        .letterhead { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }
        .header-logo { width: 48px; height: auto; flex-shrink: 0; object-fit: contain; mix-blend-mode: multiply; }
        .header-text { flex: 1; min-width: 0; text-align: center; font-family: 'Times New Roman', Times, serif; }
        .header-text .republic { font-size: 10pt; }
        .header-text .dept { font-size: 10.5pt; font-weight: bold; text-transform: uppercase; margin: 1px 0; line-height: 1.2; }
        .header-text .office { font-size: 8.5pt; }
        .header-right { flex-shrink: 0; text-align: center; min-width: 96px; }
        .header-right .slip-no { margin-top: 2px; font-size: 8pt; }
        .header-right .num { font-size: 11pt; font-weight: bold; letter-spacing: .3px; }
        .title { font-size: 16pt; font-weight: bold; text-align: center; letter-spacing: 5px; margin: 4px 0 1px; }
        .subtitle { text-align: center; font-size: 9pt; margin-bottom: 6px; }
        .purpose { margin: 2px 0 8px; font-size: 10pt; overflow-wrap: anywhere; }
        .purpose strong { margin-right: 8px; }
        .sig-row { display: flex; justify-content: space-between; gap: 20px; margin: 4px 0 6px; }
        .sig-col { width: 48%; min-width: 0; }
        .sig-col.right { text-align: right; }
        .sig-pad { height: 34px; display: flex; align-items: flex-end; }
        .sig-col.right .sig-pad { justify-content: flex-end; }
        .sig-pad img { max-height: 34px; max-width: 140px; object-fit: contain; }
        .sig-name { font-weight: bold; font-size: 9.5pt; border-top: 1px solid #000; padding-top: 2px; min-height: 16px; overflow-wrap: anywhere; }
        .sig-hint { font-size: 7.5pt; color: #333; }
        .guard-row { display: flex; justify-content: space-between; gap: 16px; font-size: 10pt; margin: 2px 0; }
        .guard-row .lbl { font-weight: bold; margin-right: 6px; }
        .coa-note { text-align: center; font-style: italic; font-size: 8pt; margin: 6px 0 2px; }
        .coa-title { text-align: center; font-weight: bold; font-size: 12pt; letter-spacing: 1px; margin-bottom: 4px; }
        .coa-body { font-size: 10pt; line-height: 1.4; overflow-wrap: anywhere; }
        .coa-times { text-align: center; margin: 5px 0 6px; }
        .cut { text-align: center; margin: 2mm 0; padding: 2px 0; border-top: 1px dashed #888; border-bottom: 1px dashed #888; font-size: 8pt; color: #666; letter-spacing: 1px; }
        @media print {
            @page { size: A4 portrait; margin: 6mm; }
            body { background: #fff; }
            .no-print { display: none !important; }
            .sheet { width: 100%; max-width: none; }
            .copy { padding: 1.5mm 2mm 1mm; page-break-inside: avoid; }
            .header-logo { width: 40px !important; }
            .header-text .republic { font-size: 8pt !important; }
            .header-text .dept { font-size: 9pt !important; }
            .header-text .office { font-size: 7.5pt !important; }
            .title { font-size: 13pt !important; margin: 2px 0 !important; letter-spacing: 4px !important; }
            .cut { margin: 1.5mm 0 !important; }
        }
    </style>
</head>
<body>
<div class="no-print" style="text-align:center;padding:12px;background:#f8f9fa;border-bottom:1px solid #ddd;margin-bottom:12px;font-family:sans-serif;">
    <button onclick="window.print()" style="background:#0d6efd;color:#fff;border:none;padding:8px 24px;border-radius:4px;cursor:pointer;font-size:14px;font-weight:bold;">Print Pass Slip (A4)</button>
    <a href="<?= APP_URL ?>/?page=ob-requests&amp;action=view&amp;id=<?= (int) $ob->id ?>" style="margin-left:15px;color:#666;text-decoration:none;">← Back</a>
</div>

<div class="sheet">
<?php for ($copy = 1; $copy <= 2; $copy++): ?>
<?php if ($copy === 2): ?>
    <div class="cut">CUT HERE — client copy</div>
<?php endif; ?>
<div class="copy">
    <div class="letterhead">
        <img src="<?= e($logoDict) ?>" class="header-logo" alt="DICT Logo" onerror="this.style.display='none'">
        <div class="header-text">
            <div class="republic">Republic of the Philippines</div>
            <div class="dept">Department of Information and Communications Technology</div>
            <div class="office">Regional Office 02, 02 Bagay Road, San Gabriel, Tuguegarao City, Cagayan 3500</div>
        </div>
        <div class="header-right">
            <img src="<?= e($logoBp) ?>" class="header-logo" alt="Bagong Pilipinas" onerror="this.style.display='none'">
            <div class="slip-no">Pass Slip No.<br><span class="num"><?= e($ob->pass_slip_no) ?></span><br>(<?= e($dateShort) ?>)</div>
        </div>
    </div>

    <div class="title">PASS SLIP</div>
    <div class="subtitle">Requesting permission to leave during office hours on Official Business</div>

    <div class="purpose"><strong>Purpose:</strong> <?= e((string) $ob->purpose) ?></div>

    <div class="sig-row">
        <div class="sig-col">
            <div class="sig-pad"></div>
            <div class="sig-name"><?= e($printedLine) ?></div>
            <div class="sig-hint"><?= e($empHint) ?></div>
        </div>
        <div class="sig-col right">
            <div class="sig-pad"><?php if ($sigs['supervisor']): ?><img src="<?= e($sigs['supervisor']) ?>" alt="Supervisor signature"><?php endif; ?></div>
            <div class="sig-name"><?= e($ob->supervisor_name ?: '') ?></div>
            <div class="sig-hint">Immediate Supervisor<br>(Signature over printed name)</div>
        </div>
    </div>

    <div class="sig-row">
        <div class="sig-col">
            <div class="sig-pad"></div>
            <div class="sig-name"><?= e($plate) ?></div>
            <div class="sig-hint">Vehicle Plate No.</div>
        </div>
        <div class="sig-col right">
            <div class="sig-pad"><?php if ($sigs['motorpool']): ?><img src="<?= e($sigs['motorpool']) ?>" alt="Motorpool signature"><?php endif; ?></div>
            <div class="sig-name"><?= $official ? e($ob->motorpool_name ?: '') : 'N/A' ?></div>
            <div class="sig-hint">Approved by / Motorpool Unit<?= $official ? '' : ' (private vehicle)' ?></div>
        </div>
    </div>

    <div class="sig-row">
        <div class="sig-col">
            <div class="sig-pad"><?php if ($sigs['guard']): ?><img src="<?= e($sigs['guard']) ?>" alt="Guard signature"><?php endif; ?></div>
            <div class="sig-name"><?= e($ob->departure_guard_name ?: '') ?></div>
            <div class="sig-hint">Guard on Duty<br>(Signature / initial)</div>
        </div>
        <div class="sig-col right">
            <div class="guard-row" style="justify-content:flex-end;"><span class="lbl">Time of Departure:</span><?= e($depTime) ?></div>
            <div class="guard-row" style="justify-content:flex-end; margin-top:8px;"><span class="lbl">Time of Arrival:</span><?= e($arrTime) ?></div>
        </div>
    </div>

    <div class="coa-note">(Official Business: Please Accomplish the following)</div>
    <div class="coa-title">CERTIFICATE OF APPEARANCE</div>
    <div class="coa-body">
        <p><strong>OFFICE/ESTABLISHMENT:</strong> <?= e((string) ($ob->coa_office ?? '')) ?></p>
        <p style="margin-top:5px;"><?= e($coaLine) ?></p>
        <p class="coa-times">From <?= e($coaFrom) ?> to <?= e($coaTo) ?>.</p>
    </div>
    <div class="sig-row" style="justify-content:flex-end;">
        <div class="sig-col right">
            <div class="sig-pad"><?php if ($sigs['coa']): ?><img src="<?= e($sigs['coa']) ?>" alt="Certificate of Appearance signature"><?php endif; ?></div>
            <div class="sig-name"><?= e($ob->coa_representative ?: '') ?></div>
            <div class="sig-hint">(Representative name and signature)</div>
        </div>
    </div>
</div>
<?php endfor; ?>
</div>
</body>
</html>
